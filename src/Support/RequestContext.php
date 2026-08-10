<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is making the request being handled, for anything that writes a record
 * of it.
 *
 * PSR-7 requests are immutable, so an attribute added by a middleware is
 * visible only to what runs INSIDE it. The request logger sits outside
 * routing — it has to, or it would miss anything the router itself refuses,
 * which is the 404 and the 405 — and authentication happens per route group,
 * well within. The logger therefore never sees the attributes authentication
 * sets, and recorded every request as anonymous.
 *
 * So the identity is put somewhere both can reach. A static is the right shape
 * here for the same reason TenantContext is one: PHP handles a single request
 * per process, and passing this down through every constructor between the two
 * would be threading a value that only two places want.
 *
 * THE SECOND READER IS THE AUDIT TRAIL, and it is why the request's own
 * details live here rather than in the logger that first needed them. An audit
 * row is written deep inside a service — RoleAdminService has no request and
 * should not be given one — and it has to carry the same address, the same
 * browser and the same session as the api_logs row for the request that caused
 * it. Two derivations of "which IP was that" drift, and the drift shows up as
 * two records of one event that disagree.
 *
 * Cleared between requests by the same call that clears the tenant. In tests
 * that matters more than in production, where the process ends anyway.
 */
final class RequestContext
{
    private static ?string $sessionHash = null;
    private static ?string $ipAddress = null;
    private static ?string $userAgent = null;
    private static ?string $platform = null;
    private static ?string $browser = null;
    private static ?string $deviceId = null;
    private static ?string $appVersion = null;

    /**
     * Take everything the request itself can tell us.
     *
     * Called once, by the outermost middleware, before anything has had a
     * chance to fail. Identity of the ACCOUNT is not known this early and is
     * set separately by authentication.
     */
    public static function identify(ServerRequestInterface $request): void
    {
        $agent = $request->getHeaderLine('User-Agent');
        $parsed = UserAgent::parse($agent);

        self::$ipAddress = self::clientIp($request);
        self::$userAgent = $agent === '' ? null : mb_substr($agent, 0, 255);
        self::$platform = $parsed['platform'];
        self::$browser = $parsed['browser'];
        self::$deviceId = self::header($request, 'X-Device-Id', 100);
        self::$appVersion = self::header($request, 'X-App-Version', 20);
    }

    public static function setSessionHash(?string $hash): void
    {
        self::$sessionHash = $hash;
    }

    public static function sessionHash(): ?string
    {
        return self::$sessionHash;
    }

    public static function ipAddress(): ?string
    {
        return self::$ipAddress;
    }

    public static function userAgent(): ?string
    {
        return self::$userAgent;
    }

    public static function platform(): ?string
    {
        return self::$platform;
    }

    public static function browser(): ?string
    {
        return self::$browser;
    }

    public static function deviceId(): ?string
    {
        return self::$deviceId;
    }

    public static function appVersion(): ?string
    {
        return self::$appVersion;
    }

    public static function forget(): void
    {
        self::$sessionHash = null;
        self::$ipAddress = null;
        self::$userAgent = null;
        self::$platform = null;
        self::$browser = null;
        self::$deviceId = null;
        self::$appVersion = null;
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
    private static function clientIp(ServerRequestInterface $request): ?string
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

    private static function header(ServerRequestInterface $request, string $name, int $limit): ?string
    {
        $value = trim($request->getHeaderLine($name));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
