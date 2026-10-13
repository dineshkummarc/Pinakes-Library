<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ETag helpers shared by the staff, sync and calendar controllers.
 *
 * Same validator semantics as CatalogController: strong ETags, a weak `W/`
 * prefix and the `-gzip` / `-br` suffix that Apache mod_deflate appends are
 * stripped before comparing, and `If-None-Match: *` never yields a 304 on a GET
 * (a client must have seen the representation to revalidate it).
 */
final class HttpCache
{
    private function __construct()
    {
    }

    public static function etagFor(string $namespace, mixed $payload): string
    {
        $seed = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($seed === false) {
            $seed = serialize($payload);
        }

        return '"' . sha1($namespace . ':' . $seed) . '"';
    }

    public static function notModified(ServerRequestInterface $request, string $etag): bool
    {
        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        if ($ifNoneMatch === '' || $ifNoneMatch === '*') {
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            $candidate = preg_replace('/^W\//', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/-(gzip|br)("?)$/', '$2', $candidate) ?? $candidate;
            if (hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Emit a JSON envelope with an ETag, or a bare 304 when the client already
     * holds this exact representation.
     *
     * @param array<string, mixed> $meta
     */
    public static function envelope(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $namespace,
        mixed $data,
        array $meta = []
    ): ResponseInterface {
        $etag = self::etagFor($namespace, ['data' => $data, 'meta' => $meta]);
        if (self::notModified($request, $etag)) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        }

        return ResponseEnvelope::success($response, $data, $meta, 200)
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
    }
}
