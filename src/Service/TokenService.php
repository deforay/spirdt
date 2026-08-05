<?php

declare(strict_types=1);

namespace App\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/**
 * Access tokens.
 *
 * Short lived on purpose. An assessor's device goes to sites, gets left in
 * vehicles and occasionally does not come back, and a token that never expires
 * is a standing key to an organisation's data. The refresh token in the
 * database is what survives a long offline stretch, and it can be revoked.
 *
 * The organisation is a claim rather than something looked up per request:
 * it is what every query is scoped by, so it has to be signed, not inferred.
 */
final class TokenService
{
    private readonly string $secret;

    private readonly int $ttlSeconds;

    public function __construct(?string $secret = null, ?int $ttlSeconds = null)
    {
        $secret ??= (string) env('JWT_SECRET', '');

        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must be at least 32 characters.');
        }

        $this->secret = $secret;

        // Floored rather than trusted: a JWT_ACCESS_TTL of 0 — an unset or
        // mistyped variable reads as 0 — would mint tokens that expire in the
        // same second they are issued, and the symptom is a device that can
        // never sync rather than an obvious configuration error.
        $this->ttlSeconds = max(60, $ttlSeconds ?? (int) env('JWT_ACCESS_TTL', 900));
    }

    /** What the device should put on its clock to know when to refresh. */
    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function issue(int $userId, int $organizationId, string $role, bool $isPlatformAdmin = false): string
    {
        $now = time();

        return JWT::encode(
            [
                'iss'   => 'spirdt',
                'iat'   => $now,
                'nbf'   => $now,
                'exp'   => $now + $this->ttlSeconds,
                'sub'   => $userId,
                'org'   => $organizationId,
                'role'  => $role,
                'admin' => $isPlatformAdmin,
            ],
            $this->secret,
            'HS256',
        );
    }

    /**
     * The claims, or null when the token is missing, expired, tampered with or
     * signed with a different secret. Deliberately one return for all of them:
     * telling a caller which of those went wrong tells an attacker too.
     *
     * @return array{sub:int,org:int,role:string,admin:bool}|null
     */
    public function verify(string $token): ?array
    {
        try {
            $claims = (array) JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (Throwable) {
            return null;
        }

        if (!isset($claims['sub'], $claims['org'])) {
            return null;
        }

        return [
            'sub'   => (int) $claims['sub'],
            'org'   => (int) $claims['org'],
            'role'  => (string) ($claims['role'] ?? ''),
            'admin' => (bool) ($claims['admin'] ?? false),
        ];
    }
}
