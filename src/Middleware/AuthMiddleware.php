<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\TokenService;
use App\Tenancy\TenantContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Establishes who is calling, and therefore which organisation's data they see.
 *
 * This is the only place a tenant is set for a request. Everything downstream
 * reads it through the model scope, so a route that skips this middleware
 * cannot quietly read across organisations — the scope throws instead. That is
 * why TenantContext fails closed rather than defaulting to unfiltered.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TokenService $tokens = new TokenService())
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return $this->unauthorized('A bearer token is required.');
        }

        $claims = $this->tokens->verify($matches[1]);

        if ($claims === null) {
            return $this->unauthorized('The token is not valid. Sign in again.');
        }

        TenantContext::set($claims['org'], $claims['sub'], $claims['admin']);

        return $handler->handle(
            $request
                ->withAttribute('user_id', $claims['sub'])
                ->withAttribute('organization_id', $claims['org'])
                ->withAttribute('role', $claims['role']),
        );
    }

    private function unauthorized(string $message): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(
            (string) json_encode(['error' => ['status' => 401, 'message' => $message]]),
        );

        return $response->withHeader('Content-Type', 'application/json');
    }
}
