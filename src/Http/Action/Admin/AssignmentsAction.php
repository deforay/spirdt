<?php

declare(strict_types=1);

namespace App\Http\Action\Admin;

use App\Models\SiteAssignment;
use App\Service\AssignmentService;
use App\Support\BinaryUuid;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who covers which site.
 *
 * The organisation is taken from the token, never from the body: an
 * administrator plans their own organisation's work, and accepting an
 * organisation id here would let them assign somebody else's people. That the
 * registry is shared across the programme makes this more important rather
 * than less — the sites are visible to everyone in the programme, and only the
 * assignments must not be.
 *
 * Listing returns the rows as stored rather than the resolved answer, because
 * a planner needs to see that a standing assignment exists AND that this round
 * overrides it. AssignmentService resolves; this reports.
 */
final class AssignmentsAction
{
    public function __construct(private readonly AssignmentService $assignments = new AssignmentService())
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = [];

        $query = SiteAssignment::query()
            ->where('organization_id', (int) $request->getAttribute('organization_id'));
        $query->getQuery()->orderBy('created_at');

        foreach ($query->get() as $row) {
            $rows[] = [
                'id'              => (int) $row->id,
                'testing_site_id' => (string) $row->testing_site_id,
                'user_id'         => $row->user_id === null ? null : (int) $row->user_id,
                'campaign_id'     => $row->campaign_id === null ? null : (int) $row->campaign_id,
                'due_on'          => $row->due_on?->format('Y-m-d'),
                'is_active'       => (bool) $row->is_active,
            ];
        }

        return $this->json($response, 200, ['assignments' => $rows]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, 422, ['error' => ['message' => 'A JSON object is required.']]);
        }

        $siteId = trim((string) ($body['testing_site_id'] ?? ''));

        if (!BinaryUuid::isValid($siteId)) {
            return $this->json($response, 422, ['error' => ['message' => 'A testing site is required.']]);
        }

        try {
            $assignment = $this->assignments->assign(
                $siteId,
                (int) $request->getAttribute('organization_id'),
                $this->optionalInt($body, 'user_id'),
                $this->optionalInt($body, 'campaign_id'),
                $this->optionalText($body, 'due_on'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->json($response, 422, ['error' => ['message' => $e->getMessage()]]);
        }

        return $this->json($response, 201, ['assignment' => ['id' => (int) $assignment->id]]);
    }

    /**
     * Withdraw an assignment.
     *
     * Deactivated rather than deleted, so "this site was covered by Joseph
     * until March" survives — a plan that silently loses its own history
     * cannot be audited, and this is an audit product.
     *
     * @param array<string,string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $assignment = SiteAssignment::query()
            ->where('site_assignments.id', (int) ($args['id'] ?? 0))
            // Programme-scoped by the model, so the organisation has to be
            // checked here: another organisation's plan is visible and must
            // not be editable.
            ->where('organization_id', (int) $request->getAttribute('organization_id'))
            ->first();

        if ($assignment === null) {
            return $this->json($response, 404, ['error' => ['message' => 'No such assignment.']]);
        }

        $assignment->is_active = false;
        $assignment->save();

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string,mixed> $body */
    private function optionalInt(array $body, string $key): ?int
    {
        $value = $body[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string,mixed> $body */
    private function optionalText(array $body, string $key): ?string
    {
        $value = trim((string) ($body[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
