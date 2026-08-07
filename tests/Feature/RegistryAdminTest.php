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
                    'templates', 'refresh_tokens', 'users', 'role_permissions', 'roles', 'organizations',
                    'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgId = $this->makeTenant('reg-a', 'Ministry');
        $this->partnerOrgId = $this->makeTenant('reg-b', 'Partner');
        $this->foreignOrgId = $this->makeTenant('reg-z', 'Another Country');

        $this->shareProgramme($this->partnerOrgId, $this->orgId);

        // Seeded here rather than relied on. The facility option lists are read
        // from the published instrument, and a suite that happened to leave a
        // stub template behind made this pass alone and fail in company.
        Capsule::table('templates')->insert([
            'organization_id' => null,
            'code'            => 'spi-rdt',
            'version'         => '1.0.0',
            'title'           => 'SPI-RDT',
            'definition'      => (string) file_get_contents(
                dirname(__DIR__, 2) . '/resources/templates/spi-rdt-1.0.0.json',
            ),
            'status'          => 'published',
        ]);

        foreach ([$this->orgId, $this->partnerOrgId, $this->foreignOrgId] as $org) {
            $this->makeRoles($org, 'admin', 'assessor', 'viewer');
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
        );

        self::assertCount(1, $inDistrict['rows']);
        self::assertSame(1, $inDistrict['total']);
        self::assertSame('Kitwe', $inDistrict['rows'][0]['place']);
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

    /**
     * Facilities hang off districts, so filtering on an exact geo_unit_id
     * matched nothing when a province was chosen — the list simply came back
     * empty and looked like a country with no facilities in it.
     */
    public function testChoosingAProvinceFindsFacilitiesInItsDistricts(): void
    {
        $token = $this->signIn('boss@example.org');

        $province = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'Province', 'name' => 'Copperbelt',
        ], $token), 'geo_unit');

        $district = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Kitwe', 'parent_id' => $province['id'],
        ], $token), 'geo_unit');

        $this->post('/api/admin/facilities', [
            'name' => 'Kitwe Central Hospital', 'geo_unit_id' => $district['id'],
        ], $token);

        $inProvince = $this->body(
            $this->get('/api/admin/facilities?geo_unit=' . $province['id'], $token),
        );

        self::assertSame(1, $inProvince['total'], 'a province means everything under it');
        self::assertSame('Copperbelt › Kitwe', $inProvince['rows'][0]['place']);
    }

    public function testFacilitiesCanBeSearchedByName(): void
    {
        $token = $this->signIn('boss@example.org');

        foreach (['Kitwe Central Hospital', 'Ndola Teaching Hospital', 'Chingola Clinic'] as $name) {
            $this->post('/api/admin/facilities', ['name' => $name], $token);
        }

        $found = $this->body($this->get('/api/admin/facilities?q=hospital', $token));

        self::assertSame(2, $found['total']);
    }

    /** A national registry is thousands of rows; nothing returns all of them. */
    public function testFacilitiesArePaginatedAndReportTheTotal(): void
    {
        $token = $this->signIn('boss@example.org');

        foreach (range(1, 7) as $n) {
            $this->post('/api/admin/facilities', ['name' => 'Facility ' . $n], $token);
        }

        $page = $this->body($this->get('/api/admin/facilities?per_page=3&page=2', $token));

        self::assertCount(3, $page['rows']);
        self::assertSame(7, $page['total']);
        self::assertSame(2, $page['page']);
    }

    /**
     * Every site in a district in ONE request. Without this the caller had to
     * fetch the district's facilities and then ask per facility, which in a
     * district of two hundred facilities is two hundred requests.
     */
    public function testTestingSitesCanBeFetchedForAWholePlaceAtOnce(): void
    {
        $token = $this->signIn('boss@example.org');

        $district = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Kitwe',
        ], $token), 'geo_unit');

        foreach (['Kitwe Central Hospital', 'Kitwe Clinic'] as $name) {
            $facility = $this->created($this->post('/api/admin/facilities', [
                'name' => $name, 'geo_unit_id' => $district['id'],
            ], $token), 'facility');

            $this->post('/api/admin/testing-sites', [
                'name' => 'TB clinic', 'facility_id' => $facility['id'],
            ], $token);
        }

        $found = $this->body($this->get('/api/admin/testing-sites?geo_unit=' . $district['id'], $token));

        self::assertSame(2, $found['total']);
        self::assertSame('Kitwe', $found['rows'][0]['place']);
        self::assertNotNull($found['rows'][0]['facility_name']);
    }

    // ─── the full facility record ───

    public function testAFacilityCarriesItsCodeContactsAndCoordinates(): void
    {
        $token = $this->signIn('boss@example.org');

        $facility = $this->created($this->post('/api/admin/facilities', [
            'name'          => 'Kitwe Central Hospital',
            'code'          => 'ZM-CB-001',
            'contact_name'  => 'Grace Phiri',
            'contact_phone' => '+260 21 1234567',
            'contact_email' => 'lab@kitwe.example.org',
            'latitude'      => -12.8024,
            'longitude'     => 28.2132,
        ], $token), 'facility');

        self::assertSame('ZM-CB-001', $facility['code']);
        self::assertSame('Grace Phiri', $facility['contact_name']);
        self::assertSame('lab@kitwe.example.org', $facility['contact_email']);
        self::assertEqualsWithDelta(-12.8024, $facility['latitude'], 0.0000001);
    }

    /**
     * A malformed address is worse than a blank one: it looks like a way to
     * reach somebody right up until the message bounces.
     */
    public function testAMalformedContactEmailIsRefused(): void
    {
        $response = $this->post('/api/admin/facilities', [
            'name'          => 'Somewhere',
            'contact_email' => 'not-an-address',
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    /** Catches latitude and longitude entered the wrong way round, sometimes. */
    public function testACoordinateOutOfRangeIsRefused(): void
    {
        $response = $this->post('/api/admin/facilities', [
            'name'     => 'Somewhere',
            'latitude' => 128.5,
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testTypeAndAffiliationOptionsComeFromThePublishedInstrument(): void
    {
        $options = $this->body($this->get('/api/admin/facility-options', $this->signIn('boss@example.org')))['options'];

        self::assertNotEmpty($options['facility_type']);
        self::assertContains(
            'hospital_bedside',
            array_column($options['facility_type'], 'key'),
        );
        self::assertContains('government', array_column($options['affiliation'], 'key'));
    }

    // ─── merging a duplicate ───

    /**
     * The same building entered twice, which the design invites: an assessor
     * arriving somewhere unlisted creates it on the spot.
     */
    public function testMergingMovesTheTestingSitesAndKeepsTheLoser(): void
    {
        $token = $this->signIn('boss@example.org');

        $keep = $this->created($this->post('/api/admin/facilities', [
            'name' => 'Kitwe Central Hospital',
        ], $token), 'facility');

        $duplicate = $this->created($this->post('/api/admin/facilities', [
            'name' => 'Kitwe Central Hosp.', 'code' => 'ZM-CB-001',
        ], $token), 'facility');

        $this->post('/api/admin/testing-sites', [
            'name' => 'TB clinic', 'facility_id' => $duplicate['id'],
        ], $token);

        $response = $this->post('/api/admin/facilities/' . $duplicate['id'] . '/merge', [
            'into' => $keep['id'],
        ], $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // The site moved, so assessments through it still resolve.
        $sites = $this->body($this->get('/api/admin/testing-sites?facility=' . $keep['id'], $token));

        self::assertSame(1, $sites['total']);

        // The loser survives, deactivated and pointing at the survivor.
        $loser = Capsule::table('facilities')
            ->where('id', hex2bin(str_replace('-', '', $duplicate['id'])))
            ->first();

        self::assertNotNull($loser);
        self::assertSame(0, (int) $loser->is_active);
        self::assertNotNull($loser->merged_into_id);

        // And the survivor took the code it was missing, without losing its name.
        self::assertSame('ZM-CB-001', $this->body($response)['facility']['code']);
        self::assertSame('Kitwe Central Hospital', $this->body($response)['facility']['name']);
    }

    public function testAFacilityCannotBeMergedIntoItself(): void
    {
        $token = $this->signIn('boss@example.org');

        $facility = $this->created($this->post('/api/admin/facilities', ['name' => 'One'], $token), 'facility');

        $response = $this->post('/api/admin/facilities/' . $facility['id'] . '/merge', [
            'into' => $facility['id'],
        ], $token);

        self::assertSame(422, $response->getStatusCode());
    }

    /** A chain would make "which facility is this really?" a walk of unknown length. */
    public function testMergingIntoAnAlreadyMergedFacilityIsRefused(): void
    {
        $token = $this->signIn('boss@example.org');

        $keep = $this->created($this->post('/api/admin/facilities', ['name' => 'Keep'], $token), 'facility');
        $first = $this->created($this->post('/api/admin/facilities', ['name' => 'First'], $token), 'facility');
        $second = $this->created($this->post('/api/admin/facilities', ['name' => 'Second'], $token), 'facility');

        $this->post('/api/admin/facilities/' . $first['id'] . '/merge', ['into' => $keep['id']], $token);

        $response = $this->post('/api/admin/facilities/' . $second['id'] . '/merge', [
            'into' => $first['id'],
        ], $token);

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
        self::assertCount(1, $this->body($this->get('/api/admin/facilities', $partner))['rows']);
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

    // ─── scope on the things an assignment names ───

    /**
     * The foreign keys are global, so they accept any id that exists anywhere.
     * A site from another PROGRAMME therefore lands happily on a plan that
     * cannot reach it.
     */
    public function testASiteFromAnotherProgrammeCannotBeAssigned(): void
    {
        $foreignFacility = $this->created($this->post('/api/admin/facilities', [
            'name' => 'Elsewhere Hospital',
        ], $this->signIn('foreign@example.org')), 'facility');

        $foreignSite = $this->created($this->post('/api/admin/testing-sites', [
            'name' => 'Elsewhere lab', 'facility_id' => $foreignFacility['id'],
        ], $this->signIn('foreign@example.org')), 'testing_site');

        $response = $this->post('/api/admin/assignments', [
            'testing_site_id' => $foreignSite['id'],
        ], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, Capsule::table('site_assignments')->count());
    }

    /**
     * An assessor in another organisation can never receive the work, so the
     * assignment is an instruction addressed to nobody.
     */
    public function testAnAssessorFromAnotherOrganisationCannotBeAssignedTo(): void
    {
        $token = $this->signIn('boss@example.org');

        $facility = $this->created($this->post('/api/admin/facilities', ['name' => 'Kitwe'], $token), 'facility');
        $site = $this->created($this->post('/api/admin/testing-sites', [
            'name' => 'TB clinic', 'facility_id' => $facility['id'],
        ], $token), 'testing_site');

        $outsider = (int) Capsule::table('users')->where('email', 'foreign@example.org')->value('id');

        $response = $this->post('/api/admin/assignments', [
            'testing_site_id' => $site['id'],
            'user_id'         => $outsider,
        ], $token);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, Capsule::table('site_assignments')->count());
    }

    /**
     * campaign_id is ON DELETE CASCADE, so accepting another organisation's
     * round means their closing it silently deletes this plan.
     */
    public function testACampaignFromAnotherOrganisationCannotBeAssignedTo(): void
    {
        $token = $this->signIn('boss@example.org');

        $facility = $this->created($this->post('/api/admin/facilities', ['name' => 'Kitwe'], $token), 'facility');
        $site = $this->created($this->post('/api/admin/testing-sites', [
            'name' => 'TB clinic', 'facility_id' => $facility['id'],
        ], $token), 'testing_site');

        $templateId = (int) Capsule::table('templates')->value('id');
        $foreignCampaign = (int) Capsule::table('campaigns')->insertGetId([
            'organization_id' => $this->foreignOrgId,
            'template_id'     => $templateId,
            'name'            => 'Their round',
            'status'          => 'active',
        ]);

        $response = $this->post('/api/admin/assignments', [
            'testing_site_id' => $site['id'],
            'campaign_id'     => $foreignCampaign,
        ], $token);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, Capsule::table('site_assignments')->count());
    }

    /**
     * createGeoUnit calls two districts with one name under one parent a typo.
     * The update path has to agree, or the rule depends on which screen the
     * name was typed on.
     */
    public function testRenamingAPlaceOntoASiblingsNameIsRefused(): void
    {
        $token = $this->signIn('boss@example.org');

        $province = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'Province', 'name' => 'Copperbelt',
        ], $token), 'geo_unit');

        $this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Kitwe', 'parent_id' => $province['id'],
        ], $token);

        $ndola = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Ndola', 'parent_id' => $province['id'],
        ], $token), 'geo_unit');

        $response = $this->request('PATCH', '/api/admin/geo-units/' . $ndola['id'], [
            'name' => 'Kitwe',
        ], $token);

        self::assertSame(422, $response->getStatusCode());
    }

    /** Renaming to something new stays possible, and so does saving unchanged. */
    public function testAnOrdinaryRenameStillWorks(): void
    {
        $token = $this->signIn('boss@example.org');

        $district = $this->created($this->post('/api/admin/geo-units', [
            'level' => 'District', 'name' => 'Kitwe',
        ], $token), 'geo_unit');

        self::assertSame(200, $this->request('PATCH', '/api/admin/geo-units/' . $district['id'], [
            'name' => 'Kitwe City',
        ], $token)->getStatusCode());

        // Saving a form without touching the name must not collide with itself.
        self::assertSame(200, $this->request('PATCH', '/api/admin/geo-units/' . $district['id'], [
            'name' => 'Kitwe City', 'level' => 'District',
        ], $token)->getStatusCode());
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
