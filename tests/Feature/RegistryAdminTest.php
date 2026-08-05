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
 * Managing the national list through the app.
 *
 * The cases that matter are the ones a two-level model would get wrong — an
 * arbitrary-depth hierarchy with country-specific level names — and the ones
 * where a shared registry could leak: it belongs to the programme, so an
 * organisation in a DIFFERENT programme must see none of it, while one in the
 * same programme sees all of it.
 */
final class RegistryAdminTest extends TestCase
{
    use MakesTenants;

    private const PASSWORD = 'correct-horse-battery';

    private int $orgId;
    private int $partnerOrgId;
    private int $foreignOrgId;

    protected function setUp(): void
    {
        Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['site_assignments', 'assessments', 'testing_sites', 'facilities', 'geo_units',
                    'refresh_tokens', 'users', 'roles', 'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgId = $this->makeTenant('reg-a', 'Ministry');
        $this->partnerOrgId = $this->makeTenant('reg-b', 'Partner');
        $this->foreignOrgId = $this->makeTenant('reg-z', 'Another Country');

        $this->shareProgramme($this->partnerOrgId, $this->orgId);

        foreach ([$this->orgId, $this->partnerOrgId, $this->foreignOrgId] as $org) {
            foreach (['admin', 'assessor', 'viewer'] as $key) {
                Capsule::table('roles')->insert([
                    'organization_id' => $org,
                    'key'             => $key,
                    'name'            => ucfirst($key),
                    'is_system'       => 1,
                ]);
            }
        }

        $this->makeUser($this->orgId, 'boss@example.org', 'admin');
        $this->makeUser($this->orgId, 'reader@example.org', 'viewer');
        $this->makeUser($this->orgId, 'joseph@example.org', 'assessor');
        $this->makeUser($this->partnerOrgId, 'partner@example.org', 'admin');
        $this->makeUser($this->foreignOrgId, 'foreign@example.org', 'admin');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    // ─── an arbitrary-depth hierarchy ───

    /**
     * Three levels, named for the country rather than for the code. A model
     * that hard-codes Province and District cannot express this at all.
     */
    public function testTheHierarchyTakesAnyDepthAndAnyLevelNames(): void
    {
        $token = $this->signIn('boss@example.org');

        $region = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'Region', 'name' => 'Oromia',
        ], $token), 'geo_unit');

