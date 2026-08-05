<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToProgramme;
use Illuminate\Database\Eloquent\Model;

/**
 * Who covers which site, and when.
 *
 * Scoped by programme, like the registry it points into — an assignment names
 * a site from the shared national list, so it cannot be organisation-scoped
 * without becoming unreadable to the programme that planned it.
 *
 * That does mean one organisation can see that another has been assigned a
 * site. This is intentional and is the smaller half of the point: knowing who
 * else is covering a site is how duplicated effort and uncovered sites are
 * found. What it does NOT expose is anything about the visit — assessments,
 * answers, findings and scores stay organisation-scoped.
 */
final class SiteAssignment extends Model
{
    use BelongsToProgramme;

    protected $table = 'site_assignments';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'testing_site_id' => BinaryUuidCast::class,
        'due_on'          => 'date',
        'is_active'       => 'boolean',
    ];
}
