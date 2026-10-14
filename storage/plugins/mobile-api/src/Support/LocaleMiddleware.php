<?php
declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Support\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers each /api/v1 request in the language the app is showing.
 *
 * The app sends `Accept-Language` with the language picked in Profile (or the
 * device language). Without this, status labels, lookups and error messages
 * came back in the installation's language even when the app was in another
 * one. Only installed languages are honoured, in the client's preference
 * order; a header naming none of them leaves the usual locale untouched.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = self::negotiate($request->getHeaderLine('Accept-Language'), array_keys(I18n::getAvailableLocales()));
        if ($locale !== null) {
            I18n::setLocale($locale);
        }
        $response = $handler->handle($request);

        return $locale !== null && !$response->hasHeader('Content-Language')
            ? $response->withHeader('Content-Language', str_replace('_', '-', $locale))
            : $response;
    }

    /**
     * Pick the installed locale that best matches an Accept-Language header:
     * exact region first ("en-US" → en_US), then the first installed locale of
     * the same language ("en" or "en-GB" → en_US). Pure, for unit tests.
     *
     * @param list<string> $available installed locale codes, e.g. ['it_IT', 'en_US']
     */
    public static function negotiate(string $header, array $available): ?string
    {
        if (trim($header) === '' || $available === []) {
            return null;
        }
        $ranked = [];
        foreach (explode(',', $header) as $i => $part) {
            $bits = array_map('trim', explode(';', $part));
            $tag = $bits[0];
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (str_starts_with($param, 'q=')) {
                    $q = (float) substr($param, 2);
                }
            }
            if ($q > 0) {
                // Stable order: higher q first, then the order the client sent.
                $ranked[] = [$q, -$i, $tag];
            }
        }
        rsort($ranked);

        $byLanguage = [];
        foreach ($available as $code) {
            $byLanguage[strtolower(substr($code, 0, 2))] ??= $code;
        }
        foreach ($ranked as [, , $tag]) {
            $normalized = I18n::normalizeLocaleCode($tag);
            if (in_array($normalized, $available, true)) {
                return $normalized;
            }
            $language = strtolower(substr($tag, 0, 2));
            if (isset($byLanguage[$language])) {
                return $byLanguage[$language];
            }
        }

        return null;
    }
}
