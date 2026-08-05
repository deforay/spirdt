<?php

declare(strict_types=1);

namespace App\Http\Action\Auth;

use App\Exception\AuthException;
use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Exchanges a password for a token pair.
 *
 * Outside AuthMiddleware, necessarily — this is what produces the token that
 * middleware checks. It is therefore the one route that establishes no tenant,
 * which is why AuthService looks users up explicitly across organisations
 * rather than relying on a scope that is not armed here.
 */
final class LoginAction
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

        $email = $this->stringField($body, 'email');
        $password = $this->stringField($body, 'password');

        if ($email === '' || $password === '') {
            return $this->json($response, 422, [
                'error' => ['message' => 'Enter your email address and password.'],
            ]);
        }

        try {
            $result = $this->auth->login(
                $email,
                $password,
                $this->stringField($body, 'organization') ?: null,
                $this->stringField($body, 'device_id') ?: null,
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent') ?: null,
            );
        } catch (AuthException $e) {
            return $this->authError($response, $e);
        }

        return $this->json($response, 200, $result);
    }
}
