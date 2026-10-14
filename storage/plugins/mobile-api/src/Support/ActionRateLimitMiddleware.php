<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * A per-action throttle that answers in the Mobile API envelope
 * (429 `rate_limited`, meta.retry_after, Retry-After header) — the core
 * RateLimitMiddleware emits its own non-envelope body.
 *
 * Keyed on the device token when AppAuthMiddleware already resolved one (so a
 * whole library behind one NAT address is not throttled as a single client),
 * otherwise on the client IP. Shares the file-backed RateLimiter and honours
 * the same E2E bypass flag as TokenQuotaMiddleware.
 */
final class ActionRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int $maxRequests,
        private int $window,
        private string $action
    ) {
        $this->maxRequests = max(1, $maxRequests);
        $this->window = max(1, $window);
    }

    public function process(Request $request, RequestHandler $handler): ResponseInterface
    {
        $bypass = $_ENV['PINAKES_E2E_BYPASS_RATE_LIMIT'] ?? getenv('PINAKES_E2E_BYPASS_RATE_LIMIT') ?: '';
        if ($bypass === '1' || strtolower((string) $bypass) === 'true') {
            return $handler->handle($request);
        }

        $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);
        $subject = is_int($tokenId)
            ? 'token:' . $tokenId
            : 'ip:' . (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        if (RateLimiter::isLimited('mobile_api_action:' . $this->action . ':' . $subject, $this->maxRequests, $this->window)) {
            return ResponseEnvelope::error(
                new SlimResponse(),
                'rate_limited',
                __('Hai superato il limite di richieste. Riprova più tardi.'),
                429,
                ['retry_after' => $this->window]
            )->withHeader('Retry-After', (string) $this->window);
        }

        return $handler->handle($request);
    }
}
