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
 * Managing the organisations that audit in one country.
 *
 * This is the only surface where an administrator of one tenant legitimately
 * reaches another tenant's row, so the boundary is what gets tested: a
 * superadmin sees and manages their own programme's organisations and nothing
 * beyond it, and even inside it they see counts rather than assessments.
 */
final class OrganizationAdminTest extends TestCase
{
    use MakesTenants;

    private const PASSWORD = 'correct-horse-battery';

    private int $ministry;
    private int $partner;
    private int $abroad;

    protected function setUp(): void
    {
        Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['site_assignments', 'assessments', 'testing_sites', 'facilities', 'geo_units',
                    'templates', 'refresh_tokens', 'users', 'role_permissions', 'roles', 'organizations',
                    'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->ministry = $this->makeTenant('zm-moh', 'Ministry of Health');
        $this->partner = $this->makeTenant('zm-partner', 'Implementing Partner');
        $this->abroad = $this->makeTenant('ke-moh', 'Kenya MoH');

        $this->shareProgramme($this->partner, $this->ministry);

        foreach ([$this->ministry, $this->partner, $this->abroad] as $org) {
            $this->makeRoles($org);
        }

        $this->makeUser($this->ministry, 'owner@example.org', 'superadmin');
        $this->makeUser($this->ministry, 'boss@example.org', 'admin');
        $this->makeUser($this->partner, 'partner@example.org', 'admin');
        $this->makeUser($this->abroad, 'kenya@example.org', 'superadmin');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    // ─── who may ───

    public function testASuperadminSeesEveryOrganisationInTheirProgramme(): void
    {
        $body = $this->body($this->get('/api/admin/organizations', $this->signIn('owner@example.org')));

        $codes = array_column($body['organizations'], 'code');
        sort($codes);

        self::assertCount(2, $body['organizations']);
        self::assertSame(['zm-moh', 'zm-partner'], $codes);
    }

    /** The country next door is a different programme and is not theirs. */
    public function testASuperadminSeesNothingFromAnotherProgramme(): void
    {
        $body = $this->body($this->get('/api/admin/organizations', $this->signIn('kenya@example.org')));

        self::assertCount(1, $body['organizations']);
        self::assertSame('ke-moh', $body['organizations'][0]['code']);
    }

    public function testAnOrdinaryAdministratorCannotReachThisAtAll(): void
    {
        $response = $this->get('/api/admin/organizations', $this->signIn('boss@example.org'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('permission_required', $this->body($response)['error']['code']);
    }

    // ─── adding one ───

    public function testAddingAnOrganisationCreatesItsAdministratorTooAndTheyCanSignIn(): void
    {
        $response = $this->post('/api/admin/organizations', [
            'code'        => 'zm-lab',
            'name'        => 'National Reference Lab',
            'admin_email' => 'lab@example.org',
            'admin_name'  => 'Lab Lead',
        ], $this->signIn('owner@example.org'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $password = $this->body($response)['password'];

        self::assertNotSame('', $password);

        $signIn = $this->post('/api/auth/login', [
            'email'    => 'lab@example.org',
            'password' => $password,
        ]);

        self::assertSame(200, $signIn->getStatusCode());
        self::assertTrue($this->body($signIn)['user']['must_change_password']);
    }

    /**
     * The new organisation joins the creator's programme, and that is taken
     * from the token — there is no programme in the body to point elsewhere.
     */
    public function testANewOrganisationJoinsTheCreatorsProgramme(): void
    {
        $this->post('/api/admin/organizations', [
            'code'         => 'zm-lab',
            'name'         => 'National Reference Lab',
            'admin_email'  => 'lab@example.org',
            'admin_name'   => 'Lab Lead',
            // Ignored: naming somebody else's programme must not move it there.
            'programme_id' => $this->programmeFor($this->abroad),
        ], $this->signIn('owner@example.org'));

        self::assertSame(
            $this->programmeFor($this->ministry),
            (int) Capsule::table('organizations')->where('code', 'zm-lab')->value('programme_id'),
        );
    }

    /**
     * Codes disambiguate an address that exists in more than one organisation
     * at sign-in, so two the same would make that ambiguous again — including
     * across programmes.
     */
    public function testACodeAlreadyUsedInAnotherProgrammeIsRefused(): void
    {
        $response = $this->post('/api/admin/organizations', [
            'code'        => 'ke-moh',
            'name'        => 'Something Else',
            'admin_email' => 'other@example.org',
            'admin_name'  => 'Other',
        ], $this->signIn('owner@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testTheNewOrganisationGetsItsOwnSystemRoles(): void
    {
        $this->post('/api/admin/organizations', [
            'code'        => 'zm-lab',
            'name'        => 'National Reference Lab',
            'admin_email' => 'lab@example.org',
            'admin_name'  => 'Lab Lead',
        ], $this->signIn('owner@example.org'));

        $newId = (int) Capsule::table('organizations')->where('code', 'zm-lab')->value('id');

        self::assertSame(5, Capsule::table('roles')->where('organization_id', $newId)->count());
    }

    // ─── changing one ───

    public function testAnOrganisationInAnotherProgrammeCannotBeTouched(): void
    {
        $response = $this->request('PATCH', '/api/admin/organizations/' . $this->abroad, [
            'is_active' => false,
        ], $this->signIn('owner@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            1,
            (int) Capsule::table('organizations')->where('id', $this->abroad)->value('is_active'),
        );
    }

    public function testDeactivatingAPartnerStopsItsPeopleSigningIn(): void
    {
        $response = $this->request('PATCH', '/api/admin/organizations/' . $this->partner, [
            'is_active' => false,
        ], $this->signIn('owner@example.org'));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->body($response)['organization']['is_active']);
    }

    /** Switching yourself off signs you out of the tool you would need to undo it. */
    public function testYouCannotSwitchOffYourOwnOrganisation(): void
    {
        $response = $this->request('PATCH', '/api/admin/organizations/' . $this->ministry, [
            'is_active' => false,
        ], $this->signIn('owner@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    /** Counts, not contents: whose assessments they are stays private. */
    public function testTheListReportsCountsWithoutExposingAssessments(): void
    {
        $body = $this->body($this->get('/api/admin/organizations', $this->signIn('owner@example.org')));
        $row = $body['organizations'][0];

        self::assertArrayHasKey('user_count', $row);
        self::assertArrayHasKey('assessments', $row);
        self::assertArrayNotHasKey('users', $row);
    }

    // ─── fixtures ───

    private function makeUser(int $organizationId, string $email, string $roleKey): int
    {
        return (int) Capsule::table('users')->insertGetId([
            'organization_id' => $organizationId,
            'role_id'         => (int) Capsule::table('roles')
                ->where('organization_id', $organizationId)
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
        return $this->body(
            $this->post('/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]),
        )['access_token'];
    }

    /** @param array<string,mixed> $payload */
    private function post(string $path, array $payload, ?string $token = null): ResponseInterface
    {
        return $this->request('POST', $path, $payload, $token);
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
