<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Exception\HttpBadRequestException;

/**
 * Decode application/json request bodies into the parsed body.
 *
 * Malformed JSON fails here with a 400 rather than surfacing later as a
 * confusing null-property error deep in a controller. Sync payloads are the
 * largest bodies this API sees, and a truncated upload from a flaky
 * connection is exactly the case that must fail loudly and early.
 */
final class JsonBodyParserMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains(strtolower($contentType), 'application/json')) {
            $raw = (string) $request->getBody();

            if ($raw !== '') {
                try {
                    /** @var array<string,mixed> $decoded */
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new HttpBadRequestException($request, 'Malformed JSON body: ' . $e->getMessage());
                }

                $request = $request->withParsedBody($decoded);
            }
        }

        return $handler->handle($request);
    }
}
