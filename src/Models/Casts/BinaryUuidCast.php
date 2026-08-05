<?php

declare(strict_types=1);

namespace App\Models\Casts;

use App\Support\BinaryUuid;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A BINARY(16) column, read and written as a UUID string.
 *
 * Note what this does NOT do: it does not make `Model::find($uuid)` work.
 * Casts apply to attributes, and a key lookup builds its where clause from the
 * raw argument, so a UUID string would be compared against sixteen raw bytes
 * and match nothing — quietly, returning null as though the row were absent.
 * Models with a binary key therefore expose findByUuid(), which converts
 * first. Keeping that explicit is the point: a silent empty result is a worse
 * failure than a missing method.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class BinaryUuidCast implements CastsAttributes
{
    /** @param array<string,mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return BinaryUuid::toString((string) $value);
    }

    /** @param array<string,mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        // Already bytes: a value hydrated from the database and saved back
        // unchanged. Converting it again would corrupt the key.
        return strlen($value) === 16 ? $value : BinaryUuid::toBytes($value);
    }
}
