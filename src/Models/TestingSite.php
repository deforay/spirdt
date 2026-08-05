<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBinaryUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where testing actually happens — a bench, a room, a counter.
 *
 * Distinct from the facility that contains it, because one hospital can run
 * several testing sites and each is assessed separately.
 */
final class TestingSite extends Model
{
    use BelongsToOrganization;
    use HasBinaryUuidKey;

    protected $table = 'testing_sites';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'id'             => BinaryUuidCast::class,
        'facility_id'    => BinaryUuidCast::class,
        'merged_into_id' => BinaryUuidCast::class,
        'is_active'      => 'boolean',
    ];

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
