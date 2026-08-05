<?php

declare(strict_types=1);

namespace App\Http\Action\Auth;

use App\Exception\AuthException;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Change your own password.
 *
 * The only authenticated route that answers while `must_change_password` is
 * set — everything else refuses until this has been used. That is the whole
 * point of it: bin/provision-org and bin/recover-access both hand out a
 * password somebody else chose and somebody else has seen, and until it is
 * replaced the account is a shared secret.
 *
 * Returns a fresh token pair, because the change revoked every session
 * including the caller's. Without that the client would be signed out by its
 * own success.
 */
final class ChangePasswordAction
{
    use RespondsWithJson;

    public function __construct(private readonly AuthService $auth = new AuthService())
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, 422, ['error' => ['message' => 'A JSON object is required.']]);
        }

        // Not trimmed. A password may legitimately begin or end with a space,
        // and quietly removing one here would store something the person did
        // not type — and then refuse it at the next sign-in.
        $current = is_string($body['current_password'] ?? null) ? $body['current_password'] : '';
        $next = is_string($body['new_password'] ?? null) ? $body['new_password'] : '';

        if ($current === '' || $next === '') {
            return $this->json($response, 422, [
                'error' => ['message' => 'Enter your current password and a new one.'],
            ]);
        }

        try {
            $result = $this->auth->changePassword(
                (int) $request->getAttribute('user_id'),
                $current,
                $next,
                $this->stringField($body, 'device_id') ?: null,
                $request->getHeaderLine('User-Agent') ?: null,
            );
        } catch (AuthException $e) {
            return $this->authError($response, $e);
        }

        return $this->json($response, 200, $result);
    }
}
