<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap;
use App\Service\ReportPdfService;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\MakesTenants;

/**
 * Reading a visit back.
 *
 * The assessments here are put in through the real sync endpoint rather than
 * inserted, so the scores being read are the ones the engine produced. A report
 * asserted against numbers the test wrote itself would pass while showing the
 * site something the engine never said.
 *
 * What matters on this surface: that a report shows what was recorded rather
 * than a tidied version of it, that the band comes from the template the visit
 * was assessed against, and that one organisation cannot read another's visit
 * to a site they share.
 */
final class ReportsTest extends TestCase
{
    use MakesTenants;

    private const PASSWORD = 'correct-horse-battery';

    private int $orgId;
    private int $partnerOrgId;
    private string $facilityId;
    private string $siteId;
    private int $districtId;

    protected function setUp(): void
    {
        Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['submissions_raw', 'assessment_scores', 'findings', 'answers', 'attachments',
                    'assessment_pathogens', 'assessments', 'templates', 'testing_sites',
                    'facilities', 'geo_units', 'refresh_tokens', 'users', 'roles',
                    'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgId = $this->makeTenant('rep-a', 'Ministry');
        $this->partnerOrgId = $this->makeTenant('rep-b', 'Partner');

        // Sharing the registry, not the assessments. That is the case the
        // isolation test below exists for.
        $this->shareProgramme($this->partnerOrgId, $this->orgId);

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

        $provinceId = (int) Capsule::table('geo_units')->insertGetId([
            'programme_id' => $this->programmeFor($this->orgId),
            'level'        => 'Province',
            'name'         => 'Copperbelt',
        ]);

        $this->districtId = (int) Capsule::table('geo_units')->insertGetId([
            'programme_id' => $this->programmeFor($this->orgId),
            'parent_id'    => $provinceId,
            'level'        => 'District',
            'name'         => 'Kitwe',
        ]);

        $this->provinceId = $provinceId;

        $this->facilityId = '019fd400-0000-7000-8000-0000000000aa';
        $this->siteId = '019fd400-0000-7000-8000-0000000000bb';

        Capsule::table('facilities')->insert([
            'id'              => BinaryUuid::toBytes($this->facilityId),
            'programme_id'    => $this->programmeFor($this->orgId),
            'organization_id' => $this->orgId,
            'geo_unit_id'     => $this->districtId,
            'name'            => 'Kitwe Central Hospital',
            'code'            => 'KCH-01',
            'source'          => 'registry',
        ]);

        Capsule::table('testing_sites')->insert([
            'id'              => BinaryUuid::toBytes($this->siteId),
            'programme_id'    => $this->programmeFor($this->orgId),
            'organization_id' => $this->orgId,
            'facility_id'     => BinaryUuid::toBytes($this->facilityId),
            'name'            => 'Kitwe TB clinic',
            'source'          => 'registry',
        ]);

        foreach ([$this->orgId, $this->partnerOrgId] as $org) {
            $this->makeRoles($org, 'admin', 'assessor', 'viewer');
        }

        $this->makeUser($this->orgId, 'boss@example.org', 'admin');
        $this->makeUser($this->orgId, 'reader@example.org', 'viewer');
        $this->makeUser($this->orgId, 'joseph@example.org', 'assessor');
        $this->makeUser($this->partnerOrgId, 'partner@example.org', 'admin');
    }

    protected function tearDown(): void
    {
        // The PDF tests are the only ones that put bytes on the disk, and a
        // test that leaves files behind is a test that passes once.
        foreach ($this->writtenFiles as $path) {
            @unlink($path);
        }

        $this->writtenFiles = [];

        TenantContext::forget();
    }

    /** @var list<string> Files written under var/uploads, removed after each test. */
    private array $writtenFiles = [];

    private int $provinceId = 0;

    // ─── the list ───

    public function testAVisitAppearsWithItsScoreAndWhereItWas(): void
    {
        $this->sync();

        $rows = $this->body($this->get('/api/admin/reports/assessments', $this->signIn('boss@example.org')));

        self::assertSame(1, $rows['total']);

        $row = $rows['rows'][0];

        self::assertSame('Kitwe TB clinic', $row['site']);
        self::assertSame('Kitwe Central Hospital', $row['facility']);
        self::assertSame('Copperbelt › Kitwe', $row['place']);
        self::assertSame('2026-08-05', $row['assessed_on']);
        self::assertSame(4, $row['total_score']);
        // The whole instrument for one pathogen: an unanswered question
        // counts against the visit rather than shrinking the denominator.
        self::assertSame(100, $row['total_possible']);
    }

    /**
     * A visit still being worked on has no score row yet. It has to appear in
     * the list all the same — an assessment that vanished until it was scored
     * would be invisible in exactly the state somebody is chasing it in.
     */
    public function testADraftWithNoScoreYetStillAppears(): void
    {
        $this->syncRaw(['status' => 'draft', 'answers' => []] + $this->payload());

        $rows = $this->body($this->get('/api/admin/reports/assessments', $this->signIn('boss@example.org')));

        self::assertSame(1, $rows['total']);
        self::assertNull($rows['rows'][0]['percentage']);
        self::assertNull($rows['rows'][0]['level']);
    }

    /**
     * Filtering by a province has to reach the visits made in its districts.
     * Visits attach to facilities and facilities attach to the bottom of the
     * tree, so an exact match on the chosen place finds nothing at all.
     */
    public function testFilteringByAProvinceFindsVisitsInItsDistricts(): void
    {
        $this->sync();

        $token = $this->signIn('boss@example.org');

        $inProvince = $this->body(
            $this->get('/api/admin/reports/assessments?geo_unit_id=' . $this->provinceId, $token),
        );

        self::assertSame(1, $inProvince['total']);

        $elsewhere = (int) Capsule::table('geo_units')->insertGetId([
            'programme_id' => $this->programmeFor($this->orgId),
            'level'        => 'Province',
            'name'         => 'Lusaka',
        ]);

        $none = $this->body($this->get('/api/admin/reports/assessments?geo_unit_id=' . $elsewhere, $token));

        self::assertSame(0, $none['total']);
    }

    public function testTheListPages(): void
    {
        $this->sync();
        $this->sync('019fd400-0000-7000-8000-000000000778', '2026-08-06');

        $token = $this->signIn('boss@example.org');

        $first = $this->body($this->get('/api/admin/reports/assessments?per_page=1', $token));

        self::assertSame(2, $first['total']);
        self::assertCount(1, $first['rows']);
        // Newest visit first.
        self::assertSame('2026-08-06', $first['rows'][0]['assessed_on']);

        $second = $this->body($this->get('/api/admin/reports/assessments?per_page=1&page=2', $token));

        self::assertSame('2026-08-05', $second['rows'][0]['assessed_on']);
    }

    // ─── the report ───

    public function testTheReportCarriesTheScoreTheEngineProduced(): void
    {
        $id = $this->sync();

        $report = $this->body(
            $this->get('/api/admin/reports/assessments/' . $id, $this->signIn('boss@example.org')),
        );

        self::assertTrue($report['score']['scored']);
        self::assertSame(4, $report['score']['total_score']);
        self::assertSame(100, $report['score']['total_possible']);

        // Three answers out of the whole instrument is 4%, which is Level 0.
        // The band is read from the template rather than from a constant, so a
        // country that moves its thresholds moves this with them.
        self::assertSame('Level 0', $report['score']['band']['label']);
        self::assertSame(0, $report['score']['level']);
    }

    /**
     * Every question in the instrument, answered or not.
     *
     * Driving the report from the ANSWERS would quietly omit whatever was
     * skipped, which is the one way this screen could mislead the site it is
     * handed to.
     */
    public function testEveryQuestionAppearsWhetherOrNotItWasAnswered(): void
    {
        $id = $this->sync();

        $report = $this->body(
            $this->get('/api/admin/reports/assessments/' . $id, $this->signIn('boss@example.org')),
        );

        $questions = [];

        foreach ($report['sections'] as $section) {
            foreach ($section['questions'] as $question) {
                $questions[$question['code']] = $question;
            }
        }

        self::assertCount(59, $questions);
        self::assertSame('N', $questions['3.2']['answers'][0]['response']);
        self::assertSame('No', $questions['3.2']['answers'][0]['label']);
        self::assertSame(0, $questions['3.2']['answers'][0]['points']);
        self::assertSame('No SOP.', $questions['3.2']['answers'][0]['comment']);

        // Asked, never answered, and still listed.
        self::assertSame([], $questions['1.1']['answers']);
    }

    /** Section 4 repeats per pathogen, so an answer there has to say which one. */
    public function testAPathogenAnswerNamesItsPathogen(): void
    {
        $id = $this->sync();

        $report = $this->body(
            $this->get('/api/admin/reports/assessments/' . $id, $this->signIn('boss@example.org')),
        );

        foreach ($report['sections'] as $section) {
            foreach ($section['questions'] as $question) {
                if ($question['code'] === '4.1') {
                    self::assertSame('HIV', $question['answers'][0]['pathogen']);

                    return;
                }
            }
        }

        self::fail('4.1 was not in the report at all');
    }

    public function testTheReportNamesTheSiteAndItsPlace(): void
    {
        $id = $this->sync();

        $report = $this->body(
            $this->get('/api/admin/reports/assessments/' . $id, $this->signIn('boss@example.org')),
        );

        self::assertSame('Kitwe TB clinic', $report['assessment']['site']['name']);
        self::assertSame('Kitwe Central Hospital', $report['assessment']['facility']['name']);
        self::assertSame('KCH-01', $report['assessment']['facility']['code']);
        self::assertSame('Copperbelt › Kitwe', $report['assessment']['facility']['place']);
        self::assertSame('HIV', $report['assessment']['pathogens'][0]['name']);
    }

    /** Immediate work comes first, and anything unmarked comes last. */
    public function testFindingsAreOrderedByWhatCannotWait(): void
    {
        $id = $this->sync();

        $this->addFinding($id, '3.2', 'follow_up', 'Write the SOP.');
        $this->addFinding($id, '1.1', null, 'No organogram.');
        $this->addFinding($id, '3.1', 'immediate', 'Expired kits on the bench.');

        $report = $this->body(
            $this->get('/api/admin/reports/assessments/' . $id, $this->signIn('boss@example.org')),
        );

        self::assertSame(
            ['immediate', 'follow_up', null],
            array_column($report['findings'], 'urgency'),
        );

        // And each carries the question it came from, in readable form.
        self::assertNotNull($report['findings'][0]['question']);
    }

    /** Several findings on one question is the whole point of the v2 shape. */
    public function testOneQuestionCanCarrySeveralFindings(): void
    {
        $id = $this->sync();

        $this->addFinding($id, '3.2', 'immediate', 'No SOP at all.');
        $this->addFinding($id, '3.2', 'follow_up', 'And nobody has been trained.');

        $report = $this->body(
            $this->get('/api/admin/reports/assessments/' . $id, $this->signIn('boss@example.org')),
        );

        self::assertCount(2, $report['findings']);

        foreach ($report['sections'] as $section) {
            foreach ($section['questions'] as $question) {
                if ($question['code'] === '3.2') {
                    self::assertSame(2, $question['findings']);

                    return;
                }
            }
        }

        self::fail('3.2 was not in the report');
    }

    // ─── who may read it ───

    public function testAViewerMayReadReports(): void
    {
        $id = $this->sync();

        $token = $this->signIn('reader@example.org');

        self::assertSame(200, $this->get('/api/admin/reports/assessments', $token)->getStatusCode());
        self::assertSame(
            200,
            $this->get('/api/admin/reports/assessments/' . $id, $token)->getStatusCode(),
        );
    }

    public function testAnAssessorMayNot(): void
    {
        $this->sync();

        self::assertSame(
            403,
            $this->get('/api/admin/reports/assessments', $this->signIn('joseph@example.org'))->getStatusCode(),
        );
    }

    /**
     * The partner shares the registry and can see the same site. The visit made
     * to it is still not theirs to read, and the id is answered as if it never
     * existed rather than as something they are not allowed.
     */
    public function testAPartnerSharingTheRegistryCannotReadTheVisit(): void
    {
        $id = $this->sync();

        $token = $this->signIn('partner@example.org');

        self::assertSame(0, $this->body($this->get('/api/admin/reports/assessments', $token))['total']);
        self::assertSame(
            404,
            $this->get('/api/admin/reports/assessments/' . $id, $token)->getStatusCode(),
        );
    }

    /**
     * The photographs come back on the section they were taken in.
     *
     * They have been in the database since the device synced them and appeared
     * on no management screen: the evidence existed and only the assessor who
     * took it could ever see it. A 0 on a question says a thing was missing;
     * the photograph of the empty shelf is what somebody argues from a year
     * later, and it belongs beside the questions it is evidence for.
     */
    public function testASectionCarriesThePhotographsTakenInIt(): void
    {
        $id = $this->sync();

        $this->addPhotograph($id, '3', 'The empty shelf', '2026-08-05 09:30:00');
        $this->addPhotograph($id, '3', null, '2026-08-05 09:31:00');
        $this->addPhotograph($id, '2', 'No organogram on the wall', '2026-08-05 09:10:00');

        $report = $this->body(
            $this->get('/api/admin/reports/assessments/' . $id, $this->signIn('boss@example.org')),
        );

        $bySection = [];

        foreach ($report['sections'] as $section) {
            $bySection[$section['code']] = $section['photographs'];
        }

        self::assertCount(2, $bySection['3']);
        self::assertCount(1, $bySection['2']);

        // Taken in order, shown in order. The sequence is part of what the
        // assessor was recording as they worked the room.
        self::assertSame('The empty shelf', $bySection['3'][0]['caption']);

        // A photograph nobody captioned is still a photograph, and the report
        // says so rather than dropping it.
        self::assertNull($bySection['3'][1]['caption']);

        // The bytes are not in the report: these files sit outside the
        // document root and are served by the application, which is the only
        // thing keeping one tenant's evidence away from another's.
        self::assertSame(
            '/api/attachments/' . $bySection['2'][0]['id'],
            $bySection['2'][0]['url'],
        );

        // A section nobody photographed says so with an empty list rather than
        // by leaving the key off.
        self::assertSame([], $bySection['1']);
    }

    /**
     * The setup screen's pictures are of the SITE, not of a section.
     *
     * The assessor treats "Site details" as the section before the first one,
     * and the building, the gate and the bench answer no question in the
     * template. Filed under a section code they would be evidence for
     * questions they are not about.
     */
    public function testThePicturesOfTheSiteItselfStandApartFromTheSections(): void
    {
        $id = $this->sync();

        $this->addPhotograph($id, 'site', 'The main entrance', '2026-08-05 08:55:00');
        $this->addPhotograph($id, '2', 'No organogram on the wall', '2026-08-05 09:10:00');

        $report = $this->body(
            $this->get('/api/admin/reports/assessments/' . $id, $this->signIn('boss@example.org')),
        );

        self::assertCount(1, $report['site_photographs']);
        self::assertSame('The main entrance', $report['site_photographs'][0]['caption']);

        foreach ($report['sections'] as $section) {
            foreach ($section['photographs'] as $photograph) {
                self::assertNotSame('The main entrance', $photograph['caption']);
            }
        }
    }

    /**
     * A signature is not a photograph.
     *
     * Both live in `attachments`, and a report that read the table by
     * assessment alone would print somebody's signature into the middle of
     * Section 3 as evidence of what was found there.
     */
    public function testASignatureDoesNotTurnUpAmongThePhotographs(): void
    {
        $id = $this->sync();

        $this->addSignature($id, 'assessor_1', 'Joseph Banda');

        $report = $this->body(
            $this->get('/api/admin/reports/assessments/' . $id, $this->signIn('boss@example.org')),
        );

        self::assertCount(1, $report['signatures']);
        self::assertSame([], $report['site_photographs']);

        foreach ($report['sections'] as $section) {
            self::assertSame([], $section['photographs']);
        }
    }

    /**
     * The file, and the fact that it is one.
     *
     * Asserted on the bytes rather than on a status code: a 200 carrying an
     * HTML error page would pass every other check here, and what the browser
     * saves is what matters. %PDF is the whole of the format's signature.
     */
    public function testAVisitCanBeDownloadedAsAPdf(): void
    {
        $id = $this->sync();

        $response = $this->get(
            '/api/admin/reports/assessments/' . $id . '/pdf',
            $this->signIn('boss@example.org'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();

        self::assertStringStartsWith('%PDF-', $body);
        // A report of fifty-nine questions that fits in a kilobyte is a report
        // that rendered nothing. What it CONTAINS is asserted below, on the
        // document rather than on the file: the renderer writes text as glyph
        // indexes into a subsetted font, so the words are not in the bytes in
        // any form a test can look for.
        self::assertGreaterThan(5000, strlen($body));
    }

    /**
     * The whole instrument reaches the file, answered or not.
     *
     * This is the report's one invariant — a document that quietly omits what
     * was skipped is the single way it could mislead the laboratory it is
     * written for — and it is worth asserting on the PDF path separately,
     * because that path builds its own view rather than reusing the screen's.
     */
    public function testThePdfCarriesEveryQuestionAndPartA(): void
    {
        $id = $this->sync();

        $html = (new ReportPdfService())->html($id);

        // Against the pinned instrument rather than against a phrase. A test
        // that samples one question stays green while a whole section stops
        // rendering, which is the failure this invariant exists to catch.
        $definition = TenantContext::withoutScope(
            fn (): mixed => json_decode(
                (string) Capsule::table('templates')->value('definition'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        $questions = 0;

        foreach ($definition['sections'] as $section) {
            self::assertStringContainsString($section['title']['en'], $html);

            foreach ($section['questions'] as $question) {
                self::assertStringContainsString($question['code'], $html, $question['code']);
                self::assertStringContainsString($question['text']['en'], $html, $question['code']);
                $questions++;
            }
        }

        self::assertGreaterThan(1, $questions);

        // What was skipped says so, rather than being left off.
        self::assertStringContainsString('Not answered', $html);

        // Part A, which never reached the report screen and is the reason the
        // file carries more than the page does.
        self::assertStringContainsString('Type of facility', $html);
        self::assertStringContainsString('Kitwe TB clinic', $html);
    }

    /**
     * A pathogen question skipped for one pathogen says so.
     *
     * Section 4 is asked once per pathogen. Answered for one and skipped for
     * another, the question comes back with a single answer — and a report
     * that lists what came back shows a complete-looking row while the missing
     * answer sits in the denominator.
     */
    public function testAPathogenLeftUnansweredIsNamedAsUnanswered(): void
    {
        $id = '019fd400-0000-7000-8000-000000000778';

        $this->syncRaw([
            'id'        => $id,
            'pathogens' => [
                ['key' => 'hiv', 'name' => 'HIV'],
                ['key' => 'syphilis', 'name' => 'Syphilis'],
            ],
            // 4.1 answered for one of the two. The other is what this is about.
            'answers'   => [['question_code' => '4.1', 'pathogen' => 'hiv', 'response' => 'Y']],
        ] + $this->payload());

        $html = (new ReportPdfService())->html($id);

        self::assertMatchesRegularExpression('/HIV.{0,200}Yes/s', $html);
        self::assertMatchesRegularExpression('/Syphilis.{0,200}Not answered/s', $html);
    }

    /**
     * A qualifier belongs to the answer that asked for it.
     *
     * The setup form hides the free-text box when the choice changes and keeps
     * what was typed in it, so a corrected answer can leave a sentence behind.
     * Printed against the new answer, that sentence says something nobody ever
     * said.
     */
    public function testAQualifierLeftOverFromAnEarlierAnswerIsNotPrinted(): void
    {
        $id = $this->sync();

        TenantContext::withoutScope(function () use ($id): void {
            Capsule::table('assessments')
                ->where('id', BinaryUuid::toBytes($id))
                ->update(['context' => json_encode([
                    // health_center does not ask to be spelled out. laboratory,
                    // the answer before it, did.
                    'facility_type'       => 'health_center',
                    'facility_type_other' => 'The old district laboratory',
                ], JSON_THROW_ON_ERROR)]);
        });

        $html = (new ReportPdfService())->html($id);

        self::assertStringContainsString('Health Centre', $html);
        self::assertStringNotContainsString('The old district laboratory', $html);
    }

    /**
     * A signed visit renders, and the signature is in it.
     *
     * Signatures are PNGs with their transparency kept, which is the one image
     * path the renderer composites rather than embedding whole — so a report
     * that renders with a photograph in it proves nothing about a report with
     * a signature in it.
     */
    public function testASignedVisitRendersWithItsSignature(): void
    {
        $id = $this->sync();

        $this->addSignature($id, 'assessor_1', 'Joseph Banda');
        $this->writeSignatureBytes($id);

        $response = $this->get(
            '/api/admin/reports/assessments/' . $id . '/pdf',
            $this->signIn('boss@example.org'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('%PDF-', (string) $response->getBody());

        $html = (new ReportPdfService())->html($id);

        // The name is in the document whether or not the machine can draw the
        // mark beside it, which is what the report has to promise on a server
        // without ext-gd.
        self::assertStringContainsString('Joseph Banda', $html);

        // And the mark itself, which is the part that is the record. Both
        // supported installations carry the extension; the branch is here
        // because the application does not require it, and the promise on a
        // machine that lacks it is that the document SAYS the mark is missing
        // rather than quietly leaving it out.
        if (extension_loaded('gd')) {
            self::assertStringContainsString('data:image/png;base64,', $html);
        } else {
            self::assertStringContainsString('cannot draw', $html);
        }
    }

    /** Named after the site, so a folder of these can be searched. */
    public function testTheDownloadIsNamedAfterTheSiteAndTheVisit(): void
    {
        $id = $this->sync();

        $disposition = $this->get(
            '/api/admin/reports/assessments/' . $id . '/pdf',
            $this->signIn('boss@example.org'),
        )->getHeaderLine('Content-Disposition');

        self::assertStringContainsString('attachment;', $disposition);
        self::assertStringContainsString('Kitwe-TB-clinic', $disposition);
        self::assertStringEndsWith('.pdf"', $disposition);
    }

    /**
     * Asking for it without the pictures is asking for a different file.
     *
     * The check is that the parameter reaches the renderer at all, which a
     * smaller document is the only outward evidence of.
     */
    public function testThePhotographsCanBeLeftOut(): void
    {
        $id = $this->sync();
        $token = $this->signIn('boss@example.org');

        $this->addPhotograph($id, '2', 'The sharps bin, overflowing', '2026-01-01 09:00:00');
        $this->writePhotographBytes($id);

        $with = strlen((string) $this->get(
            '/api/admin/reports/assessments/' . $id . '/pdf',
            $token,
        )->getBody());

        $without = strlen((string) $this->get(
            '/api/admin/reports/assessments/' . $id . '/pdf?photographs=0',
            $token,
        )->getBody());

        self::assertLessThan($with, $without);
    }

    /** The same answer the JSON gives: an id from elsewhere names nothing. */
    public function testAPartnerCannotDownloadTheVisit(): void
    {
        $id = $this->sync();

        self::assertSame(
            404,
            $this->get(
                '/api/admin/reports/assessments/' . $id . '/pdf',
                $this->signIn('partner@example.org'),
            )->getStatusCode(),
        );
    }

    public function testAnIdThatIsNotAUuidIsNotAServerError(): void
    {
        self::assertSame(
            404,
            $this->get('/api/admin/reports/assessments/nonsense', $this->signIn('boss@example.org'))
                ->getStatusCode(),
        );
    }

    // ─── fixtures ───

    private function sync(
        string $id = '019fd400-0000-7000-8000-000000000777',
        string $assessedOn = '2026-08-05',
    ): string {
        $this->syncRaw(['id' => $id, 'assessed_on' => $assessedOn] + $this->payload());

        return $id;
    }

    /** @param array<string,mixed> $payload */
    private function syncRaw(array $payload): void
    {
        $response = $this->request(
            'POST',
            '/api/sync/assessments',
            $payload,
            $this->signIn('joseph@example.org'),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'id'               => '019fd400-0000-7000-8000-000000000777',
            'testing_site_id'  => $this->siteId,
            'facility_id'      => $this->facilityId,
            'template_code'    => 'spi-rdt',
            'template_version' => '1.0.0',
            'assessed_on'      => '2026-08-05',
            // A draft, because three answers of fifty-nine is one. These tests
            // are about reading a visit back, and a report shows a visit still
            // being worked on — that is the state somebody is usually chasing
            // it in.
            'status'           => 'draft',
            'context'          => ['refers_specimens' => 'no'],
            'pathogens'        => [['key' => 'hiv', 'name' => 'HIV']],
            'answers'          => [
                ['question_code' => '3.1', 'response' => 'Y'],
                ['question_code' => '3.2', 'response' => 'N', 'comment' => 'No SOP.'],
                ['question_code' => '4.1', 'pathogen' => 'hiv', 'response' => 'Y'],
            ],
        ];
    }

    private function addFinding(string $assessmentId, string $questionCode, ?string $urgency, string $gap): void
    {
        TenantContext::withoutScope(function () use ($assessmentId, $questionCode, $urgency, $gap): void {
            Capsule::table('findings')->insert([
                'id'                   => BinaryUuid::toBytes(BinaryUuid::v7()),
                'organization_id'      => $this->orgId,
                'assessment_id'        => BinaryUuid::toBytes($assessmentId),
                'question_code'        => $questionCode,
                'response'             => 'N',
                'gap'                  => $gap,
                'responsibility_level' => 'site',
                'urgency'              => $urgency,
                'status'               => 'open',
            ]);
        });
    }

    /**
     * One photograph, written straight in.
     *
     * The report never opens the file, so there is no point putting bytes on
     * disk to read a row back. `uploaded_at` is given explicitly because the
     * order photographs are shown in is the order they were taken, and rows
     * inserted in the same second would not test that.
     */
    private function addPhotograph(
        string $assessmentId,
        ?string $sectionCode,
        ?string $caption,
        string $uploadedAt,
    ): void {
        $this->addAttachment($assessmentId, [
            'kind'         => 'photo',
            'section_code' => $sectionCode,
            'caption'      => $caption,
            'client_key'   => BinaryUuid::v7(),
            'uploaded_at'  => $uploadedAt,
        ]);
    }

    private function addSignature(string $assessmentId, string $role, string $signedName): void
    {
        $this->addAttachment($assessmentId, [
            'kind'        => 'signature',
            'role'        => $role,
            'signed_name' => $signedName,
        ]);
    }

    /**
     * Put a real image where the row says one is.
     *
     * Every other test here inserts the attachment row alone, because nothing
     * it asserts reads the file. A PDF does: with no bytes on the disk the
     * renderer draws nothing and a document with photographs is the same size
     * as one without, so the test would pass while proving the opposite of
     * what it claims.
     *
     * A one-pixel JPEG, spelled out rather than generated, because ext-gd is
     * optional on this application and a test may not need more of the machine
     * than the thing it tests.
     */
    private function writePhotographBytes(string $assessmentId): void
    {
        $pixel = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof'
            . 'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAAB'
            . 'AAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        );

        self::assertIsString($pixel);

        $paths = TenantContext::withoutScope(fn (): array => Capsule::table('attachments')
            ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
            ->where('kind', 'photo')
            ->pluck('storage_path')
            ->all());

        foreach ($paths as $relative) {
            $path = dirname(__DIR__, 2) . '/var/uploads/' . $relative;

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o775, true);
            }

            file_put_contents($path, $pixel);
            $this->writtenFiles[] = $path;
        }
    }

    /**
     * The same, for a signature — a transparent PNG, as the device saves one.
     *
     * Spelled out rather than generated for the reason the JPEG is, and it has
     * to be a real PNG WITH an alpha channel: that is the path the renderer
     * composites, and the only one that needs ext-gd.
     */
    private function writeSignatureBytes(string $assessmentId): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            true,
        );

        self::assertIsString($png);

        $rows = TenantContext::withoutScope(fn (): array => Capsule::table('attachments')
            ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
            ->where('kind', 'signature')
            ->get(['id', 'storage_path'])
            ->all());

        foreach ($rows as $row) {
            TenantContext::withoutScope(function () use ($row): void {
                Capsule::table('attachments')
                    ->where('id', $row->id)
                    ->update(['mime_type' => 'image/png']);
            });

            $path = dirname(__DIR__, 2) . '/var/uploads/' . $row->storage_path;

            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o775, true);
            }

            file_put_contents($path, $png);
            $this->writtenFiles[] = $path;
        }
    }

    /** @param array<string,mixed> $row */
    private function addAttachment(string $assessmentId, array $row): void
    {
        TenantContext::withoutScope(function () use ($assessmentId, $row): void {
            $id = BinaryUuid::v7();

            Capsule::table('attachments')->insert($row + [
                'id'              => BinaryUuid::toBytes($id),
                'organization_id' => $this->orgId,
                'assessment_id'   => BinaryUuid::toBytes($assessmentId),
                'storage_path'    => 'attachments/' . $this->orgId . '/' . $assessmentId . '/' . $id . '.jpg',
                'mime_type'       => 'image/jpeg',
                'byte_size'       => 1024,
                // Left off on purpose. Two photographs of the same shelf a
                // minute apart are plausibly byte-identical, and identity here
                // is the key the device minted rather than the bytes.
                'checksum'        => null,
            ]);
        });
    }

    private function makeUser(int $organizationId, string $email, string $roleKey): int
    {
        return (int) Capsule::table('users')->insertGetId([
            'organization_id' => $organizationId,
            'role_id'         => (int) Capsule::table('roles')
                ->where('organization_id', $organizationId)->where('key', $roleKey)->value('id'),
            'email'           => $email,
            'password_hash'   => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'full_name'       => ucfirst(explode('@', $email)[0]),
            'is_active'       => 1,
        ]);
    }

    private function signIn(string $email): string
    {
        return $this->body(
            $this->request('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]),
        )['access_token'];
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
