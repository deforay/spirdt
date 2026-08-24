<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\TestingSite;
use App\Service\SitePhotoService;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Support\MakesTenants;

/**
 * The photograph of a testing site, against a real database and a real
 * directory.
 *
 * Two properties are worth the cost of an integration test. The first is that
 * the disk and the row never disagree: a replaced photograph must leave one
 * file behind rather than two, and a removed one must leave none, or an
 * installation accumulates images nothing references and nobody can find.
 *
 * The second is the scope. A site belongs to a PROGRAMME rather than to an
 * organisation, which is a wider boundary than most of this application's, and
 * the tests below pin both sides of it: two organisations sharing a country's
 * registry see the same photograph, and one in another programme cannot reach
 * it at all.
 */
final class SitePhotoServiceTest extends TestCase
{
    use MakesTenants;

    private int $orgA;
    private int $orgB;
    private string $siteA;
    private string $storage;
    private SitePhotoService $service;

    protected function setUp(): void
    {
        \App\Bootstrap::createApp();

        TenantContext::forget();

        TenantContext::withoutScope(function (): void {
            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 0');

            foreach (['testing_sites', 'facilities', 'organizations', 'programmes'] as $table) {
                Capsule::table($table)->delete();
            }

            Capsule::connection()->statement('SET FOREIGN_KEY_CHECKS = 1');
        });

        $this->orgA = $this->makeTenant('photo-a');
        $this->orgB = $this->makeTenant('photo-b');

        $this->siteA = $this->makeSite($this->orgA, 'aa');

        $this->storage = sys_get_temp_dir() . '/spirdt-site-photos-' . bin2hex(random_bytes(4));
        $this->service = new SitePhotoService($this->storage);

        $this->useTenant($this->orgA);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        $this->removeDirectory($this->storage);
    }

    public function testStoresThePhotographAndRecordsItOnTheSite(): void
    {
        $stored = $this->service->store($this->siteA, $this->png());

        $this->assertTrue($stored['has_photo']);
        $this->assertNotNull($stored['photo_taken_at']);

        $site = $this->site($this->siteA);

        $this->assertSame('image/png', $site->photo_mime);
        $this->assertNotNull($site->photo_checksum);
        $this->assertFileExists($this->storage . '/' . $site->photo_path);
        $this->assertCount(1, $this->storedFiles());
    }

    /**
     * A weak connection means the same photograph arrives twice. The second
     * one must cost a hash and nothing else — not a second file replacing an
     * identical one, and not a new date on a record that has not changed.
     */
    public function testTheSameImageTwiceIsANoOp(): void
    {
        $bytes = $this->pngBytes(4, 4);

        $this->service->store($this->siteA, $this->upload($bytes));
        $first = $this->site($this->siteA);

        $this->service->store($this->siteA, $this->upload($bytes));
        $second = $this->site($this->siteA);

        $this->assertSame($first->photo_path, $second->photo_path);
        $this->assertCount(1, $this->storedFiles());
    }

    /**
     * The bytes of a superseded photograph go with it. Keeping them would
     * leave the disk growing on every correction, with nothing pointing at
     * what was left behind.
     */
    public function testReplacingDeletesTheOldFile(): void
    {
        $this->service->store($this->siteA, $this->png(2, 2));
        $original = (string) $this->site($this->siteA)->photo_path;

        $this->service->store($this->siteA, $this->png(8, 8));
        $replacement = (string) $this->site($this->siteA)->photo_path;

        $this->assertNotSame($original, $replacement);
        $this->assertFileDoesNotExist($this->storage . '/' . $original);
        $this->assertFileExists($this->storage . '/' . $replacement);
        $this->assertCount(1, $this->storedFiles());
    }

    public function testRemovingClearsTheRowAndTheFile(): void
    {
        $this->service->store($this->siteA, $this->png());
        $path = (string) $this->site($this->siteA)->photo_path;

        $left = $this->service->remove($this->siteA);

        $this->assertFalse($left['has_photo']);
        $this->assertNull($this->site($this->siteA)->photo_path);
        $this->assertFileDoesNotExist($this->storage . '/' . $path);
        $this->assertSame([], $this->storedFiles());
    }

    public function testReadsTheBytesBack(): void
    {
        $bytes = $this->pngBytes(3, 3);

        $this->service->store($this->siteA, $this->upload($bytes));

        $found = $this->service->read($this->siteA);

        $this->assertNotNull($found);
        $this->assertSame($bytes, $found['bytes']);
        $this->assertSame('image/png', $found['mime']);
    }

    public function testASiteWithNoPhotographReadsAsNothing(): void
    {
        $this->assertNull($this->service->read($this->siteA));
    }

    /**
     * The registry is what a programme shares. A second organisation auditing
     * in the same country is looking at the same bench, and hiding its
     * photograph from them would be hiding half the site record.
     */
    public function testAnotherOrganisationInTheSameProgrammeSeesIt(): void
    {
        $this->service->store($this->siteA, $this->png());

        $this->shareProgramme($this->orgB, $this->orgA);
        $this->useTenant($this->orgB);

        $this->assertNotNull($this->service->read($this->siteA));
    }

    /**
     * A different programme is a different country's list. Not-found rather
     * than forbidden, so a caller learns nothing about whether the id exists
     * somewhere they cannot see.
     */
    public function testAnotherProgrammeCannotReachIt(): void
    {
        $this->service->store($this->siteA, $this->png());

        $this->useTenant($this->orgB);

        $this->assertNull($this->service->read($this->siteA));

        $this->expectException(RuntimeException::class);
        $this->service->store($this->siteA, $this->png());
    }

    /** The bytes are what decide, never the name or the declared type. */
    public function testRefusesAFileThatIsNotAnImage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->store(
            $this->siteA,
            $this->upload('MZ this is not a picture of anything', 'bench.png', 'image/png'),
        );
    }

    public function testRefusesAnUnknownSite(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->store('019fd201-0000-7000-8000-00000000ffff', $this->png());
    }

    private function site(string $id): TestingSite
    {
        $site = TestingSite::query()->where('testing_sites.id', BinaryUuid::toBytes($id))->first();

        $this->assertInstanceOf(TestingSite::class, $site);

        return $site;
    }

    private function makeSite(int $organizationId, string $slot): string
    {
        $facilityId = '019fd202-0000-7000-8000-0000000000' . $slot;
        $siteId = '019fd202-0000-7000-8000-0000000001' . $slot;

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

        return $siteId;
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
        return $this->upload($this->pngBytes($width, $height));
    }

    private function upload(string $bytes, string $name = 'bench.png', string $type = 'image/png'): UploadedFile
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
        if (!is_dir($this->storage)) {
            return [];
        }

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
}
