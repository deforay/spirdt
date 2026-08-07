<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Who is making the request being handled, for anything that writes a record
 * of it.
 *
 * PSR-7 requests are immutable, so an attribute added by a middleware is
 * visible only to what runs INSIDE it. The request logger sits outside
 * routing — it has to, or it would miss anything the router itself refuses,
 * which is the 404 and the 405 — and authentication happens per route group,
 * well within. The logger therefore never sees the attributes authentication
 * sets, and recorded every request as anonymous.
 *
 * So the identity is put somewhere both can reach. A static is the right shape
 * here for the same reason TenantContext is one: PHP handles a single request
 * per process, and passing this down through every constructor between the two
 * would be threading a value that only two places want.
 *
 * Cleared between requests by the same call that clears the tenant. In tests
 * that matters more than in production, where the process ends anyway.
 */
final class RequestContext
{
    private static ?string $sessionHash = null;

    public static function setSessionHash(?string $hash): void
    {
        self::$sessionHash = $hash;
    }

    public static function sessionHash(): ?string
    {
        return self::$sessionHash;
    }

    public static function forget(): void
    {
        self::$sessionHash = null;
    }
}
