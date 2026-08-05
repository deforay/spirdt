<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBinaryUuidKey;
use Illuminate\Database\Eloquent\Model;

/** A hospital, clinic or health centre. Contains one or more testing sites. */
final class Facility extends Model
{
    use BelongsToOrganization;
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
