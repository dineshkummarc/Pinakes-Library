<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\AdminController;
use App\Plugins\BookClub\BookClubPlugin;
use App\Plugins\BookClub\Repo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Club management for staff in the companion app (/api/v1/bookclub/staff,
 * Mobile API 1.7.0 Tier 2): clubs, their workflow and their members.
 *
 * Mounted next to the member bridge only when the mobile-api plugin ships
 * its staff role guard and delegation helper (1.7.0+). Every write is
 * delegated to the plugin's own AdminController with the form the
 * /admin/book-club pages post; its flash (`bookclub_flash`, isolated by the
 * delegation) tells success from refusal. Reads use the Repo.
 */
final class MobileStaffBridge
{
    private const PRIVACIES = ['public', 'private', 'invite', 'hidden'];
    private const MEMBER_STATUSES = ['pending', 'active', 'suspended', 'left', 'banned'];

    public function __construct(private \mysqli $db, private Repo $repo)
    {
    }

    public static function supported(): bool
    {
        return class_exists(\App\Plugins\MobileApi\Support\StaffRoleMiddleware::class)
            && class_exists(\App\Plugins\MobileApi\Support\Delegation::class)
            && class_exists(\App\Plugins\MobileApi\Support\ResponseEnvelope::class);
    }

    /**
     * @param callable(): \App\Plugins\MobileApi\Support\AppAuthMiddleware $authMw
     * @param callable(): \App\Plugins\MobileApi\Support\TokenQuotaMiddleware $quotaMw
     */
    public function register(mixed $app, callable $authMw, callable $quotaMw): void
    {
        if (!self::supported()) {
            return;
        }
        $bridge = $this;
        $staffMw = static fn (): \App\Plugins\MobileApi\Support\StaffRoleMiddleware => new \App\Plugins\MobileApi\Support\StaffRoleMiddleware();
        $routes = [
            ['GET', '/clubs', static fn ($q, $r, $a) => $bridge->clubs($q, $r)],
            ['POST', '/clubs', static fn ($q, $r, $a) => $bridge->save($q, $r, null)],
            ['GET', '/clubs/{id:[0-9]+}', static fn ($q, $r, $a) => $bridge->club($q, $r, (int) $a['id'])],
            ['PUT', '/clubs/{id:[0-9]+}', static fn ($q, $r, $a) => $bridge->save($q, $r, (int) $a['id'])],
            ['DELETE', '/clubs/{id:[0-9]+}', static fn ($q, $r, $a) => $bridge->delete($q, $r, (int) $a['id'])],
            ['PUT', '/clubs/{id:[0-9]+}/workflow', static fn ($q, $r, $a) => $bridge->workflow($q, $r, (int) $a['id'])],
            ['GET', '/clubs/{id:[0-9]+}/members', static fn ($q, $r, $a) => $bridge->members($q, $r, (int) $a['id'])],
            ['POST', '/clubs/{id:[0-9]+}/members', static fn ($q, $r, $a) => $bridge->addMember($q, $r, (int) $a['id'])],
            ['PUT', '/clubs/{id:[0-9]+}/members/{memberId:[0-9]+}', static fn ($q, $r, $a) => $bridge->updateMember($q, $r, (int) $a['id'], (int) $a['memberId'])],
        ];
        $group = $app->group('/api/v1/bookclub/staff', function ($g) use ($routes, $staffMw, $authMw, $quotaMw): void {
            foreach ($routes as [$method, $pattern, $handler]) {
                $g->map([$method], $pattern, function (ServerRequestInterface $rq, ResponseInterface $rs, array $a) use ($handler): ResponseInterface {
                    return $handler($rq, $rs, $a);
                })->add($staffMw())->add($quotaMw())->add($authMw());
            }
        });
        $group->add(new \App\Plugins\MobileApi\Support\HttpsEnforceMiddleware());
    }

    // ─── Clubs ──────────────────────────────────────────────────────────────

