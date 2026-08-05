<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToProgramme;
use App\Models\Concerns\HasBinaryUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A hospital, clinic or health centre. Contains one or more testing sites.
 *
 * Registry rather than audit data, so it is scoped by PROGRAMME. See
 * BelongsToProgramme before assuming organization_id still means what it did.
 */
final class Facility extends Model
{
    use BelongsToProgramme;
    use HasBinaryUuidKey;

    protected $table = 'facilities';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'id'             => BinaryUuidCast::class,
        'merged_into_id' => BinaryUuidCast::class,
        'is_active'      => 'boolean',
    ];
}
