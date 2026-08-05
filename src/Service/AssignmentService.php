<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\SiteAssignment;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;

/**
 * Working out which sites somebody is supposed to visit.
 *
 * The table stores three independent axes — organisation, optional assessor,
 * optional round — and the rule that turns them into an answer is the only
 * part a constraint could not express, so it lives here:
 *
 *   A ROUND BEATS THE STANDING PLAN, PER SITE. If a site has any assignment
 *   for the round being asked about, the standing assignments for that site
 *   are ignored entirely. Otherwise the standing ones apply.
 *
 * Per site, and not globally, is the whole point. Assign the country once,
 * then move the handful that change each round — a rule that switched wholesale
 * would mean one override silently unassigns everything else.
 *
 *   AN ASSIGNMENT WITH NO ASSESSOR NAMED BELONGS TO THE WHOLE ORGANISATION.
 *   One that names somebody belongs to them. So a site assigned specifically
 *   to Joseph does not appear in Mary's list, and one assigned to the
 *   organisation appears in both.
 */
final class AssignmentService
{
    /**
     * Site ids the caller should see, and which of them are theirs personally.
     *
     * Returned as a map rather than two lists because the sites endpoint has
     * to annotate every site it returns, and looking each one up would be a
     * query per row.
     *
     * @param  int|null                                     $campaignId the round being planned or worked
     * @return array<string,array{organisation:bool,mine:bool}> site UUID => how it is assigned
     */
    public function forUser(int $organizationId, ?int $userId, ?int $campaignId = null): array
    {
        $rows = SiteAssignment::query()
            ->where('is_active', 1)
            ->where('organization_id', $organizationId)
            ->get();

        /** @var array<string,list<object>> $bySite */
        $bySite = [];

        foreach ($rows as $row) {
            $bySite[(string) $row->testing_site_id][] = $row;
        }

        $result = [];

        foreach ($bySite as $siteId => $assignments) {
            $governing = $this->governing($assignments, $campaignId);

            if ($governing === []) {
                continue;
            }

            $mine = false;

            foreach ($governing as $assignment) {
                $assignedTo = $assignment->user_id === null ? null : (int) $assignment->user_id;

                // Nobody named: the whole organisation. Named: only them.
                if ($assignedTo === null || ($userId !== null && $assignedTo === $userId)) {
                    $mine = true;

                    break;
                }
            }

            $result[$siteId] = ['organisation' => true, 'mine' => $mine];
        }

        return $result;
    }

    /**
     * The rows that decide this site, for this round.
     *
     * @param  list<object> $assignments every assignment for one site
     * @return list<object>
     */
    private function governing(array $assignments, ?int $campaignId): array
    {
        if ($campaignId !== null) {
            $forRound = array_values(array_filter(
                $assignments,
                static fn (object $row): bool => $row->campaign_id !== null
                    && (int) $row->campaign_id === $campaignId,
            ));

            if ($forRound !== []) {
                return $forRound;
            }
        }

        // No round asked for, or none set for this site: the standing plan.
        // Assignments belonging to OTHER rounds are deliberately excluded —
        // last year's plan is not this year's default.
        return array_values(array_filter(
            $assignments,
            static fn (object $row): bool => $row->campaign_id === null,
        ));
    }

    /**
     * Assign a site, or update the assignment that already exists.
     *
     * Idempotent on (site, organisation, assessor, round), which the unique
     * key enforces underneath: an administrator clicking Assign twice must be
     * a no-op rather than a duplicate.
     */
    public function assign(
        string $siteId,
        int $organizationId,
        ?int $userId = null,
        ?int $campaignId = null,
        ?string $dueOn = null,
    ): SiteAssignment {
        $existing = SiteAssignment::query()
            ->where('testing_site_id', BinaryUuid::toBytes($siteId))
            ->where('organization_id', $organizationId)
            ->where('user_key', $userId ?? 0)
            ->where('campaign_key', $campaignId ?? 0)
            ->first();

        $assignment = $existing instanceof SiteAssignment ? $existing : new SiteAssignment();

        $assignment->fill([
            'programme_id'    => TenantContext::requireProgrammeId(),
            'testing_site_id' => $siteId,
            'organization_id' => $organizationId,
            'user_id'         => $userId,
            'campaign_id'     => $campaignId,
            'due_on'          => $dueOn,
            'is_active'       => 1,
        ]);

        $assignment->save();

        return $assignment;
    }
}
