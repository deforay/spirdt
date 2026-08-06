<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Service\UserAdminService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The people in this organisation.
 *
 * Behind RequireRoleMiddleware('admin', 'superadmin'), and scoped like
 * everything else — the organisation comes from the token, so there is no
 * parameter naming whose users these are.
 *
 * A generated password is returned exactly once, on creation and on reset, and
 * is never retrievable afterwards. It is flagged as needing to be changed, and
 * unlike before there is now a screen to change it on.
 */
final class UsersAction
{
    public function __construct(private readonly UserAdminService $users = new UserAdminService())
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 200, ['users' => $this->users->list()]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, 422, ['error' => ['message' => 'A JSON object is required.']]);
        }

        try {
            $result = $this->users->create($body, (string) $request->getAttribute('role'));
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 201, $result);
    }

    /** @param array<string,string> $args */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, 422, ['error' => ['message' => 'A JSON object is required.']]);
        }

        try {
            $user = $this->users->update(
                (int) ($args['id'] ?? 0),
                $body,
                (int) $request->getAttribute('user_id'),
                (string) $request->getAttribute('role'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 200, ['user' => $user]);
    }

    /** @param array<string,string> $args */
    public function resetPassword(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        try {
            $result = $this->users->resetPassword(
                (int) ($args['id'] ?? 0),
                (string) $request->getAttribute('role'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 200, $result);
    }

    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
