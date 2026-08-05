<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToProgramme;
use App\Models\Concerns\HasBinaryUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where testing actually happens — a bench, a room, a counter.
 *
 * Distinct from the facility that contains it, because one hospital can run
 * several testing sites and each is assessed separately.
 *
 * Registry rather than audit data, so it is scoped by PROGRAMME: two
 * organisations auditing in the same country have to be talking about the same
 * bench, not two similarly-named rows. See BelongsToProgramme — including for
 * what organization_id means on this table now, which is not what it used to.
 */
final class TestingSite extends Model
{
    use BelongsToProgramme;
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
