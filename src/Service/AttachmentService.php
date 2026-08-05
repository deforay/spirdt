<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Assessment;
use App\Models\Attachment;
use App\Support\BinaryUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * Takes a signature or photograph off a device and puts it on disk.
 *
 * Uploads are the part of an API most worth being paranoid about, so nothing
 * the caller says about the file is believed:
 *
 *   The type is sniffed from the bytes, not read from the Content-Type header
 *   or the filename, and then confirmed by actually decoding the image. A file
 *   that claims to be a PNG and is not never reaches disk.
 *
 *   The name on disk is minted here. A client-supplied filename is how a
 *   traversal lands, and there is no reason to keep one — nothing about the
 *   original name is information.
 *
 *   The checksum is computed from what arrived. A checksum the caller can
 *   choose cannot detect anything.
 *
 *   Files live under var/uploads, outside the document root. Even a stored
 *   file that somehow got past the checks above is inert there.
 *
 * Re-uploading is expected rather than exceptional: a device that cannot tell
 * a failed request from a lost response will send the same signature again.
 * The (assessment, checksum) unique key makes that a no-op, and a signature
 * redrawn for the same role replaces the old one rather than accumulating.
 */
final class AttachmentService
{
    /**
     * Five megabytes.
     *
     * A signature is a few kilobytes; this is sized for the photographs that
     * will follow it, and to be a limit rather than a hope. Anything larger is
     * refused before it is read into memory.
     */
    public const MAX_BYTES = 5_242_880;

