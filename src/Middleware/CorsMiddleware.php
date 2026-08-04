<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * CORS for the PWA and admin builds, which are served from their own origins.
 *
 * Allowed origins come from CORS_ALLOWED_ORIGINS and are matched exactly —
 * no wildcard, no prefix matching. Credentials are allowed, and a wildcard
 * origin with credentials is both forbidden by the spec and a genuine hole.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');

        $response = $handler->handle($request);

        if ($origin === '' || !in_array($origin, self::allowedOrigins(), true)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With, X-Device-Id, X-App-Version')
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader('Vary', 'Origin');
    }

    /** @return list<string> */
    private static function allowedOrigins(): array
    {
        $raw = (string) env('CORS_ALLOWED_ORIGINS', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
