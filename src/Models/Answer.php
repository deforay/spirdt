<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\BinaryUuidCast;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * One response.
 *
 * The primary key is a plain auto-increment because an answer is never
 * referenced by anything else — it is identified by its natural key
 * (assessment, question code, pathogen), which is what the sync upserts on and
 * what the unique constraint enforces.
 */
final class Answer extends Model
{
    use BelongsToOrganization;

    protected $table = 'answers';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'assessment_id' => BinaryUuidCast::class,
        'pathogen_id'   => BinaryUuidCast::class,
        'answered_at'   => 'datetime',
    ];
}
