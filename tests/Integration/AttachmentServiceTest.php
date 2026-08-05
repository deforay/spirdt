<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Attachment;
use App\Service\AttachmentService;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Signature upload, against a real database and a real directory.
 *
 * Uploads are where an API is most worth distrusting, so the cases below are
 * mostly attempts to get something past it: a file that lies about its type, a
 * file that is too big, an assessment belonging to somebody else. The
 * idempotency cases matter for a different reason — a device on a weak
 * connection will send the same signature repeatedly, and a second copy of a
 * signature is a second thing claiming to be what was signed.
 */
final class AttachmentServiceTest extends TestCase
{
    private int $orgA;
    private int $orgB;
    private string $assessmentA;
    private string $assessmentB;
    private string $storage;
    private AttachmentService $service;

    protected function setUp(): void
    {
        \App\Bootstrap::createApp();

        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (
                ['attachments', 'assessment_scores', 'findings', 'answers', 'assessment_pathogens',
                    'assessments', 'templates', 'testing_sites', 'facilities', 'organizations'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgA = $this->makeOrganization('att-a');
        $this->orgB = $this->makeOrganization('att-b');

        $templateId = (int) Capsule::table('templates')->insertGetId([
            'organization_id' => null,
            'code'            => 'spi-rdt',
            'version'         => '1.0.0',
            'title'           => 'SPI-RDT',
            'definition'      => json_encode(['sections' => []], JSON_THROW_ON_ERROR),
            'status'          => 'published',
        ]);

        $this->assessmentA = $this->makeAssessment($this->orgA, $templateId, 'a1');
        $this->assessmentB = $this->makeAssessment($this->orgB, $templateId, 'b1');

        $this->storage = sys_get_temp_dir() . '/spirdt-attachments-' . bin2hex(random_bytes(4));
        $this->service = new AttachmentService($this->storage);

        TenantContext::set($this->orgA, null);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        $this->removeDirectory($this->storage);
    }

    public function testStoresASignatureAndWritesTheFile(): void
    {
        $result = $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
        ]);

        $this->assertSame('signature', $result['kind']);
        $this->assertSame('assessor_1', $result['role']);

        $row = Attachment::query()->first();

        $this->assertNotNull($row);
        $this->assertSame('image/png', $row->mime_type);
        $this->assertSame($this->orgA, (int) $row->organization_id);
        $this->assertFileExists($this->storage . '/' . $row->storage_path);
    }