    public function clubs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return $this->ok($response, array_map(fn (array $c): array => $this->mapClub($c), $this->repo->listAllClubs()));
        } catch (\Throwable $e) {
            return $this->fail($response, 'clubs', $e);
        }
    }

    public function club(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $club = $this->repo->clubById($id);

            return $club === null ? $this->notFound($response) : $this->ok($response, $this->detail($club));
        } catch (\Throwable $e) {
            return $this->fail($response, 'club', $e);
        }
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response, ?int $id): ResponseInterface
    {
        $json = $this->json($request);
        try {
            $current = null;
            if ($id !== null) {
                $current = $this->repo->clubById($id);
                if ($current === null) {
                    return $this->notFound($response);
                }
            }
            $name = array_key_exists('name', $json) ? (is_scalar($json['name']) ? trim((string) $json['name']) : '') : (string) ($current['name'] ?? '');
            if ($name === '' || mb_strlen($name) > 190) {
                return $this->error($response, 'validation_error', __('Il nome del club è obbligatorio.'), 422, ['field' => 'name']);
            }
            $privacy = array_key_exists('privacy', $json) ? $json['privacy'] : ($current['privacy'] ?? 'public');
            if (!is_string($privacy) || !in_array($privacy, self::PRIVACIES, true)) {
                return $this->error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'privacy']);
            }
            $color = array_key_exists('color', $json) ? $json['color'] : ($current['color'] ?? '#4f46e5');
            if (!is_string($color) || preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
                return $this->error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'color']);
            }
            $settings = is_array($current['settings'] ?? null) ? $current['settings'] : [];
            // The admin form is a full form (checkboxes absent = off): re-post the stored values.
            $form = [
                'name'        => $name,
                'description' => $this->text($json, 'description', $current['description'] ?? ''),
                'rules'       => $this->text($json, 'rules', $current['rules'] ?? ''),
                'color'       => $color,
                'privacy'     => $privacy,
                'max_members' => array_key_exists('max_members', $json)
                    ? (is_int($json['max_members']) ? (string) $json['max_members'] : '')
                    : (isset($current['max_members']) ? (string) $current['max_members'] : ''),
                'max_proposals_per_member' => array_key_exists('max_proposals_per_member', $json)
                    ? (is_int($json['max_proposals_per_member']) ? (string) $json['max_proposals_per_member'] : '')
                    : (isset($settings['max_proposals_per_member']) ? (string) $settings['max_proposals_per_member'] : ''),
            ];
            $active = array_key_exists('active', $json) ? $json['active'] === true : ($current === null || (int) ($current['is_active'] ?? 1) === 1);
            if ($active) {
                $form['is_active'] = '1';
            }
            $moderate = array_key_exists('moderate_proposals', $json) ? $json['moderate_proposals'] === true : (bool) ($settings['moderate_proposals'] ?? false);
            if ($moderate) {
                $form['moderate_proposals'] = '1';
            }
            $modules = array_key_exists('modules', $json) && is_array($json['modules'])
                ? array_values(array_filter($json['modules'], 'is_string'))
                : (is_array($settings['modules'] ?? null) ? $settings['modules'] : null);
            if ($modules !== null) {
                $form['modules'] = $modules;
            }

            $before = (int) ($this->rows('SELECT COALESCE(MAX(id), 0) AS m FROM bookclub_clubs', [])[0]['m'] ?? 0);
            $outcome = $this->delegate(static fn (AdminController $c): ResponseInterface => $c->save(
                \App\Plugins\MobileApi\Support\Delegation::request('POST', '/admin/book-club/new', $form),
                new SlimResponse(),
                $id
            ));
            $flash = $outcome->session['bookclub_flash'] ?? null;
            if (is_array($flash) && ($flash['type'] ?? '') === 'error') {
                return $this->error($response, 'validation_error', (string) ($flash['message'] ?? __('Valore non valido.')), 422);
            }
            $clubId = $id ?? $outcome->locationId('#/admin/book-club/(\d+)$#') ?? (int) ($this->rows('SELECT MAX(id) AS m FROM bookclub_clubs WHERE id > ?', [$before])[0]['m'] ?? 0);
            $club = $clubId > 0 ? $this->repo->clubById($clubId) : null;
            if ($club === null) {
                throw new \RuntimeException('club not resolvable after save');
            }

            return $this->ok($response, $this->detail($club), $id === null ? 201 : 200);
        } catch (\Throwable $e) {
            return $this->fail($response, 'club save', $e);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            if ($this->repo->clubById($id) === null) {
                return $this->notFound($response);
            }
            $this->delegate(static fn (AdminController $c): ResponseInterface => $c->delete(
                \App\Plugins\MobileApi\Support\Delegation::request('POST', '/admin/book-club/' . $id . '/delete'),
                new SlimResponse(),
                $id
            ));

            return $this->ok($response, ['id' => $id, 'deleted' => true]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'club delete', $e);
        }
    }

    public function workflow(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $states = $this->json($request)['states'] ?? null;
        if (!is_array($states) || count($states) < 2) {
            return $this->error($response, 'validation_error', __('Il workflow deve avere almeno due stati.'), 422, ['field' => 'states']);
        }
        $form = ['state_key' => [], 'state_label' => [], 'state_color' => []];
        foreach (array_values($states) as $i => $state) {
            if (!is_array($state) || !is_string($state['label'] ?? null)) {
                return $this->error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'states']);
            }
            $form['state_key'][$i] = is_string($state['key'] ?? null) ? $state['key'] : '';
            $form['state_label'][$i] = $state['label'];
            $form['state_color'][$i] = is_string($state['color'] ?? null) ? $state['color'] : '';
            foreach (['voting', 'current', 'archived'] as $flag) {
                if (($state['flags'][$flag] ?? false) === true) {
                    $form['flag_' . $flag][$i] = '1';
                }
            }
        }
        try {
            if ($this->repo->clubById($id) === null) {
                return $this->notFound($response);
            }
            $outcome = $this->delegate(static fn (AdminController $c): ResponseInterface => $c->saveWorkflow(
                \App\Plugins\MobileApi\Support\Delegation::request('POST', '/admin/book-club/' . $id . '/workflow', $form),
                new SlimResponse(),
                $id
            ));
            $flash = $outcome->session['bookclub_flash'] ?? null;
            if (is_array($flash) && ($flash['type'] ?? '') === 'error') {
                return $this->error($response, 'validation_error', (string) ($flash['message'] ?? ''), 422, ['field' => 'states']);
            }

            return $this->ok($response, $this->detail((array) $this->repo->clubById($id)));
        } catch (\Throwable $e) {
            return $this->fail($response, 'club workflow', $e);
        }
    }

    // ─── Members ────────────────────────────────────────────────────────────

    public function members(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            if ($this->repo->clubById($id) === null) {
                return $this->notFound($response);
            }

            return $this->ok($response, array_map(fn (array $m): array => $this->mapMember($m), $this->repo->listMembers($id)));
        } catch (\Throwable $e) {
            return $this->fail($response, 'club members', $e);
        }
    }

    public function addMember(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $json = $this->json($request);
        $userId = is_int($json['user_id'] ?? null) ? $json['user_id'] : 0;
        $role = is_string($json['role'] ?? null) ? $json['role'] : 'member';
        if ($userId <= 0) {
            return $this->error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'user_id']);
        }
        if (!in_array($role, BookClubPlugin::SYSTEM_ROLES, true)) {
            return $this->error($response, 'validation_error', __('Ruolo non valido.'), 422, ['field' => 'role']);
        }
        try {
            if ($this->repo->clubById($id) === null) {
                return $this->notFound($response);
            }
            $email = $this->rows('SELECT email FROM utenti WHERE id = ?', [$userId])[0]['email'] ?? null;
            if (!is_string($email) || $email === '') {
                return $this->error($response, 'not_found', __('Utente non trovato'), 404, ['field' => 'user_id']);
            }
            $outcome = $this->delegate(static fn (AdminController $c): ResponseInterface => $c->addMember(
                \App\Plugins\MobileApi\Support\Delegation::request('POST', '/admin/book-club/' . $id . '/members/add', ['email' => $email, 'role' => $role]),
                new SlimResponse(),
                $id
            ));
            $flash = $outcome->session['bookclub_flash'] ?? null;
            if (is_array($flash) && ($flash['type'] ?? '') === 'error') {
                return $this->error($response, 'validation_error', (string) ($flash['message'] ?? ''), 422);
            }
            $member = $this->rows('SELECT id FROM bookclub_members WHERE club_id = ? AND user_id = ?', [$id, $userId])[0] ?? null;
            $row = $member !== null ? $this->memberDetail($id, (int) $member['id']) : null;
            if ($row === null) {
                throw new \RuntimeException('member not resolvable after add');
            }

            return $this->ok($response, $row, 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'club member add', $e);
        }
    }

    public function updateMember(ServerRequestInterface $request, ResponseInterface $response, int $id, int $memberId): ResponseInterface
    {
        $json = $this->json($request);
        $status = $json['status'] ?? null;
        $role = $json['role'] ?? null;
        if ($status !== null && (!is_string($status) || !in_array($status, self::MEMBER_STATUSES, true))) {
            return $this->error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'status']);
        }
        if ($role !== null && (!is_string($role) || !in_array($role, BookClubPlugin::SYSTEM_ROLES, true))) {
            return $this->error($response, 'validation_error', __('Ruolo non valido.'), 422, ['field' => 'role']);
        }
        try {
            if ($this->memberDetail($id, $memberId) === null) {
                return $this->notFound($response);
            }
            $form = array_filter(['status' => $status, 'role' => $role], static fn ($v): bool => $v !== null);
            $this->delegate(static fn (AdminController $c): ResponseInterface => $c->updateMember(
                \App\Plugins\MobileApi\Support\Delegation::request('POST', '/admin/book-club/' . $id . '/members/' . $memberId . '/update', $form),
                new SlimResponse(),
                $id,
                $memberId
            ));

            return $this->ok($response, $this->memberDetail($id, $memberId));
        } catch (\Throwable $e) {
            return $this->fail($response, 'club member update', $e);
        }
    }

    // ─── Mapping ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $club
     * @return array<string, mixed>
     */
    private function mapClub(array $club): array
    {
        $settings = is_array($club['settings'] ?? null) ? $club['settings'] : (json_decode((string) ($club['settings'] ?? ''), true) ?: []);

        return [
            'id' => (int) $club['id'], 'slug' => (string) $club['slug'], 'name' => (string) $club['name'],
            'description' => (string) ($club['description'] ?? ''), 'rules' => (string) ($club['rules'] ?? ''),
            'color' => (string) ($club['color'] ?? ''), 'privacy' => (string) $club['privacy'],
            'max_members' => isset($club['max_members']) ? (int) $club['max_members'] : null,
            'active' => (int) ($club['is_active'] ?? 0) === 1,
            'moderate_proposals' => (bool) ($settings['moderate_proposals'] ?? false),
            'max_proposals_per_member' => isset($settings['max_proposals_per_member']) ? (int) $settings['max_proposals_per_member'] : null,
            'modules' => is_array($settings['modules'] ?? null) ? array_values($settings['modules']) : [],
            'member_count' => isset($club['member_count']) ? (int) $club['member_count'] : null,
            'created_at' => (string) ($club['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $club
     * @return array<string, mixed>
     */
    private function detail(array $club): array
    {
        return $this->mapClub($club) + [
            'workflow' => $this->repo->workflowStates($club),
            'members'  => array_map(fn (array $m): array => $this->mapMember($m), $this->repo->listMembers((int) $club['id'])),
        ];
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private function mapMember(array $m): array
    {
        return [
            'id' => (int) $m['id'], 'user_id' => (int) $m['user_id'],
            'name' => trim((string) ($m['nome'] ?? '') . ' ' . (string) ($m['cognome'] ?? '')), 'email' => (string) ($m['email'] ?? ''),
            'role' => (string) ($m['role_slug'] ?? ''), 'role_name' => (string) ($m['role_name'] ?? ''), 'status' => (string) $m['status'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function memberDetail(int $clubId, int $memberId): ?array
    {
        foreach ($this->repo->listMembers($clubId) as $m) {
            if ((int) $m['id'] === $memberId) {
                return $this->mapMember($m);
            }
        }

        return null;
    }

    // ─── Plumbing ───────────────────────────────────────────────────────────

    /**
     * @param callable(AdminController): ResponseInterface $action
     */
    private function delegate(callable $action): \App\Plugins\MobileApi\Support\DelegationOutcome
    {
        $controller = new AdminController($this->db, $this->repo);
        $keys = array_merge(\App\Plugins\MobileApi\Support\Delegation::SESSION_KEYS, ['bookclub_flash']);

        return \App\Plugins\MobileApi\Support\Delegation::run(static fn (): ResponseInterface => $action($controller), $keys);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function text(array $json, string $key, mixed $fallback): string
    {
        if (!array_key_exists($key, $json)) {
            return is_scalar($fallback) ? (string) $fallback : '';
        }

        return is_scalar($json[$key]) ? (string) $json[$key] : '';
    }

    /** @return array<string, mixed> */
    private function json(ServerRequestInterface $request): array
    {
        return \App\Plugins\MobileApi\Support\JsonBody::parse($request);
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed: ' . $this->db->error);
        }
        if ($params !== []) {
            $stmt->bind_param(implode('', array_map(static fn ($p): string => is_int($p) ? 'i' : 's', $params)), ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $rows[] = $r;
        }
        $stmt->close();

        return $rows;
    }

    /** @param mixed $data */
    private function ok(ResponseInterface $response, $data, int $status = 200): ResponseInterface
    {
        return \App\Plugins\MobileApi\Support\ResponseEnvelope::success($response, $data, [], $status);
    }

    /** @param array<string, mixed> $meta */
    private function error(ResponseInterface $response, string $code, string $message, int $status, array $meta = []): ResponseInterface
    {
        return \App\Plugins\MobileApi\Support\ResponseEnvelope::error($response, $code, $message, $status, $meta);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return $this->error($response, 'not_found', __('Club non trovato.'), 404);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[BookClub:mobile-staff] ' . $what . ' failed: ' . $e->getMessage());

        return $this->error($response, 'internal_error', __('Errore interno.'), 500);
    }

    /**
     * OpenAPI paths of the staff bridge.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function extendOpenApi(array $doc): array
    {
        if (!self::supported()) {
            return $doc;
        }
        $tag = ['bookclub-staff'];
        $sec = [['bearerAuth' => []]];
        $club = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];
        $op = static fn (string $summary, array $responses, array $extra = []): array => array_merge([
            'tags' => $tag, 'summary' => $summary, 'security' => $sec,
            'responses' => $responses + ['401' => ['description' => 'Token required.'], '403' => ['description' => '`forbidden`: admin or staff only.']],
        ], $extra);
        $body = static fn (array $props): array => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $props]]]];
        $str = ['type' => 'string'];
        $write = [
            'name' => $str, 'description' => $str, 'rules' => $str, 'color' => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'],
            'privacy' => ['type' => 'string', 'enum' => self::PRIVACIES], 'max_members' => ['type' => ['integer', 'null']],
            'active' => ['type' => 'boolean'], 'moderate_proposals' => ['type' => 'boolean'],
            'max_proposals_per_member' => ['type' => ['integer', 'null']], 'modules' => ['type' => 'array', 'items' => $str],
        ];
        $doc['tags'][] = ['name' => 'bookclub-staff', 'description' => 'Book club management for admin and staff accounts (book-club plugin).'];
        $doc['paths'] = array_merge(is_array($doc['paths'] ?? null) ? $doc['paths'] : [], [
            '/bookclub/staff/clubs' => [
                'get' => $op('Every club, inactive included', ['200' => ['description' => 'Clubs.']]),
                'post' => $op('Create a club (the operator becomes its owner)', ['201' => ['description' => 'Club detail.'], '422' => ['description' => '`validation_error`.']], ['requestBody' => $body($write)]),
            ],
            '/bookclub/staff/clubs/{id}' => [
                'get' => $op('Club detail with workflow and members', ['200' => ['description' => 'Club detail.'], '404' => ['description' => 'Unknown club.']], ['parameters' => [$club]]),
                'put' => $op('Update a club (absent keys keep their value)', ['200' => ['description' => 'Club detail.'], '404' => ['description' => 'Unknown club.'], '422' => ['description' => '`validation_error`.']], ['parameters' => [$club], 'requestBody' => $body($write)]),
                'delete' => $op('Soft-delete a club', ['200' => ['description' => '{id, deleted}.'], '404' => ['description' => 'Unknown club.']], ['parameters' => [$club]]),
            ],
            '/bookclub/staff/clubs/{id}/workflow' => ['put' => $op('Replace the proposal workflow (at least two states)', ['200' => ['description' => 'Club detail.'], '404' => ['description' => 'Unknown club.'], '422' => ['description' => '`validation_error`.']], [
                'parameters' => [$club],
                'requestBody' => $body(['states' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'object', 'properties' => [
                    'key' => $str, 'label' => $str, 'color' => $str, 'flags' => ['type' => 'object', 'properties' => ['voting' => ['type' => 'boolean'], 'current' => ['type' => 'boolean'], 'archived' => ['type' => 'boolean']]],
                ]]]]),
            ])],
            '/bookclub/staff/clubs/{id}/members' => [
                'get' => $op('Members of a club', ['200' => ['description' => 'Members.'], '404' => ['description' => 'Unknown club.']], ['parameters' => [$club]]),
                'post' => $op('Add a registered user to a club', ['201' => ['description' => 'Member.'], '404' => ['description' => 'Unknown club or user.'], '422' => ['description' => '`validation_error`.']], [
                    'parameters' => [$club], 'requestBody' => $body(['user_id' => ['type' => 'integer'], 'role' => ['type' => 'string', 'enum' => BookClubPlugin::SYSTEM_ROLES]]),
                ]),
            ],
            '/bookclub/staff/clubs/{id}/members/{memberId}' => ['put' => $op('Change a member\'s status or role', ['200' => ['description' => 'Member.'], '404' => ['description' => 'Unknown member.'], '422' => ['description' => '`validation_error`.']], [
                'parameters' => [$club, ['name' => 'memberId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'requestBody' => $body(['status' => ['type' => 'string', 'enum' => self::MEMBER_STATUSES], 'role' => ['type' => 'string', 'enum' => BookClubPlugin::SYSTEM_ROLES]]),
            ])],
        ]);

        return $doc;
    }
}
