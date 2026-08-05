<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Confines a model to the current programme rather than the current
 * organisation.
 *
 * Used by the registry — geographic units, facilities, testing sites — and by
 * nothing else. Those are the national list that two organisations auditing in
 * the same country have to agree on, or a cross-organisation comparison is
 * matching facility names and hoping.
 *
 * READ THIS BEFORE ADDING THE TRAIT TO ANYTHING
 *
 * A model using this is visible to EVERY organisation in the programme. That
 * is correct for a site registry and catastrophic for anything derived from a
 * visit. Answers, findings, scores and attachments stay on
 * BelongsToOrganization: the registry is shared, the audit data is not, and
 * that boundary is the entire security property of the programme layer.
 *
 * `organization_id` survives on these tables and no longer means what it did.
 * It records which organisation ORIGINATED the row — set when an assessor
 * created a facility in the field before it existed centrally, null when an
 * administrator entered it. It is provenance for whoever reconciles
 * duplicates, and it is never the scope. Nothing here reads it.
 */
trait BelongsToProgramme
{
    public static function bootBelongsToProgramme(): void
    {
        static::addGlobalScope('programme', static function (Builder $builder): void {
            if (!TenantContext::isScoped()) {
                return;
            }

            $table = $builder->getModel()->getTable();

            $builder->where($table . '.programme_id', TenantContext::requireProgrammeId());
        });

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('programme_id') === null && TenantContext::isScoped()) {
                $model->setAttribute('programme_id', TenantContext::requireProgrammeId());
            }
        });
    }

    /**
     * Read across programmes.
     *
     * Named for what it does rather than the neutral withoutGlobalScope(), so
     * every call site is greppable and a reviewer can find them all.
     *
     * @return Builder<static>
     */
    public static function acrossProgrammes(): Builder
    {
        return static::query()->withoutGlobalScope('programme');
    }
}
