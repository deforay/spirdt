<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\TestingSite;
use App\Support\BinaryUuid;
use App\Support\ImageUpload;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * The photograph of a testing site.
 *
 * A site's name is whatever the people who work there call it, and an assessor
 * arriving at a hospital with four benches and a row reading "Lab 2" is
 * relying on somebody at reception knowing which one that is. A photograph is
 * the one part of the record that cannot be typed wrong.
 *
 * REGISTRY DATA, NOT AUDIT DATA, which is why this is not an attachment. The
 * attachments table holds one organisation's signatures and evidence, scoped
 * to that organisation and correctly invisible to the rest of the programme.
 * A site belongs to the programme — the national list everyone auditing in the
 * country works from — and so does its photograph.
 *
 * ONE PHOTOGRAPH, REPLACED RATHER THAN ACCUMULATED. The question it answers is
 * "which bench is this", and that has one current answer. Superseded bytes are
 * deleted rather than orphaned: the row is the record, and a file with no row
 * is litter that grows on every correction.
 *
 * What arrives is not believed — see ImageUpload, which holds the checks this
 * shares with the attachment channel so the two cannot drift apart, and
 * ImageProcessor, which decides how big what gets stored is allowed to be.
 */
final class SitePhotoService
{
    private readonly string $baseDirectory;

    private readonly ImageProcessor $images;

    public function __construct(string $baseDirectory, ?ImageProcessor $images = null)
    {
        $this->baseDirectory = $baseDirectory;
        // Configured from the environment unless a caller says otherwise,
        // which only tests do.
        $this->images = $images ?? ImageProcessor::fromEnvironment();
    }

    /**
     * @return array<string,mixed> what the screen needs to show it was stored
     *
     * @throws \InvalidArgumentException the upload is wrong; retrying sends the same thing
     * @throws RuntimeException          no such site in this programme
     */
    public function store(string $siteId, UploadedFileInterface $file): array
    {
        $site = $this->require($siteId);

        $bytes = ImageUpload::verifiedBytes($file);
        $mime = ImageUpload::sniff($bytes);

        // Brought down to size here rather than trusted to have been resized
        // on the way in. The screen does resize, and an assessor on a tablet
        // that has been offline all day may have chosen a twelve-megapixel
        // photograph in a browser that could not.
        ['bytes' => $bytes, 'mime' => $mime] = $this->images->process($bytes, $mime);

        $checksum = hash('sha256', $bytes);

        // The same image sent twice — a retry, or somebody pressing the button
        // again because the first attempt looked slow — is a no-op rather than
        // a second file replacing an identical one.
        if ((string) $site->photo_checksum === $checksum && $site->photo_path !== null) {
            return $this->describe($site);
        }

        $superseded = $site->photo_path === null ? null : (string) $site->photo_path;

        $relative = sprintf(
            'sites/%d/%s/%s.%s',
            TenantContext::requireProgrammeId(),
            $siteId,
            BinaryUuid::v7(),
            ImageUpload::extensionFor($mime),
        );

        ImageUpload::write($this->baseDirectory, $relative, $bytes);

        try {
            Capsule::connection()->transaction(function () use ($site, $relative, $mime, $bytes, $checksum): void {
                $site->forceFill([
                    'photo_path'       => $relative,
                    'photo_mime'       => $mime,
                    'photo_checksum'   => $checksum,
                    'photo_byte_size'  => strlen($bytes),
                    'photo_taken_at'   => gmdate('Y-m-d H:i:s'),
                    'photo_by_user_id' => TenantContext::current()?->userId,
                ])->save();
            });
        } catch (Throwable $e) {
            @unlink(ImageUpload::absolute($this->baseDirectory, $relative));

            throw $e;
        }

        // Only once the row points at the new file. The other order leaves a
        // site whose record names bytes that are no longer on disk.
        if ($superseded !== null) {
            @unlink(ImageUpload::absolute($this->baseDirectory, $superseded));
        }

        return $this->describe($site);
    }

    /**
     * The bytes, or null when there is no photograph or no such site here.
     *
     * The two are one answer on purpose. A site in another programme is "not
     * found" rather than "forbidden", so a caller learns nothing about whether
     * the id exists elsewhere.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public function read(string $siteId): ?array
    {
        $site = BinaryUuid::isValid($siteId)
            ? TestingSite::query()->where('testing_sites.id', BinaryUuid::toBytes($siteId))->first()
            : null;

        if (!$site instanceof TestingSite || $site->photo_path === null) {
            return null;
        }

        $bytes = @file_get_contents(
            ImageUpload::absolute($this->baseDirectory, (string) $site->photo_path),
        );

        if ($bytes === false) {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => (string) $site->photo_mime];
    }

    /**
     * @return array<string,mixed> the site as it now stands, with no photograph
     *
     * @throws RuntimeException no such site in this programme
     */
    public function remove(string $siteId): array
    {
        $site = $this->require($siteId);
        $path = $site->photo_path === null ? null : (string) $site->photo_path;

        $site->forceFill([
            'photo_path'       => null,
            'photo_mime'       => null,
            'photo_checksum'   => null,
            'photo_byte_size'  => null,
            'photo_taken_at'   => null,
            'photo_by_user_id' => null,
        ])->save();

        if ($path !== null) {
            @unlink(ImageUpload::absolute($this->baseDirectory, $path));
        }

        return $this->describe($site);
    }

    /** @throws RuntimeException */
    private function require(string $siteId): TestingSite
    {
        $site = BinaryUuid::isValid($siteId)
            ? TestingSite::query()->where('testing_sites.id', BinaryUuid::toBytes($siteId))->first()
            : null;

        if (!$site instanceof TestingSite) {
            throw new RuntimeException('That testing site is not in this programme.');
        }

        return $site;
    }

    /** @return array<string,mixed> */
    private function describe(TestingSite $site): array
    {
        return [
            'has_photo'      => $site->photo_path !== null,
            'photo_taken_at' => $site->photo_taken_at?->format('c'),
            'byte_size'      => $site->photo_byte_size === null ? null : (int) $site->photo_byte_size,
        ];
    }
}
