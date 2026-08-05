<?php

declare(strict_types=1);

/**
 * bin/lib/console.php — shared console output, prompts and shell helpers.
 *
 * Every bin/ script that talks to a human uses these, so the output looks the
 * same everywhere and there is one place to change it. Deliberately
 * zero-dependency: setup runs before composer install, so nothing here may
 * touch vendor/.
 */

if (!function_exists('con_supports_colour')) {
    function con_supports_colour(): bool
    {
        static $supported = null;

        if ($supported === null) {
            $supported = stream_isatty(STDOUT) && getenv('NO_COLOR') === false;
        }

        return $supported;
    }
}

if (!function_exists('con_paint')) {
    function con_paint(string $text, string $colour): string
    {
        if (!con_supports_colour()) {
            return $text;
        }

        $codes = [
            'red' => 31, 'green' => 32, 'yellow' => 33,
            'blue' => 34, 'cyan' => 36, 'grey' => 90,
            'bold' => 1, 'dim' => 2,
        ];

        return "\033[" . ($codes[$colour] ?? 0) . 'm' . $text . "\033[0m";
    }
}

if (!function_exists('con_print')) {
    /**
     * Levelled output. Errors and warnings go to STDERR so a caller can
     * separate them from normal output when piping.
     *
     * @param 'info'|'success'|'warn'|'error'|'step'|'header'|'plain' $level
     */
    function con_print(string $level, string $message): void
    {
        [$prefix, $colour, $stream] = match ($level) {
            'success' => ['  ok    ', 'green',  STDOUT],
            'warn'    => ['  warn  ', 'yellow', STDERR],
            'error'   => ['  fail  ', 'red',    STDERR],
            'info'    => ['        ', 'grey',   STDOUT],
            'step'    => ['', 'bold', STDOUT],
            'header'  => ['', 'cyan', STDOUT],
            default   => ['', '', STDOUT],
        };

        // Flush STDOUT before writing anything to STDERR. The two streams
        // buffer independently, so without this an error can surface above
        // output that was actually emitted before it — which reads as though
        // the steps ran out of order.
        if ($stream === STDERR) {
            fflush(STDOUT);
        }

        if ($level === 'header') {
            fwrite($stream, "\n" . con_paint($message, $colour) . "\n");

            return;
        }

        if ($level === 'step') {
            fwrite($stream, "\n" . con_paint($message, $colour) . "\n");

            return;
        }

        fwrite(
            $stream,
            ($colour === '' ? $prefix : con_paint($prefix, $colour)) . $message . "\n",
        );
    }
}

if (!function_exists('con_fail')) {
    function con_fail(string $message, int $code = 1): never
    {
        con_print('error', $message);
        exit($code);
    }
}

if (!function_exists('con_run')) {
    /**
     * Run a shell command, capturing combined output.
     *
     * @return array{0:string,1:int} [output, exitCode]
     */
    function con_run(string $command, bool $simulate = false): array
    {
        if ($simulate) {
            con_print('info', 'would run: ' . $command);

            return ['', 0];
        }

        $output = [];
        $code   = 0;
        exec($command . ' 2>&1', $output, $code);

        return [implode("\n", $output), $code];
    }
}

if (!function_exists('con_is_interactive')) {
    /**
     * Whether we may prompt. CI, cron and container exec runs must never
     * block waiting on a human that will never type — every prompt therefore
     * needs a non-interactive answer via flag or environment.
     */
    function con_is_interactive(): bool
    {
        return stream_isatty(STDIN) && getenv('CI') === false;
    }
}

if (!function_exists('con_ask')) {
    function con_ask(string $question, string $default = ''): string
    {
        if (!con_is_interactive()) {
            return $default;
        }

        $suffix = $default === '' ? '' : con_paint(" [{$default}]", 'grey');
        fwrite(STDOUT, con_paint('  ? ', 'cyan') . $question . $suffix . ': ');

        $answer = trim((string) fgets(STDIN));

        return $answer === '' ? $default : $answer;
    }
}

if (!function_exists('con_ask_secret')) {
    /**
     * Prompt without echoing. Falls back to a visible prompt when stty is
     * unavailable — better a visible password than a hung script.
     */
    function con_ask_secret(string $question): string
    {
        if (!con_is_interactive()) {
            return '';
        }

        fwrite(STDOUT, con_paint('  ? ', 'cyan') . $question . ': ');

        [, $sttyCheck] = con_run('command -v stty');
        if ($sttyCheck !== 0) {
            $answer = trim((string) fgets(STDIN));
            fwrite(STDOUT, "\n");

            return $answer;
        }

        con_run('stty -echo');
        $answer = trim((string) fgets(STDIN));
        con_run('stty echo');
        fwrite(STDOUT, "\n");

        return $answer;
    }
}

if (!function_exists('con_confirm')) {
    function con_confirm(string $question, bool $default = false): bool
    {
        if (!con_is_interactive()) {
            return $default;
        }

        $hint   = $default ? 'Y/n' : 'y/N';
        $answer = strtolower(con_ask($question . con_paint(" ({$hint})", 'grey')));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes'], true);
    }
}

if (!function_exists('con_confirm_typed')) {
    /**
     * Confirmation for destructive actions. Requires the exact word, so a
     * reflexive "y" cannot drop a database.
     */
    function con_confirm_typed(string $question, string $word): bool
    {
        if (!con_is_interactive()) {
            return false;
        }

        con_print('warn', $question);

        return con_ask("Type '{$word}' to proceed") === $word;
    }
}

if (!function_exists('con_env_path')) {
    function con_env_path(string $root): string
    {
        return $root . '/.env';
    }
}

