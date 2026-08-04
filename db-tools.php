<?php

declare(strict_types=1);

/**
 * db-tools configuration — maps our .env names onto what db-tools expects.
 * See: https://github.com/amitdugar/db-tools
 *
 * db-tools does NOT load .env itself, and it gets invoked down several paths
 * (shell, cron, spawned subprocess), so this file loads .env on its own.
 * safeLoad() so it never clobbers a variable the environment set deliberately.
 */

if (is_file(__DIR__ . '/.env') && class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

// Check getenv() AND $_ENV: PHP's variables_order may omit "E", which leaves
// $_ENV empty when the variables come from the real OS environment.
$envVar = static function (string $key, string $default = ''): string {
    $value = getenv($key);

    if ($value !== false && $value !== '') {
        return $value;
    }

    return isset($_ENV[$key]) && $_ENV[$key] !== '' ? (string) $_ENV[$key] : $default;
};

$backupDir = __DIR__ . '/var/backups/db';

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0o775, true);
}

return [
    'default' => [
        'host'       => $envVar('DB_HOST', '127.0.0.1'),
        'port'       => (int) $envVar('DB_PORT', '3306'),
        'database'   => $envVar('DB_NAME', 'spirdt'),
        'user'       => $envVar('DB_USER', 'root'),
        'password'   => $envVar('DB_PASS', ''),
        'output_dir' => $backupDir,
        // Assessment data is the product. Keep a fortnight of daily restore
        // points rather than the usual week.
        'retention'  => 14,
    ],
];
