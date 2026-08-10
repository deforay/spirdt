<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Auth\Permission;
use App\Bootstrap;
use App\Support\RequestContext;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\MakesTenants;

/**
 * What the trail records, and what it must not.
 *
 * Three properties matter more than the individual entries.
 *
 * The actor is the person who acted, never the person acted upon. An audit of
 * user administration that stores those the wrong way round is worse than none,
 * because it reads as an accusation.
 *
 * An action is recorded once. The device retries a sync until it lands, so a
 * row per attempt would report one visit as submitted five times.
 *
 * And a failure to write must not fail the action. That is tested by breaking
 * the table, because it is the only way to know the catch is real.
 */
final class AuditTrailTest extends TestCase
{
    use MakesTenants;

    private const PASSWORD = 'correct-horse-battery';

    private int $orgId;
    private int $boss;

    protected function setUp(): void
    {
        Bootstrap::createApp();
        TenantContext::forget();
        RequestContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['audit_log', 'login_attempts', 'refresh_tokens', 'users', 'role_permissions',
                    'roles', 'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgId = $this->makeTenant('audit-org', 'Audit Org');
        $this->makeRoles($this->orgId);

        $this->boss = $this->makeUser('boss@example.org', 'admin');
        $this->makeUser('joseph@example.org', 'assessor');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        RequestContext::forget();
    }

    // ─── access ───

    public function testSigningInIsRecordedWithTheSessionItStarted(): void
    {
        $this->signIn('boss@example.org');

        $row = $this->latest(AuditAction::SIGNED_IN);

        self::assertSame($this->boss, (int) $row->actor_id);
        self::assertSame($this->orgId, (int) $row->organization_id);
        self::assertSame('user', $row->actor_type);

        // The whole value of the row. Without it a sign-in cannot be joined to
        // anything the session went on to do.
        self::assertNotNull($row->session_hash);
        self::assertSame(64, strlen((string) $row->session_hash));
    }

    public function testSigningOutIsRecordedAgainstTheSessionItEnded(): void
    {
        $body = $this->body($this->request('POST', '/api/auth/login', [
            'email'    => 'boss@example.org',
            'password' => self::PASSWORD,
        ]));

        $this->request('POST', '/api/auth/logout', ['refresh_token' => $body['refresh_token']]);

        $in = $this->latest(AuditAction::SIGNED_IN);
        $out = $this->latest(AuditAction::SIGNED_OUT);

        self::assertNotNull($out);
        self::assertSame((string) $in->session_hash, (string) $out->session_hash);
    }

    /**
     * Nobody performs this one. A token presented twice means a copy exists,
     * and every session for the account is ended because of it — which is
     * exactly the event somebody investigating an incident is looking for.
     */
    public function testAReplayedRefreshTokenIsRecorded(): void
    {
        $login = $this->body($this->request('POST', '/api/auth/login', [
            'email'    => 'boss@example.org',
            'password' => self::PASSWORD,
        ]));

        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);

        $row = $this->latest(AuditAction::TOKEN_REPLAYED);

        self::assertNotNull($row);
        self::assertSame($this->boss, (int) $row->actor_id);
    }

    public function testChangingYourOwnPasswordIsRecorded(): void
    {
        $token = $this->signIn('boss@example.org');

        $this->request('POST', '/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password'     => 'a-different-long-one',
        ], $token);

