<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What several organisations auditing in one country share.
 *
 * In practice a national programme, usually the ministry. It owns the registry
 * — geographic units, facilities, testing sites — so that two organisations
 * assessing the same lab are provably assessing the same row rather than two
 * similar strings, which is the only basis on which their results can be
 * compared at all.
 *
 * It owns nothing else. Assessments, answers, findings and scores stay with
 * the organisation that collected them.
 *
 * Not scoped by anything: it sits above the tenant boundary, so every read of
 * it is deliberate and there are few of them.
 */
final class Programme extends Model
{
    protected $table = 'programmes';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
