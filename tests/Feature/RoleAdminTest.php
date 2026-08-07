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
 * Editing what a role may do.
 *
 * This is the screen that can be used to obtain every other permission, so
 * most of what follows is about the three guards that bound it rather than
 * about the editing itself. Each is tested for what it forbids AND for what it
 * still allows — a guard that refuses everything passes half a suite and makes
 * the screen useless.
 */
final class RoleAdminTest extends TestCase
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

        $this->orgId = $this->makeTenant('role-org', 'Role Org');
        $this->makeRoles($this->orgId);

        $this->makeUser('owner@example.org', 'superadmin');
        $this->makeUser('boss@example.org', 'admin');
        $this->makeUser('reader@example.org', 'viewer');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    // ─── reading ───

    public function testTheListSaysWhatEachRoleHoldsAndWhoHasIt(): void
    {
        $body = $this->body($this->get('/api/admin/roles', $this->signIn('boss@example.org')));

        $byKey = [];
        foreach ($body['roles'] as $role) {
            $byKey[$role['key']] = $role;
        }

        self::assertSame(5, count($byKey));
        self::assertContains(Permission::REPORTS_READ, $byKey['viewer']['permissions']);
        self::assertNotContains(Permission::REGISTRY_WRITE, $byKey['viewer']['permissions']);
        self::assertSame(1, $byKey['viewer']['user_count']);
        self::assertSame(0, $byKey['assessor']['user_count']);
    }

    /** The catalogue comes from the server, so a new permission needs no rebuild. */
    public function testTheListCarriesTheWholeCatalogueAndWhatTheActorMayGrant(): void
    {
        $body = $this->body($this->get('/api/admin/roles', $this->signIn('boss@example.org')));

        self::assertContains(Permission::ORGANIZATIONS_MANAGE, $body['catalogue']);

        // An administrator does not hold it, so it is in the catalogue and not
        // in what they may hand out.
        self::assertNotContains(Permission::ORGANIZATIONS_MANAGE, $body['grantable']);
        self::assertContains(Permission::REGISTRY_WRITE, $body['grantable']);
    }

    public function testAnAdministratorIsToldTheSuperadminRoleIsNotTheirsToEdit(): void
    {
        $body = $this->body($this->get('/api/admin/roles', $this->signIn('boss@example.org')));

        foreach ($body['roles'] as $role) {
            self::assertSame(
                $role['key'] !== 'superadmin',
                $role['editable'],
                $role['key'] . ' editable flag',
            );
        }
    }

    public function testAViewerCannotReachThisAtAll(): void
    {
        $response = $this->get('/api/admin/roles', $this->signIn('reader@example.org'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('permission_required', $this->body($response)['error']['code']);
    }

    // ─── editing ───

    public function testGrantingAPermissionOpensTheRouteForEverybodyHoldingThatRole(): void
    {
        $token = $this->signIn('reader@example.org');

        self::assertSame(403, $this->request('POST', '/api/admin/geo-units', [
            'name'  => 'Copperbelt',
            'level' => 1,
        ], $token)->getStatusCode());

        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId('viewer') . '/permissions', [
            'permissions' => [
                Permission::REGISTRY_READ,
                Permission::REPORTS_READ,
                Permission::REGISTRY_WRITE,
            ],
        ], $this->signIn('boss@example.org'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertContains(Permission::REGISTRY_WRITE, $this->body($response)['role']['permissions']);

        // The proof is the route, not the row. Same token as before the change.
        self::assertSame(201, $this->request('POST', '/api/admin/geo-units', [
            'name'  => 'Copperbelt',
            'level' => 1,
        ], $token)->getStatusCode());
    }

    public function testRemovingAPermissionClosesIt(): void
    {
        $this->setPermissions('viewer', [Permission::REGISTRY_READ], 'boss@example.org');

        self::assertSame(
            403,
            $this->get('/api/admin/reports/assessments', $this->signIn('reader@example.org'))->getStatusCode(),
        );
    }

    public function testAnUnknownPermissionIsRefusedRatherThanStored(): void
    {
        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId('viewer') . '/permissions', [
            'permissions' => ['registry.read', 'registry.destroy'],
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('registry.destroy', $this->body($response)['error']['message']);
        self::assertContains(
            Permission::REPORTS_READ,
            $this->permissionsOf('viewer'),
            'the whole request is refused, not partly applied',
        );
    }

    // ─── guard: nobody may grant what they do not hold ───

    public function testAnAdministratorCannotGrantAPermissionTheyDoNotHold(): void
    {
        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId('viewer') . '/permissions', [
            'permissions' => [Permission::ORGANIZATIONS_MANAGE],
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertNotContains(Permission::ORGANIZATIONS_MANAGE, $this->permissionsOf('viewer'));
    }

    /**
     * Including onto their own role, which is the escalation the guard exists
     * for. Without it, one request turns an administrator into a superadmin.
     */
    public function testAnAdministratorCannotGrantThemselvesTheOnePermissionTheyLack(): void
    {
        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId('admin') . '/permissions', [
            'permissions' => [...$this->permissionsOf('admin'), Permission::ORGANIZATIONS_MANAGE],
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertNotContains(Permission::ORGANIZATIONS_MANAGE, $this->permissionsOf('admin'));
    }

    /** A superadmin does hold it, so for them it is an ordinary edit. */
    public function testASuperadminCanGrantIt(): void
    {
        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId('viewer') . '/permissions', [
            'permissions' => [Permission::ORGANIZATIONS_MANAGE],
        ], $this->signIn('owner@example.org'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertContains(Permission::ORGANIZATIONS_MANAGE, $this->permissionsOf('viewer'));
    }

    // ─── guard: nobody may edit a role that outranks theirs ───

    public function testAnAdministratorCannotEditTheSuperadminRole(): void
    {
        $before = $this->permissionsOf('superadmin');

        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId('superadmin') . '/permissions', [
            'permissions' => [Permission::REPORTS_READ],
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, $this->permissionsOf('superadmin'));
    }

    public function testAnAdministratorCanStillEditTheirOwnRole(): void
    {
        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId('admin') . '/permissions', [
            'permissions' => [
                Permission::ROLES_MANAGE,
                Permission::USERS_MANAGE,
                Permission::REPORTS_READ,
            ],
        ], $this->signIn('boss@example.org'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotContains(Permission::REGISTRY_WRITE, $this->permissionsOf('admin'));
    }

    // ─── guard: nobody may lock themselves out ───

    public function testYouCannotTakeRoleManagementOffYourOwnRole(): void
    {
        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId('admin') . '/permissions', [
            'permissions' => [Permission::USERS_MANAGE, Permission::REPORTS_READ],
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('your own role', $this->body($response)['error']['message']);
        self::assertContains(Permission::ROLES_MANAGE, $this->permissionsOf('admin'));
    }

    /**
     * But somebody above you may, because they can put it back.
     *
     * This is what distinguishes the lockout guard from a blanket refusal. The
     * change is only irreversible when the person making it is the one who
     * would have to undo it.
     */
    public function testASuperadminCanTakeItOffTheAdministratorRole(): void
    {
        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId('admin') . '/permissions', [
            'permissions' => [Permission::USERS_MANAGE, Permission::REPORTS_READ],
        ], $this->signIn('owner@example.org'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotContains(Permission::ROLES_MANAGE, $this->permissionsOf('admin'));

        // And the administrator is now shut out of the screen entirely.
        self::assertSame(
            403,
            $this->get('/api/admin/roles', $this->signIn('boss@example.org'))->getStatusCode(),
        );
    }

    // ─── tenancy ───

    public function testARoleInAnotherOrganisationCannotBeTouched(): void
    {
        $otherOrg = $this->makeTenant('other-role-org', 'Other');
        $this->makeRoles($otherOrg, 'viewer');

        $outsiderRole = (int) Capsule::table('roles')
            ->where('organization_id', $otherOrg)
            ->where('key', 'viewer')
            ->value('id');

        $response = $this->request('PATCH', '/api/admin/roles/' . $outsiderRole . '/permissions', [
            'permissions' => [Permission::USERS_MANAGE],
        ], $this->signIn('owner@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            0,
            Capsule::table('role_permissions')
                ->where('role_id', $outsiderRole)
                ->where('permission_key', Permission::USERS_MANAGE)
                ->count(),
        );
    }

    // ─── helpers ───

    private function roleId(string $roleKey): int
    {
        return (int) Capsule::table('roles')
            ->where('organization_id', $this->orgId)
            ->where('key', $roleKey)
            ->value('id');
    }

    /** @return list<string> */
    private function permissionsOf(string $roleKey): array
    {
        return array_values(Capsule::table('role_permissions')
            ->where('role_id', $this->roleId($roleKey))
            ->pluck('permission_key')
            ->all());
    }

    /** @param list<string> $permissions */
    private function setPermissions(string $roleKey, array $permissions, string $actor): void
    {
        $response = $this->request('PATCH', '/api/admin/roles/' . $this->roleId($roleKey) . '/permissions', [
            'permissions' => $permissions,
        ], $this->signIn($actor));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
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
        return $this->body($this->request('POST', '/api/auth/login', [
            'email'    => $email,
            'password' => self::PASSWORD,
        ]))['access_token'];
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
