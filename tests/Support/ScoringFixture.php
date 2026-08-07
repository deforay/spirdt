<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Scoring\ScoringEngine;

/**
 * Reads the shared fixtures in tests/fixtures/scoring/ and expands them into
 * engine input.
 *
 * The expansion rules — override precedence, the `@pathogen` key form,
 * default_response, omit — are specified in that directory's README because
 * the TypeScript suite has to implement the same ones. Anything this class
 * decides for itself is a place the two harnesses can disagree while both
 * engines are correct, so it decides as little as possible.
 */
final class ScoringFixture
{
    public static function root(): string
    {
        return dirname(__DIR__) . '/fixtures/scoring';
    }

    /**
     * @return array<string,mixed>
     */
    public static function read(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException("Unreadable fixture: {$path}");
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException("Fixture is not a JSON object: {$path}");
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /**
     * Every case file, keyed by name so a PHPUnit failure names the fixture
     * rather than a row number.
     *
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function cases(): array
    {
        $files = glob(self::root() . '/cases/*.json');

        if ($files === false || $files === []) {
            throw new \RuntimeException('No scoring cases found. The fixtures are the contract; an empty suite is a broken one, not a passing one.');
        }

        $cases = [];

        foreach ($files as $file) {
            $fixture = self::read($file);
            $name    = is_string($fixture['name'] ?? null) ? $fixture['name'] : basename($file, '.json');

            $cases[$name] = [$fixture];
        }

        return $cases;
    }

    /**
     * Resolve a template name. Real instruments first, so a fixture cannot
     * shadow the canonical template with a convenient local copy that then
     * stops tracking it.
     *
     * @return array<string,mixed>
     */
    public static function template(string $name): array
    {
        $candidates = [
            dirname(__DIR__, 2) . '/resources/templates/' . $name . '.json',
            self::root() . '/templates/' . $name . '.json',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return self::read($path);
            }
        }

        throw new \RuntimeException("Unknown template in fixture: {$name}");
    }

    /**
     * Expand a case into the answer rows the engine takes.
     *
     * @param  array<string,mixed>                                                $fixture
     * @param  array<string,mixed>                                                $template
     * @param  array<string,mixed>                                                $context
     * @param  list<string>                                                       $pathogens
     * @return list<array{question_code:string,response:string,pathogen:?string}>
     */
    public static function answers(array $fixture, array $template, array $context, array $pathogens): array
    {
        $overrides = is_array($fixture['answers'] ?? null) ? $fixture['answers'] : [];
        $omit      = is_array($fixture['omit'] ?? null) ? $fixture['omit'] : [];
        $default   = is_string($fixture['default_response'] ?? null) ? $fixture['default_response'] : null;
        $comments  = is_array($fixture['comments'] ?? null) ? $fixture['comments'] : [];

        $answers = [];

        foreach ((new ScoringEngine())->expectedQuestions($template, $context, $pathogens) as $question) {
            $instance = $question->pathogen === null
                ? $question->questionCode
                : $question->questionCode . '@' . $question->pathogen;

            if (in_array($instance, $omit, true) || in_array($question->questionCode, $omit, true)) {
                continue;
            }

            $response = $overrides[$instance] ?? $overrides[$question->questionCode] ?? $default;

            if (!is_string($response)) {
                continue;
            }

            $answers[] = [
                'question_code' => $question->questionCode,
                'pathogen'      => $question->pathogen,
                'response'      => $response,
                'comment'       => $comments[$instance] ?? $comments[$question->questionCode] ?? null,
            ];
        }

        foreach (is_array($fixture['extra_answers'] ?? null) ? $fixture['extra_answers'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $answers[] = [
                'question_code' => (string) ($row['question_code'] ?? ''),
                'pathogen'      => isset($row['pathogen']) && is_string($row['pathogen']) ? $row['pathogen'] : null,
                'response'      => (string) ($row['response'] ?? ''),
            ];
        }

        return $answers;
    }
}
