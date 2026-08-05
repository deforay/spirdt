<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBinaryUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A pathogen assessed on one visit. Section 4 repeats once per row here, which
 * is what makes the possible total scale with how many were assessed.
 */
final class AssessmentPathogen extends Model
{
    use BelongsToOrganization;
    use HasBinaryUuidKey;

    protected $table = 'assessment_pathogens';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'id'            => BinaryUuidCast::class,
        'assessment_id' => BinaryUuidCast::class,
        'sequence'      => 'integer',
    ];
}
