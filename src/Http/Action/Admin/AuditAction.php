<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Service\AuditReadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What has been done in this organisation.
 *
 * Behind RequirePermissionMiddleware(Permission::AUDIT_READ). Read-only, and
 * there is no write endpoint on purpose: rows are written by the services that
 * perform the actions, from an actor taken off a verified token. An endpoint
 * that accepted an audit entry would accept one that never happened.
 *
 * Named AuditAction for the same reason its siblings are, which unfortunately
 * collides with App\Audit\AuditAction — the vocabulary of what gets recorded.
 * They are aliased where both are needed. The alternative was calling one of
 * them something it is not.
 */
final class AuditAction
{
    public function __construct(private readonly AuditReadService $audit = new AuditReadService())
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        $payload = $this->audit->list(
            [
                'action'       => $query['action'] ?? null,
                'actor_id'     => $query['actor_id'] ?? null,
                'from'         => $query['from'] ?? null,
                'to'           => $query['to'] ?? null,
                'session_hash' => $query['session_hash'] ?? null,
            ],
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 50),
        );

        $response->getBody()->write((string) json_encode($payload));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
