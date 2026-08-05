<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProgramme;
use Illuminate\Database\Eloquent\Model;

/**
 * A place in the country's administrative hierarchy.
 *
 * Self-referencing and arbitrary-depth on purpose. `level` is free text, not
 * an enum, because the tiering is a per-country fact: Zambia is Province →
 * District, Ethiopia is Region → Zone → Woreda, and some programmes run four
 * levels. Anything that hard-codes "Province" and "District" has to be
 * rewritten for the second country.
 *
 * Registry, so it belongs to the programme rather than an organisation — two
 * organisations comparing results by district have to be using the same
 * districts.
 */
final class GeoUnit extends Model
{
    use BelongsToProgramme;

    protected $table = 'geo_units';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
