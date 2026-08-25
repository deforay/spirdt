<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Service\RequestLogReadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What the server has been asked.
 *
 * Behind RequirePermissionMiddleware(Permission::AUDIT_READ), sharing the
 * permission with the audit trail rather than inventing one. The two screens
 * answer the same kind of question for the same reader — the compliance reader
 * that permission was written for, "who changes nothing and sees everything" —
 * and an installation that has decided somebody may read the history of what
 * was done has already decided the harder half.
 *
 * Read-only, and there is no write endpoint for the same reason the audit
 * trail has none: the rows are written by the middleware from what actually
 * arrived, and an endpoint that accepted one would accept a request that was
 * never made.
 */
final class RequestLogAction
{
    public function __construct(
        private readonly RequestLogReadService $requests = new RequestLogReadService(),
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        $payload = $this->requests->list(
            [
                'method'       => $query['method'] ?? null,
                'status'       => $query['status'] ?? null,
                'path'         => $query['path'] ?? null,
                'session_hash' => $query['session_hash'] ?? null,
                'from'         => $query['from'] ?? null,
                'to'           => $query['to'] ?? null,
                // The id the client already has the far side of, so extending
                // the list cannot be shifted by requests arriving mid-read —
                // including the ones this screen is making.
                'before_id'    => $query['before_id'] ?? null,
            ],
            (int) ($query['per_page'] ?? 50),
        );

        $response->getBody()->write((string) json_encode($payload));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
