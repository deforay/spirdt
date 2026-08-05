<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasBinaryUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A signature or photograph captured during a visit.
 *
 * Deliberately not part of the assessment payload. Media dominates upload size,
 * and a failed transfer on a weak connection must not take a completed
 * assessment down with it — so an assessment is valid without any of these, and
 * they reconcile afterwards.
 *
 * `checksum` is what makes a retried upload idempotent, and it is computed from
 * the bytes the server received rather than taken from the device. A checksum a
 * caller can choose is not a checksum.
 */
final class Attachment extends Model
{
    use BelongsToOrganization;
    use HasBinaryUuidKey;

    protected $table = 'attachments';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'id'            => BinaryUuidCast::class,
        'assessment_id' => BinaryUuidCast::class,
        'byte_size'     => 'int',
    ];
}
