<?php

declare(strict_types=1);

namespace App\Http\Action\Auth;

use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Revokes one refresh token.
 *
 * Always answers 200, including for a token that was never valid. There is
 * nothing to learn from the difference, and a sign-out that reports failure
 * invites someone to try again rather than to walk away.
 */
final class LogoutAction
{
    use RespondsWithJson;

    public function __construct(private readonly AuthService $auth = new AuthService())
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $token = is_array($body) ? $this->stringField($body, 'refresh_token') : '';

        if ($token !== '') {
            $this->auth->logout($token);
        }

        return $this->json($response, 200, ['signed_out' => true]);
    }
}
