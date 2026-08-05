<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Signing in.
 *
 * The cases here are the ones where being wrong is expensive and invisible: a
 * failure that reports whether an address exists, a refresh token that stays
 * usable after being spent, a lockout that never engages. None of those show up
 * as a broken screen.
 */
final class AuthEndpointTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private int $orgA;
    private int $orgB;

    protected function setUp(): void
    {
        Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['login_attempts', 'refresh_tokens', 'users', 'roles', 'organizations'] as $table) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgA = (int) Capsule::table('organizations')->insertGetId(['code' => 'org-a', 'name' => 'A']);
        $this->orgB = (int) Capsule::table('organizations')->insertGetId(['code' => 'org-b', 'name' => 'B']);

        $this->makeUser($this->orgA, 'jane@example.org', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    public function testTheRightPasswordReturnsAPair(): void
    {
        $response = $this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->body($response);

        self::assertNotSame('', $body['access_token']);
        self::assertNotSame('', $body['refresh_token']);
        self::assertSame('assessor', $body['user']['role']);
        self::assertSame($this->orgA, $body['user']['organization_id']);
        self::assertArrayNotHasKey('password_hash', $body['user']);
    }

    public function testAWrongPasswordAndAnUnknownAddressAreIndistinguishable(): void
    {
        $wrong = $this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => 'not-the-password',
        ]);

        $unknown = $this->post('/api/auth/login', [
            'email'    => 'nobody@example.org',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame(401, $unknown->getStatusCode());
        self::assertSame(
            $this->body($wrong)['error']['message'],
            $this->body($unknown)['error']['message'],
            'the response must not report which half was wrong',
        );
    }

    public function testASwitchedOffAccountCannotSignIn(): void
    {
        Capsule::table('users')->where('email', 'jane@example.org')->update(['is_active' => 0]);

        $response = $this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnAddressInTwoOrganisationsNeedsTheOrganisationCode(): void
    {
        // The same person, the same password, two organisations on one
        // installation. Nothing in the request says which one they mean.
        $this->makeUser($this->orgB, 'jane@example.org', self::PASSWORD);

        $ambiguous = $this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(409, $ambiguous->getStatusCode());

        $resolved = $this->post('/api/auth/login', [
            'email'        => 'jane@example.org',
            'password'     => self::PASSWORD,
            'organization' => 'org-b',
        ]);

        self::assertSame(200, $resolved->getStatusCode());
        self::assertSame($this->orgB, $this->body($resolved)['user']['organization_id']);
    }

    public function testARefreshTokenIsSpentWhenItIsUsed(): void
    {
        $first = $this->body($this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => self::PASSWORD,
        ]));

        $second = $this->post('/api/auth/refresh', ['refresh_token' => $first['refresh_token']]);

        self::assertSame(200, $second->getStatusCode());
        self::assertNotSame(
            $first['refresh_token'],
            $this->body($second)['refresh_token'],
            'the refresh token rotates',
        );

        $replayed = $this->post('/api/auth/refresh', ['refresh_token' => $first['refresh_token']]);

        self::assertSame(401, $replayed->getStatusCode(), 'a spent token is not usable again');
    }

    public function testReplayingASpentTokenEndsEverySessionForThatUser(): void
    {
        // A token presented twice means a copy exists somewhere. Which of the
        // two holders is the real one is unknowable from here, so both stop.
        $first = $this->body($this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => self::PASSWORD,
        ]));

        $rotated = $this->body($this->post('/api/auth/refresh', [
            'refresh_token' => $first['refresh_token'],
        ]));

        $this->post('/api/auth/refresh', ['refresh_token' => $first['refresh_token']]);

        $afterTheft = $this->post('/api/auth/refresh', ['refresh_token' => $rotated['refresh_token']]);

        self::assertSame(401, $afterTheft->getStatusCode(), 'the live session was revoked too');
    }

    public function testAnExpiredRefreshTokenIsRefused(): void
    {
        $login = $this->body($this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => self::PASSWORD,
        ]));

        Capsule::table('refresh_tokens')
            ->where('token_hash', hash('sha256', $login['refresh_token']))
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $response = $this->post('/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testSigningOutRevokesTheToken(): void
    {
        $login = $this->body($this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => self::PASSWORD,
        ]));

        self::assertSame(200, $this->post('/api/auth/logout', [
            'refresh_token' => $login['refresh_token'],
        ])->getStatusCode());

        self::assertSame(401, $this->post('/api/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ])->getStatusCode());
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $this->post('/api/auth/login', ['email' => 'jane@example.org', 'password' => 'wrong']);
        }

        $response = $this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(429, $response->getStatusCode(), 'the right password does not bypass the limit');
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));
    }

    public function testTheTokenFromLoginIsAcceptedBySync(): void
    {
        // The whole point of the endpoint: what it hands out has to work on the
        // route the device actually calls.
        $login = $this->body($this->post('/api/auth/login', [
            'email'    => 'jane@example.org',
            'password' => self::PASSWORD,
        ]));

        $app = Bootstrap::createApp();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/sync/assessments')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $login['access_token'])
            ->withParsedBody(['id' => 'not-a-uuid']);

        $response = $app->handle($request);

        // 422 because the payload is nonsense, NOT 401 — the token was accepted.
        self::assertSame(422, $response->getStatusCode());
    }

    private function makeUser(int $organizationId, string $email, string $password): int
    {
        $roleId = Capsule::table('roles')
            ->where('organization_id', $organizationId)
            ->where('key', 'assessor')
            ->value('id');

        if ($roleId === null) {
            $roleId = Capsule::table('roles')->insertGetId([
                'organization_id' => $organizationId,
                'key'             => 'assessor',
                'name'            => 'Assessor',
                'is_system'       => 1,
            ]);
        }

        return (int) Capsule::table('users')->insertGetId([
            'organization_id' => $organizationId,
            'role_id'         => (int) $roleId,
            'email'           => $email,
            'password_hash'   => password_hash($password, PASSWORD_DEFAULT),
            'full_name'       => 'Jane Doe',
            'is_active'       => 1,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function post(string $path, array $payload): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($payload);

        return Bootstrap::createApp()->handle($request);
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
