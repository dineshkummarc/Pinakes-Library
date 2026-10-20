<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\HtmlHelper;
use App\Support\Permissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/** Covers backend routes even when a plugin omitted its route-level guard. */
final class BackendPermissionsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $base = HtmlHelper::getBasePath();
        $local = $base !== '' && str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : $path;
        if ($local === '/admin' || (!str_starts_with($local, '/admin/') && !str_starts_with($local, '/api/admin/'))
            || empty($_SESSION['user'])) {
            return $handler->handle($request);
        }
        if (!Permissions::authorizePath($path, null, $request->getMethod())) {
            $response = new Response(403);
            $response->getBody()->write(__('Non hai il permesso di accedere a questa sezione.'));
            return $response->withHeader('Cache-Control', 'no-store');
        }
        return $handler->handle($request);
    }
}
