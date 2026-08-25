<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Organization;
use App\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Reading the request log back.
 *
 * `api_logs` is the diagnostic and `audit_log` is the evidence — the two are
 * kept apart deliberately and this reads the first of them. It answers "what
 * was this server actually asked, by whom, from where, and what did it
 * answer", which is the question you have when a screen is behaving in a way
 * the code does not explain. It is not a record of consequence and nothing
 * here is written once and kept for ever: `bin/housekeeping` prunes it.
 *
 * SCOPED BY HAND, like AuditReadService, and for a related reason —
 * `api_logs.organization_id` is nullable. The difference is which rows the
 * nulls are, and it matters more here.
 *
 * Every request made before authentication succeeds — signing in, refreshing a
 * token, the ones that answered 401 — is written with no organisation and no
 * user, because at the moment the row was written there was nobody to
 * attribute it to. Those are exactly the rows somebody debugging a sign-in
 * needs to see, and they are also the rows that must not be handed to another
 * tenant: a login body carries the email address it was attempted with.
 *
 * So they are reachable through the session and only through the session. A
 * session hash is minted once at sign-in and copied across every refresh, so
 * if ANY row bearing that hash is attributed to this organisation then the
 * whole run of activity is this organisation's, unattributed rows included.
 * That is checked against the table rather than assumed — see mine() — and
 * without a session filter the nulls stay out of the list entirely.
 */
final class RequestLogReadService
{
    private const PAGE_SIZE = 50;

