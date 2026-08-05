<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A tenant. Not scoped by itself — it is what everything else is scoped to. */
final class Organization extends Model
{
    protected $table = 'organizations';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'is_active'                  => 'boolean',
        'requires_assessment_review' => 'boolean',
    ];
}
