<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Models\Facility;
use App\Models\TestingSite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The testing sites this organisation assesses.
 *
 * Downloaded whole rather than searched. A registry of sites for one
 * organisation is small, and the device needs it offline — a search endpoint
 * would work in the office and be useless in the building where it matters.
 *
 * Scoped like everything else: the organisation comes from the token, so there
 * is no organisation parameter to tamper with.
 */
final class SitesAction
{
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

        foreach ($query->get() as $site) {
            $facilityId = (string) $site->facility_id;

            $sites[] = [
                'id'            => (string) $site->id,
                'name'          => (string) $site->name,
                'facility_id'   => $facilityId,
                'facility_name' => $facilities[$facilityId] ?? null,
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
}
