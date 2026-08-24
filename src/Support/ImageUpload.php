<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * The checks every uploaded image goes through, in one place.
 *
 * Two things in this application take an image off a client — a signature or
 * evidence photograph belonging to a visit, and the photograph of a testing
 * site — and they store it in different places for different reasons. What
 * they must never differ on is what they accept, because these checks are the
 * whole defence:
 *
 *   The type is sniffed from the bytes, not read from the Content-Type header
 *   or the filename, and then confirmed by actually decoding the image. A file
 *   that claims to be a PNG and is not never reaches disk.
 *
 *   The name on disk is minted by the caller from an id it generated. A
 *   client-supplied filename is how a traversal lands, and there is no reason
 *   to keep one.
 *
 *   Files land under var/uploads, outside the document root, readable by the
 *   web user and never executable. Even a stored file that somehow got past
 *   the checks above is inert there.
 *
 * Static rather than injected, because there is nothing to configure and no
 * seam worth having: a caller that could substitute a laxer set of checks is
 * the failure this class exists to prevent.
 */
final class ImageUpload
{
    /**
     * Ten megabytes, matching UPLOAD_MAX_BYTES in .env.example.
     *
     * A limit rather than a hope: anything larger is refused before it is read
     * into memory. It is generous because it no longer decides what gets
     * stored — ImageProcessor brings what arrives down to a sensible size, so
     * this only has to stop a request that would cost memory to read. Keep it
     * under the SAPI's own upload_max_filesize, or large media is rejected
     * before it ever reaches this code.
     */
    public const DEFAULT_MAX_BYTES = 10_485_760;

    /** The configured ceiling, or the default when the deployment says nothing. */
    public static function maxBytes(): int
    {
        $configured = env('UPLOAD_MAX_BYTES');

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_MAX_BYTES;
    }

    /** Sniffed type to the extension it is stored under. Nothing else is accepted. */
    private const ALLOWED_TYPES = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
    ];

    /**
     * Read the upload, refusing anything oversized before it is in memory.
     *
     * @throws InvalidArgumentException
     */
    public static function verifiedBytes(UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The file did not arrive intact. Send it again.');
        }

        $limit = self::maxBytes();

        $declared = $file->getSize();

        if ($declared !== null && $declared > $limit) {
            throw new InvalidArgumentException(self::tooLarge());
        }

        $stream = $file->getStream();
        $stream->rewind();
        // One byte past the limit, so a stream that lied about its size is
        // still caught without reading an unbounded amount of it.
        $bytes = $stream->read($limit + 1);

        if (strlen($bytes) > $limit) {
            throw new InvalidArgumentException(self::tooLarge());
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
    public static function sniff(string $bytes): string
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

    /** The extension a sniffed type is stored under. */
    public static function extensionFor(string $mime): string
    {
        return self::ALLOWED_TYPES[$mime] ?? 'bin';
    }

    /** @throws RuntimeException */
    public static function write(string $baseDirectory, string $relative, string $bytes): void
    {
        $path = self::absolute($baseDirectory, $relative);
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

    public static function absolute(string $baseDirectory, string $relative): string
    {
        return rtrim($baseDirectory, '/') . '/' . ltrim($relative, '/');
    }

    private static function tooLarge(): string
    {
        return sprintf('The file is larger than %d KB.', intdiv(self::maxBytes(), 1024));
    }
}
