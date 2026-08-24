<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Attachment;
use App\Service\AttachmentService;
use App\Support\BinaryUuid;
use App\Support\ImageUpload;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Support\MakesTenants;

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
    use MakesTenants;

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
                    'assessments', 'templates', 'testing_sites', 'facilities', 'organizations', 'programmes'] as $table
            ) {
                Capsule::table($table)->delete();
            }
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgA = $this->makeTenant('att-a');
        $this->orgB = $this->makeTenant('att-b');

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

        $this->useTenant($this->orgA);
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
            'signed_name'   => 'Grace Phiri',
        ]);

        $this->assertSame('signature', $result['kind']);
        $this->assertSame('assessor_1', $result['role']);
        $this->assertSame('Grace Phiri', $result['signed_name']);

        $row = Attachment::query()->first();

        $this->assertNotNull($row);
        $this->assertSame('image/png', $row->mime_type);
        $this->assertSame('Grace Phiri', $row->signed_name);
        $this->assertSame($this->orgA, (int) $row->organization_id);
        $this->assertFileExists($this->storage . '/' . $row->storage_path);
    }

    /**
     * A mark with no name against it is a squiggle, and the name is stored
     * rather than resolved later because a user can be renamed after a visit
     * and a second assessor is nowhere in the data at all.
     */
    public function testRefusesASignatureWithNoName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_2',
            'signed_name'   => '   ',
        ]);
    }

    public function testAcceptsAllThreeSignatureRoles(): void
    {
        foreach (
            [['assessor_1', 2], ['assessor_2', 3], ['site_representative', 4]] as [$role, $size]
        ) {
            $this->service->store($this->png($size, $size), [
                'assessment_id' => $this->assessmentA,
                'kind'          => 'signature',
                'role'          => $role,
                'signed_name'   => 'Someone ' . $role,
            ]);
        }

        $this->assertSame(3, Attachment::query()->count());
    }

    /**
     * A signature slot accepts a single tap, and a tap in the same place on
     * the same device is byte-identical. Matching idempotency on the checksum
     * alone handed the second signatory the first one's row, and the device
     * then marked its own mark clean and stopped retrying — one image on the
     * server, two roles claiming it, nothing looking wrong anywhere.
     */
    public function testTwoRolesWithIdenticalBytesBothKeepTheirOwnMark(): void
    {
        $first = $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
            'signed_name'   => 'First Assessor',
        ]);

        $second = $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_2',
            'signed_name'   => 'Second Assessor',
        ]);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, Attachment::query()->count());
        $this->assertCount(2, $this->storedFiles());

        $names = Attachment::query()->pluck('signed_name')->all();

        $this->assertContains('First Assessor', $names);
        $this->assertContains('Second Assessor', $names);
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
            'signed_name'   => 'Test Signatory',
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
            'signed_name'   => 'Test Signatory',
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
            'signed_name'   => 'Test Signatory',
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
            'signed_name'   => 'Test Signatory',
        ]);

        $this->service->store($this->png(3, 3), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'site_representative',
            'signed_name'   => 'Site Person',
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
            'signed_name'   => 'Test Signatory',
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
                'signed_name'   => 'Test Signatory',
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
                'signed_name'   => 'Test Signatory',
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
            'signed_name'   => 'Test Signatory',
        ]);
    }

    /**
     * The size is checked against the bytes read, not the size the upload
     * declares — a stream can say anything.
     */
    public function testRefusesAFileLargerThanTheLimitEvenWhenItUnderstatesItsSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $oversized = str_repeat('A', ImageUpload::maxBytes() + 1024);

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
                'signed_name'   => 'Test Signatory',
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
            'signed_name'   => 'Test Signatory',
        ]);
    }

    public function testRefusesAKindItDoesNotKnow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'video',
            'role'          => 'assessor_1',
            'signed_name'   => 'Test Signatory',
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
                'signed_name'   => 'Test Signatory',
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
            'signed_name'   => 'Test Signatory',
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
            'signed_name'   => 'Test Signatory',
        ]);

        TenantContext::forget();
        $this->useTenant($this->orgB);

        $this->assertNull($this->service->read($stored['id']));
    }

    // ─── fixtures ───

    /**
     * The identity is the key the device minted, and it has to be, because
     * nothing about the image can do the job: a section holds several
     * photographs and two taken a minute apart by a phone that did not move
     * are byte-identical.
     */
    public function testAPhotographIsIdentifiedByItsClientKey(): void
    {
        $key = '019fd300-0000-7000-8000-000000000001';
        $bytes = $this->pngBytes(6, 6);

        $first = $this->service->store($this->upload($bytes, 'photo.png', 'image/png'), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'photo',
            'section_code'  => '2',
            'caption'       => 'Fridge with no log',
            'client_key'    => $key,
        ]);

        $second = $this->service->store($this->upload($bytes, 'photo.png', 'image/png'), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'photo',
            'section_code'  => '2',
            'caption'       => 'Fridge with no temperature log',
            'client_key'    => $key,
        ]);

        $this->assertSame($first['id'], $second['id']);
        // The words are a correction; the picture is the same picture.
        $this->assertSame('Fridge with no temperature log', $second['caption']);
        $this->assertCount(1, $this->storedFiles());
    }

    /**
     * Two photographs of the same shelf are two photographs. Folding them
     * together on checksum would lose one, and the assessor took it on purpose.
     */
    public function testIdenticalBytesUnderDifferentKeysAreTwoPhotographs(): void
    {
        $bytes = $this->pngBytes(7, 7);

        foreach (['019fd300-0000-7000-8000-00000000000a', '019fd300-0000-7000-8000-00000000000b'] as $key) {
            $this->service->store($this->upload($bytes, 'photo.png', 'image/png'), [
                'assessment_id' => $this->assessmentA,
                'kind'          => 'photo',
                'section_code'  => '3',
                'client_key'    => $key,
            ]);
        }

        $this->assertSame(2, Attachment::query()->where('section_code', '3')->count());
    }

    /**
     * Enforced here as well as on the screen: the screen is not the only thing
     * that can reach this endpoint, and a device carrying a queue from an
     * older build is a real case.
     */
    public function testRefusesMoreThanFivePhotographsInASection(): void
    {
        for ($taken = 0; $taken < AttachmentService::MAX_PER_SECTION; $taken++) {
            $this->service->store($this->png(4 + $taken, 4 + $taken), [
                'assessment_id' => $this->assessmentA,
                'kind'          => 'photo',
                'section_code'  => '4',
                'client_key'    => sprintf('019fd300-0000-7000-8000-0000000001%02d', $taken),
            ]);
        }

        $this->expectException(InvalidArgumentException::class);

        $this->service->store($this->png(20, 20), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'photo',
            'section_code'  => '4',
            'client_key'    => '019fd300-0000-7000-8000-0000000001ff',
        ]);
    }

    /** A full section must still accept retries of what it already holds. */
    public function testAFullSectionStillAcceptsARetry(): void
    {
        $keys = [];

        for ($taken = 0; $taken < AttachmentService::MAX_PER_SECTION; $taken++) {
            $keys[] = $key = sprintf('019fd300-0000-7000-8000-0000000002%02d', $taken);

            $this->service->store($this->png(4 + $taken, 4 + $taken), [
                'assessment_id' => $this->assessmentA,
                'kind'          => 'photo',
                'section_code'  => '5',
                'client_key'    => $key,
            ]);
        }

        $again = $this->service->store($this->png(4, 4), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'photo',
            'section_code'  => '5',
            'client_key'    => $keys[0],
        ]);

        $this->assertSame($keys[0], $again['client_key']);
        $this->assertSame(AttachmentService::MAX_PER_SECTION, Attachment::query()->where('section_code', '5')->count());
    }

    public function testRemovesAPhotographAndItsFile(): void
    {
        $stored = $this->service->store($this->png(9, 9), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'photo',
            'section_code'  => '1',
            'client_key'    => '019fd300-0000-7000-8000-000000000cc1',
        ]);

        $this->assertTrue($this->service->remove($stored['id']));
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, Attachment::query()->count());

        // Deleting one that is already gone is a success: a device retrying a
        // delete it never saw acknowledged must not be stuck on it.
        $this->assertTrue($this->service->remove($stored['id']));
    }

    /**
     * A report whose countersignature can be deleted is not a countersigned
     * report. Signatures are replaced by signing again.
     */
    public function testWillNotDeleteASignature(): void
    {
        $stored = $this->service->store($this->png(), [
            'assessment_id' => $this->assessmentA,
            'kind'          => 'signature',
            'role'          => 'assessor_1',
            'signed_name'   => 'Grace Phiri',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->service->remove($stored['id']);
    }

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

    /** @see SyncServiceTest::makeFacility for why the slot is chosen, not derived. */
    private function makeAssessment(int $organizationId, int $templateId, string $slot): string
    {
        $facilityId = '019fd201-0000-7000-8000-0000000000' . $slot[0] . $slot[1];
        $siteId = '019fd201-0000-7000-8000-0000000001' . $slot[0] . $slot[1];
        $assessmentId = '019fd201-0000-7000-8000-0000000002' . $slot[0] . $slot[1];

        Capsule::table('facilities')->insert([
            'id'              => BinaryUuid::toBytes($facilityId),
            'programme_id'    => $this->programmeFor($organizationId),
            'organization_id' => $organizationId,
            'name'            => 'Facility ' . $organizationId,
            'source'          => 'registry',
        ]);

        Capsule::table('testing_sites')->insert([
            'id'              => BinaryUuid::toBytes($siteId),
            'programme_id'    => $this->programmeFor($organizationId),
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
