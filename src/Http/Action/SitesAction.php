<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Models\Facility;
use App\Models\TestingSite;
use App\Service\AssignmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The testing sites available to this caller, and which are theirs to visit.
 *
 * Downloaded whole rather than searched. The device needs it offline, and a
 * search endpoint would work in the office and be useless in the building
 * where it matters.
 *
 * EVERY site in the programme is returned, each annotated with how it is
 * assigned, rather than the server returning only the assigned ones. Two
 * reasons, both about the field. An assessor who arrives somewhere unplanned
 * must still be able to work — refusing to show a site because a planner did
 * not list it turns an administrative gap into a wasted visit. And the
 * filtering has to happen with no signal, which means the device needs the
 * facts rather than a server-side mode it cannot re-ask for.
 *
 * Scoped like everything else: the programme and organisation come from the
 * token, so there is no parameter to tamper with.
 */
final class SitesAction
{
    public function __construct(private readonly AssignmentService $assignments = new AssignmentService())
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $facilities = [];

        foreach (Facility::query()->where('is_active', 1)->get() as $facility) {
            $facilities[(string) $facility->id] = (string) $facility->name;
        }

        $sites = [];

        // Ordering is applied to the underlying query: orderBy reaches Eloquent
        // through __call and degrades the builder's type.
        $query = TestingSite::query()->where('is_active', 1);
        $query->getQuery()->orderBy('name');

        $campaignId = $this->campaignId($request);

        $assigned = $this->assignments->forUser(
            (int) $request->getAttribute('organization_id'),
            (int) $request->getAttribute('user_id'),
            $campaignId,
        );

        foreach ($query->get() as $site) {
            $facilityId = (string) $site->facility_id;
            $id = (string) $site->id;

            $sites[] = [
                'id'            => $id,
                'name'          => (string) $site->name,
                'facility_id'   => $facilityId,
                'facility_name' => $facilities[$facilityId] ?? null,
                // Assigned to this organisation at all, and assigned to this
                // person in particular. A site assigned to a named colleague
                // is neither.
                'assigned'      => isset($assigned[$id]),
                'assigned_to_me' => $assigned[$id]['mine'] ?? false,
            ];
        }

        $response->getBody()->write((string) json_encode([
            'sites'      => $sites,
            // The device caches this list; the count is what it checks a
            // refresh against without re-reading every row.
            'count'      => count($sites),
            'fetched_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * The round to resolve assignments against.
     *
     * A query parameter rather than a stored "current campaign", because two
     * rounds can legitimately overlap while one is being closed and the next
     * planned, and a single global notion of "current" would silently pick one.
     */
    private function campaignId(ServerRequestInterface $request): ?int
    {
        $value = $request->getQueryParams()['campaign'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
