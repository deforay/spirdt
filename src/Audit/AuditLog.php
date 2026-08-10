<?php

declare(strict_types=1);

namespace App\Audit;

use App\Helper\Log;
use App\Support\BinaryUuid;
use App\Support\RequestContext;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use Throwable;

/**
 * Writing down who did something that mattered.
 *
 * The table has existed since 0.1.4 and held eight rows, every one of them
 * written by bin/recover-access. Meanwhile the application grew a screen for
 * creating accounts, a screen for resetting other people's passwords, and
 * finally a screen for changing what a role may do — the last of which can be
 * used to obtain every permission in the system. RoleAdminService decides who
 * MAY make that change. Nothing recorded that anybody HAD.
 *
 * THE ACTOR IS NOT PASSED IN. record() takes it from TenantContext, which
 * authentication established from a verified token, and the request's own
 * details from RequestContext. A caller that could name the actor could name
 * the wrong one, and an audit trail that can be told who did something is not
 * an audit trail.
 *
 * There is one exception and it is asUser(). Signing in, signing out and
 * changing a password all happen on routes that establish no tenant — the
 * first two have no authentication at all, because they are what produces the
 * credential it would check. At that moment AuthService is the only thing that
 * knows who this is, and it knows because it has just verified a password or a
 * refresh token. Every other caller uses record() and cannot name anybody.
 *
 * WRITING NEVER FAILS THE ACTION. This is the harder of the two calls and it
 * goes the same way as the request logger, for a blunter reason: the failure
 * this prevents is a full or locked audit table refusing a password reset for
 * somebody locked out at a clinic. A missing row is recoverable — api_logs
 * still has the request, and the file log gets a warning naming the action
 * that went unrecorded. A change that cannot be made is not.
 *
 * That trade is only defensible because it is loud. If the warnings below ever
 * appear in production, the fix is the table, not this decision.
 */
final class AuditLog
{
    /**
     * @param string                 $action     One of AuditAction's constants.
     * @param string|null            $entityType The kind of thing acted on, e.g. 'user'.
     * @param string|int|null        $entityId   Its id. A UUID string is stored as bytes.
     * @param array<string,mixed>    $metadata   What changed. Small, and never a secret.
     */
    public static function record(
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        array $metadata = [],
    ): void {
        $tenant = TenantContext::current();

        self::write($tenant?->userId, $tenant?->organizationId, $action, $entityType, $entityId, $metadata);
    }

    /**
     * The same, for the routes that establish no tenant.
     *
     * Authentication only. See the note above for why this exists and why
     * nothing else may use it.
     *
     * @param array<string,mixed> $metadata
     */
    public static function asUser(
        ?int $userId,
        ?int $organizationId,
        string $action,
        ?string $entityType = null,
        string|int|null $entityId = null,
        array $metadata = [],
    ): void {
        self::write($userId, $organizationId, $action, $entityType, $entityId, $metadata);
    }

    /** @param array<string,mixed> $metadata */
    private static function write(
        ?int $userId,
        ?int $organizationId,
        string $action,
        ?string $entityType,
        string|int|null $entityId,
        array $metadata,
    ): void {
        try {
            Capsule::table('audit_log')->insert([
                'organization_id' => $organizationId,
                // 'user' unless nobody is signed in. A row with no actor is
                // still worth having — a replayed token is discovered while
                // refusing the request that carried it, and who presented it
                // is exactly what is not known.
                'actor_type'      => $userId === null ? 'system' : 'user',
                'actor_id'        => $userId,
                'session_hash'    => RequestContext::sessionHash(),
                'action'          => mb_substr($action, 0, 100),
                'entity_type'     => $entityType === null ? null : mb_substr($entityType, 0, 50),
                'entity_id'       => self::entityId($entityId),
                'metadata'        => $metadata === [] ? null : self::encode($metadata),
                'ip_address'      => RequestContext::ipAddress(),
                'user_agent'      => RequestContext::userAgent(),
                'platform'        => RequestContext::platform(),
                'browser'         => RequestContext::browser(),
                'device_id'       => RequestContext::deviceId(),
                'request_uid'     => Log::requestUid(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit row not written for {action}: {reason}', [
                'action' => $action,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record an action against an account other than the actor's own.
     *
     * A convenience over record(), because "who did it" and "who it was done
     * to" are different columns and swapping them in a user-administration
     * trail turns the record inside out. The subject goes in entity_id where a
     * reader will look for it; the actor still comes from the token.
     *
     * @param array<string,mixed> $metadata
     */
    public static function aboutUser(string $action, int $userId, array $metadata = []): void
    {
        self::record($action, 'user', $userId, $metadata);
    }

    /**
     * VARBINARY(16) holds either an integer id or a UUID's bytes, so the
     * column serves both without a second one beside it. A UUID string is
     * converted; anything else is stored as text, which is what a reader
     * searching for "42" will type.
     */
    private static function entityId(string|int|null $id): ?string
    {
        if ($id === null) {
            return null;
        }

        if (is_string($id) && BinaryUuid::isValid($id)) {
            return BinaryUuid::toBytes($id);
        }

        return (string) $id;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private static function encode(array $metadata): ?string
    {
        $encoded = json_encode($metadata);

        // A row that says what happened and cannot say the detail is better
        // than no row. Losing the whole entry to an unencodable value would
        // lose the fact of the action as well.
        return $encoded === false ? null : mb_substr($encoded, 0, 4000);
    }
}
