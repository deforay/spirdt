<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Validation\ContextValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Part A validation, against the shared fixtures.
 *
 * The cases live in tests/fixtures/context because the TypeScript validator
 * reads the same files. A rule proved here and not there is a rule the
 * assessor is told about at the bench and the server disagrees with, or the
 * reverse — and either way somebody is arguing with a form.
 *
 * This class decides as little as possible. Anything it works out for itself
 * is a place the two harnesses can disagree while both validators are correct.
 */
final class ContextValidatorTest extends TestCase
{
    /**
     * @return iterable<string,array{array<string,mixed>}>
     */
    public static function cases(): iterable
    {
        $files = glob(dirname(__DIR__, 2) . '/fixtures/context/*.json') ?: [];

        self::assertNotSame([], $files, 'the fixtures directory is empty');

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \RuntimeException("Fixture is not a JSON object: {$file}");
            }

            yield basename($file, '.json') => [$decoded];
        }
    }

    /**
     * @param array<string,mixed> $case
     */
    #[DataProvider('cases')]
    public function testFixture(array $case): void
    {
        $template = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 3) . '/resources/templates/' . $case['template'] . '.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($template);

        $problems = (new ContextValidator())->validate($template, (array) $case['context']);

        $actual = array_map(static fn ($problem): array => $problem->toArray(), $problems);

        self::assertEquals(
            $case['expect'],
            $actual,
            (string) $case['why'],
        );
    }
}
