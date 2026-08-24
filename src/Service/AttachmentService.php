<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Assessment;
use App\Models\Attachment;
use App\Support\BinaryUuid;
use App\Support\ImageUpload;
use App\Tenancy\TenantContext;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * Takes a signature or photograph off a device and puts it on disk.
 *
 * Uploads are the part of an API most worth being paranoid about, and nothing
 * the caller says about the file is believed. Those checks are ImageUpload's,
 * shared with the registry's site photographs so the two surfaces cannot drift
 * into accepting different things — read them there. The checksum is the one
 * this class adds: computed from what arrived, because a checksum the caller
 * can choose cannot detect anything.
 *
 * Re-uploading is expected rather than exceptional: a device that cannot tell
 * a failed request from a lost response will send the same signature again.
 * The (assessment, checksum) unique key makes that a no-op, and a signature
 * redrawn for the same role replaces the old one rather than accumulating.
 */
final class AttachmentService
{
    private const KINDS = ['signature', 'photo', 'document'];

    /**
     * One signature per role. A second one for the same role means the
     * assessor redrew it, so it replaces rather than joins.
     *
     * Two assessors and the site. A second assessor is optional and common
     * enough to be worth a slot; the site's countersignature is what makes the
     * findings agreed rather than asserted.
     */
    private const SIGNATURE_ROLES = ['assessor_1', 'assessor_2', 'site_representative'];

    /** The column is VARCHAR(200). Longer than any name, short enough to bound. */
    private const MAX_NAME_LENGTH = 200;

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
        $signedName = $this->requireSignedName($meta, $kind);
        $questionCode = $this->questionCode($meta, $kind);

        // The scope means an assessment belonging to another organisation
        // resolves to null. Refused as not-found rather than forbidden: the
        // caller learns nothing about whether the id exists elsewhere.
        $assessment = Assessment::findByUuid($assessmentId);

        if ($assessment === null) {
            throw new RuntimeException('That assessment is not in this organisation.');
        }

        $bytes = ImageUpload::verifiedBytes($file);
        $mime = ImageUpload::sniff($bytes);

        // Brought down to size before anything else looks at it, so what is
        // hashed, stored and served are all the same bytes. A device that has
        // been offline for a day sends whatever it was given.
        ['bytes' => $bytes, 'mime' => $mime] = $this->images->process($bytes, $mime);

        $checksum = hash('sha256', $bytes);

        // Idempotency, checked before anything is written. A retried upload
        // must cost one hash and one indexed lookup, not a second file.
        //
        // Scoped by role as well as checksum. A signature slot accepts a
        // single tap, and a tap in the same place on the same device produces
        // byte-identical bytes — so matching on checksum alone would hand a
        // second signatory the first one's row, and the device would mark its
        // own mark clean and stop retrying with nothing looking wrong.
        //
        // IS NULL rather than = NULL for a photograph, which carries no role.
        // `where('role', null)` compiles to `role = NULL`, which matches
        // nothing, and a check that never matches is not a check.
        $query = Attachment::query()
            ->where('assessment_id', BinaryUuid::toBytes($assessmentId))
            ->where('checksum', $checksum);

        $existing = ($role === null ? $query->whereNull('role') : $query->where('role', $role))
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
            ImageUpload::extensionFor($mime),
        );

        ImageUpload::write($this->baseDirectory, $relative, $bytes);

        try {
            $attachment = Capsule::connection()->transaction(
                function () use ($id, $organizationId, $assessmentId, $kind, $role, $signedName, $questionCode, $relative, $mime, $bytes, $checksum): Attachment {
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
                        'signed_name'     => $signedName,
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
            @unlink(ImageUpload::absolute($this->baseDirectory, $relative));

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

        $path = ImageUpload::absolute($this->baseDirectory, (string) $attachment->storage_path);
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
            @unlink(ImageUpload::absolute($this->baseDirectory, (string) $old->storage_path));
            $old->delete();
        }
    }

    /** @return array<string,mixed> */
    private function acknowledge(Attachment $attachment): array
    {
        return [
            'id'          => (string) $attachment->id,
            'kind'        => (string) $attachment->kind,
            'role'        => $attachment->role,
            'signed_name' => $attachment->signed_name,
            'checksum'    => (string) $attachment->checksum,
            'byte_size'   => (int) $attachment->byte_size,
        ];
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

    /**
     * Who the mark claims to be.
     *
     * Required for a signature and stored with it, rather than resolved later
     * from the assessment's creator or from Part A. A user can be renamed
     * after a visit; what has to stay recoverable is the name as it stood when
     * the pen went down. For a second assessor there is nowhere else to get it
     * from at all.
     *
     * @param array<string,mixed> $meta
     */
    private function requireSignedName(array $meta, string $kind): ?string
    {
        $name = $meta['signed_name'] ?? null;
        $name = is_string($name) ? trim($name) : '';

        if ($kind !== 'signature') {
            return $name === '' ? null : mb_substr($name, 0, self::MAX_NAME_LENGTH);
        }

        if ($name === '') {
            throw new InvalidArgumentException('signed_name is required for a signature.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('signed_name must be %d characters or fewer.', self::MAX_NAME_LENGTH),
            );
        }

        return $name;
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
