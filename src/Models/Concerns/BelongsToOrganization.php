<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Confines a model to the current organisation.
 *
 * Two halves, and both are needed. The global scope filters every read, so a
 * query written without a where clause still cannot see another tenant. The
 * creating hook stamps organization_id on every write, so a row cannot be
 * created without one — a nullable column would eventually hold a null, and a
 * null organisation belongs to everybody.
 *
 * The scope is qualified with the table name. Unqualified, `organization_id`
 * is ambiguous the moment a query joins two scoped tables, and MySQL rejects
 * it — which at least fails loudly, unlike the version of this bug where the
 * wrong table gets filtered.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', static function (Builder $builder): void {
            if (!TenantContext::isScoped()) {
                return;
            }

            $table = $builder->getModel()->getTable();

            $builder->where($table . '.organization_id', TenantContext::requireOrganizationId());
        });

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('organization_id') === null && TenantContext::isScoped()) {
                $model->setAttribute('organization_id', TenantContext::requireOrganizationId());
            }
        });
    }

    /**
     * Read across organisations.
     *
     * Named for what it does rather than the neutral withoutGlobalScope(), so
     * it is greppable — every call site is somewhere a reviewer should look.
     *
     * @return Builder<static>
     */
    public static function acrossOrganizations(): Builder
    {
        return static::query()->withoutGlobalScope('organization');
    }
}
