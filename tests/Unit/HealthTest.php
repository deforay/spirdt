<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Proves the harness and autoloader are wired up, independently of anything
 * with logic in it — so a red suite can be told apart from a broken one.
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