    /** Sniffed type to the extension it is stored under. Nothing else is accepted. */
    private const ALLOWED_TYPES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
    ];

    private const KINDS = ['signature', 'photo', 'document'];

    /**
     * One signature per role. A second one for the same role means the
     * assessor redrew it, so it replaces rather than joins.
     */
    private const SIGNATURE_ROLES = ['assessor_1', 'assessor_2', 'site_representative'];

    public function __construct(private readonly string $baseDirectory)
    {
    }

    /**
     * @param  array<string,mixed> $meta
     * @return array<string,mixed> what the device needs to mark the row clean
     *
     * @throws InvalidArgumentException the upload is wrong; retrying sends the same thing
     * @throws RuntimeException         it belongs to another organisation
     */
    public function store(UploadedFileInterface $file, array $meta): array
    {
        $organizationId = TenantContext::requireOrganizationId();

        $assessmentId = $this->requireUuid($meta, 'assessment_id');
        $kind = $this->requireKind($meta);
        $role = $this->requireRole($meta, $kind);
        $questionCode = $this->questionCode($meta, $kind);

        // The scope means an assessment belonging to another organisation
        // resolves to null. Refused as not-found rather than forbidden: the
        // caller learns nothing about whether the id exists elsewhere.
        $assessment = Assessment::findByUuid($assessmentId);

        if ($assessment === null) {
            throw new RuntimeException('That assessment is not in this organisation.');
        }

        $bytes = $this->readVerifiedBytes($file);
        $mime = $this->sniff($bytes);
        $checksum = hash('sha256', $bytes);

        // Idempotency, checked before anything is written. A retried upload
        // must cost one hash and one indexed lookup, not a second file.
        $existing = Attachment::query()
            ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
            ->where('checksum', $checksum)
            ->first();

        if ($existing instanceof Attachment) {
            return $this->acknowledge($existing);
        }

        $id = BinaryUuid::v7();
        $relative = sprintf(
            'attachments/%d/%s/%s.%s',
            $organizationId,
            $assessmentId,
            $id,
            self::ALLOWED_TYPES[$mime],
        );

        $this->write($relative, $bytes);

        try {
            $attachment = Capsule::connection()->transaction(
                function () use ($id, $organizationId, $assessmentId, $kind, $role, $questionCode, $relative, $mime, $bytes, $checksum): Attachment {
                    if ($kind === 'signature') {
                        $this->replaceSignature($assessmentId, $role);
                    }

                    $attachment = new Attachment();
                    $attachment->id = $id;
                    $attachment->fill([
                        'organization_id' => $organizationId,
                        'assessment_id'   => $assessmentId,
                        'kind'            => $kind,
                        'role'            => $role,
                        'question_code'   => $questionCode,
                        'storage_path'    => $relative,
                        'mime_type'       => $mime,
                        'byte_size'       => strlen($bytes),
                        'checksum'        => $checksum,
                    ]);
                    $attachment->save();

                    return $attachment;
                },
            );
        } catch (Throwable $e) {
            // The row is the record; a file with no row is litter. Remove it
            // rather than leaving the directory to grow on every failure.
            @unlink($this->absolute($relative));

            throw $e;
        }

        return $this->acknowledge($attachment);
    }

    /**
     * The bytes of a stored attachment, or null if it is not this organisation's.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public function read(string $attachmentId): ?array
    {
        $attachment = Attachment::query()
            ->where('id', BinaryUuid::toBytes($attachmentId))
            ->first();

        if (!$attachment instanceof Attachment) {
            return null;
        }

        $path = $this->absolute((string) $attachment->storage_path);
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime'  => (string) $attachment->mime_type,
        ];
    }

    /**
     * Drop whatever was previously signed for this role, file and row.
     *
     * A signature is a statement by one person, not a collection. Keeping the
     * superseded one would leave two images with equal claim to being what was
     * signed, and no way to tell which the site actually saw.
     */
    private function replaceSignature(string $assessmentId, ?string $role): void
    {
        $superseded = Attachment::query()
            ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
            ->where('kind', 'signature')
            ->where('role', $role)
            ->get();

        foreach ($superseded as $old) {
            @unlink($this->absolute((string) $old->storage_path));
            $old->delete();
        }
    }

    /** @return array<string,mixed> */
    private function acknowledge(Attachment $attachment): array
    {
        return [
            'id'        => (string) $attachment->id,
            'kind'      => (string) $attachment->kind,
            'role'      => $attachment->role,
            'checksum'  => (string) $attachment->checksum,
            'byte_size' => (int) $attachment->byte_size,
        ];
    }

    /**
     * Read the upload, refusing anything oversized before it is in memory.
     *
     * @throws InvalidArgumentException
     */
    private function readVerifiedBytes(UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The file did not arrive intact. Send it again.');
        }

        $declared = $file->getSize();

        if ($declared !== null && $declared > self::MAX_BYTES) {
            throw new InvalidArgumentException(
                sprintf('The file is larger than %d KB.', intdiv(self::MAX_BYTES, 1024)),
            );
        }

        $stream = $file->getStream();
        $stream->rewind();
        // One byte past the limit, so a stream that lied about its size is
        // still caught without reading an unbounded amount of it.
        $bytes = $stream->read(self::MAX_BYTES + 1);

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException(
                sprintf('The file is larger than %d KB.', intdiv(self::MAX_BYTES, 1024)),
            );
        }

        if ($bytes === '') {
            throw new InvalidArgumentException('The file is empty.');
        }

        return $bytes;
    }

    /**
     * What the bytes actually are.
     *
     * Sniffed, then decoded. finfo reads a magic number, which a crafted file
     * can carry in front of anything; getimagesize has to parse enough of the
     * image to report its dimensions, so a file that passes both is an image.
     *
     * @throws InvalidArgumentException
     */
    private function sniff(string $bytes): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);

        if (!is_string($mime) || !isset(self::ALLOWED_TYPES[$mime])) {
            throw new InvalidArgumentException('Only PNG and JPEG images are accepted.');
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new InvalidArgumentException('That file is not a readable image.');
        }

        return $mime;
    }

    private function write(string $relative, string $bytes): void
    {
        $path = $this->absolute($relative);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the upload directory.');
        }

        if (@file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the file.');
        }

        // Readable by the web user and its group, by nobody else, and never
        // executable. These are data files that happen to live on a server.
        @chmod($path, 0o640);
    }

    private function absolute(string $relative): string
    {
        return rtrim($this->baseDirectory, '/') . '/' . ltrim($relative, '/');
    }

    /** @param array<string,mixed> $meta */
    private function requireUuid(array $meta, string $key): string
    {
        $value = $meta[$key] ?? null;

        if (!is_string($value) || !BinaryUuid::isValid($value)) {
            throw new InvalidArgumentException("{$key} must be a UUID.");
        }

        return $value;
    }

    /** @param array<string,mixed> $meta */
    private function requireKind(array $meta): string
    {
        $kind = $meta['kind'] ?? null;

        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('kind must be signature, photo or document.');
        }

        return $kind;
    }

    /**
     * The role, constrained for signatures and free for the rest.
     *
     * A signature role decides what replaces what, so an unrecognised one
     * would quietly create a second parallel signature slot nothing reads.
     *
     * @param array<string,mixed> $meta
     */
    private function requireRole(array $meta, string $kind): ?string
    {
        $role = $meta['role'] ?? null;

        if ($kind === 'signature') {
            if (!is_string($role) || !in_array($role, self::SIGNATURE_ROLES, true)) {
                throw new InvalidArgumentException(
                    'role must be one of: ' . implode(', ', self::SIGNATURE_ROLES) . '.',
                );
            }

            return $role;
        }

        if ($role === null || $role === '') {
            return null;
        }

        if (!is_string($role) || mb_strlen($role) > 50) {
            throw new InvalidArgumentException('role must be 50 characters or fewer.');
        }

        return $role;
    }

    /** @param array<string,mixed> $meta */
    private function questionCode(array $meta, string $kind): ?string
    {
        $code = $meta['question_code'] ?? null;

        if ($code === null || $code === '') {
            return null;
        }

        if (!is_string($code) || preg_match('/^\d{1,3}\.\d{1,3}$/', $code) !== 1) {
            throw new InvalidArgumentException('question_code must look like 4.10.');
        }

        if ($kind === 'signature') {
            throw new InvalidArgumentException('A signature does not belong to a question.');
        }

        return $code;
    }
}
