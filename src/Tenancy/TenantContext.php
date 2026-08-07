<?php

declare(strict_types=1);

namespace App\Tenancy;

use RuntimeException;

/**
 * Which organisation the current request belongs to.
 *
 * Every tenant-scoped table carries organization_id, and the global scope on
 * the models reads it from here.
 *
 * FAILS CLOSED. With no context set, a scoped query throws rather than
 * returning every organisation's rows. The alternative — treating "no context"
 * as "no filter" — means one forgotten middleware turns a reporting endpoint
 * into a cross-tenant data leak, and nothing about the response would look
 * wrong. A loud exception in development is the cheap version of that bug.
 *
 * Held statically because Eloquent's global scopes are resolved deep inside
 * the query builder, with no route to constructor injection. That makes it the
 * one piece of global state in the application, so it is confined to this
 * class, cleared between requests, and escaped only through withoutScope().
 */
final class TenantContext
{
    private static ?self $current = null;

    /** Depth rather than a flag, so nested withoutScope() calls do not re-arm early. */
    private static int $unscopedDepth = 0;

    private function __construct(
        public readonly int $organizationId,
        public readonly ?int $userId = null,
        public readonly bool $isPlatformAdmin = false,
        public readonly ?int $programmeId = null,
    ) {
    }

    /**
     * @param int|null $programmeId the programme the organisation belongs to,
     *                              which scopes the shared site registry. Null
     *                              is allowed so that callers with no reason to
     *                              touch the registry — most tests, most CLI
     *                              work — need not supply one; a registry query
     *                              made without it throws rather than guessing.
     */
    public static function set(
        int $organizationId,
        ?int $userId = null,
        bool $isPlatformAdmin = false,
        ?int $programmeId = null,
    ): self {
        self::$current = new self($organizationId, $userId, $isPlatformAdmin, $programmeId);

        return self::$current;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * The organisation to filter by.
     *
     * @throws RuntimeException when nothing has established a tenant.
     */
    public static function requireOrganizationId(): int
    {
        if (self::$current === null) {
            throw new RuntimeException(
                'No tenant context. A scoped query ran outside a request that established an organisation — '
                . 'set one with TenantContext::set(), or wrap the call in TenantContext::withoutScope() '
                . 'if it is deliberately cross-tenant.',
            );
        }

        return self::$current->organizationId;
    }

    /**
     * The programme to filter the shared registry by.
     *
     * Fails closed for the same reason the organisation does, and it matters
     * more here: the registry is the one thing several organisations share, so
     * an unfiltered query returns another programme's national site list.
     *
     * @throws RuntimeException when no programme has been established.
     */
    public static function requireProgrammeId(): int
    {
        if (self::$current?->programmeId === null) {
            throw new RuntimeException(
                'No programme in the tenant context. The registry — geographic units, facilities, '
                . 'testing sites — is scoped by programme rather than organisation. Pass the '
                . 'programme to TenantContext::set(), or wrap a deliberately cross-programme call '
                . 'in TenantContext::withoutScope().',
            );
        }

        return self::$current->programmeId;
    }

    public static function isScoped(): bool
    {
        return self::$unscopedDepth === 0;
    }

    /**
     * Run something across every organisation.
     *
     * For platform administration, migrations and CLI work. Deliberately a
     * callback rather than a flag: the scope is restored on the way out even
     * when the callback throws, so an exception cannot leave the process
     * unscoped for everything that follows it.
     *
     * @template T
     * @param  callable():T $callback
     * @return T
     */
    public static function withoutScope(callable $callback): mixed
    {
        ++self::$unscopedDepth;

        try {
            return $callback();
        } finally {
            --self::$unscopedDepth;
        }
    }

    /** Between requests, and between tests. */
    public static function forget(): void
    {
        // The request identity goes with it. They are set together by
        // authentication and a stale session hash on the next request would
        // attribute one person's activity to another.
        \App\Support\RequestContext::forget();

        self::$current = null;
        self::$unscopedDepth = 0;
    }
}
