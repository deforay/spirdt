<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Scoring\Percentage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScoringFixture;

/**
 * The percentage arithmetic against tests/fixtures/scoring/banding.json.
 *
 * The same file drives the TypeScript suite. If these two ever disagree, one
 * of the two engines is quietly certifying sites the other would not.
 */
final class PercentageTest extends TestCase
{
    /**
     * @param array{score:int,possible:int,round_dp:int,expect:array{percentage:float|null,level:int|null}} $case
     * @param list<array{level:int,min_percent:int|float}>                                                  $bands
     */
    #[DataProvider('bandingCases')]
    public function testFixture(array $case, array $bands): void
    {
        $scaled = Percentage::scaled($case['score'], $case['possible'], $case['round_dp']);

        $percentage = $scaled === null ? null : Percentage::toFloat($scaled, $case['round_dp']);
        $level      = Percentage::level($scaled, $bands, $case['round_dp']);

        self::assertSame($case['expect']['percentage'], $percentage, 'percentage');
        self::assertSame($case['expect']['level'], $level, 'level');
    }

    /**
     * A percentage is never allowed to exceed its own precision. Catches an
     * implementation that divides first and rounds for display only, which
     * looks identical until the value is banded or written to DECIMAL(5,2).
     */
    public function testScaledIsAlwaysAnInteger(): void
    {
        for ($score = 0; $score <= 206; ++$score) {
            $scaled = Percentage::scaled($score, 206, 2);

            self::assertNotNull($scaled);

            $value = Percentage::toFloat($scaled, 2);

            self::assertSame(
                round($value, 2),
                $value,
                "score {$score} of 206 produced more than two decimal places",
            );
        }
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:list<array{level:int,min_percent:int|float}>}>
     */
    public static function bandingCases(): array
    {
        $fixture = ScoringFixture::read(ScoringFixture::root() . '/banding.json');

        /** @var list<array{level:int,min_percent:int|float}> $bands */
        $bands = $fixture['bands'];

        $cases = [];

        /** @var list<array<string,mixed>> $rows */
        $rows = $fixture['cases'];

        foreach ($rows as $row) {
            $label = sprintf('%s of %s at %s dp', $row['score'], $row['possible'], $row['round_dp']);

            $cases[$label] = [$row, $bands];
        }

        return $cases;
    }
}
