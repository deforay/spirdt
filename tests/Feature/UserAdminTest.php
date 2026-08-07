<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\MakesTenants;

/**
 * Managing people through the app rather than a shell.
 *
 * Most of what follows is about one accident: an organisation ending up with
 * nobody able to administer it. That is the situation bin/recover-access
 * exists to dig out of, and every route it takes to get there is reachable
 * from this screen — demote yourself while tidying up, switch off the wrong
 * account, hand the last admin role to somebody and take it back.
 */
final class UserAdminTest extends TestCase
{
    use MakesTenants;

    private const PASSWORD = 'correct-horse-battery';

    private int $orgId;
    private int $boss;
    private int $assessor;

    protected function setUp(): void
    {
        Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['login_attempts', 'refresh_tokens', 'users', 'role_permissions', 'roles', 'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgId = $this->makeTenant('ua-org', 'User Admin Org');

        $this->makeRoles($this->orgId);

        $this->boss = $this->makeUser('boss@example.org', 'admin');
        $this->assessor = $this->makeUser('joseph@example.org', 'assessor');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    // ─── the role gate ───

    public function testAnAssessorCannotReachUserAdministration(): void
    {
        $response = $this->request('GET', '/api/admin/users', token: $this->signIn('joseph@example.org'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('permission_required', $this->body($response)['error']['code']);
    }

    public function testAnAdministratorSeesTheOrganisation(): void
    {
        $response = $this->request('GET', '/api/admin/users', token: $this->signIn('boss@example.org'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $this->body($response)['users']);
    }

    // ─── adding people ───

    public function testAddingSomebodyReturnsAPasswordOnceAndFlagsIt(): void
    {
        $response = $this->request('POST', '/api/admin/users', [
            'email'     => 'mary@example.org',
            'full_name' => 'Mary Banda',
            'role'      => 'assessor',
        ], $this->signIn('boss@example.org'));

        self::assertSame(201, $response->getStatusCode());

        $body = $this->body($response);

        self::assertNotSame('', $body['password']);
        self::assertTrue($body['user']['must_change_password']);

        // And it works, which is the only proof that matters.
        self::assertSame(200, $this->login('mary@example.org', $body['password'])->getStatusCode());
    }

    public function testTheSameAddressTwiceIsRefused(): void
    {
        $response = $this->request('POST', '/api/admin/users', [
            'email'     => 'joseph@example.org',
            'full_name' => 'Another Joseph',
            'role'      => 'assessor',
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * An administrator who can mint a superadmin holds the role in all but
     * name, and the distinction stops meaning anything.
     */
    public function testAnAdministratorCannotCreateASuperadmin(): void
    {
        $response = $this->request('POST', '/api/admin/users', [
            'email'     => 'root@example.org',
            'full_name' => 'Root',
            'role'      => 'superadmin',
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('superadmin', $this->body($response)['error']['message']);
    }

    public function testASuperadminCan(): void
    {
        $this->makeUser('sa@example.org', 'superadmin');

        $response = $this->request('POST', '/api/admin/users', [
            'email'     => 'root@example.org',
            'full_name' => 'Root',
            'role'      => 'superadmin',
        ], $this->signIn('sa@example.org'));

        self::assertSame(201, $response->getStatusCode());
    }

    // ─── the lockout guards ───

    public function testYouCannotRemoveYourOwnAdminRole(): void
    {
        // Even with somebody else to fall back on: this is the tidying-up
        // accident, and the person making it is the one who cannot undo it.
        $this->makeUser('second@example.org', 'admin');

        $response = $this->request('PATCH', '/api/admin/users/' . $this->boss, [
            'role' => 'assessor',
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('your own', $this->body($response)['error']['message']);
    }

    public function testYouCannotSwitchYourselfOff(): void
    {
        $this->makeUser('second@example.org', 'admin');

        $response = $this->request('PATCH', '/api/admin/users/' . $this->boss, [
            'is_active' => false,
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * The self-guard is the whole of the protection, and this is why it is
     * enough: whoever is demoting somebody had to be an administrator to get
     * here, and cannot be demoting themselves, so they are still an
     * administrator afterwards. The role cannot be emptied.
     *
     * Written as a sequence rather than an assertion about one call, because
     * the claim is about what a series of tidying-up changes can reach.
     */
    public function testOneAdministratorAlwaysSurvivesAnyChain(): void
    {
        $second = $this->makeUser('second@example.org', 'admin');

        // Each demotes the other in turn; each is refused at the point where
        // the only move left is on themselves.
        self::assertSame(200, $this->request('PATCH', '/api/admin/users/' . $this->boss, [
            'role' => 'assessor',
        ], $this->signIn('second@example.org'))->getStatusCode());

        self::assertSame(422, $this->request('PATCH', '/api/admin/users/' . $second, [
            'role' => 'viewer',
        ], $this->signIn('second@example.org'))->getStatusCode());

        self::assertSame(
            1,
            Capsule::table('users')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('users.organization_id', $this->orgId)
                ->where('users.is_active', 1)
                ->whereIn('roles.key', ['admin', 'superadmin'])
                ->count(),
            'somebody can still administer this organisation',
        );
    }

    public function testAnOrdinaryDemotionIsAllowed(): void
    {
        $response = $this->request('PATCH', '/api/admin/users/' . $this->assessor, [
            'role'      => 'viewer',
            'full_name' => 'Joseph Banda',
        ], $this->signIn('boss@example.org'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('viewer', $this->body($response)['user']['role']);
        self::assertSame('Joseph Banda', $this->body($response)['user']['full_name']);
    }

    public function testDeactivatingSomebodyStopsThemSigningIn(): void
    {
        $this->request('PATCH', '/api/admin/users/' . $this->assessor, [
            'is_active' => false,
        ], $this->signIn('boss@example.org'));

        self::assertSame(403, $this->login('joseph@example.org', self::PASSWORD)->getStatusCode());
    }

    // ─── resetting somebody's password ───

    public function testResettingIssuesANewPasswordAndEndsTheirSessions(): void
    {
        $stolen = $this->body($this->login('joseph@example.org', self::PASSWORD))['refresh_token'];

        $response = $this->request(
            'POST',
            '/api/admin/users/' . $this->assessor . '/password',
            token: $this->signIn('boss@example.org'),
        );

        self::assertSame(200, $response->getStatusCode());

        $password = $this->body($response)['password'];

        self::assertSame(401, $this->login('joseph@example.org', self::PASSWORD)->getStatusCode());
        self::assertSame(200, $this->login('joseph@example.org', $password)->getStatusCode());

        $refresh = $this->request('POST', '/api/auth/refresh', ['refresh_token' => $stolen]);

        self::assertSame(401, $refresh->getStatusCode());
    }

    // ─── an administrator may not outrank themselves ───

    /**
     * The three ways an admin could have taken a superadmin's place.
     *
     * resolveAssignableRole() guarded which role may be HANDED OUT, and that
     * looked like the whole rule. It is not: every one of these hands out
     * nothing at all. Resetting the password takes the account directly.
     * Demoting and deactivating take the organisation by removing the only
     * person who outranks the actor, which is the same end reached backwards.
     */
    public function testAnAdministratorCannotResetASuperadminsPassword(): void
    {
        $superadmin = $this->makeUser('sa@example.org', 'superadmin');

        $response = $this->request(
            'POST',
            '/api/admin/users/' . $superadmin . '/password',
            token: $this->signIn('boss@example.org'),
        );

        self::assertSame(422, $response->getStatusCode());

        // The password is untouched, so the account is not now shared.
        self::assertSame(200, $this->login('sa@example.org', self::PASSWORD)->getStatusCode());
    }

    public function testAnAdministratorCannotDemoteASuperadmin(): void
    {
        $superadmin = $this->makeUser('sa@example.org', 'superadmin');

        $response = $this->request('PATCH', '/api/admin/users/' . $superadmin, [
            'role' => 'assessor',
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('superadmin', $this->roleOf($superadmin));
    }

    public function testAnAdministratorCannotDeactivateASuperadmin(): void
    {
        $superadmin = $this->makeUser('sa@example.org', 'superadmin');

        $response = $this->request('PATCH', '/api/admin/users/' . $superadmin, [
            'is_active' => false,
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(200, $this->login('sa@example.org', self::PASSWORD)->getStatusCode());
    }

    /** A superadmin outranks an admin, so the same operations are theirs to make. */
    public function testASuperadminCanResetAnAdministratorsPassword(): void
    {
        $this->makeUser('sa@example.org', 'superadmin');

        $response = $this->request(
            'POST',
            '/api/admin/users/' . $this->boss . '/password',
            token: $this->signIn('sa@example.org'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /** Two admins are peers, and peers administer each other. */
    public function testAnAdministratorCanResetAnotherAdministratorsPassword(): void
    {
        $second = $this->makeUser('second@example.org', 'admin');

        $response = $this->request(
            'POST',
            '/api/admin/users/' . $second . '/password',
            token: $this->signIn('boss@example.org'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    // ─── a token stops being a licence the moment the account changes ───

    /**
     * The access token carries the role, and it was believed on its own. So an
     * administrator demoted while holding one could spend the rest of its life
     * — a quarter of an hour — using it to put themselves back, and the
     * demotion had no effect at all.
     */
    public function testADemotedAdministratorCannotUseTheTokenTheyAlreadyHad(): void
    {
        $second = $this->makeUser('second@example.org', 'admin');
        $theirToken = $this->signIn('second@example.org');

        $this->request('PATCH', '/api/admin/users/' . $second, [
            'role' => 'assessor',
        ], $this->signIn('boss@example.org'));

        $response = $this->request('PATCH', '/api/admin/users/' . $second, [
            'role' => 'admin',
        ], $theirToken);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('assessor', $this->roleOf($second));
    }

    public function testADeactivatedUserCannotUseTheTokenTheyAlreadyHad(): void
    {
        $theirToken = $this->signIn('joseph@example.org');

        $this->request('PATCH', '/api/admin/users/' . $this->assessor, [
            'is_active' => false,
        ], $this->signIn('boss@example.org'));

        self::assertSame(401, $this->request('GET', '/api/sites', token: $theirToken)->getStatusCode());
    }

    /**
     * A reset exists to take an account away from whoever has its password.
     * Revoking the refresh token alone left the access token they were already
     * holding good for another fifteen minutes — which is exactly the window
     * the reset was called to close.
     */
    public function testAResetEndsTheAccessTokenAsWellAsTheRefreshToken(): void
    {
        $stolen = $this->signIn('joseph@example.org');

        $this->request(
            'POST',
            '/api/admin/users/' . $this->assessor . '/password',
            token: $this->signIn('boss@example.org'),
        );

        $response = $this->request('GET', '/api/sites', token: $stolen);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('password_change_required', $this->body($response)['error']['code']);
    }

    // ─── tenancy ───

    public function testAnAdministratorCannotTouchAnotherOrganisationsUser(): void
    {
        $otherOrg = $this->makeTenant('other-org', 'Other');

        $this->makeRoles($otherOrg, 'assessor');

        $outsider = (int) Capsule::table('users')->insertGetId([
            'organization_id' => $otherOrg,
            'role_id'         => (int) Capsule::table('roles')
                ->where('organization_id', $otherOrg)->where('key', 'assessor')->value('id'),
            'email'           => 'outsider@example.org',
            'password_hash'   => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'full_name'       => 'Outsider',
            'is_active'       => 1,
        ]);

        $response = $this->request('PATCH', '/api/admin/users/' . $outsider, [
            'role' => 'viewer',
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());

        // Unchanged, and still pointing at the OTHER organisation's role row.
        self::assertSame(
            'assessor',
            (string) Capsule::table('roles')
                ->where('id', (int) Capsule::table('users')->where('id', $outsider)->value('role_id'))
                ->value('key'),
        );
        self::assertSame(
            $otherOrg,
            (int) Capsule::table('users')->where('id', $outsider)->value('organization_id'),
        );
    }

    private function roleOf(int $userId): string
    {
        return (string) Capsule::table('roles')
            ->where('id', (int) Capsule::table('users')->where('id', $userId)->value('role_id'))
            ->value('key');
    }

    // ─── fixtures ───

    private function makeUser(string $email, string $roleKey): int
    {
        return (int) Capsule::table('users')->insertGetId([
            'organization_id' => $this->orgId,
            'role_id'         => (int) Capsule::table('roles')
                ->where('organization_id', $this->orgId)
                ->where('key', $roleKey)
                ->value('id'),
            'email'           => $email,
            'password_hash'   => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'full_name'       => ucfirst(strtok($email, '@') ?: $email),
            'is_active'       => 1,
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
