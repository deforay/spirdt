<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\BinaryUuid;

/**
 * A BINARY(16) primary key holding a UUID.
 *
 * Used by everything that can be created on a device while offline. The id is
 * minted by the app and the server accepts it as given, which is what makes a
 * sync idempotent: replaying the same payload writes the same rows rather than
 * inserting a second copy under a new id.
 */
trait HasBinaryUuidKey
{
    public function initializeHasBinaryUuidKey(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    /** Queries take the UUID string; the column takes bytes. */
    public function getKeyForSaveQuery(): string
    {
        $key = $this->original[$this->getKeyName()] ?? $this->getKey();

        return is_string($key) && strlen($key) !== 16 ? BinaryUuid::toBytes($key) : (string) $key;
    }
}
