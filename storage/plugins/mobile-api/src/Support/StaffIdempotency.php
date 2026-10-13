<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Idempotency keys and creation locks for the non-idempotent staff creates
 * (POST /staff/loans, POST /staff/reservations — Mobile API 1.7.0).
 *
 * A client that retries after a timeout sends the same `Idempotency-Key`: the
 * first successful create is remembered (key → request hash → resource id,
 * 24 h, table `mobile_idempotency_keys`) and every later call with that key
 * answers the original resource instead of creating a second one; the same
 * key with a different payload is refused.
 *
 * Serialisation uses MySQL advisory locks (GET_LOCK) rather than a row lock
 * held in a transaction: the create is delegated to a core controller that
 * opens and commits its own transaction on the same connection, which would
 * implicitly commit (and release) any transaction opened around it. An
 * advisory lock lives on the session, so it survives the delegated commit:
 *   - the key lock makes a concurrent duplicate wait, then replay;
 *   - the per-title create lock makes "the newest row above the high-water
 *     mark" belong to this request, so the new id is never someone else's.
 */
final class StaffIdempotency
{
    public const TTL_HOURS = 24;
    private const LOCK_TIMEOUT = 15;

    public function __construct(private mysqli $db)
    {
    }

    /**
     * The client key: `Idempotency-Key` header, else body `idempotency_key`.
     * null = none sent, false = malformed (1..128 of A-Z a-z 0-9 . _ : -).
     *
     * @param array<string, mixed> $json
     */
    public static function keyFrom(ServerRequestInterface $request, array $json): string|false|null
    {
        $raw = trim($request->getHeaderLine('Idempotency-Key'));
        if ($raw === '' && array_key_exists('idempotency_key', $json)) {
            $value = $json['idempotency_key'];
            if ($value === null) {
                return null;
            }
            if (!is_string($value)) {
                return false;
            }
            $raw = trim($value);
        }
        if ($raw === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._:\-]{1,128}$/D', $raw) === 1 ? $raw : false;
    }

    /** @param array<string, mixed> $payload */
    public static function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Advisory-lock name, scoped to this database (GET_LOCK is server-wide; ≤ 64 chars). */
    public function lockName(string ...$parts): string
    {
        $database = '';
        $result = $this->db->query('SELECT DATABASE()');
        if ($result instanceof \mysqli_result) {
            $row = $result->fetch_row();
            $database = (string) ($row[0] ?? '');
            $result->free();
        }

        return 'pnk_mapi_' . sha1($database . '|' . implode('|', $parts));
    }

    public function lock(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, ?)');
        if ($stmt === false) {
            return false;
        }
        $timeout = self::LOCK_TIMEOUT;
        $stmt->bind_param('si', $name, $timeout);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_row() : null;
        $stmt->close();

        return (int) ($row[0] ?? 0) === 1;
    }

    public function unlock(string $name): void
    {
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->get_result();
        $stmt->close();
    }

    /**
     * A remembered, unexpired key of this operator.
     *
     * @return array{request_hash:string, resource_id:int}|null
     */
    public function find(int $userId, string $scope, string $key): ?array
    {
        $ttl = self::TTL_HOURS;
        $stmt = $this->db->prepare(
            'SELECT request_hash, resource_id FROM mobile_idempotency_keys
              WHERE user_id = ? AND scope = ? AND idem_key = ? AND created_at >= NOW() - INTERVAL ? HOUR LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('idempotency lookup prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('issi', $userId, $scope, $key, $ttl);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row === null ? null : ['request_hash' => (string) $row['request_hash'], 'resource_id' => (int) $row['resource_id']];
    }

    public function remember(int $userId, string $scope, string $key, string $hash, int $resourceId): void
    {
        // REPLACE: an expired row with the same key is superseded.
        $stmt = $this->db->prepare(
            'REPLACE INTO mobile_idempotency_keys (user_id, scope, idem_key, request_hash, resource_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        if ($stmt === false) {
            throw new \RuntimeException('idempotency store prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('isssi', $userId, $scope, $key, $hash, $resourceId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Run a staff create under the idempotency contract.
     *
     * $create runs while this title's create lock is held and returns either
     * an error response or the id of the new resource; $load maps an id to
     * the API resource. A remembered key with the same payload replays the
     * original resource (200, meta.replayed=true); with another payload it is
     * 422 `idempotency_conflict`.
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed> $payload normalised request (hashed)
     * @param callable(): (ResponseInterface|int) $create
     * @param callable(int): (array<string, mixed>|null) $load
     */
    public function run(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $json,
        string $scope,
        int $staffId,
        int $bookId,
        array $payload,
        callable $create,
        callable $load
    ): ResponseInterface {
        $key = self::keyFrom($request, $json);
        if ($key === false) {
            return ResponseEnvelope::error($response, 'validation_error', __('Chiave di idempotenza non valida.'), 422, ['field' => 'idempotency_key']);
        }
        $hash = self::hash($payload);
        $keyLock = null;
        if ($key !== null) {
            $keyLock = $this->lockName('key', (string) $staffId, $scope, $key);
            if (!$this->lock($keyLock)) {
                return ResponseEnvelope::error($response, 'concurrent_retry', CirculationOutcome::message('concurrent_retry'), 409);
            }
        }
        try {
            if ($key !== null) {
                $this->purgeExpired();
                $previous = $this->find($staffId, $scope, $key);
                if ($previous !== null) {
                    if (!hash_equals($previous['request_hash'], $hash)) {
                        return ResponseEnvelope::error(
                            $response,
                            'idempotency_conflict',
                            __('Questa chiave di idempotenza è già stata usata per una richiesta diversa.'),
                            422,
                            ['field' => 'idempotency_key']
                        );
                    }
                    $resource = $load($previous['resource_id']);
                    if ($resource === null) {
                        return ResponseEnvelope::error($response, 'not_found', __('Elemento non trovato.'), 404);
                    }

                    return ResponseEnvelope::success($response, $resource, ['replayed' => true], 200);
                }
            }

            $createLock = $this->lockName('create', $scope, (string) $bookId);
            if (!$this->lock($createLock)) {
                return ResponseEnvelope::error($response, 'concurrent_retry', CirculationOutcome::message('concurrent_retry'), 409);
            }
            try {
                $result = $create();
            } finally {
                $this->unlock($createLock);
            }
            if ($result instanceof ResponseInterface) {
                return $result;
            }
            $resource = $load($result);
            if ($resource === null) {
                throw new \RuntimeException('created resource ' . $result . ' not readable');
            }
            if ($key !== null) {
                $this->remember($staffId, $scope, $key, $hash, $result);
            }

            return ResponseEnvelope::success($response, $resource, [], 201);
        } finally {
            if ($keyLock !== null) {
                $this->unlock($keyLock);
            }
        }
    }

    /** Drop keys older than the TTL (bounded, cheap: indexed on created_at). */
    public function purgeExpired(): void
    {
        $ttl = self::TTL_HOURS;
        $stmt = $this->db->prepare('DELETE FROM mobile_idempotency_keys WHERE created_at < NOW() - INTERVAL ? HOUR LIMIT 500');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $ttl);
        $stmt->execute();
        $stmt->close();
    }
}
