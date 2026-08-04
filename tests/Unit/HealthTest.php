<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Placeholder that proves the harness and autoloader are wired up.
 * Replace with the scoring-engine fixture suite once the engine lands.
 */
final class HealthTest extends TestCase
{
    public function testEnvHelperCastsBooleanStrings(): void
    {
        $_ENV['SPIRDT_TEST_FLAG'] = 'false';

        self::assertFalse(env('SPIRDT_TEST_FLAG'));
        self::assertSame('fallback', env('SPIRDT_MISSING_KEY', 'fallback'));
    }
}
