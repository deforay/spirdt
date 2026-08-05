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

    /**
     * The proposed password will not do.
     *
     * Unlike the messages above, this one says exactly what is wrong. The
     * person reading it has already proved who they are, so there is nothing
     * left to conceal — and "that password is not acceptable" without a reason
     * is how somebody ends up trying the same thing four times.
     */
    public static function passwordUnacceptable(string $reason): self
    {
        return new self($reason, 422);
    }

    /**
     * Nothing else will answer until the password is changed.
     *
     * 403 rather than 401, because the token is perfectly valid and signing in
     * again would change nothing — a 401 would send the client round a refresh
     * loop that cannot resolve.
     */
    public static function passwordChangeRequired(): self
    {
        return new self('Change your password before you continue.', 403);
    }
}