    /**
     * The checksum is computed from what arrived, never taken from the caller.
     * A caller that can choose the checksum can make any two files look
     * identical, which is the one thing the idempotency check must not allow.
     */
    public function testIgnoresAChecksumSentByTheCaller(): void
    {
        $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
            'checksum'      => str_repeat('0', 64),
        ]);

        $row = Attachment::query()->first();

        $this->assertNotNull($row);
        $this->assertNotSame(str_repeat('0', 64), $row->checksum);
    }

    /** A retried upload must cost a lookup, not a second file. */
    public function testTheSameFileTwiceStoresOnce(): void
    {
        $meta = [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
        ];

        $first = $this->service->store($this->png(), $meta);
        $second = $this->service->store($this->png(), $meta);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, Attachment::query()->count());
        $this->assertCount(1, $this->storedFiles());
    }

    /**
     * Redrawing replaces. Two images with equal claim to being what was signed,
     * and no way to tell which the site actually saw, is worse than one.
     */
    public function testRedrawingReplacesTheSignatureAndItsFile(): void
    {
        $meta = [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
        ];

        $first = $this->service->store($this->png(2, 2), $meta);
        $second = $this->service->store($this->png(3, 3), $meta);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(1, Attachment::query()->count());
        $this->assertCount(1, $this->storedFiles(), 'the superseded file should be gone');
    }

    public function testTwoRolesBothKeepTheirSignature(): void
    {
        $this->service->store($this->png(2, 2), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
        ]);

        $this->service->store($this->png(3, 3), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'site_representative',
        ]);

        $this->assertSame(2, Attachment::query()->count());
    }

    public function testRefusesAnAssessmentInAnotherOrganisation(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentB,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
        ]);
    }

    /**
     * The type is sniffed from the bytes. A PHP script named .png and declared
     * image/png is the oldest upload attack there is.
     */
    public function testRefusesAFileThatIsNotAnImage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->store(
            $this->upload("<?php echo 'hello';", 'signature.png', 'image/png'),
            [
                'assessment_id' => $this->assessmentA,
                'kind'          => 'signature',
                'role'          => 'assessor_1',
            ],
        );
    }

    /** A PNG header in front of anything else still has to fail to decode. */
    public function testRefusesATruncatedImage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->store(
            $this->upload("\x89PNG\r\n\x1a\n" . str_repeat("\0", 40), 'signature.png', 'image/png'),
            [
                'assessment_id' => $this->assessmentA,
                'kind'          => 'signature',
                'role'          => 'assessor_1',
            ],
        );
    }

    public function testRefusesAnEmptyFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->store($this->upload('', 'signature.png', 'image/png'), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
        ]);
    }

    /**
     * The size is checked against the bytes read, not the size the upload
     * declares — a stream can say anything.
     */
    public function testRefusesAFileLargerThanTheLimitEvenWhenItUnderstatesItsSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $oversized = str_repeat('A', AttachmentService::MAX_BYTES + 1024);

        $this->service->store(
            new UploadedFile(
                (new StreamFactory())->createStream($oversized),
                'signature.png',
                'image/png',
                12,
            ),
            [
                'assessment_id' => $this->assessmentA,
                'kind'          => 'signature',
                'role'          => 'assessor_1',
            ],
        );
    }

    public function testRefusesASignatureRoleItDoesNotKnow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'the_inspector',
        ]);
    }

    public function testRefusesAKindItDoesNotKnow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'video',
            'role'          => 'assessor_1',
        ]);
    }

    /** The filename on disk is minted here; nothing the caller sends reaches a path. */
    public function testTheFilenameOnDiskIgnoresTheOneSent(): void
    {
        $this->service->store(
            $this->upload($this->pngBytes(), '../../../../etc/passwd', 'image/png'),
            [
                'assessment_id' => $this->assessmentA,
                'kind'          => 'signature',
                'role'          => 'assessor_1',
            ],
        );

        $row = Attachment::query()->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('..', (string) $row->storage_path);
        $this->assertStringNotContainsString('passwd', (string) $row->storage_path);
        $this->assertFileExists($this->storage . '/' . $row->storage_path);
    }

    public function testReadsBackWhatWasStored(): void
    {
        $stored = $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
        ]);

        $found = $this->service->read($stored['id']);

        $this->assertNotNull($found);
        $this->assertSame('image/png', $found['mime']);
        $this->assertSame($this->pngBytes(), $found['bytes']);
    }

    public function testWillNotReadAnotherOrganisationsAttachment(): void
    {
        $stored = $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
        ]);

        TenantContext::forget();
        TenantContext::set($this->orgB, null);

        $this->assertNull($this->service->read($stored['id']));
    }

    // ─── fixtures ───

    private function pngBytes(int $width = 2, int $height = 2): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function png(int $width = 2, int $height = 2): UploadedFile
    {
        return $this->upload($this->pngBytes($width, $height), 'signature.png', 'image/png');
    }

    private function upload(string $bytes, string $name, string $type): UploadedFile
    {
        return new UploadedFile(
            (new StreamFactory())->createStream($bytes),
            $name,
            $type,
            strlen($bytes),
        );
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        $found = [];
        $directory = new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile()) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    private function makeOrganization(string $code): int
    {
        return (int) Capsule::table('organizations')->insertGetId([
            'code' => $code,
            'name' => strtoupper($code),
        ]);
    }

    /** @see SyncServiceTest::makeFacility for why the slot is chosen, not derived. */
    private function makeAssessment(int $organizationId, int $templateId, string $slot): string
    {
        $facilityId = '019fd201-0000-7000-8000-0000000000' . $slot[0] . $slot[1];
        $siteId = '019fd201-0000-7000-8000-0000000001' . $slot[0] . $slot[1];
        $assessmentId = '019fd201-0000-7000-8000-0000000002' . $slot[0] . $slot[1];

        Capsule::table('facilities')->insert([
            'id'              => BinaryUuid::toBytes($facilityId),
            'organization_id' => $organizationId,
            'name'            => 'Facility ' . $organizationId,
            'source'          => 'registry',
        ]);

        Capsule::table('testing_sites')->insert([
            'id'              => BinaryUuid::toBytes($siteId),
            'organization_id' => $organizationId,
            'facility_id'     => BinaryUuid::toBytes($facilityId),
            'name'            => 'Site ' . $organizationId,
            'source'          => 'registry',
        ]);

        Capsule::table('assessments')->insert([
            'id'              => BinaryUuid::toBytes($assessmentId),
            'organization_id' => $organizationId,
            'template_id'     => $templateId,
            'testing_site_id' => BinaryUuid::toBytes($siteId),
            'facility_id'     => BinaryUuid::toBytes($facilityId),
            'status'          => 'draft',
            'assessed_on'     => '2026-08-05',
        ]);

        return $assessmentId;
    }
}
