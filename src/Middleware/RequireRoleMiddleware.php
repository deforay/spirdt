<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Which roles may reach a group of routes.
 *
 * Always placed INSIDE AuthMiddleware, which is what puts the role on the
 * request. On its own this middleware refuses everything, because a request
 * with no established role is one that did not pass authentication — failing
 * closed here means a route group accidentally wired without AuthMiddleware
 * returns 403 rather than opening to the world.
 *
 * The list is what may pass, never what may not. A role added to the system
 * later gets in nowhere until somebody names it, which is the direction the
 * mistake should point.
 */
final class RequireRoleMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private readonly array $allowed;

    public function __construct(string ...$allowed)
    {
        $this->allowed = array_values($allowed);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $role = $request->getAttribute('role');

        if (!is_string($role) || !in_array($role, $this->allowed, true)) {
            return $this->forbidden();
        }

        return $handler->handle($request);
    }

    /**
     * Says what is required rather than what the caller is.
     *
     * The caller already knows their own role; what they cannot know is which
     * roles this route wanted, and a message that says only "forbidden" turns
     * a configuration question into a support ticket.
     */
    private function forbidden(): ResponseInterface
    {
        $response = new Response(403);
        $response->getBody()->write((string) json_encode([
            'error' => [
                'status'  => 403,
                'message' => 'Your account does not have access to this. Ask your administrator.',
                'code'    => 'role_not_permitted',
            ],
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
