<?php
declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Public routes have no trailing slash, so `/catalogo/` matched nothing and
 * answered 404: a link or a crawler adding the slash hit a dead page instead
 * of the real one. The not-found handlers ask here first and answer a 301 to
 * the same address without the slash; if that one does not exist either, the
 * next request gets the 404 as before. Only routing misses get here, so a
 * route that really ends with "/" is never touched.
 */
final class TrailingSlash
{
    /** Location for the 301, or null when the request is not a slash variant. */
    public static function redirectTarget(ServerRequestInterface $request): ?string
    {
        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return null;
        }

        $path = $request->getUri()->getPath();
        if (!str_ends_with($path, '/') || str_contains($path, '\\')) {
            return null;
        }

        // One leading slash only: "//evil.example/" trimmed to
        // "//evil.example" would be a protocol-relative link to another host.
        $target = '/' . ltrim(rtrim($path, '/'), '/');
        if ($target === '/') {
            return null;
        }

        $query = $request->getUri()->getQuery();
        return $query !== '' ? $target . '?' . $query : $target;
    }
}
