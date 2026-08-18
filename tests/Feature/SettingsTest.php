<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap;
use App\Support\Secret;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\MakesTenants;

/**
 * The settings screen.
 *
 * Three things are worth holding down here and the rest is form handling.
 *
 * The SMTP password is write-only: it goes in, it never comes back, and an
 * empty box means "leave it" rather than "clear it". Get that wrong and the
 * password is wiped by somebody correcting the port.
 *
 * The permission is superadmin's alone, because `system_config` is shared
 * across every organisation on the installation. An administrator reaching it
 * would be setting another tenant's outgoing mail.
 *
 * And the localisation half writes to the caller's OWN organisation, taken
 * from the token. Anything else would make one tenant's settings screen a way
 * into another's row.
 */
final class SettingsTest extends TestCase
{
    use MakesTenants;

    private const PASSWORD = 'correct-horse-battery';

    /** A valid 32-byte key, so encryption is exercised rather than skipped. */
    private const APP_KEY = 'MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=';

    private int $orgId;

    protected function setUp(): void
    {
        Bootstrap::createApp();
        TenantContext::forget();

        $_ENV['APP_KEY'] = self::APP_KEY;
        $_SERVER['APP_KEY'] = self::APP_KEY;

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['login_attempts', 'refresh_tokens', 'audit_log', 'users', 'role_permissions',
                    'roles', 'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');

            Capsule::table('system_config')->where('key', 'like', 'instance.%')->delete();
            Capsule::table('system_config')->where('key', 'like', 'smtp.%')->delete();
        });

        $this->orgId = $this->makeTenant('settings-org', 'Settings Org');
        $this->makeRoles($this->orgId);

        $this->makeUser('owner@example.org', 'superadmin');
        $this->makeUser('boss@example.org', 'admin');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
    }

    // ─── who may ───

    public function testAnAdministratorCannotReachTheInstallationsSettings(): void
    {
        self::assertSame(
            403,
            $this->get('/api/admin/settings', $this->signIn('boss@example.org'))->getStatusCode(),
        );
    }

    public function testASuperadminCan(): void
    {
        $response = $this->get('/api/admin/settings', $this->signIn('owner@example.org'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    // ─── reading ───

    public function testAFreshInstallationComesBackWithUsableDefaults(): void
    {
        $body = $this->body($this->get('/api/admin/settings', $this->signIn('owner@example.org')));

        self::assertSame('', $body['instance']['name']);
        // 587 rather than 0: a screen reading a missing row as an empty string
        // would offer a port nothing listens on.
        self::assertSame(587, $body['mail']['port']);
        self::assertSame('tls', $body['mail']['encryption']);
        self::assertFalse($body['mail']['has_password']);
        self::assertSame('Settings Org', $body['localisation']['organization']);
    }

    public function testTheReadNeverCarriesThePassword(): void
    {
        $token = $this->signIn('owner@example.org');

        $this->patch(['mail' => ['password' => 'hunter2']], $token);

        $raw = (string) $this->get('/api/admin/settings', $token)->getBody();

        self::assertStringNotContainsString('hunter2', $raw);
        self::assertTrue($this->body($this->get('/api/admin/settings', $token))['mail']['has_password']);
    }

    // ─── writing ───

    public function testTheInstanceBlockIsSaved(): void
    {
        $token = $this->signIn('owner@example.org');

        $body = $this->body($this->patch([
            'instance' => [
                'name'          => 'Zambia SPI-RDT',
                'contact_name'  => 'Amina Demo',
                'contact_email' => 'Help@Example.ORG',
            ],
        ], $token));

        self::assertSame('Zambia SPI-RDT', $body['instance']['name']);
        // Lower-cased on the way in, because an address is compared and typed
        // back by people who will not match the capitals.
        self::assertSame('help@example.org', $body['instance']['contact_email']);
    }

    public function testLocalisationWritesToTheCallersOwnOrganisation(): void
    {
        $token = $this->signIn('owner@example.org');

        $body = $this->body($this->patch([
            'localisation' => ['timezone' => 'Africa/Lusaka', 'locale' => 'fr', 'country_code' => 'zm'],
        ], $token));

        self::assertSame('Africa/Lusaka', $body['localisation']['timezone']);
        self::assertSame('ZM', $body['localisation']['country_code']);

        $row = TenantContext::withoutScope(
            fn () => Capsule::table('organizations')->where('id', $this->orgId)->first(),
        );

        self::assertSame('Africa/Lusaka', $row->timezone);
        self::assertSame('fr', $row->default_locale);
    }

    public function testATimezoneTheServerDoesNotKnowIsRefused(): void
    {
        $response = $this->request('PATCH', '/api/admin/settings', [
            'localisation' => ['timezone' => 'Mars/Olympus'],
        ], $this->signIn('owner@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testALanguageWithNoTranslationsIsRefused(): void
    {
        $response = $this->request('PATCH', '/api/admin/settings', [
            'localisation' => ['locale' => 'de'],
        ], $this->signIn('owner@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testAnAddressThatIsNotOneIsRefused(): void
    {
        $response = $this->request('PATCH', '/api/admin/settings', [
            'instance' => ['contact_email' => 'not-an-address'],
        ], $this->signIn('owner@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testAPortOutsideTheRangeIsRefused(): void
    {
        $response = $this->request('PATCH', '/api/admin/settings', [
            'mail' => ['port' => 99999],
        ], $this->signIn('owner@example.org'));

        self::assertSame(422, $response->getStatusCode());
    }

    // ─── the password, which is the whole reason for the care ───

    public function testAnEmptyPasswordLeavesTheStoredOneAlone(): void
    {
        $token = $this->signIn('owner@example.org');

        $this->patch(['mail' => ['password' => 'hunter2']], $token);

        // The screen posts every field on every save, and it can never post the
        // password back because it was never given it.
        $body = $this->body($this->patch([
            'mail' => ['port' => 2525, 'password' => ''],
        ], $token));

        self::assertSame(2525, $body['mail']['port']);
        self::assertTrue($body['mail']['has_password']);
        self::assertSame('hunter2', $this->storedPassword());
    }

    public function testClearingIsItsOwnInstruction(): void
    {
        $token = $this->signIn('owner@example.org');

        $this->patch(['mail' => ['password' => 'hunter2']], $token);

        $body = $this->body($this->patch(['mail' => ['clear_password' => true]], $token));

        self::assertFalse($body['mail']['has_password']);
    }

    public function testTheStoredPasswordIsNotReadableFromTheRow(): void
    {
        $this->patch(['mail' => ['password' => 'hunter2']], $this->signIn('owner@example.org'));

        $stored = (string) Capsule::table('system_config')->where('key', 'smtp.password')->value('value');

        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('hunter2', $stored);
        self::assertSame('hunter2', Secret::decrypt($stored));
    }

    /**
     * Saving the same password twice produces two different rows, because the
     * nonce is fresh each time. Worth asserting: an identical ciphertext would
     * mean the nonce had been fixed, and a fixed nonce is how this construction
     * stops protecting anything.
     */
    public function testTwoWritesOfOnePasswordDoNotMatch(): void
    {
        $token = $this->signIn('owner@example.org');

        $this->patch(['mail' => ['password' => 'hunter2']], $token);
        $first = (string) Capsule::table('system_config')->where('key', 'smtp.password')->value('value');

        $this->patch(['mail' => ['password' => 'hunter2']], $token);
        $second = (string) Capsule::table('system_config')->where('key', 'smtp.password')->value('value');

        self::assertNotSame($first, $second);
        self::assertSame('hunter2', Secret::decrypt($second));
    }

    public function testWithNoKeyThePasswordIsRefusedAndTheRestStillSaves(): void
    {
        $_ENV['APP_KEY'] = '';
        $_SERVER['APP_KEY'] = '';

        $token = $this->signIn('owner@example.org');

        self::assertFalse(
            $this->body($this->get('/api/admin/settings', $token))['can_store_password'],
        );

        $refused = $this->request('PATCH', '/api/admin/settings', [
            'mail' => ['host' => 'smtp.example.org', 'password' => 'hunter2'],
        ], $token);

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('APP_KEY', $this->body($refused)['error']['message']);

        // And without the password it goes through, so one impossible field
        // does not hold the screen hostage.
        $body = $this->body($this->patch(['mail' => ['host' => 'smtp.example.org']], $token));

        self::assertSame('smtp.example.org', $body['mail']['host']);
    }

    // ─── the record of it ───

    public function testAChangeIsAuditedByKeyAndNeverByValue(): void
    {
        $this->patch([
            'instance' => ['name' => 'Zambia SPI-RDT'],
            'mail'     => ['password' => 'hunter2'],
        ], $this->signIn('owner@example.org'));

        $row = TenantContext::withoutScope(
            fn () => Capsule::table('audit_log')
                ->where('action', 'settings.updated')
                ->orderByDesc('id')
                ->first(),
        );

        self::assertNotNull($row);

        $metadata = (string) $row->metadata;

        self::assertStringContainsString('smtp.password', $metadata);
        self::assertStringNotContainsString('hunter2', $metadata);
    }

    public function testSavingNothingRecordsNothing(): void
    {
        $token = $this->signIn('owner@example.org');

        $this->patch(['instance' => ['name' => 'Zambia SPI-RDT']], $token);
        $this->patch(['instance' => ['name' => 'Zambia SPI-RDT']], $token);

        $count = TenantContext::withoutScope(
            fn (): int => Capsule::table('audit_log')->where('action', 'settings.updated')->count(),
        );

        self::assertSame(1, $count);
    }

    // ─── what rides on the sign-in response ───

    public function testTheContactTravelsWithTheSessionSoAnAccountWithNothingCanSeeIt(): void
    {
        $this->patch([
            'instance' => ['name' => 'Zambia SPI-RDT', 'contact_email' => 'help@example.org'],
        ], $this->signIn('owner@example.org'));

        $body = $this->body($this->request('POST', '/api/auth/login', [
            'email'    => 'boss@example.org',
            'password' => self::PASSWORD,
        ]));

        self::assertSame('Zambia SPI-RDT', $body['instance']['name']);
        self::assertSame('help@example.org', $body['instance']['contact_email']);
    }

    // ─── helpers ───

    private function storedPassword(): ?string
    {
        return Secret::decrypt(
            (string) Capsule::table('system_config')->where('key', 'smtp.password')->value('value'),
        );
    }

    /** @param array<string,mixed> $payload */
    private function patch(array $payload, string $token): ResponseInterface
    {
        $response = $this->request('PATCH', '/api/admin/settings', $payload, $token);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    private function makeUser(string $email, string $roleKey): int
    {
        $roleId = (int) Capsule::table('roles')
            ->where('organization_id', $this->orgId)
            ->where('key', $roleKey)
            ->value('id');

        return (int) Capsule::table('users')->insertGetId([
            'organization_id'      => $this->orgId,
            'role_id'              => $roleId,
            'email'                => $email,
            'password_hash'        => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'full_name'            => 'Test Person',
            'is_active'            => 1,
            'must_change_password' => 0,
        ]);
    }

    private function signIn(string $email): string
    {
        return $this->body($this->request('POST', '/api/auth/login', [
            'email'    => $email,
            'password' => self::PASSWORD,
        ]))['access_token'];
    }

    private function get(string $path, string $token): ResponseInterface
    {
        return $this->request('GET', $path, [], $token);
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
