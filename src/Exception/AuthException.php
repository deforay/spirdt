<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * A sign-in that cannot proceed, carrying the status the caller should send.
 *
 * The messages here are read by a person standing in a clinic, so they say what
 * to do next. What they never say is which half of a credential was wrong —
 * "no such account" and "wrong password" are the same sentence, because the
 * difference is a way to discover who holds an account on an installation.
 */
final class AuthException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('That email address and password do not match.', 401);
    }

    /**
     * The account exists in more than one organisation on this installation.
     *
     * Only reachable once the password has already verified, so it tells an
     * attacker nothing they did not already have.
     */
    public static function organizationRequired(): self
    {
        return new self('Enter your organisation code as well.', 409);
    }

    public static function inactive(): self
    {
        return new self('That account is switched off. Ask your administrator.', 403);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(
            'Too many sign-in attempts. Wait a few minutes and try again.',
            429,
            $retryAfter,
        );
    }

    public static function refreshRejected(): self
    {
        return new self('Your session has ended. Sign in again.', 401);
    }
}
