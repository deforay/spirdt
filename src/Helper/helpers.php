<?php

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * Read an environment variable with a typed fallback.
     *
     * Casts the string forms of booleans and null that .env files produce,
     * so callers get real bools instead of the string "false" — which is
     * truthy and has burned every project that skipped this.
     */
    function env(string $key, mixed $default = null): mixed
    {
        // getenv() returns false when unset; the ?? chain already rules out
        // null, so false and '' are the only "absent" cases left to handle.
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            default            => $value,
        };
    }
}