if (!function_exists('con_read_env')) {
    /**
     * Parse .env without vendor/. Deliberately minimal: KEY=VALUE, optional
     * quotes, # comments. setup must work before composer install.
     *
     * @return array<string,string>
     */
    function con_read_env(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $values = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key   = trim($parts[0]);
            $value = trim($parts[1]);

            // Strip an inline comment, but only when unquoted — a value like
            // "pa#ss" is legitimate.
            if (!str_starts_with($value, '"') && !str_starts_with($value, "'")) {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }

            $values[$key] = trim($value, "\"'");
        }

        return $values;
    }
}

if (!function_exists('con_set_env')) {
    /**
     * Set or replace one key in .env, preserving comments and ordering.
     * Appends when the key is absent.
     */
    function con_set_env(string $path, string $key, string $value): void
    {
        $lines   = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $written = false;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line) === 1) {
                $lines[$i] = $key . '=' . $value;
                $written   = true;
                break;
            }
        }

        if (!$written) {
            $lines[] = $key . '=' . $value;
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }
}

if (!function_exists('con_docker_prefix')) {
    /**
     * Where should composer / bin scripts run?
     *
     * Inside a container we are already there. On the host, DB_HOST=mysql can
     * only mean the Compose service, so commands belong in the php container.
     * Anything else is the native path.
     */
    function con_docker_prefix(string $root): string
    {
        if (is_file('/.dockerenv')) {
            return '';
        }

        $env = con_read_env(con_env_path($root));

        if (($env['DB_HOST'] ?? '') !== 'mysql') {
            return '';
        }

        // Only route into the container when it is actually running. Routing
        // into a stopped one fails with "service php is not running", which
        // reads as a broken script rather than a stopped stack.
        return con_compose_running('php') ? 'docker compose exec -T php ' : '';
    }
}

if (!function_exists('con_compose_running')) {
    /**
     * Is a Compose service actually running?
     *
     * The obvious test — run `docker compose ps` and check the exit code — is
     * wrong, and wrong in the direction that hurts. With the stack down the
     * command exits 0 and prints nothing, so an exit-code check concludes the
     * stack is up. Verified against Compose v2: stopped service, exit 0, empty
     * output. That made bin/setup skip the warning about DB_HOST=mysql on
     * exactly the machines that needed it, and made con_docker_prefix() route
     * commands into a container that was not there.
     *
     * So this reads the output, and `--status=running` because plain `ps -a`
     * lists stopped containers too.
     *
     * The output is checked for a container ID rather than merely being
     * non-empty: con_run merges stderr, so "Cannot connect to the Docker
     * daemon" is itself output and would otherwise read as a running service.
     */
    function con_compose_running(string $service): bool
    {
        [$out, $code] = con_run('docker compose ps --quiet --status=running ' . escapeshellarg($service));

        if ($code !== 0) {
            return false;
        }

        $first = trim(strtok($out, "\n") ?: '');

        return preg_match('/^[0-9a-f]{12,}$/i', $first) === 1;
    }
}

if (!function_exists('con_db_remedy')) {
    /**
     * What to do about a connection that failed, given where we are running.
     *
     * A PDO error says the name did not resolve; it cannot say that "mysql" is
     * a Compose service name and you are not in Compose. That mismatch — .env
     * left at the Docker default while running natively, or the reverse — is by
     * some distance the most common reason a fresh checkout appears broken, and
     * it has cost setup time more than once.
     *
     * Lives here rather than in one script because setup, preflight and
     * anything else that opens a connection all owe the same answer.
     *
     * Returns an empty string when the host looks right for where we are, since
     * then the fault is the server or the credentials and a guess would mislead.
     */
    function con_db_remedy(string $host, PDOException $e): string
    {
        // Only where we never reached a server: 2002 cannot connect, 2005
        // unknown host, 2006 server gone away. An "access denied" means the
        // host was fine and the credentials were not, and telling someone to
        // change DB_HOST at that point sends them away from the actual fault.
        $driverCode = $e->errorInfo[1] ?? null;

        if ($driverCode === null) {
            $driverCode = preg_match('/\[(\d+)\]/', $e->getMessage(), $m) === 1 ? (int) $m[1] : null;
        }

        if (!in_array($driverCode, [2002, 2005, 2006], true)) {
            return '';
        }

        // The reliable marker: the file exists in a container and nowhere else.
        $inContainer = is_file('/.dockerenv');

        if ($host === 'mysql' && !$inContainer) {
            return 'DB_HOST=mysql is the Compose service name and resolves only inside the Compose network. '
                 . 'Running natively: set DB_HOST=127.0.0.1 in .env. '
                 . 'Running under Docker: start it with docker compose up -d, then run inside the container — '
                 . 'docker compose exec php composer preflight';
        }

        if ($inContainer && in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return 'Inside a container, ' . $host . ' is the container itself, not your machine. '
                 . 'Set DB_HOST=mysql for the Compose database, or host.docker.internal for a MySQL running on the host.';
        }

        if ($host === 'localhost') {
            return 'localhost makes PHP try a unix socket and ignore DB_PORT entirely, which fails when the '
                 . 'socket path differs from what php.ini expects. Use DB_HOST=127.0.0.1 to force TCP.';
        }

        return '';
    }
}

if (!function_exists('con_pdo')) {
    /**
     * Connect using .env values. $database null connects to the server with no
     * database selected — needed to CREATE DATABASE on a first run.
     *
     * @param array<string,string> $env
     */
    function con_pdo(array $env, ?string $database): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1',
            $env['DB_PORT'] ?? '3306',
        );

        if ($database !== null) {
            $dsn .= ';dbname=' . $database;
        }

        return new PDO(
            $dsn,
            $env['DB_USER'] ?? 'root',
            $env['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10],
        );
    }
}
