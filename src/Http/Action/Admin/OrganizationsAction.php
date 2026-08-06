<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Service\OrganizationAdminService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The organisations auditing under one programme.
 *
 * Behind RequireRoleMiddleware('superadmin'): this is the only surface where
 * one tenant's administrator legitimately reaches another tenant's row, and it
 * is bounded to their own programme by the token rather than by a parameter.
 *
 * Creating one returns its first administrator's password exactly once, for
 * the same reason adding a person does.
 */
final class OrganizationsAction
{
    public function __construct(
        private readonly OrganizationAdminService $organizations = new OrganizationAdminService(),
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 200, ['organizations' => $this->organizations->list()]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, 422, ['error' => ['message' => 'A JSON object is required.']]);
        }

        try {
            $result = $this->organizations->create($body);
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
            $organization = $this->organizations->update((int) ($args['id'] ?? 0), $body);
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 200, ['organization' => $organization]);
    }

    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
