<?php

declare(strict_types=1);

namespace App\Http\Action\Auth;

use App\Exception\AuthException;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Trades a refresh token for a new pair.
 *
 * This is the route that makes a long offline stretch survivable. A device that
 * comes back after a fortnight has an access token that expired days ago and a
 * refresh token that has not, and this is how it gets moving again without
 * asking someone to remember a password in a car park.
 */
final class RefreshAction
{
    use RespondsWithJson;

    public function __construct(private readonly AuthService $auth = new AuthService())
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $token = is_array($body) ? $this->stringField($body, 'refresh_token') : '';

        if ($token === '') {
            return $this->json($response, 422, ['error' => ['message' => 'A refresh token is required.']]);
        }

        try {
            $result = $this->auth->refresh(
                $token,
                is_array($body) ? ($this->stringField($body, 'device_id') ?: null) : null,
                $request->getHeaderLine('User-Agent') ?: null,
            );
        } catch (AuthException $e) {
            return $this->authError($response, $e);
        }

        return $this->json($response, 200, $result);
    }
}
