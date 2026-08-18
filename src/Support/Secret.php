<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * A value that has to be stored and later read back as itself.
 *
 * There is exactly one of these so far and it is the SMTP password. Everything
 * else the application keeps about a person is either a hash — a password is
 * checked, never recovered — or not a secret at all. A mail server, though,
 * wants the password itself at the moment it connects, so hashing it is not an
 * option and storing it in plain text in a shared table is not either.
 *
 * XSalsa20-Poly1305 through libsodium, which ships with PHP and needs no
 * dependency. Authenticated, so a row edited in the database fails to decrypt
 * rather than decrypting to something else. The nonce is random per write and
 * travels with the ciphertext, which is why saving the same password twice
 * produces two different rows — that is correct, and a settings screen that
 * showed the stored value would be the real mistake.
 *
 * THIS FAILS CLOSED AND LOUDLY. With no APP_KEY, encrypt() throws and the save
 * is refused with a message naming the fix. The alternative — storing the
 * password unprotected and mentioning it in a log nobody reads — is how a
 * mailbox credential ends up in a database dump. Everything else on the
 * settings screen saves without a key; only this one field is held back.
 *
 * The key is in the environment rather than the database on purpose. A key
 * stored beside what it protects protects nothing.
 */
final class Secret
{
    /**
     * Marks the format, so a future scheme can be told from this one by
     * reading the value rather than by guessing from its length.
     */
    private const PREFIX = 'v1:';

    /** Whether anything can be encrypted at all on this installation. */
    public static function available(): bool
    {
        return self::key() !== null;
    }

    /**
     * @throws RuntimeException when APP_KEY is missing or the wrong length
     */
    public static function encrypt(#[SensitiveParameter] string $plain): string
    {
        $key = self::key();

        if ($key === null) {
            throw new RuntimeException(
                'No APP_KEY is set, so a password cannot be stored safely. '
                . 'Generate one with: php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"',
            );
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    /**
     * The stored value, or null when it cannot be read back.
     *
     * Null covers every way this goes wrong and they are all the same to the
     * caller: no key, a rotated key, a truncated column, a row somebody edited
     * by hand. What matters is that none of them is mistaken for a password —
     * returning a corrupted string would have the mail server refuse a
     * connection with an error naming nothing useful.
     */
    public static function decrypt(string $stored): ?string
    {
        $key = self::key();

        if ($key === null || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );

        return $plain === false ? null : $plain;
    }

    /**
     * Accepts the key as base64 or as hex, because both are what a generator
     * hands somebody and neither is wrong. Anything that does not resolve to
     * 32 bytes is treated as absent rather than padded into working — a key
     * that is silently the wrong one encrypts values nothing can read back.
     */
    private static function key(): ?string
    {
        $configured = trim((string) env('APP_KEY', ''));

        if ($configured === '') {
            return null;
        }

        $decoded = base64_decode($configured, true);

        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }

        if (preg_match('/^[0-9a-f]{64}$/i', $configured) === 1) {
            return (string) hex2bin($configured);
        }

        return null;
    }
}
