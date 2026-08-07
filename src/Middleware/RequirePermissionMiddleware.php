<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * What a request must be allowed to do to reach a group of routes.
 *
 * This replaced a gate that listed roles. The two look interchangeable and are
 * not: naming roles means adding a capability is adding a role, and every route
 * that should have included it has to be found and edited. Naming the
 * capability means an organisation grants it to whoever it already trusts, and
 * the routes never change.
 *
 * Always placed INSIDE AuthMiddleware, which is what resolves the permissions
 * and puts them on the request. On its own this middleware refuses everything,
 * because a request with no resolved permissions is one that did not pass
 * authentication — failing closed here means a route group accidentally wired
 * without AuthMiddleware returns 403 rather than opening to the world.
 *
 * The list is what is REQUIRED, and every entry must be held. Passing two is
 * "and", never "or". A route needing either of two capabilities is a route
 * whose capabilities are drawn wrongly.
 */
final class RequirePermissionMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private readonly array $required;

    public function __construct(string ...$required)
    {
        $this->required = array_values($required);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $held = $request->getAttribute('permissions');

        if (!is_array($held)) {
            return $this->forbidden();
        }

        foreach ($this->required as $permission) {
            if (!in_array($permission, $held, true)) {
                return $this->forbidden();
            }
        }

        return $handler->handle($request);
    }

    /**
     * Says what was needed rather than what the caller holds.
     *
     * The caller already knows what their own account can do. What they cannot
     * know is which capability this route wanted, and a message that says only
     * "forbidden" turns a configuration question into a support ticket. The
     * required keys go in a field of their own so the management app can show
     * them to an administrator without parsing a sentence.
     */
    private function forbidden(): ResponseInterface
    {
        $response = new Response(403);
        $response->getBody()->write((string) json_encode([
            'error' => [
                'status'   => 403,
                'message'  => 'Your account does not have access to this. Ask your administrator.',
                'code'     => 'permission_required',
                'requires' => $this->required,
            ],
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
