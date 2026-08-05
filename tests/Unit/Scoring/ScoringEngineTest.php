<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Scoring\ScoreResult;
use App\Scoring\ScoringEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScoringFixture;

/**
 * Whole assessments, scored end to end, against tests/fixtures/scoring/cases/.
 *
 * The same files drive the TypeScript suite. Adding a case is adding a file;
 * there is nothing to register here, which is what stops the two suites from
 * covering different sets.
 */
final class ScoringEngineTest extends TestCase
{
    /** Everything a case may assert. Anything else is a typo, not an opinion. */
    private const EXPECT_KEYS = [
        'total_score', 'total_possible', 'percentage', 'level', 'pathogen_count',
        'is_scorable', 'is_complete', 'is_valid',
        'missing_count', 'unexpected_count', 'violation_count',
        'sections', 'pathogens',
    ];

    private const SECTION_KEYS = ['score', 'possible', 'answered', 'excluded', 'applicable'];

    private const PATHOGEN_KEYS = ['score', 'possible', 'answered', 'excluded'];

    private const FIXTURE_KEYS = [
        'name', 'why', 'template', 'context', 'pathogens',
        'default_response', 'answers', 'omit', 'extra_answers', 'expect',
    ];

    /**
     * @param array<string,mixed> $fixture
     */
    #[DataProvider('cases')]
    public function testFixture(array $fixture): void
    {
        self::assertSame(
            [],
            array_diff(array_keys($fixture), self::FIXTURE_KEYS),
            'unrecognised fixture keys — see tests/fixtures/scoring/README.md',
        );

        self::assertIsString($fixture['why'] ?? null, 'every case states what it exists to prove');

        /** @var array<string,mixed> $context */
        $context = is_array($fixture['context'] ?? null) ? $fixture['context'] : [];

        /** @var list<string> $pathogens */
        $pathogens = is_array($fixture['pathogens'] ?? null) ? array_values($fixture['pathogens']) : [];

        $template = ScoringFixture::template((string) $fixture['template']);
        $answers  = ScoringFixture::answers($fixture, $template, $context, $pathogens);

        $result = (new ScoringEngine())->score($template, $answers, $context, $pathogens);

        /** @var array<string,mixed> $expect */
        $expect = is_array($fixture['expect'] ?? null) ? $fixture['expect'] : [];

        self::assertSame(
            [],
            array_diff(array_keys($expect), self::EXPECT_KEYS),
            'unrecognised expect keys — an expectation nothing reads is an expectation nothing enforces',
        );

        $this->assertTotals($expect, $result);
        $this->assertSections($expect, $result);
        $this->assertPathogens($expect, $result);
    }

    /**
     * The breakdown has to survive the round trip through a JSON column, since
     * that is the only form it is ever read back in.
     */
    public function testBreakdownIsJsonSerialisable(): void
    {
        $template = ScoringFixture::template('spi-rdt-1.0.0');
        $context  = ['refers_specimens' => 'yes'];
        $answers  = ScoringFixture::answers(['default_response' => 'Y'], $template, $context, ['hiv']);

        $result  = (new ScoringEngine())->score($template, $answers, $context, ['hiv']);
        $encoded = json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        self::assertJson($encoded);
        self::assertSame(ScoringEngine::VERSION, $result->scoringVersion);
    }

