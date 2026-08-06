<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\MakesTenants;

/**
 * Two controls that were decorative until now, tested where they bite.
 *
 * `must_change_password` is set by bin/provision-org and by
 * bin/recover-access, both of which hand out a password somebody else chose
 * and somebody else has seen. Until it is changed the account is a shared
 * secret, so the flag has to stop the account doing anything except fixing
 * itself. A flag nothing enforces is worse than no flag, because the operator
 * believes it did something.
 *
 * Roles travelled in the token and opened every route equally, which made
 * "make this person a viewer" a statement with no consequences.
 */
final class PasswordAndRoleTest extends TestCase
{
    use MakesTenants;

    private const PASSWORD = 'correct-horse-battery';
    private const NEW_PASSWORD = 'a-different-long-one';

    private int $organizationId;

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

        $this->organizationId = $this->makeTenant('pw-org', 'Password Org');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    // ─── must_change_password ───

    public function testAFlaggedAccountCannotReachAnythingElse(): void
    {
        $this->makeUser('flagged@example.org', 'assessor', mustChange: true);
        $token = $this->signIn('flagged@example.org');

        $response = $this->request('GET', '/api/sites', token: $token);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('password_change_required', $this->body($response)['error']['code']);
    }

    public function testAFlaggedAccountCanStillChangeItsPassword(): void
    {
        $this->makeUser('flagged@example.org', 'assessor', mustChange: true);
        $token = $this->signIn('flagged@example.org');

        $response = $this->request('POST', '/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password'     => self::NEW_PASSWORD,
        ], $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->body($response)['user']['must_change_password']);
    }

    /** The pair returned by the change must not still carry the flag. */
    public function testTheNewTokenOpensTheRestOfTheApi(): void
    {
        $this->makeUser('flagged@example.org', 'assessor', mustChange: true);

        $changed = $this->request('POST', '/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password'     => self::NEW_PASSWORD,
        ], $this->signIn('flagged@example.org'));

        $response = $this->request('GET', '/api/sites', token: $this->body($changed)['access_token']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnUnflaggedAccountIsUnaffected(): void
    {
        $this->makeUser('normal@example.org', 'assessor');

        $response = $this->request('GET', '/api/sites', token: $this->signIn('normal@example.org'));

        self::assertSame(200, $response->getStatusCode());
    }

    // ─── changing a password ───

    /**
     * The current password is required even though the caller holds a valid
     * token. A tablet left signed in on a bench is otherwise a permanent
     * account takeover for whoever picks it up.
     */
    public function testTheCurrentPasswordIsRequired(): void
    {
        $this->makeUser('normal@example.org', 'assessor');

        $response = $this->request('POST', '/api/auth/password', [
            'current_password' => 'not-the-password',
            'new_password'     => self::NEW_PASSWORD,
        ], $this->signIn('normal@example.org'));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testTheNewPasswordMustMeetTheLengthFloor(): void
    {
        $this->makeUser('normal@example.org', 'assessor');

        $response = $this->request('POST', '/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password'     => 'short',
        ], $this->signIn('normal@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testTheNewPasswordMustDifferFromTheOld(): void
    {
        $this->makeUser('normal@example.org', 'assessor');

        $response = $this->request('POST', '/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password'     => self::PASSWORD,
        ], $this->signIn('normal@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testTheOldPasswordStopsWorkingAndTheNewOneStarts(): void
    {
        $this->makeUser('normal@example.org', 'assessor');

        $this->request('POST', '/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password'     => self::NEW_PASSWORD,
        ], $this->signIn('normal@example.org'));

        self::assertSame(401, $this->login('normal@example.org', self::PASSWORD)->getStatusCode());
        self::assertSame(200, $this->login('normal@example.org', self::NEW_PASSWORD)->getStatusCode());
    }

    /**
     * If the reason for the change was that somebody else knew the old
     * password, leaving their session alive makes the change decorative.
     */
    public function testEverySessionIsRevoked(): void
    {
        $userId = $this->makeUser('normal@example.org', 'assessor');

        $stolen = $this->body($this->login('normal@example.org', self::PASSWORD))['refresh_token'];

        $this->request('POST', '/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password'     => self::NEW_PASSWORD,
        ], $this->signIn('normal@example.org'));

        $live = Capsule::table('refresh_tokens')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->count();

        // Only the pair the change itself returned.
        self::assertSame(1, $live);

        $response = $this->request('POST', '/api/auth/refresh', ['refresh_token' => $stolen]);

        self::assertSame(401, $response->getStatusCode());
    }

    // ─── roles ───

    public function testAViewerCannotFileAnAssessment(): void
    {
        $this->makeUser('viewer@example.org', 'viewer');

        $response = $this->request('POST', '/api/sync/assessments', ['id' => 'nope'], $this->signIn('viewer@example.org'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('role_not_permitted', $this->body($response)['error']['code']);
    }

    public function testASiteUserCannotFileAnAssessment(): void
    {
        $this->makeUser('site@example.org', 'site_user');

        $response = $this->request('POST', '/api/sync/assessments', ['id' => 'nope'], $this->signIn('site@example.org'));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Past the role gate, into the payload validation behind it. 422 rather
     * than 403 is the whole point of the assertion.
     */
    public function testAnAssessorReachesTheSyncEndpoint(): void
    {
        $this->makeUser('normal@example.org', 'assessor');

        $response = $this->request('POST', '/api/sync/assessments', ['id' => 'nope'], $this->signIn('normal@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testAnAdminReachesTheSyncEndpoint(): void
    {
        $this->makeUser('boss@example.org', 'admin');

        $response = $this->request('POST', '/api/sync/assessments', ['id' => 'nope'], $this->signIn('boss@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    /** A viewer still reads what they are for. */
    public function testAViewerCanStillReadReferenceData(): void
    {
        $this->makeUser('viewer@example.org', 'viewer');

        $response = $this->request('GET', '/api/sites', token: $this->signIn('viewer@example.org'));

        self::assertSame(200, $response->getStatusCode());
    }

    // ─── fixtures ───

    private function makeUser(string $email, string $roleKey, bool $mustChange = false): int
    {
        $roleId = Capsule::table('roles')
            ->where('organization_id', $this->organizationId)
            ->where('key', $roleKey)
            ->value('id');

        if ($roleId === null) {
            $roleId = Capsule::table('roles')->insertGetId([
                'organization_id' => $this->organizationId,
                'key'             => $roleKey,
                'name'            => ucfirst($roleKey),
                'is_system'       => 1,
            ]);
        }

        return (int) Capsule::table('users')->insertGetId([
            'organization_id'      => $this->organizationId,
            'role_id'              => (int) $roleId,
            'email'                => $email,
            'password_hash'        => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'full_name'            => 'Test Person',
            'is_active'            => 1,
            'must_change_password' => $mustChange ? 1 : 0,
        ]);
    }

    private function signIn(string $email): string
    {
        return $this->body($this->login($email, self::PASSWORD))['access_token'];
    }

    private function login(string $email, string $password): ResponseInterface
    {
        return $this->request('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** @param array<string,mixed> $payload */
    private function request(
        string $method,
        string $path,
        array $payload = [],
        ?string $token = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Content-Type', 'application/json');

        if ($payload !== []) {
            $request = $request->withParsedBody($payload);
        }

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return Bootstrap::createApp()->handle($request);
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
