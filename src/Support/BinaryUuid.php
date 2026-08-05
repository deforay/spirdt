<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Conversion between a UUID string and the BINARY(16) the database stores.
 *
 * Sixteen raw bytes rather than a 36-character string: the ids are primary
 * keys on tables that grow by fifty-nine rows per site visit, and every
 * foreign key and index carries a copy. Storing them as text would more than
 * double the index size for no benefit, since nothing queries a UUID by
 * prefix.
 *
 * Ids arrive from the device already minted, so this runs on the way in and
 * on the way out of every synced row.
 */
final class BinaryUuid
{
    /** @throws InvalidArgumentException when the input is not a UUID. */
    public static function toBytes(string $uuid): string
    {
        $hex = str_replace('-', '', trim($uuid));

        if (preg_match('/^[0-9a-f]{32}$/i', $hex) !== 1) {
            throw new InvalidArgumentException("Not a UUID: {$uuid}");
        }

        $bytes = hex2bin($hex);

        if ($bytes === false) {
            throw new InvalidArgumentException("Not a UUID: {$uuid}");
        }

        return $bytes;
    }

    public static function toString(string $bytes): string
    {
        // Already a UUID string. Happens when a value has been round-tripped
        // through the model rather than read straight off a column, and
        // converting it twice would corrupt it.
        if (strlen($bytes) !== 16) {
            return $bytes;
        }

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public static function isValid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
