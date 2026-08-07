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

    /**
     * `pwd` carries "this password has to be changed before anything else".
     *
     * A claim rather than a lookup: the alternative is reading the users table
     * on every request to check one boolean. It is only as durable as the token
     * — changing the password issues a new pair without it, and a token minted
     * before the change expires within the access TTL.
     */
    public function issue(
        int $userId,
        int $organizationId,
        string $role,
        bool $isPlatformAdmin = false,
        bool $mustChangePassword = false,
        ?int $programmeId = null,
        ?string $sessionHash = null,
    ): string {
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
                'pwd'   => $mustChangePassword,
                // The programme scopes the shared registry. Carried in the
                // token for the same reason as the rest: resolving it would
                // mean joining organizations on every single request.
                'prg'   => $programmeId,
                /**
                 * The session this token belongs to.
                 *
                 * Minted once at sign-in and copied across every refresh, so
                 * it is the one value that identifies a run of activity as
                 * belonging to one person on one device. An IP cannot do that
                 * job: assessors work behind carrier-grade NAT where a whole
                 * province shares an address, so requests from one IP are not
                 * one person and requests from two are not two people.
                 *
                 * Carried in the token because the alternative is a database
                 * lookup on every request to learn something the token could
                 * simply say.
                 */
                'sid'   => $sessionHash,
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
     * @return array{sub:int,org:int,role:string,admin:bool,pwd:bool,prg:int|null,sid:string|null}|null
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
            // Absent on a token minted before this claim existed. Defaulting
            // to false is the safe reading: it lets an old token keep working
            // rather than locking a signed-in assessor out mid-visit, and the
            // flag is re-read from the database at the next sign-in anyway.
            'pwd'   => (bool) ($claims['pwd'] ?? false),
            // Null on a token minted before programmes existed. The registry
            // scope then throws rather than guessing, which is the right way
            // for that to fail — the alternative is showing one programme's
            // national site list to another.
            'prg'   => isset($claims['prg']) ? (int) $claims['prg'] : null,
            // Null on a token minted before sessions were identified. The logs
            // then record the request without a session rather than refusing
            // it: a token issued yesterday is still a valid token, and losing
            // one line of correlation is not worth signing somebody out for.
            'sid'   => isset($claims['sid']) ? (string) $claims['sid'] : null,
        ];
    }
}
