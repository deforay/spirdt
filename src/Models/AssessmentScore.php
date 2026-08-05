<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * The snapshot. Computed once, server side, and never recomputed from a live
 * template — a certification level has to mean the same thing in a year, after
 * the organisation has edited its template five times.
 */
final class AssessmentScore extends Model
{
    use BelongsToOrganization;

    protected $table = 'assessment_scores';

    protected $primaryKey = 'assessment_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'assessment_id'  => BinaryUuidCast::class,
        'breakdown'      => 'array',
        'percentage'     => 'float',
        'level'          => 'integer',
        'total_score'    => 'integer',
        'total_possible' => 'integer',
        'pathogen_count' => 'integer',
    ];
}