        self::assertSame($this->boss, (int) $this->latest(AuditAction::PASSWORD_CHANGED)->actor_id);
    }

    // ─── who did it to whom ───

    /**
     * The actor is the administrator. The subject is the entity.
     *
     * Storing these the wrong way round produces a trail saying the person
     * whose password was reset reset it themselves, which is not a small
     * mistake in a record kept as evidence.
     */
    public function testAPasswordResetRecordsTheAdministratorAsActorAndTheAccountAsSubject(): void
    {
        $target = (int) Capsule::table('users')->where('email', 'joseph@example.org')->value('id');

        $this->request(
            'POST',
            '/api/admin/users/' . $target . '/password',
            [],
            $this->signIn('boss@example.org'),
        );

        $row = $this->latest(AuditAction::USER_PASSWORD_RESET);

        self::assertSame($this->boss, (int) $row->actor_id, 'the administrator acted');
        self::assertSame('user', $row->entity_type);
        self::assertSame((string) $target, (string) $row->entity_id, 'the account was acted upon');
    }

    public function testCreatingAnAccountRecordsTheRoleItWasGiven(): void
    {
        $this->request('POST', '/api/admin/users', [
            'email'     => 'mary@example.org',
            'full_name' => 'Mary Banda',
            'role'      => 'viewer',
        ], $this->signIn('boss@example.org'));

        $row = $this->latest(AuditAction::USER_CREATED);

        self::assertSame('viewer', $this->metadata($row)['role'] ?? null);
    }

    public function testChangingARolesPermissionsRecordsTheDifference(): void
    {
        $viewerRole = (int) Capsule::table('roles')
            ->where('organization_id', $this->orgId)
            ->where('key', 'viewer')
            ->value('id');

        $this->request('PATCH', '/api/admin/roles/' . $viewerRole . '/permissions', [
            'permissions' => [Permission::REGISTRY_READ, Permission::REGISTRY_WRITE],
        ], $this->signIn('boss@example.org'));

        $metadata = $this->metadata($this->latest(AuditAction::ROLE_PERMISSIONS_CHANGED));

        // What changed, not what the role now holds. A reader months later
        // wants the delta; the resulting set does not say which is new.
        self::assertSame([Permission::REGISTRY_WRITE], $metadata['granted']);
        self::assertSame([Permission::REPORTS_READ], $metadata['revoked']);
        self::assertSame('viewer', $metadata['role']);
    }

    /** An edit that changes nothing writes nothing. */
    public function testResendingTheSamePermissionsRecordsNoChange(): void
    {
        $viewerRole = (int) Capsule::table('roles')
            ->where('organization_id', $this->orgId)
            ->where('key', 'viewer')
            ->value('id');

        $this->request('PATCH', '/api/admin/roles/' . $viewerRole . '/permissions', [
            'permissions' => [Permission::REGISTRY_READ, Permission::REPORTS_READ],
        ], $this->signIn('boss@example.org'));

        self::assertSame(0, $this->rowsFor(AuditAction::ROLE_PERMISSIONS_CHANGED));
    }

    // ─── reading it back ───

    public function testTheTrailIsReadableAndScopedToTheOrganisation(): void
    {
        $token = $this->signIn('boss@example.org');

        // Another organisation's row, which must not appear.
        $otherOrg = $this->makeTenant('other-audit-org', 'Other');
        Capsule::table('audit_log')->insert([
            'organization_id' => $otherOrg,
            'actor_type'      => 'user',
            'action'          => AuditAction::SIGNED_IN,
        ]);

        // And a platform row belonging to nobody, which must not either.
        Capsule::table('audit_log')->insert([
            'organization_id' => null,
            'actor_type'      => 'platform_admin',
            'action'          => AuditAction::ORGANIZATION_CREATED,
        ]);

        $body = $this->body($this->request('GET', '/api/admin/audit', [], $token));

        self::assertGreaterThan(0, $body['total']);

        foreach ($body['rows'] as $row) {
            self::assertNotSame(AuditAction::ORGANIZATION_CREATED, $row['action']);
        }

        // The filter offers every action this version can write, not only the
        // ones that happen to have occurred.
        self::assertContains(AuditAction::FACILITY_MERGED, $body['actions']);
    }

    public function testAnAssessorCannotReadTheTrail(): void
    {
        $response = $this->request('GET', '/api/admin/audit', [], $this->signIn('joseph@example.org'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testTheTrailCanBeFilteredByAction(): void
    {
        $token = $this->signIn('boss@example.org');

        $this->request('POST', '/api/admin/users', [
            'email'     => 'mary@example.org',
            'full_name' => 'Mary Banda',
            'role'      => 'viewer',
        ], $token);

        $body = $this->body($this->request(
            'GET',
            '/api/admin/audit?action=' . AuditAction::USER_CREATED,
            [],
            $token,
        ));

        self::assertSame(1, $body['total']);
        self::assertSame(AuditAction::USER_CREATED, $body['rows'][0]['action']);
    }

    // ─── the trade-off, tested ───

    /**
     * A broken audit table must not refuse the action.
     *
     * The failure this prevents is a password reset being refused for somebody
     * locked out at a clinic because the log table is full. The trade is only
     * defensible if it really is a trade — so the table is genuinely made
     * unwritable here rather than mocked, and the reset still has to succeed.
     */
    public function testAFailingAuditWriteDoesNotFailTheAction(): void
    {
        $target = (int) Capsule::table('users')->where('email', 'joseph@example.org')->value('id');
        $token = $this->signIn('boss@example.org');

        Capsule::connection()->statement('RENAME TABLE audit_log TO audit_log_hidden');

        try {
            $response = $this->request('POST', '/api/admin/users/' . $target . '/password', [], $token);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertNotSame('', $this->body($response)['password']);
        } finally {
            Capsule::connection()->statement('RENAME TABLE audit_log_hidden TO audit_log');
        }
    }

    // ─── helpers ───

    private function latest(string $action): object
    {
        $row = Capsule::table('audit_log')
            ->where('action', $action)
            ->orderByDesc('id')
            ->first();

        self::assertNotNull($row, 'no audit row for ' . $action);

        return $row;
    }

    private function rowsFor(string $action): int
    {
        return Capsule::table('audit_log')->where('action', $action)->count();
    }

    /** @return array<string,mixed> */
    private function metadata(object $row): array
    {
        $decoded = json_decode((string) $row->metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function makeUser(string $email, string $roleKey): int
    {
        return (int) Capsule::table('users')->insertGetId([
            'organization_id'      => $this->orgId,
            'role_id'              => (int) Capsule::table('roles')
                ->where('organization_id', $this->orgId)
                ->where('key', $roleKey)
                ->value('id'),
            'email'                => $email,
            'password_hash'        => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'full_name'            => 'Test Person',
            'is_active'            => 1,
            'must_change_password' => 0,
        ]);
    }

    private function signIn(string $email): string
    {
        return $this->body($this->request('POST', '/api/auth/login', [
            'email'    => $email,
            'password' => self::PASSWORD,
        ]))['access_token'];
    }

    /** @param array<string,mixed> $payload */
    private function request(
        string $method,
        string $path,
        array $payload = [],
        ?string $token = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Content-Type', 'application/json');

        if ($payload !== []) {
            $request = $request->withParsedBody($payload);
        }

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return Bootstrap::createApp()->handle($request);
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