    /**
     * Two answers to the same question is only reachable from a malformed sync
     * payload — the answers table's unique key makes it unreachable from
     * stored data. Reported rather than resolved: choosing between two
     * conflicting answers is not a decision the engine has any basis to make.
     */
    public function testConflictingAnswersAreReportedNotResolved(): void
    {
        $template = ScoringFixture::template('fixture-custom-bands');

        $result = (new ScoringEngine())->score($template, [
            ['question_code' => '1.1', 'pathogen' => null, 'response' => 'Y'],
            ['question_code' => '1.1', 'pathogen' => null, 'response' => 'N'],
            ['question_code' => '1.2', 'pathogen' => null, 'response' => 'Y'],
            ['question_code' => '1.3', 'pathogen' => null, 'response' => 'Y'],
            ['question_code' => '1.4', 'pathogen' => null, 'response' => 'Y'],
        ]);

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->violations);
        self::assertStringContainsString('more than once', $result->violations[0]);
        self::assertSame(8, $result->totalScore, 'the first answer stands; the duplicate is dropped, not added');
    }

    /**
     * A response outside the template's set — a newer app version, a corrupted
     * payload — must not be scored as anything, least of all as a zero.
     */
    public function testUnrecognisedResponseIsRejected(): void
    {
        $template = ScoringFixture::template('fixture-custom-bands');

        $result = (new ScoringEngine())->score($template, [
            ['question_code' => '1.1', 'pathogen' => null, 'response' => 'MAYBE'],
        ]);

        self::assertFalse($result->isValid());
        self::assertFalse($result->isComplete());
        self::assertSame(0, $result->totalPossible);
    }

    /**
     * refers_specimens arrives as the option key 'yes' from the form, as a
     * boolean from the API and as an integer from a column. All three name the
     * same fact and must resolve the same way — a Section 5 that appears for
     * one caller and not another is 18 points of denominator that moves.
     *
     * @param mixed $value
     */
    #[DataProvider('applicabilityValues')]
    public function testApplicabilityFieldAcceptsTheShapesItArrivesIn(mixed $value, bool $applies): void
    {
        $template = ScoringFixture::template('spi-rdt-1.0.0');
        $context  = ['refers_specimens' => $value];

        $expected = (new ScoringEngine())->expectedQuestions($template, $context, []);
        $inFive   = array_filter($expected, static fn ($q) => $q->sectionCode === '5');

        self::assertCount($applies ? 9 : 0, $inFive);
    }

    /**
     * @return array<string,array{0:mixed,1:bool}>
     */
    public static function applicabilityValues(): array
    {
        return [
            'option key yes' => ['yes', true],
            'option key no'  => ['no', false],
            'boolean true'   => [true, true],
            'boolean false'  => [false, false],
            'integer one'    => [1, true],
            'integer zero'   => [0, false],
            'absent'         => [null, false],
        ];
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function cases(): array
    {
        return ScoringFixture::cases();
    }

    /**
     * @param array<string,mixed> $expect
     */
    private function assertTotals(array $expect, ScoreResult $result): void
    {
        $actual = [
            'total_score'      => $result->totalScore,
            'total_possible'   => $result->totalPossible,
            'percentage'       => $result->percentage,
            'level'            => $result->level,
            'pathogen_count'   => $result->pathogenCount,
            'is_scorable'      => $result->isScorable(),
            'is_complete'      => $result->isComplete(),
            'is_valid'         => $result->isValid(),
            'missing_count'    => count($result->missing),
            'unexpected_count' => count($result->unexpected),
            'violation_count'  => count($result->violations),
        ];

        foreach ($actual as $key => $value) {
            if (!array_key_exists($key, $expect)) {
                continue;
            }

            $wanted = $expect[$key];

            // A percentage written as 100 rather than 100.0 means the same
            // thing to a reader and should not fail on the type alone.
            if ($key === 'percentage' && is_int($wanted)) {
                $wanted = (float) $wanted;
            }

            self::assertSame($wanted, $value, $key);
        }
    }

    /**
     * @param array<string,mixed> $expect
     */
    private function assertSections(array $expect, ScoreResult $result): void
    {
        if (!is_array($expect['sections'] ?? null)) {
            return;
        }

        $bySection = array_column($result->sections, null, 'code');

        /** @var array<string,mixed> $sections */
        $sections = $expect['sections'];

        foreach ($sections as $code => $wanted) {
            self::assertArrayHasKey((string) $code, $bySection, "section {$code} missing from the breakdown");
            self::assertIsArray($wanted);
            self::assertSame([], array_diff(array_keys($wanted), self::SECTION_KEYS), "section {$code}: unrecognised keys");

            foreach ($wanted as $key => $value) {
                self::assertSame($value, $bySection[(string) $code][$key], "section {$code}.{$key}");
            }
        }
    }

    /**
     * @param array<string,mixed> $expect
     */
    private function assertPathogens(array $expect, ScoreResult $result): void
    {
        if (!is_array($expect['pathogens'] ?? null)) {
            return;
        }

        $byPathogen = array_column($result->pathogens, null, 'key');

        /** @var array<string,mixed> $pathogens */
        $pathogens = $expect['pathogens'];

        self::assertCount(count($pathogens), $byPathogen, 'pathogen breakdown has entries the case does not name');

        foreach ($pathogens as $key => $wanted) {
            self::assertArrayHasKey((string) $key, $byPathogen, "pathogen {$key} missing from the breakdown");
            self::assertIsArray($wanted);
            self::assertSame([], array_diff(array_keys($wanted), self::PATHOGEN_KEYS), "pathogen {$key}: unrecognised keys");

            foreach ($wanted as $field => $value) {
                self::assertSame($value, $byPathogen[(string) $key][$field], "pathogen {$key}.{$field}");
            }
        }
    }
}
