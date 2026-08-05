<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who signs in.
 *
 * Scoped like everything else, with one deliberate exception: sign-in itself
 * runs before any organisation is known, so AuthService looks users up through
 * acrossOrganizations(). That is the whole reason the escape hatch is named
 * rather than neutral — this is a call site a reviewer should stop at.
 *
 * password_hash is hidden so a model serialised into a response, a log line or
 * an exception dump cannot carry it. Nothing should ever be serialising a user
 * wholesale, but the cost of being wrong about that is every password on the
 * installation.
 */
final class User extends Model
{
    use BelongsToOrganization;

    protected $table = 'users';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password_hash'];

    /** @var array<string,string> */
    protected $casts = [
        'is_active'            => 'boolean',
        'must_change_password' => 'boolean',
        'last_login_at'        => 'datetime',
    ];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
