<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Service\RoleAdminService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What each role in this organisation may do.
 *
 * Behind RequirePermissionMiddleware(Permission::ROLES_MANAGE), and scoped like
 * everything else — the organisation comes from the token, so there is no
 * parameter naming whose roles these are.
 *
 * The actor's own role travels from the request attribute rather than the body.
 * Every guard in RoleAdminService is written against it, and one taken from a
 * payload would be a guard the caller sets.
 */
final class RolesAction
{
    public function __construct(private readonly RoleAdminService $roles = new RoleAdminService())
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 200, $this->roles->list((string) $request->getAttribute('role')));
    }

    /** @param array<string,string> $args */
    public function updatePermissions(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $body = $request->getParsedBody();

        if (!is_array($body) || !isset($body['permissions']) || !is_array($body['permissions'])) {
            return $this->json($response, 422, [
                'error' => ['message' => 'A permissions array is required.'],
            ]);
        }

        try {
            $role = $this->roles->updatePermissions(
                (int) ($args['id'] ?? 0),
                array_values($body['permissions']),
                (int) $request->getAttribute('user_id'),
                (string) $request->getAttribute('role'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 200, ['role' => $role]);
    }

    /** @param array<string,mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
