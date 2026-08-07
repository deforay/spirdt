<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helper\Log;
use App\Support\RequestContext;
use App\Support\UserAgent;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Throwable;

/**
 * One row per API request.
 *
 * The `api_logs` table has existed since 0.1.4 and had nothing writing to it,
 * while the README and the architecture notes described this middleware as
 * though it were running. A 405 on every admin list — the client defaulted to
 * POST and the routes are GET — went unnoticed for as long as it did precisely
 * because nothing kept a record of what the server had been asked.
 *
 * The file log answers "what happened while handling this request". This
 * answers "what has been asked of the server, by whom, from where, and how
 * often", which is a different question and not one a text file is any good
 * at. They are joined by the request UID that UidProcessor stamps on every
 * line.
 *
 * WRITING NEVER FAILS A REQUEST. A logging table that can refuse an assessment
 * is a worse problem than no logging: the sync is the thing that must not
 * break, and it must not break because the log it writes afterwards is full,
 * locked or missing. Everything here is inside a catch, and a failure to
 * record goes to the file log instead.
 */
final class ApiLoggerMiddleware implements MiddlewareInterface
{
    /**
     * Field names whose value is replaced before the body is stored.
     *
     * Matched on the KEY, at any depth. A log holding a password is worse than
     * no log, and a sync payload is the largest body in the system — so this
     * runs over a structure that can be several megabytes and must stay cheap.
     */
    private const REDACT = [
        'password', 'current_password', 'new_password', 'password_confirmation',
        'token', 'access_token', 'refresh_token', 'jwt_secret', 'secret',
        'phone', 'interviewee_phone', 'signature', 'blob',
    ];

    /** A truncated body is still debuggable. submissions_raw keeps the whole one. */
    private const MAX_BODY = 8000;

    public function process(Request $request, Handler $handler): Response
    {
        $startedAt = microtime(true);

        $response = $handler->handle($request);

        try {
            $this->record($request, $response, $startedAt);
        } catch (Throwable $e) {
            // Deliberately swallowed. The request has already been answered and
            // the caller is not the person who needs to know about this.
            Log::warning('Request log not written: {reason}', ['reason' => $e->getMessage()]);
        }

        return $response;
    }

    private function record(Request $request, Response $response, float $startedAt): void
    {
        $agent = $request->getHeaderLine('User-Agent');
        $parsed = UserAgent::parse($agent);

        // Read from the shared context rather than from this request. PSR-7
        // requests are immutable, so the attributes authentication adds are
        // visible only inside it — and this runs outside routing, which is
        // where it has to be to record a 404 or a 405 at all.
        $tenant = TenantContext::current();
        $sessionHash = RequestContext::sessionHash();

        Capsule::table('api_logs')->insert([
            'organization_id' => $tenant?->organizationId,
            'user_id'         => $tenant?->userId,
            'session_hash'    => $sessionHash,
            'request_uid'     => Log::requestUid(),
            'method'          => $request->getMethod(),
            'path'            => mb_substr($request->getUri()->getPath(), 0, 255),
            'status_code'     => $response->getStatusCode(),
            'duration_ms'     => (int) round((microtime(true) - $startedAt) * 1000),
            'request_body'    => $this->body($request),
            'ip_address'      => $this->ip($request),
            'user_agent'      => $agent === '' ? null : mb_substr($agent, 0, 255),
            'platform'        => $parsed['platform'],
            'browser'         => $parsed['browser'],
            'device_id'       => $this->header($request, 'X-Device-Id', 100),
            'app_version'     => $this->header($request, 'X-App-Version', 20),
        ]);
    }

    /**
     * The client's address, trusting a proxy header only where one is declared.
     *
     * X-Forwarded-For is set by the caller when nothing in front of the app
     * rewrites it, so believing it unconditionally means believing whatever an
     * attacker types. TRUSTED_PROXIES names the hops that are actually in
     * front; with none configured the socket address is used, which is correct
     * for a server reached directly.
     */
    private function ip(Request $request): ?string
    {
        $params = $request->getServerParams();
        $remote = is_string($params['REMOTE_ADDR'] ?? null) ? $params['REMOTE_ADDR'] : null;

        $trusted = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))));

        if ($trusted === [] || $remote === null || !in_array($remote, $trusted, true)) {
            return $remote;
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-For');

        if ($forwarded === '') {
            return $remote;
        }

        // The left-most entry is the original client; everything after it is a
        // hop that added itself.
        $first = trim(explode(',', $forwarded)[0]);

        return $first === '' ? $remote : mb_substr($first, 0, 45);
    }

    private function header(Request $request, string $name, int $limit): ?string
    {
        $value = $request->getHeaderLine($name);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function body(Request $request): ?string
    {
        $parsed = $request->getParsedBody();

        if (!is_array($parsed) || $parsed === []) {
            return null;
        }

        $encoded = json_encode($this->redact($parsed));

        if ($encoded === false) {
            return null;
        }

        return mb_substr($encoded, 0, self::MAX_BODY);
    }

    /**
     * @param  array<mixed> $body
     * @return array<mixed>
     */
    private function redact(array $body): array
    {
        $clean = [];

        foreach ($body as $key => $value) {
            if (is_string($key) && in_array(mb_strtolower($key), self::REDACT, true)) {
                $clean[$key] = '[redacted]';

                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }
}
