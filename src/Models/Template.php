<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;

/**
 * A versioned instrument, stored as the JSON document the scoring engine reads.
 *
 * NOT scoped by BelongsToOrganization. organization_id is nullable here on
 * purpose: a null means the template ships with the platform and every
 * organisation can use it. The scope would filter those out for everyone,
 * so template lookup states its own rule — this organisation's, or the
 * platform's.
 */
final class Template extends Model
{
    protected $table = 'templates';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'definition'   => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * The instrument this organisation is working to, whichever version.
     *
     * For screens that need the instrument's own vocabulary rather than a
     * specific version — the facility type and affiliation lists, which a
     * country may customise. Prefers the organisation's own copy over the
     * platform default, then the newest published version.
     */
    public static function published(int $organizationId): ?self
    {
        $rows = Capsule::table('templates')
            ->where('status', 'published')
            ->whereIn('org_key', [$organizationId, 0])
            ->orderByDesc('org_key')
            ->orderByDesc('id')
            ->limit(1)
            ->get()
            ->all();

        return self::query()
            ->hydrate(array_map(static fn (object $row): array => (array) $row, $rows))
            ->first();
    }

    /**
     * The published template for a code and version, preferring the
     * organisation's own copy over the platform default.
     */
    public static function resolve(int $organizationId, string $code, string $version): ?self
    {
        // org_key is generated as IFNULL(organization_id, 0), so ordering by
        // it descending puts the organisation's own copy ahead of the platform
        // default without needing a second query.
        //
        // Built on the query builder and hydrated, rather than chained off the
        // model: whereIn and orderByDesc reach Eloquent through __call, which
        // degrades the builder's type and leaves the return unverifiable.
        // Selecting first and hydrating keeps every filter in SQL — the
        // alternative, fetching and filtering in PHP, would pull every
        // organisation's copy of the instrument across the wire, and each one
        // carries a 96 KB definition.
        $rows = Capsule::table('templates')
            ->where('code', $code)
            ->where('version', $version)
            ->where('status', 'published')
            ->whereIn('org_key', [$organizationId, 0])
            ->orderByDesc('org_key')
            ->limit(1)
            ->get()
            ->all();

        return self::query()
            ->hydrate(array_map(static fn (object $row): array => (array) $row, $rows))
            ->first();
    }
}
