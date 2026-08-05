<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBinaryUuidKey;
use App\Support\BinaryUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One site visit.
 *
 * The id is minted on the device before the visit starts, so it exists before
 * the server has ever heard of the assessment. That is what makes a retried
 * sync safe: the same payload writes the same row rather than a second copy.
 */
final class Assessment extends Model
{
    use BelongsToOrganization;
    use HasBinaryUuidKey;

    protected $table = 'assessments';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'id'                     => BinaryUuidCast::class,
        'testing_site_id'        => BinaryUuidCast::class,
        'facility_id'            => BinaryUuidCast::class,
        'previous_assessment_id' => BinaryUuidCast::class,
        'context'                => 'array',
        'refers_specimens'       => 'boolean',
        'assessed_on'            => 'date',
        'started_at'             => 'datetime',
        'ended_at'               => 'datetime',
        'submitted_at'           => 'datetime',
    ];

    /** Scoped, so an id belonging to another organisation resolves to null. */
    public static function findByUuid(string $uuid): ?self
    {
        return self::query()->where('assessments.id', BinaryUuid::toBytes($uuid))->first();
    }

    /** @return HasMany<Answer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'assessment_id');
    }

    /**
     * Ordering is left to the caller: chaining it here degrades the relation
     * to a query builder, and every caller needs sequence order anyway.
     *
     * @return HasMany<AssessmentPathogen, $this>
     */
    public function pathogens(): HasMany
    {
        return $this->hasMany(AssessmentPathogen::class, 'assessment_id');
    }
}
