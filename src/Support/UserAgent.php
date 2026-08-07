<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Platform and browser, from a user agent string.
 *
 * Deliberately small, and deliberately not a library. What this is for is
 * answering "is the problem confined to one kind of device" when an assessor
 * reports something — Android against iPad, Chrome against Safari — and that
 * question needs the broad family, not a version number.
 *
 * The raw string is stored beside the result, so a better parser can be run
 * over the same rows later. Nothing here is load-bearing: every caller treats
 * an unrecognised agent as unknown rather than as an error, because a new
 * browser is not a fault.
 *
 * Order matters in both lists below. Every Chrome on Android says it is Safari
 * and most say they are Mozilla; Edge says it is Chrome. Matching the most
 * specific claim first is the whole technique.
 */
final class UserAgent
{
    /** @var list<array{0:string,1:string}> */
    private const PLATFORMS = [
        // Before Android, which iPads on desktop mode do not claim, and before
        // Linux, which Android is.
        ['iPhone', 'iOS'],
        ['iPad', 'iPadOS'],
        ['Android', 'Android'],
        ['Windows', 'Windows'],
        // Before Linux: a Mac says neither, but CrOS says Linux too.
        ['CrOS', 'ChromeOS'],
        ['Mac OS X', 'macOS'],
        ['Linux', 'Linux'],
    ];

    /** @var list<array{0:string,1:string}> */
    private const BROWSERS = [
        // Edge and Opera both carry Chrome in their agent, and Chrome carries
        // Safari. Specific first, or everything is Safari.
        ['Edg/', 'Edge'],
        ['OPR/', 'Opera'],
        ['SamsungBrowser', 'Samsung Internet'],
        ['Firefox/', 'Firefox'],
        ['Chrome/', 'Chrome'],
        ['Safari/', 'Safari'],
    ];

    /** @return array{platform:?string,browser:?string} */
    public static function parse(?string $agent): array
    {
        if ($agent === null || trim($agent) === '') {
            return ['platform' => null, 'browser' => null];
        }

        return [
            'platform' => self::first($agent, self::PLATFORMS),
            'browser'  => self::first($agent, self::BROWSERS),
        ];
    }

    /** @param list<array{0:string,1:string}> $table */
    private static function first(string $agent, array $table): ?string
    {
        foreach ($table as [$needle, $name]) {
            if (stripos($agent, $needle) !== false) {
                return $name;
            }
        }

        return null;
    }
}
