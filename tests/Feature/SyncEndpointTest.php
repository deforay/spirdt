<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap;
use App\Models\Assessment;
use App\Service\TokenService;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The sync endpoint, through the whole Slim stack.
 *
 * The integration suite covers what the service does with a payload. What is
 * tested here is the boundary: that an unauthenticated caller gets nothing,
 * that the organisation comes from the signed token rather than the body, and
 * that a refused payload is still filed.
 */
final class SyncEndpointTest extends TestCase
{
    private int $orgA;
    private int $orgB;
    private string $siteId;
    private string $facilityId;

    protected function setUp(): void
    {
        Bootstrap::createApp();
        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['submissions_raw', 'assessment_scores', 'answers', 'assessment_pathogens',
                    'assessments', 'templates', 'testing_sites', 'facilities', 'organizations'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgA = (int) Capsule::table('organizations')->insertGetId(['code' => 'org-a', 'name' => 'A']);
        $this->orgB = (int) Capsule::table('organizations')->insertGetId(['code' => 'org-b', 'name' => 'B']);

        Capsule::table('templates')->insert([
            'organization_id' => null,
            'code'            => 'spi-rdt',
            'version'         => '1.0.0',
            'title'           => 'SPI-RDT',
            'definition'      => (string) file_get_contents(
                dirname(__DIR__, 2) . '/resources/templates/spi-rdt-1.0.0.json',
            ),
            'status'          => 'published',
        ]);

        $this->facilityId = '019fd200-0000-7000-8000-0000000000aa';
        $this->siteId = '019fd200-0000-7000-8000-0000000000bb';

        Capsule::table('facilities')->insert([
            'id'              => BinaryUuid::toBytes($this->facilityId),
            'organization_id' => $this->orgA,
            'name'            => 'Facility A',
            'source'          => 'registry',
        ]);

        Capsule::table('testing_sites')->insert([
            'id'              => BinaryUuid::toBytes($this->siteId),
            'organization_id' => $this->orgA,
            'facility_id'     => BinaryUuid::toBytes($this->facilityId),
            'name'            => 'Site A',
            'source'          => 'registry',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
    }

    public function testWithoutATokenNothingIsAccepted(): void
    {
        $response = $this->send($this->payload(), null);

        self::assertSame(401, $response->getStatusCode());

        TenantContext::withoutScope(function (): void {
            self::assertSame(0, Assessment::acrossOrganizations()->count());
        });
    }

    public function testATamperedTokenIsRefused(): void
    {
        $token = (new TokenService())->issue(1, $this->orgA, 'assessor');
        $response = $this->send($this->payload(), $token . 'x');

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAValidTokenStoresTheAssessment(): void
    {
        $token = (new TokenService())->issue(1, $this->orgA, 'assessor');
        $response = $this->send($this->payload(), $token);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body);
        self::assertSame(4, $body['score']['total_score']);
        self::assertSame(6, $body['score']['total_possible']);
    }

    public function testTheOrganizationComesFromTheTokenNotTheBody(): void
    {
        // The body claims organisation A's site while the token says B. The
        // token has to win, or an assessor could write into any tenant by
        // editing a field.
        $token = (new TokenService())->issue(1, $this->orgB, 'assessor');
        $response = $this->send($this->payload(), $token);

        self::assertSame(200, $response->getStatusCode());

        TenantContext::set($this->orgA);
        self::assertSame(0, Assessment::query()->count(), 'nothing landed in A');

        TenantContext::set($this->orgB);
        self::assertSame(1, Assessment::query()->count(), 'it landed in B, from the token');
    }

    public function testARefusedPayloadIsStillFiled(): void
    {
        $token = (new TokenService())->issue(1, $this->orgA, 'assessor');

        $payload = $this->payload();
        $payload['template_version'] = '9.9.9';

        $response = $this->send($payload, $token);

        self::assertSame(422, $response->getStatusCode());

        $filed = TenantContext::withoutScope(
            static fn () => Capsule::table('submissions_raw')->get(),
        );

        self::assertCount(1, $filed);
        self::assertStringContainsString('9.9.9', (string) $filed[0]->rejected_reason);
    }

    public function testTheSamePayloadTwiceProducesOneAssessment(): void
    {
        $token = (new TokenService())->issue(1, $this->orgA, 'assessor');

        self::assertSame(200, $this->send($this->payload(), $token)->getStatusCode());
        self::assertSame(200, $this->send($this->payload(), $token)->getStatusCode());

        TenantContext::set($this->orgA);
        self::assertSame(1, Assessment::query()->count());
    }

    /** @param array<string,mixed> $payload */
    private function send(array $payload, ?string $token): \Psr\Http\Message\ResponseInterface
    {
        $app = Bootstrap::createApp();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/sync/assessments')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($payload);

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        return $app->handle($request);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'id'               => '019fd200-0000-7000-8000-000000000777',
            'testing_site_id'  => $this->siteId,
            'facility_id'      => $this->facilityId,
            'template_code'    => 'spi-rdt',
            'template_version' => '1.0.0',
            'assessed_on'      => '2026-08-05',
            'context'          => ['refers_specimens' => 'no'],
            'pathogens'        => [['key' => 'hiv', 'name' => 'HIV']],
            'answers'          => [
                ['question_code' => '3.1', 'response' => 'Y'],
                ['question_code' => '3.2', 'response' => 'N', 'comment' => 'No SOP.'],
                ['question_code' => '4.1', 'pathogen' => 'hiv', 'response' => 'Y'],
            ],
        ];
    }
}
