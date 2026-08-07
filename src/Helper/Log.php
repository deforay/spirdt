<?php

declare(strict_types=1);

namespace App\Helper;

use Psr\Log\LoggerInterface;

/**
 * Static gateway to the request-scoped Monolog logger.
 *
 * Services that don't take a LoggerInterface in their constructor would
 * otherwise reach for a bare error_log(), which bypasses the UidProcessor —
 * so a failed sync or a dropped audit write lands in the process log with no
 * request UID to correlate it against the surrounding API call. This routes
 * those lines through the SAME logger instance the container builds, so every
 * line carries the per-request UID that ApiLoggerMiddleware and ErrorHandler
 * already stamp.
 *
 * Before the logger is seeded (early boot, CLI one-offs, the test harness)
 * calls fall back to PHP's error_log() so a line is never silently lost.
 *
 * This file holds the ONLY sanctioned error_log() in src/ — bin/check-code-guards
 * bans it everywhere else.
 */
final class Log
{
    private static ?LoggerInterface $logger = null;

    /**
     * The UID stamped on every line this process writes.
     *
     * Held here as well as inside Monolog's processor because the request log
     * table stores it too, and a row that cannot be joined to the lines
     * written while handling the same request is half a record. Monolog keeps
     * it private to the processor, so the value is handed over at boot rather
     * than dug out later.
     */
    private static ?string $requestUid = null;

    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public static function setRequestUid(?string $uid): void
    {
        self::$requestUid = $uid;
    }

    public static function requestUid(): ?string
    {
        return self::$requestUid;
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        if (self::$logger instanceof LoggerInterface) {
            self::$logger->{$level}($message, $context);

            return;
        }

        error_log(sprintf(
            '[%s] %s %s',
            strtoupper($level),
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES),
        ));
    }
}
