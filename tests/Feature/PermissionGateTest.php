<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Permission;
use App\Bootstrap;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\MakesTenants;

/**
 * The gate reads the database, not the role's name.
 *
 * Every test here does the same thing twice with the same account and changes
 * only a row in `role_permissions` between them. That is the entire claim of
 * the layer, and it is not provable by testing an administrator and an assessor
 * against each other — they differ in a dozen ways, so a passing pair says
 * nothing about WHICH difference the gate is reading. Two runs of one account
 * leave one variable.
 *
 * The direction matters as much as the fact. A permission removed has to close
 * a route that was open, and a permission granted to a role that never held it
 * has to open one. A system that only does the first is a system where the role
 * name still decides and the table is decoration.
 */
final class PermissionGateTest extends TestCase
{
    use MakesTenants;

    private const PASSWORD = 'correct-horse-battery';

    private int $orgId;

    protected function setUp(): void
    {
        Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['login_attempts', 'refresh_tokens', 'users', 'role_permissions', 'roles',
                    'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgId = $this->makeTenant('perm-org', 'Permission Org');
        $this->makeRoles($this->orgId);

        $this->makeUser('boss@example.org', 'admin');
        $this->makeUser('reader@example.org', 'viewer');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    // ─── taking one away ───

    public function testAnAdministratorLosesARouteWhenThePermissionIsRevoked(): void
    {
        $token = $this->signIn('boss@example.org');

        self::assertSame(200, $this->get('/api/admin/users', $token)->getStatusCode());

        $this->revoke('admin', Permission::USERS_MANAGE);

        // The same account, the same token, the same role name. Only the row
        // is gone.
        $response = $this->get('/api/admin/users', $token);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('permission_required', $this->body($response)['error']['code']);
        self::assertSame([Permission::USERS_MANAGE], $this->body($response)['error']['requires']);
    }

    /**
     * On the token already in hand, not on the next sign-in.
     *
     * A permission that only takes effect when the holder next authenticates is
     * a permission that survives its own withdrawal for as long as an access
     * token lives. AuthMiddleware re-reads the grants on every request for
     * exactly this reason, and the test above proves it by never signing in
     * again after the revoke.
     */
    public function testRevokingDoesNotWaitForTheTokenToExpire(): void
    {
        $token = $this->signIn('boss@example.org');

        $this->revoke('admin', Permission::REGISTRY_WRITE);

        self::assertSame(403, $this->request('POST', '/api/admin/geo-units', [
            'name'  => 'Copperbelt',
            'level' => 1,
        ], $token)->getStatusCode());
    }

    /** Revoking one leaves the others alone. */
    public function testRevokingOnePermissionDoesNotCloseTheRest(): void
    {
        $this->revoke('admin', Permission::USERS_MANAGE);

        $token = $this->signIn('boss@example.org');

        self::assertSame(403, $this->get('/api/admin/users', $token)->getStatusCode());
        self::assertSame(200, $this->get('/api/admin/geo-units', $token)->getStatusCode());
    }

    // ─── giving one ───

    public function testAViewerGrantedRegistryWriteCanWriteToTheRegistry(): void
    {
        $token = $this->signIn('reader@example.org');

        self::assertSame(403, $this->request('POST', '/api/admin/geo-units', [
            'name'  => 'Copperbelt',
            'level' => 1,
        ], $token)->getStatusCode());

        $this->grant('viewer', Permission::REGISTRY_WRITE);

        // Still a viewer by name. The route no longer cares.
        self::assertSame(201, $this->request('POST', '/api/admin/geo-units', [
            'name'  => 'Copperbelt',
            'level' => 1,
        ], $token)->getStatusCode());
    }

    // ─── failing closed ───

    public function testARoleWithNoPermissionsReachesNothing(): void
    {
        $this->makeUser('nobody@example.org', 'site_user');

        $token = $this->signIn('nobody@example.org');

        self::assertSame(403, $this->get('/api/admin/users', $token)->getStatusCode());
        self::assertSame(403, $this->get('/api/admin/geo-units', $token)->getStatusCode());
        self::assertSame(403, $this->get('/api/admin/reports/assessments', $token)->getStatusCode());
        self::assertSame(403, $this->request(
            'POST',
            '/api/sync/assessments',
            ['id' => 'nope'],
            $token,
        )->getStatusCode());
    }

    /**
     * Emptying a role does not fall back to what the role is called.
     *
     * The tempting shortcut is to treat "no rows" as "not configured yet" and
     * apply the defaults. It would make the last revoke on a role restore every
     * permission it ever had, which is the opposite of what the administrator
     * asked for and impossible to notice from the screen they asked it on.
     */
    public function testAnEmptiedAdministratorRoleIsNotRescuedByItsName(): void
    {
        Capsule::table('role_permissions')->where('role_id', $this->roleId('admin'))->delete();

        $token = $this->signIn('boss@example.org');

        self::assertSame(403, $this->get('/api/admin/users', $token)->getStatusCode());
    }

    // ─── what the client is told ───

    public function testSigningInReturnsWhatTheAccountMayDo(): void
    {
        $body = $this->body($this->login('reader@example.org', self::PASSWORD));

        sort($body['user']['permissions']);

        self::assertSame(
            [Permission::REGISTRY_READ, Permission::REPORTS_READ],
            $body['user']['permissions'],
        );
    }

    /**
     * And it is a description, never a decision.
     *
     * The list exists so the management app can hide a link it would only be
     * refused on. Nothing server-side reads it back, which is what this checks:
     * the account is told it may do something, the grant is then removed, and
     * the route refuses anyway.
     */
    public function testTheListedPermissionsGrantNothingByThemselves(): void
    {
        $signIn = $this->body($this->login('boss@example.org', self::PASSWORD));

        self::assertContains(Permission::USERS_MANAGE, $signIn['user']['permissions']);

        $this->revoke('admin', Permission::USERS_MANAGE);

        self::assertSame(403, $this->get('/api/admin/users', $signIn['access_token'])->getStatusCode());
    }

    // ─── helpers ───

    private function roleId(string $roleKey): int
    {
        return (int) Capsule::table('roles')
            ->where('organization_id', $this->orgId)
            ->where('key', $roleKey)
            ->value('id');
    }

    private function grant(string $roleKey, string $permission): void
    {
        Capsule::table('role_permissions')->insertOrIgnore([
            ['role_id' => $this->roleId($roleKey), 'permission_key' => $permission],
        ]);
    }

    private function revoke(string $roleKey, string $permission): void
    {
        Capsule::table('role_permissions')
            ->where('role_id', $this->roleId($roleKey))
            ->where('permission_key', $permission)
            ->delete();
    }

    private function makeUser(string $email, string $roleKey): int
    {
        return (int) Capsule::table('users')->insertGetId([
            'organization_id'      => $this->orgId,
            'role_id'              => $this->roleId($roleKey),
            'email'                => $email,
            'password_hash'        => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'full_name'            => 'Test Person',
            'is_active'            => 1,
            'must_change_password' => 0,
        ]);
    }

    private function signIn(string $email): string
    {
        return $this->body($this->login($email, self::PASSWORD))['access_token'];
    }

    private function login(string $email, string $password): ResponseInterface
    {
        return $this->request('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, [], $token);
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
