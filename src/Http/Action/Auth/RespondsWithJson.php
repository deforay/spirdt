<?php

declare(strict_types=1);

namespace App\Http\Action\Auth;

use App\Exception\AuthException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Shared plumbing for the three auth routes. */
trait RespondsWithJson
{
    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function authError(ResponseInterface $response, AuthException $e): ResponseInterface
    {
        $response = $this->json($response, $e->status, [
            'error' => ['status' => $e->status, 'message' => $e->getMessage()],
        ]);

        if ($e->retryAfter !== null) {
            $response = $response->withHeader('Retry-After', (string) $e->retryAfter);
        }

        return $response;
    }

    /** @param array<array-key,mixed> $body */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The address to throttle against.
     *
     * REMOTE_ADDR only. Forwarded headers are attacker-controlled unless a
     * proxy is known to be rewriting them, and trusting one here would let a
     * caller pick a new identity per request and walk straight past the limit.
     * A deployment behind a load balancer configures the balancer to set
     * REMOTE_ADDR rather than teaching this to read a header.
     */
    private function clientIp(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($address) && $address !== '' ? $address : '0.0.0.0';
    }
}