        $zone = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'Zone', 'name' => 'Arsi', 'parent_id' => $region['id'],
        ], $token), 'geo_unit');

        $woreda = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'Woreda', 'name' => 'Tiyo', 'parent_id' => $zone['id'],
        ], $token), 'geo_unit');

        self::assertNull($region['parent_id']);
        self::assertSame($region['id'], $zone['parent_id']);
        self::assertSame($zone['id'], $woreda['parent_id']);
        self::assertSame('Woreda', $woreda['level']);

        $all = $this->body($this->get('/api/admin/geo-units', $token))['geo_units'];

        self::assertCount(3, $all);
    }

    public function testTwoPlacesWithTheSameNameUnderDifferentParentsAreFine(): void
    {
        $token = $this->signIn('boss@example.org');

        $north = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'Province', 'name' => 'Northern',
        ], $token), 'geo_unit');

        $south = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'Province', 'name' => 'Southern',
        ], $token), 'geo_unit');

        self::assertSame(201, $this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Central', 'parent_id' => $north['id'],
        ], $token)->getStatusCode());

        self::assertSame(201, $this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Central', 'parent_id' => $south['id'],
        ], $token)->getStatusCode());
    }

    /** Under the same parent it is a typo, not two places. */
    public function testTheSameNameTwiceUnderOneParentIsRefused(): void
    {
        $token = $this->signIn('boss@example.org');

        $province = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'Province', 'name' => 'Copperbelt',
        ], $token), 'geo_unit');

        $this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Kitwe', 'parent_id' => $province['id'],
        ], $token);

        $second = $this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Kitwe', 'parent_id' => $province['id'],
        ], $token);

        self::assertSame(422, $second->getStatusCode());
    }

    // ─── facilities and sites ───

    public function testAFacilityAndItsTestingSitesHangOffAPlace(): void
    {
        $token = $this->signIn('boss@example.org');

        $district = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Kitwe',
        ], $token), 'geo_unit');

        $facility = $this->created($this->post('/api/admin/facilities', [
            'name' => 'Kitwe Central Hospital', 'geo_unit_id' => $district['id'],
            'facility_type' => 'hospital', 'affiliation' => 'government',
        ], $token), 'facility');

        self::assertSame($district['id'], $facility['geo_unit_id']);
        self::assertSame('registry', $facility['source']);

        $site = $this->created($this->post('/api/admin/testing-sites', [
            'name' => 'TB clinic', 'facility_id' => $facility['id'],
        ], $token), 'testing_site');

        self::assertSame($facility['id'], $site['facility_id']);

        $inDistrict = $this->body(
            $this->get('/api/admin/facilities?geo_unit=' . $district['id'], $token),
        )['facilities'];

        self::assertCount(1, $inDistrict);
    }

    public function testAFacilityCannotBeHungOffAnotherProgrammesPlace(): void
    {
        $foreignDistrict = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Elsewhere',
        ], $this->signIn('foreign@example.org')), 'geo_unit');

        $response = $this->post('/api/admin/facilities', [
            'name' => 'Smuggled', 'geo_unit_id' => $foreignDistrict['id'],
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    // ─── the registry is shared inside a programme ───

    public function testAPartnerInTheSameProgrammeSeesTheSameRegistry(): void
    {
        $token = $this->signIn('boss@example.org');

        $district = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Kitwe',
        ], $token), 'geo_unit');

        $this->post('/api/admin/facilities', [
            'name' => 'Kitwe Central Hospital', 'geo_unit_id' => $district['id'],
        ], $token);

        $partner = $this->signIn('partner@example.org');

        self::assertCount(1, $this->body($this->get('/api/admin/geo-units', $partner))['geo_units']);
        self::assertCount(1, $this->body($this->get('/api/admin/facilities', $partner))['facilities']);
    }

    public function testAnotherProgrammeSeesNoneOfIt(): void
    {
        $token = $this->signIn('boss@example.org');

        $this->post('/api/admin/geo-units', ['level' => 'District', 'name' => 'Kitwe'], $token);

        $foreign = $this->signIn('foreign@example.org');

        self::assertCount(0, $this->body($this->get('/api/admin/geo-units', $foreign))['geo_units']);
    }

    // ─── who may read and who may write ───

    public function testAViewerCanReadTheRegistryButNotChangeIt(): void
    {
        $reader = $this->signIn('reader@example.org');

        self::assertSame(200, $this->get('/api/admin/geo-units', $reader)->getStatusCode());
        self::assertSame(403, $this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Sneaky',
        ], $reader)->getStatusCode());
    }

    public function testAnAssessorReachesNeither(): void
    {
        $joseph = $this->signIn('joseph@example.org');

        self::assertSame(403, $this->get('/api/admin/geo-units', $joseph)->getStatusCode());
        self::assertSame(403, $this->post('/api/admin/facilities', ['name' => 'x'], $joseph)->getStatusCode());
    }

    // ─── assigning a site to an assessor ───

    public function testAdminAssignsASiteToAnAssessorAndItShowsUpOnTheirList(): void
    {
        $token = $this->signIn('boss@example.org');

        $facility = $this->created($this->post('/api/admin/facilities', [
            'name' => 'Kitwe Central Hospital',
        ], $token), 'facility');

        $site = $this->created($this->post('/api/admin/testing-sites', [
            'name' => 'TB clinic', 'facility_id' => $facility['id'],
        ], $token), 'testing_site');

        $joseph = (int) Capsule::table('users')->where('email', 'joseph@example.org')->value('id');

        self::assertSame(201, $this->post('/api/admin/assignments', [
            'testing_site_id' => $site['id'],
            'user_id'         => $joseph,
        ], $token)->getStatusCode());

        $sites = $this->body($this->get('/api/sites', $this->signIn('joseph@example.org')))['sites'];
        $assigned = array_values(array_filter($sites, static fn (array $s): bool => $s['assigned_to_me']));

        self::assertCount(1, $assigned);
        self::assertSame('TB clinic', $assigned[0]['name']);
    }

    /**
     * The partner shares the registry and therefore sees the site, but the
     * plan is the assigning organisation's own.
     */
    public function testAPartnerOrganisationDoesNotInheritTheAssignment(): void
    {
        $token = $this->signIn('boss@example.org');

        $facility = $this->created($this->post('/api/admin/facilities', ['name' => 'Kitwe'], $token), 'facility');
        $site = $this->created($this->post('/api/admin/testing-sites', [
            'name' => 'TB clinic', 'facility_id' => $facility['id'],
        ], $token), 'testing_site');

        $this->post('/api/admin/assignments', ['testing_site_id' => $site['id']], $token);

        $partnerSites = $this->body($this->get('/api/sites', $this->signIn('partner@example.org')))['sites'];

        self::assertCount(1, $partnerSites, 'the site is shared');
        self::assertFalse($partnerSites[0]['assigned'], 'the assignment is not');
    }

    public function testWithdrawingAnAssignmentDeactivatesRatherThanDeletes(): void
    {
        $token = $this->signIn('boss@example.org');

        $facility = $this->created($this->post('/api/admin/facilities', ['name' => 'Kitwe'], $token), 'facility');
        $site = $this->created($this->post('/api/admin/testing-sites', [
            'name' => 'TB clinic', 'facility_id' => $facility['id'],
        ], $token), 'testing_site');

        $created = $this->body($this->post('/api/admin/assignments', [
            'testing_site_id' => $site['id'],
        ], $token))['assignment'];

        $this->request('DELETE', '/api/admin/assignments/' . $created['id'], [], $token);

        self::assertSame(1, Capsule::table('site_assignments')->count(), 'the row survives');
        self::assertSame(0, (int) Capsule::table('site_assignments')->value('is_active'));
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

    /** @return array<string,mixed> */
    private function created(ResponseInterface $response, string $key): array
    {
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->body($response)[$key];
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
        $parts = explode('?', $path, 2);

        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Content-Type', 'application/json');

        if (isset($parts[1])) {
            parse_str($parts[1], $query);
            $request = $request->withQueryParams($query);
        }

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
