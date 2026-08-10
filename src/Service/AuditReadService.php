<?php

declare(strict_types=1);

namespace App\Service;

use App\Audit\AuditAction;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Reading the trail back.
 *
 * SCOPED BY HAND, and this is the one service where that is correct rather
 * than a smell. `audit_log.organization_id` is nullable — uniquely among
 * tenant tables — because a platform-level action belongs in the trail and
 * sits above any organisation. The global model scope cannot express "mine and
 * not the ones belonging to nobody", so the filter is written here, explicitly,
 * and it excludes the null rows. An organisation reading its own history must
 * not be shown actions taken across the whole installation.
 *
 * There is no model for this table on purpose. Nothing joins to it, nothing
 * updates it, and giving it an Eloquent model would offer a save() that must
 * never be called: the value of an audit row is that it is written once and
 * never touched again.
 *
 * The actor's name is joined in rather than stored. A row records who acted by
 * id, so that renaming somebody does not rewrite what the trail says about
 * them — but a reader wants a name, and the account is nearly always still
 * there. A deleted one reads as its id, which is honest.
 */
final class AuditReadService
{
    private const PAGE_SIZE = 50;

    /**
     * @param  array<string,mixed> $filters
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, per_page: int, actions: list<string>}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        $perPage = max(1, min($perPage, 200));
        $page = max(1, $page);

        $query = Capsule::table('audit_log')
            ->where('audit_log.organization_id', TenantContext::requireOrganizationId());

        $action = trim((string) ($filters['action'] ?? ''));

        if ($action !== '') {
            $query->where('audit_log.action', $action);
        }

        $actor = $filters['actor_id'] ?? null;

        if (is_numeric($actor)) {
            $query->where('audit_log.actor_id', (int) $actor);
        }

        // Whole days, in the organisation's own reading of them. A trail
        // filtered by an instant is a trail nobody can ask a question of.
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));

        if ($from !== '') {
            $query->where('audit_log.created_at', '>=', $from . ' 00:00:00');
        }

        if ($to !== '') {
            $query->where('audit_log.created_at', '<=', $to . ' 23:59:59');
        }

        $session = trim((string) ($filters['session_hash'] ?? ''));

        if ($session !== '') {
            $query->where('audit_log.session_hash', $session);
        }

        $total = (clone $query)->count();

        $records = $query
            ->leftJoin('users', 'users.id', '=', 'audit_log.actor_id')
            ->orderByDesc('audit_log.id')
            ->forPage($page, $perPage)
            ->get([
                'audit_log.id',
                'audit_log.actor_type',
                'audit_log.actor_id',
                'audit_log.session_hash',
                'audit_log.action',
                'audit_log.entity_type',
                'audit_log.entity_id',
                'audit_log.metadata',
                'audit_log.ip_address',
                'audit_log.platform',
                'audit_log.browser',
                'audit_log.created_at',
                'users.full_name as actor_name',
                'users.email as actor_email',
            ]);

        $rows = [];

        foreach ($records as $record) {
            $rows[] = [
                'id'           => (int) $record->id,
                'action'       => (string) $record->action,
                'actor_type'   => (string) $record->actor_type,
                'actor_id'     => $record->actor_id === null ? null : (int) $record->actor_id,
                'actor_name'   => $record->actor_name === null ? null : (string) $record->actor_name,
                'actor_email'  => $record->actor_email === null ? null : (string) $record->actor_email,
                'entity_type'  => $record->entity_type === null ? null : (string) $record->entity_type,
                'entity_id'    => $this->readableEntityId($record->entity_id),
                'metadata'     => $this->decode($record->metadata),
                'ip_address'   => $record->ip_address === null ? null : (string) $record->ip_address,
                'platform'     => $record->platform === null ? null : (string) $record->platform,
                'browser'      => $record->browser === null ? null : (string) $record->browser,
                // Truncated to something a person can compare by eye. The whole
                // value is a correlator, not a secret, but sixty-four
                // characters in a table column is noise.
                'session'      => $record->session_hash === null
                    ? null
                    : mb_substr((string) $record->session_hash, 0, 8),
                'session_hash' => $record->session_hash === null ? null : (string) $record->session_hash,
                'created_at'   => (string) $record->created_at,
            ];
        }

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            // Every action this version can write, so the filter offers the
            // whole vocabulary rather than only what has happened so far. A
            // dropdown built from existing rows cannot offer the one somebody
            // is checking has never occurred.
            'actions'  => $this->catalogue(),
        ];
    }

    /**
     * VARBINARY(16) holds an integer id as text or a UUID as bytes, so which
     * of the two this is has to be worked out on the way out. Sixteen raw
     * bytes is a UUID; anything else was written as text and is returned as
     * it was.
     */
    private function readableEntityId(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (strlen($value) === 16 && preg_match('/[^\x20-\x7e]/', $value) === 1) {
            return BinaryUuid::toString($value);
        }

        return $value;
    }

    /** @return array<string,mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private function catalogue(): array
    {
        $actions = [];

        foreach ((new \ReflectionClass(AuditAction::class))->getConstants() as $value) {
            if (is_string($value)) {
                $actions[] = $value;
            }
        }

        sort($actions);

        return $actions;
    }
}
