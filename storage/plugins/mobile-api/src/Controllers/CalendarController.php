<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\CalendarTokenService;
use App\Plugins\MobileApi\Support\HttpCache;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\UserLoanCalendar;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Personal, revocable loans calendar.
 *
 *   GET    /me/calendar                  status + subscription URLs (bearer)
 *   POST   /me/calendar/token            create or rotate the secret URL (bearer)
 *   DELETE /me/calendar/token            revoke it (bearer, idempotent)
 *   GET    /calendar/feed/{token}.ics    the feed itself — public, the token is
 *                                        the credential (calendar clients cannot
 *                                        send headers)
 *
 * The feed answers 404 for every failure (unknown / revoked / malformed token,
 * app access disabled, catalogue mode, account no longer active) so it never
 * reveals whether a token once existed.
 */
final class CalendarController
{
    public function __construct(private mysqli $db)
    {
    }

    public function status(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        try {
            return ResponseEnvelope::success($response, $this->statusPayload($userId));
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] calendar status failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function rotate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        try {
            (new CalendarTokenService($this->db))->rotate($userId);

            return ResponseEnvelope::success($response, $this->statusPayload($userId), [], 201);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] calendar token rotation failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function revoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);
        try {
            (new CalendarTokenService($this->db))->revoke($userId);

            return ResponseEnvelope::success($response, ['enabled' => false]);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] calendar token revoke failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
    }

    public function feed(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $token,
        bool $appAccessEnabled
    ): ResponseInterface {
        try {
            if (!$appAccessEnabled || \App\Support\ConfigStore::isCatalogueMode()) {
                return $this->notFound($response);
            }
            $userId = (new CalendarTokenService($this->db))->resolve($token);
            if ($userId === null || !$this->isActiveUser($userId)) {
                return $this->notFound($response);
            }

            $name = (string) \App\Support\ConfigStore::get('app.name', 'Pinakes') . ': ' . __('I miei prestiti');
            $body = (new UserLoanCalendar($this->db))->generate($userId, $name, true, false);
            $etag = '"' . sha1($body) . '"';

            $response = $response
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=900')
                ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                ->withHeader('Referrer-Policy', 'no-referrer');
            if (HttpCache::notModified($request, $etag)) {
                return $response->withStatus(304);
            }

            $response->getBody()->write($body);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
                ->withHeader('Content-Disposition', 'inline; filename="pinakes-loans.ics"');
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] calendar feed failed: ' . $e->getMessage());

            return ResponseEnvelope::error(new \Slim\Psr7\Response(), 'internal_error', __('Errore interno.'), 500);
        }
    }

    /**
     * @return array{enabled:bool, feed_url:?string, webcal_url:?string, created_at:?string, rotated_at:?string}
     */
    private function statusPayload(int $userId): array
    {
        $status = (new CalendarTokenService($this->db))->status($userId);
        $feedUrl = null;
        $webcalUrl = null;
        if ($status['enabled'] && $status['token'] !== null) {
            $feedUrl = absoluteUrl('/api/v1/calendar/feed/' . $status['token'] . '.ics');
            $webcalUrl = (string) preg_replace('#^https?://#i', 'webcal://', $feedUrl);
        }

        return [
            'enabled'    => $status['enabled'],
            'feed_url'   => $feedUrl,
            'webcal_url' => $webcalUrl,
            'created_at' => $status['created_at'],
            'rotated_at' => $status['rotated_at'],
        ];
    }

    private function isActiveUser(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM utenti WHERE id = ? AND stato = 'attivo' AND email_verificata = 1 LIMIT 1");
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $ok = $result !== false && $result->fetch_row() !== null;
        $stmt->close();

        return $ok;
    }

    private function userId(ServerRequestInterface $request): int
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);

        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Calendario non trovato.'), 404)
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
