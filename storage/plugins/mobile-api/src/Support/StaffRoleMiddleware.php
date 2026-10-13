<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Role guard for the /api/v1/staff/* surface (content management).
 *
 * Runs AFTER AppAuthMiddleware, which re-reads `utenti.tipo_utente` from the
 * database on every request and attaches it as the `mobile_user` attribute, so
 * a demoted operator loses access on the very next call without any extra
 * revalidation here. The admitted roles mirror the web back office
 * (AdminAuthMiddleware: admin + staff); the list is duplicated because the
 * core constant is private.
 *
 * A missing attribute (middleware wired in the wrong order) fails closed.
 */
final class StaffRoleMiddleware implements MiddlewareInterface
{
    /** Roles admitted to the staff surface (same set as the web back office). */
    public const STAFF_ROLES = ['admin', 'staff'];

    public function process(Request $request, RequestHandler $handler): ResponseInterface
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);
        if (!self::isStaff($user)) {
            return ResponseEnvelope::error(
                new SlimResponse(),
                'forbidden',
                __('Operazione riservata allo staff.'),
                403
            );
        }

        return $handler->handle($request);
    }

    /**
     * True when the token-resolved identity of this request is an admin. The
     * web back office reproduces a few admin-only checks inline (import
     * history of other operators, periodical deletes, …); the staff API does
     * the same with this helper.
     */
    public static function isAdmin(Request $request): bool
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);

        return is_array($user) && ($user['tipo_utente'] ?? null) === 'admin';
    }

    /**
     * True when the resolved identity carries an operator role.
     */
    public static function isStaff(mixed $user): bool
    {
        if (!is_array($user)) {
            return false;
        }
        $role = $user['tipo_utente'] ?? null;

        return is_string($role) && in_array($role, self::STAFF_ROLES, true);
    }
}
