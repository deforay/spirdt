<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A named set of permissions, per organisation.
 *
 * The `key` is what travels in the token and what code compares against; the
 * `name` is what an organisation may rename to local vocabulary. Never branch
 * on the name.
 */
final class Role extends Model
{
    use BelongsToOrganization;

    protected $table = 'roles';

    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'is_system' => 'boolean',
    ];
}
