<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Audit\AuditAction;
use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssessmentPathogen;
use App\Models\AssessmentScore;
use App\Models\Finding;
use App\Scoring\ScoringEngine;
use App\Service\SyncService;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\MakesTenants;

/**
 * The sync, against a real database.
 *
 * Two properties matter more than the rest, because both fail silently:
 * running the same payload twice must not produce a second site visit, and one
 * organisation must never be able to touch another's data.
 */
final class SyncServiceTest extends TestCase
{
    use MakesTenants;

    private const ASSESSMENT_ID = '019fd200-0000-7000-8000-000000000001';

    /** Device-minted, because the finding's own id is the upsert key now. */
    private const FINDING_ID = '019fd600-0000-7000-8000-00000000000a';
    private const SECOND_FINDING_ID = '019fd600-0000-7000-8000-00000000000b';

    /** Server-minted, standing in for a finding stored before the key changed. */
    private const LEGACY_FINDING_ID = '019fd600-0000-7000-8000-00000000000c';

    private int $orgA;
    private int $orgB;
    private string $siteId;
    private string $facilityId;

    protected function setUp(): void
    {
        \App\Bootstrap::createApp();

        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['assessment_scores', 'findings', 'answers', 'assessment_pathogens', 'assessments',
                    'templates', 'testing_sites', 'facilities', 'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgA = $this->makeTenant('org-a');
        $this->orgB = $this->makeTenant('org-b');

        $definition = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/resources/templates/spi-rdt-1.0.0.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        Capsule::table('templates')->insert([
            'organization_id' => null,
            'code'            => 'spi-rdt',
            'version'         => '1.0.0',
            'title'           => 'SPI-RDT',
            'definition'      => json_encode($definition, JSON_THROW_ON_ERROR),
            'status'          => 'published',
        ]);

        $this->facilityId = $this->makeFacility($this->orgA, 'a1');
        $this->siteId = $this->makeSite($this->orgA, $this->facilityId, 'a2');

        $this->useTenant($this->orgA);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    public function testAcceptsAnAssessmentAndSnapshotsTheScore(): void
    {
        $result = (new SyncService())->accept($this->payload());

        self::assertSame(3, count($result['accepted']));

        $stored = Assessment::findByUuid($result['assessment_id']);
        self::assertNotNull($stored);
        self::assertSame('draft', $stored->status, 'three answers of fifty-nine is a draft');

        $score = AssessmentScore::query()->first();
        self::assertNotNull($score);
        self::assertSame(2, (int) $score->pathogen_count);
        // 3.1 Yes and 3.2 No, plus 4.1 Yes for one pathogen, earning 4.
        // The denominator is the WHOLE instrument for two pathogens, because
        // an unanswered question counts against the visit rather than
        // shrinking what it is measured against.
        self::assertSame(4, (int) $score->total_score);
        self::assertSame(146, (int) $score->total_possible);
        self::assertFalse($result['score']['is_complete'], 'a partial visit is not complete');
    }

    public function testTheAuditRoundIsStoredAsTheDeviceWroteIt(): void
    {
        $payload = $this->payload();
        $payload['audit_round'] = '  Baseline  ';

        $result = (new SyncService())->accept($payload);
        $stored = Assessment::findByUuid($result['assessment_id']);

        self::assertNotNull($stored);
        self::assertSame(
            'Baseline',
            $stored->audit_round,
            'a round is a word as often as a number, and it arrives with the spacing a phone keyboard gave it',
        );
    }

    public function testAnAbsentOrEmptyAuditRoundIsStoredAsNothing(): void
    {
        // A device that predates the field sends nothing; an assessor who left
        // it blank sends an empty string. Both mean "not recorded", and an
        // empty string in the column would sort and filter as a real value.
        foreach ([null, ['audit_round' => ''], ['audit_round' => '   ']] as $variant) {
            $payload = $this->payload();

            if (is_array($variant)) {
                $payload += $variant;
            }

            $stored = Assessment::findByUuid((new SyncService())->accept($payload)['assessment_id']);

            self::assertNotNull($stored);
            self::assertNull($stored->audit_round);
        }
    }

    public function testAnOverlongAuditRoundIsCutToTheColumn(): void
    {
        // Nothing on the device stops a paste, and a payload arriving from a
        // phone is not a promise about lengths.
        $payload = $this->payload();
        $payload['audit_round'] = str_repeat('R', 60);

        $stored = Assessment::findByUuid((new SyncService())->accept($payload)['assessment_id']);

        self::assertNotNull($stored);
        self::assertSame(30, mb_strlen((string) $stored->audit_round));
    }

    public function testFindingsAreStoredAndNotDuplicatedOnRetry(): void
    {
        $sync = new SyncService();
        $payload = $this->payload();
        $payload['findings'] = [
            [
                'id'                   => self::FINDING_ID,
                'question_code'        => '3.2',
                'response'             => 'N',
                'gap'                  => 'No exposure SOP on site.',
                'recommendation'       => 'Adapt the national SOP and post it at the bench.',
                'responsibility_level' => 'facility',
                'urgency'              => 'immediate',
                'responsible_person'   => 'Lab manager',
                'due_date'             => '2026-09-30',
            ],
        ];

        $first = $sync->accept($payload);
        self::assertSame([self::FINDING_ID], $first['accepted_findings']);

        $sync->accept($payload);

        self::assertSame(1, Finding::query()->count(), 'a retry corrects, it does not duplicate');

        $finding = Finding::query()->first();
        self::assertNotNull($finding);
        self::assertSame('facility', $finding->responsibility_level);
        self::assertSame('immediate', $finding->urgency);
        self::assertSame('open', $finding->status);
    }

    /**
     * One No can hide more than one problem — no SOP, and staff untrained on
     * the one that is missing — and each needs its own recommendation, owner
     * and date. Collapsing them into one text box produces an action list
     * nobody can work from.
     */
    public function testOneQuestionCanCarrySeveralFindings(): void
    {
        $payload = $this->payload();
        $payload['findings'] = [
            [
                'id'            => self::FINDING_ID,
                'question_code' => '3.2',
                'response'      => 'N',
                'gap'           => 'No exposure SOP on site.',
                'urgency'       => 'follow_up',
            ],
            [
                'id'            => self::SECOND_FINDING_ID,
                'question_code' => '3.2',
                'response'      => 'N',
                'gap'           => 'Staff have not been trained on exposure response.',
                'urgency'       => 'immediate',
            ],
        ];

        $result = (new SyncService())->accept($payload);

        self::assertSame(2, Finding::query()->count());
        self::assertSame([self::FINDING_ID, self::SECOND_FINDING_ID], $result['accepted_findings']);

        $urgencies = Finding::query()->pluck('urgency')->all();

        self::assertContains('immediate', $urgencies);
        self::assertContains('follow_up', $urgencies);
    }

    /** Several on one question, and a retry still corrects rather than doubling. */
    public function testSeveralFindingsOnOneQuestionSurviveARetry(): void
    {
        $payload = $this->payload();
        $payload['findings'] = [
            ['id' => self::FINDING_ID, 'question_code' => '3.2', 'response' => 'N', 'gap' => 'First gap.'],
            ['id' => self::SECOND_FINDING_ID, 'question_code' => '3.2', 'response' => 'N', 'gap' => 'Second gap.'],
        ];

        $sync = new SyncService();
        $sync->accept($payload);
        $sync->accept($payload);

        self::assertSame(2, Finding::query()->count());
    }

    /**
     * The upgrade window.
     *
     * A device that synced before findings were keyed on their own id is
     * holding rows the server already has, under ids the server minted and
     * never told it. Version 5 re-keys those rows and sends the key they used
     * to have. Without that, the finding arrives looking new and the site's
     * action list gets the same gap twice.
     */
    public function testAReKeyedFindingIsAdoptedRatherThanDuplicated(): void
    {
        $sync = new SyncService();
        $sync->accept($this->payload());

        $this->makeServerKeyedFinding(self::LEGACY_FINDING_ID, '3.2', 'No exposure SOP on site.');

        // Somebody has already started on it. Adoption has to keep that, which
        // is most of the reason not to just let the duplicate through.
        Finding::query()->update(['status' => 'in_progress']);

        $payload = $this->payload();
        $payload['findings'] = [
            [
                'id'            => self::FINDING_ID,
                'previous_key'  => self::ASSESSMENT_ID . '|3.2|',
                'question_code' => '3.2',
                'response'      => 'N',
                'gap'           => 'No exposure SOP on site.',
            ],
        ];

        $result = $sync->accept($payload);

        self::assertSame(1, Finding::query()->count(), 'the same gap, not a second one');
        self::assertSame([self::FINDING_ID], $result['accepted_findings']);

        $finding = Finding::query()->first();
        self::assertNotNull($finding);
        self::assertSame(self::FINDING_ID, (string) $finding->id, 'it answers to the device id now');
        self::assertSame('in_progress', $finding->status, 'the work already done on it survives');

        // And once only. The second sync finds it by id and never looks for
        // anything to adopt.
        $sync->accept($payload);
        self::assertSame(1, Finding::query()->count());
    }

    /**
     * The case adoption must not break.
     *
     * One question may carry several findings, and the device sends only what
     * is dirty — so the second gap raised on a question arrives alone, with an
     * id the server has never seen, against a question that already has a
     * finding. That is a new row. It says nothing about a previous key, and
     * that silence is what distinguishes it.
     */
    public function testASecondGapOnAQuestionIsNotMistakenForAReKeyedOne(): void
    {
        $sync = new SyncService();
        $sync->accept($this->payload());

        $this->makeServerKeyedFinding(self::LEGACY_FINDING_ID, '3.2', 'No exposure SOP on site.');

        $payload = $this->payload();
        $payload['findings'] = [
            [
                'id'            => self::SECOND_FINDING_ID,
                'question_code' => '3.2',
                'response'      => 'N',
                'gap'           => 'Staff have not been trained on exposure response.',
            ],
        ];

        $sync->accept($payload);

        self::assertSame(2, Finding::query()->count(), 'two gaps on one question are two findings');

        $legacy = Finding::query()
            ->where('findings.id', BinaryUuid::toBytes(self::LEGACY_FINDING_ID))
            ->first();

        self::assertNotNull($legacy, 'the finding already stored is untouched');
        self::assertSame('No exposure SOP on site.', $legacy->gap);
    }

    /**
     * A previous key is checked against the finding it arrives with, because
     * one describing another question would move a gap onto that question.
     */
    public function testAPreviousKeyForAnotherQuestionIsIgnored(): void
    {
        $sync = new SyncService();
        $sync->accept($this->payload());

        $this->makeServerKeyedFinding(self::LEGACY_FINDING_ID, '3.2', 'No exposure SOP on site.');

        $payload = $this->payload();
        $payload['findings'] = [
            [
                'id'            => self::FINDING_ID,
                'previous_key'  => self::ASSESSMENT_ID . '|3.1|',
                'question_code' => '3.2',
                'response'      => 'N',
                'gap'           => 'No exposure SOP on site.',
            ],
        ];

        $sync->accept($payload);

        self::assertSame(2, Finding::query()->count(), 'stored as new rather than adopted on a bad key');
    }

    /**
     * Only the OLD server's rows can be adopted. Once a device has claimed a
     * finding, a replayed or forged previous key cannot reach it — otherwise
     * the guard above could be walked around by sending the key twice.
     */
    public function testAPreviousKeyCannotTakeOverAFindingADeviceAlreadyKeyed(): void
    {
        $sync = new SyncService();

        $payload = $this->payload();
        $payload['findings'] = [
            [
                'id'            => self::FINDING_ID,
                'question_code' => '3.2',
                'response'      => 'N',
                'gap'           => 'No exposure SOP on site.',
            ],
        ];
        $sync->accept($payload);

        $payload['findings'] = [
            [
                'id'            => self::SECOND_FINDING_ID,
                'previous_key'  => self::ASSESSMENT_ID . '|3.2|',
                'question_code' => '3.2',
                'response'      => 'N',
                'gap'           => 'Staff have not been trained on exposure response.',
            ],
        ];
        $sync->accept($payload);

        self::assertSame(2, Finding::query()->count());

        $first = Finding::query()->where('findings.id', BinaryUuid::toBytes(self::FINDING_ID))->first();
        self::assertNotNull($first);
        self::assertSame('No exposure SOP on site.', $first->gap, 'the first finding is not overwritten');
    }

    /**
     * The id is the identity now, so a payload without one cannot be upserted
     * — accepting it would insert a fresh row on every retry.
     */
    public function testAFindingWithNoIdIsDropped(): void
    {
        $payload = $this->payload();
        $payload['findings'] = [
            ['question_code' => '3.2', 'response' => 'N', 'gap' => 'No exposure SOP on site.'],
        ];

        $result = (new SyncService())->accept($payload);

        self::assertSame(0, Finding::query()->count());
        self::assertSame([], $result['accepted_findings']);
    }

    /** Blank means nobody said, which is not the same as follow-up. */
    public function testAnUnstatedUrgencyStaysUnstated(): void
    {
        $payload = $this->payload();
        $payload['findings'] = [
            ['id' => self::FINDING_ID, 'question_code' => '3.2', 'response' => 'N', 'gap' => 'A gap.'],
        ];

        (new SyncService())->accept($payload);

        self::assertNull(Finding::query()->first()?->urgency);
    }

    public function testAnUrgencyItDoesNotRecogniseIsIgnoredRatherThanStored(): void
    {
        $payload = $this->payload();
        $payload['findings'] = [
            [
                'id'            => self::FINDING_ID,
                'question_code' => '3.2',
                'response'      => 'N',
                'gap'           => 'A gap.',
                'urgency'       => 'whenever',
            ],
        ];

        (new SyncService())->accept($payload);

        self::assertNull(Finding::query()->first()?->urgency);
    }

    public function testAFindingAgainstAPassingAnswerIsDropped(): void
    {
        // Only a Partial or a No describes a shortfall. Anything else would sit
        // in the site's action list with nothing behind it.
        $payload = $this->payload();
        $payload['findings'] = [
            ['id' => self::FINDING_ID, 'question_code' => '3.1', 'response' => 'Y', 'gap' => 'Nothing wrong.'],
            ['id' => self::SECOND_FINDING_ID, 'question_code' => '3.2', 'response' => 'N', 'gap' => ''],
        ];

        $result = (new SyncService())->accept($payload);

        self::assertSame([], $result['accepted_findings']);
        self::assertSame(0, Finding::query()->count());
    }

    public function testASyncDoesNotReopenAClosedFinding(): void
    {
        $sync = new SyncService();
        $payload = $this->payload();
        $payload['findings'] = [
            ['id' => self::FINDING_ID, 'question_code' => '3.2', 'response' => 'N', 'gap' => 'No exposure SOP on site.'],
        ];

        $sync->accept($payload);

        // An administrator closes it while the device is out of coverage.
        Finding::query()->update(['status' => 'closed', 'closed_on' => '2026-08-06']);

        // The device syncs again, still holding the version it recorded.
        $sync->accept($payload);

        $finding = Finding::query()->first();
        self::assertNotNull($finding);
        self::assertSame('closed', $finding->status, 'the device must not reopen closed work');
    }

    public function testADraftBacksUpWithoutBecomingASubmission(): void
    {
        // Mid-visit backup. The work is off the tablet, but the visit has not
        // been submitted and must not read as though it has.
        $payload = $this->payload();
        $payload['status'] = 'draft';

        $result = (new SyncService())->accept($payload);

        $stored = Assessment::findByUuid($result['assessment_id']);
        self::assertNotNull($stored);
        self::assertSame('draft', $stored->status);
        self::assertNull($stored->submitted_at);
    }

    public function testASubmittedVisitIsNeverReopenedByALateRetry(): void
    {
        $sync = new SyncService();

        $sync->accept($this->submittablePayload());

        $submitted = Assessment::findByUuid('019fd200-0000-7000-8000-000000000001');
        self::assertNotNull($submitted);
        self::assertSame('submitted', $submitted->status);
        $submittedAt = (string) $submitted->submitted_at;

        // A draft payload from before the submission, arriving after it.
        $stale = $this->payload();
        $stale['status'] = 'draft';
        $sync->accept($stale);

        $after = Assessment::findByUuid('019fd200-0000-7000-8000-000000000001');
        self::assertNotNull($after);
        self::assertSame('submitted', $after->status);
        self::assertSame($submittedAt, (string) $after->submitted_at, 'the submission time is not restamped');
    }

    /**
     * One submission, however many times the device sends it.
     *
     * An assessor works in a building with no signal and the device retries
     * until the payload lands. A row per attempt would report one visit as
     * submitted five times on five different days, which is worse than no
     * record: it is a record that disagrees with itself.
     */
    public function testASubmissionIsAuditedOnceHoweverOftenItIsRetried(): void
    {
        Capsule::table('audit_log')->delete();

        $sync = new SyncService();
        $payload = $this->submittablePayload();

        $sync->accept($payload);
        $sync->accept($payload);
        $sync->accept($payload);

        self::assertSame(
            1,
            Capsule::table('audit_log')->where('action', AuditAction::ASSESSMENT_SUBMITTED)->count(),
        );
    }

    public function testRunningTheSamePayloadTwiceChangesNothing(): void
    {
        $sync = new SyncService();
        $payload = $this->payload();

        $first = $sync->accept($payload);
        $second = $sync->accept($payload);

        self::assertSame($first['assessment_id'], $second['assessment_id']);
        self::assertSame($first['score'], $second['score']);

        self::assertSame(1, Assessment::query()->count(), 'one site visit, not two');
        self::assertSame(3, Answer::query()->count(), 'answers upsert on their natural key');
        self::assertSame(2, AssessmentPathogen::query()->count());
        self::assertSame(1, AssessmentScore::query()->count());
    }

    public function testAChangedAnswerReplacesTheOldOneAndRescores(): void
    {
        $sync = new SyncService();
        $payload = $this->payload();

        $sync->accept($payload);

        // The assessor corrected 3.2 from No to Yes before syncing again.
        $payload['answers'][1]['response'] = 'Y';
        $second = $sync->accept($payload);

        self::assertSame(3, Answer::query()->count(), 'corrected, not duplicated');
        self::assertSame(6, $second['score']['total_score']);
        self::assertSame(146, $second['score']['total_possible']);
    }

    public function testNotApplicableLeavesTheDenominator(): void
    {
        $sync = new SyncService();
        $payload = $this->payload();

        // The same visit with 3.9 left unanswered, to measure against.
        $before = $sync->accept($payload);

        // 3.9 permits Not applicable; it must not be scored as a zero.
        $payload['answers'][] = ['question_code' => '3.9', 'response' => 'NA', 'comment' => 'No requirement.'];
        $result = $sync->accept($payload);

        self::assertSame(4, $result['score']['total_score'], 'NA earns nothing');

        // And it TAKES the question out of the denominator, which is not the
        // same as leaving it blank. Blank counts against the visit; Not
        // applicable says the question does not apply here and removes it.
        //
        // That difference is exactly why na_allowed exists on five questions
        // and not fifty-nine. A site that could answer NA freely could
        // certify by declaring the hard questions inapplicable.
        self::assertSame(
            $before['score']['total_possible'] - 2,
            $result['score']['total_possible'],
            'NA narrows the denominator; an unanswered question does not',
        );
    }

    /**
     * The device checks Part A from the same template before it sends, so this
     * should never fire against the app as shipped. That is what it is for: a
     * build from before the constraint existed, a replayed payload, a client
     * somebody wrote themselves.
     */
    public function testPartAOutsideTheInstrumentsLimitsIsRefused(): void
    {
        $payload = $this->payload();
        $payload['context']['previous_assessment_date'] = '2099-01-01';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/previous_assessment_date/');

        (new SyncService())->accept($payload);
    }

    /** Refused whole, so nothing is half-stored to be reconciled later. */
    public function testARefusedContextStoresNothing(): void
    {
        $payload = $this->payload();
        $payload['context']['poc_site_count'] = '-4';

        try {
            (new SyncService())->accept($payload);
            self::fail('the payload should have been refused');
        } catch (InvalidArgumentException) {
            // Expected.
        }

        self::assertSame(0, Assessment::query()->count());
        self::assertSame(0, Answer::query()->count());
    }

    /** The ordinary case, so the check cannot be tightened into refusing everything. */
    public function testValidPartAIsAccepted(): void
    {
        $payload = $this->payload();
        $payload['context']['previous_assessment_date'] = '2025-01-15';
        $payload['context']['poc_site_count'] = '3';

        $result = (new SyncService())->accept($payload);

        self::assertSame(3, count($result['accepted']));
    }

    /**
     * The rule the app has always shown and the server has never checked.
     *
     * docs/scoring.md said the submission endpoint refuses on is_complete and
     * is_valid. It did not — the device disabled its own button and the server
     * stored whatever arrived, which makes the rule a property of one build of
     * one client rather than of the record.
     */
    public function testAnIncompleteVisitCannotBeSubmitted(): void
    {
        $payload = $this->payload();
        $payload['status'] = 'submitted';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unanswered/');

        (new SyncService())->accept($payload);
    }

    /**
     * A comment is optional on every question, so what a gap obliges is not
     * words but an ACTION — something described, owned and dated, which is
     * what the site is left with when the assessor drives away. A visit
     * recording shortfalls and no corrective actions has measured a site
     * without helping it.
     */
    public function testAGapWithNoCorrectiveActionCannotBeSubmitted(): void
    {
        $payload = $this->submittablePayload();

        // One answer turned into a gap, and nothing said about it.
        $payload['answers'][0]['response'] = 'N';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/corrective action/');

        (new SyncService())->accept($payload);
    }

    /** A comment is not one. It is an observation, and optional everywhere. */
    public function testACommentIsNotACorrectiveAction(): void
    {
        $payload = $this->submittablePayload();
        $payload['answers'][0]['response'] = 'N';
        $payload['answers'][0]['comment'] = 'No organogram displayed anywhere on site.';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/corrective action/');

        (new SyncService())->accept($payload);
    }

    /** The same gap with an action against it goes through. */
    public function testAGapWithACorrectiveActionIsAccepted(): void
    {
        $payload = $this->submittablePayload();
        $payload['answers'][0]['response'] = 'N';
        $payload['findings'] = [
            [
                'id'            => self::FINDING_ID,
                'question_code' => $payload['answers'][0]['question_code'],
                'response'      => 'N',
                'gap'           => 'No organogram displayed anywhere on site.',
                'urgency'       => 'follow_up',
            ],
        ];

        $result = (new SyncService())->accept($payload);

        $stored = Assessment::findByUuid($result['assessment_id']);
        self::assertNotNull($stored);
        self::assertSame('submitted', $stored->status);
    }

    /**
     * A draft is accepted incomplete, deliberately. Syncing mid-visit is how an
     * assessor gets work off a tablet before losing it, and refusing that would
     * make the safest thing they can do the thing that fails.
     */
    public function testADraftIsAcceptedHoweverIncomplete(): void
    {
        $payload = $this->payload();
        $payload['status'] = 'draft';

        $result = (new SyncService())->accept($payload);

        self::assertSame(3, count($result['accepted']));
    }

    /**
     * The predecessor collected a geopoint on every submission and plotted one
     * marker per assessment. This is the reading that map is built from, and
     * it says where the assessor stood rather than where a record claims the
     * facility is.
     */
    public function testADeviceReadingIsStoredAsTheVisitsLocation(): void
    {
        $payload = $this->payload();
        $payload['latitude'] = -12.8024;
        $payload['longitude'] = 28.2132;
        $payload['accuracy_m'] = 12;
        $payload['located_at'] = '2026-08-05T09:14:00Z';

        $result = (new SyncService())->accept($payload);

        $stored = Assessment::findByUuid($result['assessment_id']);
        self::assertNotNull($stored);
        self::assertSame('device', $stored->location_source);
        self::assertSame(12, (int) $stored->accuracy_m);
        self::assertEqualsWithDelta(-12.8024, (float) $stored->latitude, 0.0000001);
    }

    /**
     * No fix is the ordinary case indoors, so the registry answers instead —
     * and says that it did, because an inherited pin and a measured one are
     * different facts and only one is evidence of a visit.
     */
    public function testWithNoReadingTheFacilitysOwnPositionIsUsed(): void
    {
        Capsule::table('facilities')
            ->where('id', BinaryUuid::toBytes($this->facilityId))
            ->update(['latitude' => -15.4167, 'longitude' => 28.2833]);

        $result = (new SyncService())->accept($this->payload());

        $stored = Assessment::findByUuid($result['assessment_id']);
        self::assertNotNull($stored);
        self::assertSame('facility', $stored->location_source);
        self::assertNull($stored->accuracy_m, 'the registry has no accuracy to report');
    }

    /**
     * A visit syncs repeatedly and the device may get a fix on only one of
     * those attempts. Later silence is absence of news, not news.
     */
    public function testALaterSyncWithNoReadingDoesNotClearThePosition(): void
    {
        $sync = new SyncService();

        $payload = $this->payload();
        $payload['latitude'] = -12.8024;
        $payload['longitude'] = 28.2132;
        $sync->accept($payload);

        $sync->accept($this->payload());

        $stored = Assessment::findByUuid('019fd200-0000-7000-8000-000000000001');
        self::assertNotNull($stored);
        self::assertSame('device', $stored->location_source);
        self::assertEqualsWithDelta(-12.8024, (float) $stored->latitude, 0.0000001);
    }

    /** Out of range is a broken client, not a place. The visit still stores. */
    public function testAnImpossibleCoordinateIsDroppedRatherThanStored(): void
    {
        $payload = $this->payload();
        $payload['latitude'] = 999;
        $payload['longitude'] = 28.2132;

        $result = (new SyncService())->accept($payload);

        $stored = Assessment::findByUuid($result['assessment_id']);
        self::assertNotNull($stored);
        self::assertNull($stored->latitude);
    }

    public function testAnotherOrganizationCannotSeeTheAssessment(): void
    {
        $result = (new SyncService())->accept($this->payload());

        $this->useTenant($this->orgB);

        self::assertNull(Assessment::findByUuid($result['assessment_id']));
        self::assertSame(0, Assessment::query()->count());
        self::assertSame(0, Answer::query()->count());
        self::assertSame(0, AssessmentScore::query()->count());
    }

    public function testAnotherOrganizationCannotOverwriteTheAssessment(): void
    {
        $result = (new SyncService())->accept($this->payload());

        $facilityB = $this->makeFacility($this->orgB, 'b1');
        $siteB = $this->makeSite($this->orgB, $facilityB, 'b2');

        $this->useTenant($this->orgB);

        $payload = $this->payload();
        $payload['id'] = $result['assessment_id'];
        $payload['facility_id'] = $facilityB;
        $payload['testing_site_id'] = $siteB;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to another organisation');

        (new SyncService())->accept($payload);
    }

    public function testAScopedQueryOutsideARequestRefusesToRun(): void
    {
        TenantContext::forget();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tenant context');

        Assessment::query()->count();
    }

    public function testAnAnswerNamingAnUndeclaredPathogenIsDropped(): void
    {
        $payload = $this->payload();
        $payload['answers'][] = ['question_code' => '4.2', 'pathogen' => 'ebola', 'response' => 'Y'];

        $result = (new SyncService())->accept($payload);

        self::assertSame(3, Answer::query()->count(), 'the undeclared pathogen answer is not stored');
        self::assertSame(146, $result['score']['total_possible']);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'id'               => self::ASSESSMENT_ID,
            'testing_site_id'  => $this->siteId,
            'facility_id'      => $this->facilityId,
            'template_code'    => 'spi-rdt',
            'template_version' => '1.0.0',
            'assessed_on'      => '2026-08-05',
            // Three answers out of fifty-nine is a draft. It defaulted to
            // 'submitted' before the server checked, which meant most of this
            // suite was exercising a submission that could never happen.
            'status'           => 'draft',
            'context'          => ['refers_specimens' => 'no'],
            'device_id'        => 'test-device',
            'pathogens'        => [
                ['key' => 'hiv', 'name' => 'HIV'],
                ['key' => 'syphilis', 'name' => 'Syphilis'],
            ],
            'answers'          => [
                ['question_code' => '3.1', 'response' => 'Y'],
                ['question_code' => '3.2', 'response' => 'N', 'comment' => 'No SOP on site.'],
                ['question_code' => '4.1', 'pathogen' => 'hiv', 'response' => 'Y'],
            ],
        ];
    }

    /**
     * A visit the server will actually accept as submitted: every expected
     * question answered, and a note against each response the template
     * obliges the assessor to explain.
     *
     * Built from the engine's own expectedQuestions rather than a hand-written
     * list, so it stays complete when the instrument gains a question. A
     * hand-written one would drift and start failing for the wrong reason.
     *
     * @return array<string,mixed>
     */
    private function submittablePayload(): array
    {
        $payload = $this->payload();
        $payload['status'] = 'submitted';

        $definition = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/resources/templates/spi-rdt-1.0.0.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $pathogens = array_map(
            static fn (array $row): string => (string) $row['key'],
            $payload['pathogens'],
        );

        $answers = [];

        foreach (
            (new ScoringEngine())->expectedQuestions($definition, $payload['context'], $pathogens) as $question
        ) {
            $answers[] = [
                'question_code' => $question->questionCode,
                'pathogen'      => $question->pathogen,
                'response'      => 'Y',
            ];
        }

        $payload['answers'] = $answers;

        return $payload;
    }

    /**
     * A finding as the old server would have stored it: an id it minted
     * itself, against the natural key it upserted on.
     */
    private function makeServerKeyedFinding(string $id, string $questionCode, string $gap): void
    {
        Capsule::table('findings')->insert([
            'id'              => BinaryUuid::toBytes($id),
            'id_origin'       => 'server',
            'organization_id' => $this->orgA,
            'assessment_id'   => BinaryUuid::toBytes(self::ASSESSMENT_ID),
            'question_code'   => $questionCode,
            'response'        => 'N',
            'gap'             => $gap,
        ]);
    }

    /**
     * $slot is two hex characters chosen by the caller, NOT derived from the
     * organisation id. Organisation ids come from an auto-increment that keeps
     * climbing between runs because deleting rows does not reset it, and once
     * it reached three digits the concatenated string stopped being a UUID —
     * after several runs had already passed.
     */
    private function makeFacility(int $organizationId, string $slot): string
    {
        $id = '019fd200-0000-7000-8000-0000000000' . $slot;

        Capsule::table('facilities')->insert([
            'id'              => BinaryUuid::toBytes($id),
            'programme_id'    => $this->programmeFor($organizationId),
            'organization_id' => $organizationId,
            'name'            => 'Facility ' . $organizationId,
            'source'          => 'registry',
        ]);

        return $id;
    }

    private function makeSite(int $organizationId, string $facilityId, string $slot): string
    {
        $id = '019fd200-0000-7000-8000-0000000001' . $slot;

        Capsule::table('testing_sites')->insert([
            'id'              => BinaryUuid::toBytes($id),
            'programme_id'    => $this->programmeFor($organizationId),
            'organization_id' => $organizationId,
            'facility_id'     => BinaryUuid::toBytes($facilityId),
            'name'            => 'Site ' . $organizationId,
            'source'          => 'registry',
        ]);

        return $id;
    }
}