    /**
     * Offered whole rather than gathered from history, for the reason the audit
     * catalogue gives: a filter built from the rows that exist cannot offer the
     * one somebody is checking has never happened.
     */
    private const METHODS = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];

    /**
     * @param  array<string,mixed> $filters
     * @return array{rows: list<array<string,mixed>>, total: int, per_page: int, methods: list<string>}
     */
    public function list(array $filters = [], int $perPage = self::PAGE_SIZE): array
    {
        $perPage = max(1, min($perPage, 200));

        $organizationId = TenantContext::requireOrganizationId();
        $session = trim((string) ($filters['session_hash'] ?? ''));

        $query = Capsule::table('api_logs');

        if ($session !== '' && $this->mine($session, $organizationId)) {
            // A session this organisation demonstrably owns, so the rows from
            // before it was signed in belong with the rest of it.
            $query
                ->where('api_logs.session_hash', $session)
                ->where(function (Builder $scope) use ($organizationId): void {
                    $scope
                        ->where('api_logs.organization_id', $organizationId)
                        ->orWhereNull('api_logs.organization_id');
                });
        } else {
            $query->where('api_logs.organization_id', $organizationId);

            if ($session !== '') {
                $query->where('api_logs.session_hash', $session);
            }
        }

        $method = strtoupper(trim((string) ($filters['method'] ?? '')));

        if (in_array($method, self::METHODS, true)) {
            $query->where('api_logs.method', $method);
        }

        $this->applyStatus($query, (string) ($filters['status'] ?? ''));

        // Substring, because the useful question is about a route rather than a
        // URL: "/sync" finds every sync endpoint without anybody having to know
        // which ones there are. Escaped, so a path containing % or _ — which
        // an encoded segment can — searches for itself rather than for
        // everything.
        $path = trim((string) ($filters['path'] ?? ''));

        if ($path !== '') {
            $query->where(
                'api_logs.path',
                'like',
                '%' . addcslashes($path, '%_\\') . '%',
            );
        }

        // Whole days in the organisation's own reading of them, converted
        // rather than concatenated — see AuditReadService, which explains at
        // length why pinning a local date to midnight asks for the wrong
        // instant everywhere but UTC.
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));

        if ($from !== '') {
            $query->where('api_logs.created_at', '>=', $this->instant($from . ' 00:00:00'));
        }

        if ($to !== '') {
            $query->where('api_logs.created_at', '<=', $this->instant($to . ' 23:59:59'));
        }

        $total = (clone $query)->count();

        // Walks back from a row rather than forward from an offset. This table
        // is appended to by every request the server handles, including the
        // ones this screen is making, so an offset would shift under the reader
        // between one page and the next.
        $before = $filters['before_id'] ?? null;

        if (is_numeric($before)) {
            $query->where('api_logs.id', '<', (int) $before);
        }

        $records = $query
            // On the organisation as well as the id, so a mismatched row cannot
            // put another tenant's name on this screen. AuditReadService makes
            // the same join for the same reason.
            ->leftJoin('users', function (JoinClause $join): void {
                $join->on('users.id', '=', 'api_logs.user_id')
                    ->on('users.organization_id', '=', 'api_logs.organization_id');
            })
            ->orderByDesc('api_logs.id')
            ->limit($perPage)
            ->get([
                'api_logs.id',
                'api_logs.method',
                'api_logs.path',
                'api_logs.status_code',
                'api_logs.duration_ms',
                'api_logs.user_id',
                'api_logs.session_hash',
                'api_logs.request_uid',
                'api_logs.request_body',
                'api_logs.ip_address',
                'api_logs.user_agent',
                'api_logs.platform',
                'api_logs.browser',
                'api_logs.device_id',
                'api_logs.app_version',
                'api_logs.created_at',
                'users.full_name as user_name',
                'users.email as user_email',
            ]);

        $rows = [];

        foreach ($records as $record) {
            $rows[] = [
                'id'          => (int) $record->id,
                'method'      => (string) $record->method,
                'path'        => (string) $record->path,
                'status'      => (int) $record->status_code,
                'duration_ms' => $record->duration_ms === null ? null : (int) $record->duration_ms,
                'user_id'     => $record->user_id === null ? null : (int) $record->user_id,
                'user_name'   => $record->user_name === null ? null : (string) $record->user_name,
                'user_email'  => $record->user_email === null ? null : (string) $record->user_email,
                // Eight characters to compare by eye, and the whole value to
                // filter by. The same pair the audit trail shows, so a session
                // followed on one screen can be followed on the other.
                'session'     => $record->session_hash === null
                    ? null
                    : mb_substr((string) $record->session_hash, 0, 8),
                'session_hash' => $record->session_hash === null ? null : (string) $record->session_hash,
                // The line in var/log written while this request was handled.
                // It is the only thread between the two, and printing it is the
                // difference between grepping for a time and grepping for a
                // request.
                'request_uid' => $record->request_uid === null ? null : (string) $record->request_uid,
                'body'        => $record->request_body === null ? null : (string) $record->request_body,
                'ip_address'  => $record->ip_address === null ? null : (string) $record->ip_address,
                'user_agent'  => $record->user_agent === null ? null : (string) $record->user_agent,
                'platform'    => $record->platform === null ? null : (string) $record->platform,
                'browser'     => $record->browser === null ? null : (string) $record->browser,
                'device_id'   => $record->device_id === null ? null : (string) $record->device_id,
                'app_version' => $record->app_version === null ? null : (string) $record->app_version,
                'created_at'  => (string) $record->created_at,
            ];
        }

        return [
            'rows'     => $rows,
            'total'    => $total,
            'per_page' => $perPage,
            'methods'  => self::METHODS,
        ];
    }

    /**
     * Status, as a class rather than a number.
     *
     * "Anything that failed" is the filter this screen is opened for, and it
     * does not correspond to a status code — it spans two of them. An exact
     * code is accepted as well, because once you know a screen is answering
     * 405 you want only the 405s.
     */
    private function applyStatus(Builder $query, string $status): void
    {
        $status = trim($status);

        if ($status === 'failed') {
            $query->where('api_logs.status_code', '>=', 400);

            return;
        }

        if ($status === '4xx') {
            $query->whereBetween('api_logs.status_code', [400, 499]);

            return;
        }

        if ($status === '5xx') {
            $query->where('api_logs.status_code', '>=', 500);

            return;
        }

        if (ctype_digit($status)) {
            $query->where('api_logs.status_code', (int) $status);
        }
    }

    /**
     * Whether a session hash belongs to this organisation.
     *
     * Asked of the log itself rather than of `refresh_tokens`, so that the
     * answer cannot outlive the rows it is being used to unlock: both are
     * pruned by the same housekeeping, and a session whose requests are all
     * gone has nothing left for this to widen the scope to.
     *
     * One attributed row is proof enough. The hash is minted at sign-in and
     * copied across every rotation, so it names one run of activity by one
     * person on one device — it cannot be half one organisation's.
     */
    private function mine(string $session, int $organizationId): bool
    {
        return Capsule::table('api_logs')
            ->where('session_hash', $session)
            ->where('organization_id', $organizationId)
            ->exists();
    }

    private function instant(string $local): string
    {
        try {
            $zone = new DateTimeZone($this->timezone());

            return (new DateTimeImmutable($local, $zone))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Exception) {
            return $local;
        }
    }

    private function timezone(): string
    {
        $zone = Organization::query()
            ->where('id', TenantContext::requireOrganizationId())
            ->value('timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }
}
