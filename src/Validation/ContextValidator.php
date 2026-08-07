<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Checks Part A answers against the limits the template declares.
 *
 * Written twice, once here and once in TypeScript, for the same reason the
 * scoring engine is: the assessor has to be told at the bench that a date is
 * wrong, and the server cannot be the only thing that knows. Both read the same
 * `constraints` block out of the template, and the shared fixtures under
 * tests/fixtures/context are what stop the two drifting.
 *
 * Nothing about the instrument is encoded here. Which field may not hold a
 * future date, and how many testing sites is too many, are facts about the form
 * — a country customising it changes them in the template and neither codebase
 * moves.
 *
 * Blank is not invalid. A field left empty is either optional, in which case
 * there is nothing to check, or required, which is a different question already
 * answered elsewhere. Reporting an empty optional field as out of range would
 * mean an assessor could not leave it empty.
 */
final class ContextValidator
{
    /**
     * Days of grace on a future-date check, server side only.
     *
     * A device in Suva and a server in London disagree about today's date for
     * ten hours out of every twenty-four. Without this, a visit recorded on the
     * morning of the 8th is refused by a server for which it is still the 7th —
     * and the assessor is shown a date error they cannot act on, because the
     * date is correct where they are standing.
     *
     * One day is enough for any real timezone offset and still catches what
     * this is for, which is a year typed wrongly.
     */
    private const FUTURE_GRACE_DAYS = 1;

    /**
     * @param  array<string,mixed>  $template
     * @param  array<string,mixed>  $context
     * @return list<Problem>        empty when everything checks out
     */
    public function validate(array $template, array $context): array
    {
        $problems = [];

        foreach ($this->fieldsOf($template) as $field) {
            $code = is_string($field['code'] ?? null) ? $field['code'] : '';

            if ($code === '') {
                continue;
            }

            $constraints = $field['constraints'] ?? null;

            if (!is_array($constraints) || $constraints === []) {
                continue;
            }

            $value = $context[$code] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $problem = $this->check(
                $code,
                is_string($field['type'] ?? null) ? $field['type'] : '',
                $constraints,
                $value,
            );

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @param array<string,mixed> $constraints
     */
    private function check(string $code, string $type, array $constraints, mixed $value): ?Problem
    {
        if ($type === 'integer') {
            return $this->checkInteger($code, $constraints, $value);
        }

        if ($type === 'date') {
            return $this->checkDate($code, $constraints, $value);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $constraints
     */
    private function checkInteger(string $code, array $constraints, mixed $value): ?Problem
    {
        // Strict, because the point of an integer field is that the answer can
        // be counted. "12 or so" is not a number of testing sites, and a form
        // that accepts it produces a column nobody can total.
        if (is_bool($value) || !is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
            return new Problem($code, 'not_an_integer');
        }

        $number = (int) $value;
        $min    = $constraints['min'] ?? null;
        $max    = $constraints['max'] ?? null;

        if (is_int($min) && $number < $min) {
            return new Problem($code, 'below_min', ['min' => $min]);
        }

        if (is_int($max) && $number > $max) {
            return new Problem($code, 'above_max', ['max' => $max]);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $constraints
     */
    private function checkDate(string $code, array $constraints, mixed $value): ?Problem
    {
        if (!is_string($value)) {
            return new Problem($code, 'not_a_date');
        }

        // The stored form, and the only one accepted. Anything else is a
        // client that has been changed without this being changed with it.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return new Problem($code, 'not_a_date');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (!checkdate($month, $day, $year)) {
            return new Problem($code, 'not_a_date');
        }

        if (($constraints['not_future'] ?? false) === true && $value > $this->latestAcceptableDate()) {
            return new Problem($code, 'in_the_future');
        }

        return null;
    }

    /** Compared as strings, which is exact for ISO dates and needs no timezone. */
    private function latestAcceptableDate(): string
    {
        return gmdate('Y-m-d', time() + self::FUTURE_GRACE_DAYS * 86400);
    }

    /**
     * Part A fields, including the ones nested inside a repeat.
     *
     * @param  array<string,mixed>       $template
     * @return list<array<string,mixed>>
     */
    private function fieldsOf(array $template): array
    {
        $fields = [];

        foreach ((array) ($template['context_fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fields[] = $field;

            foreach ((array) ($field['fields'] ?? []) as $nested) {
                if (is_array($nested)) {
                    $fields[] = $nested;
                }
            }
        }

        return $fields;
    }
}
