<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

/**
 * Everything that arrives as an image is brought down to a sensible size here.
 *
 * WHY THE SERVER AND NOT THE SCREEN. The screens resize before they upload,
 * and that is worth doing — it is the difference between a photograph that
 * arrives over a district office's connection and one that times out. But it
 * is an optimisation, not a guarantee. An assessor works offline for a day and
 * their device queues whatever it was given: a twelve-megapixel photograph, a
 * scan somebody attached from a laptop, an image chosen in a browser too old
 * for the resize path. Refusing those at the door means telling somebody who
 * has already left the laboratory that the evidence they collected is not
 * acceptable. Taking them and shrinking them means it is.
 *
 * WHAT IT WILL NOT DO IS RE-ENCODE FOR THE SAKE OF IT. An image already inside
 * the bounds is stored exactly as it arrived. Generational loss is real — a
 * photograph recompressed on every pass through a system ends up visibly
 * worse than the first copy — and a signature is a few kilobytes that nothing
 * would gain from being touched.
 *
 * TRANSPARENCY SURVIVES. A signature is dark ink on nothing, so that the mark
 * composites onto a report page rather than carrying a white rectangle across
 * it. Flattening one to JPEG would put a black box behind somebody's name, so
 * an image with an alpha channel stays a PNG however large it is. A PNG with
 * no transparency in it is a photograph somebody's phone happened to save in
 * the wrong format, and becomes a JPEG — which is most of the saving.
 *
 * WITHOUT ext-gd NOTHING HAPPENS HERE. Images are stored as they arrive, which
 * is what this application did before this class existed. The extension is not
 * required to install, so the alternative is a deployment where uploads fail
 * rather than one where they are merely bigger. `bin/preflight` says so.
 */
final class ImageProcessor
{
    /**
     * The long edge, in pixels.
     *
     * Enough to read a label on a shelf or a serial number on a machine, and
     * roughly a fifth of what a current phone camera produces. Everything this
     * application does with an image — recognise a room, show a mark, print
     * onto a report — is comfortable well below it.
     */
    public const DEFAULT_MAX_EDGE = 1600;

    /** Visibly indistinguishable at that size, and a fraction of the bytes. */
    public const DEFAULT_QUALITY = 82;

    /**
     * Past this, an image is recompressed even when its dimensions are fine.
     *
     * A photograph can sit inside the pixel bounds and still be four megabytes
     * of camera-quality JPEG. Half a megabyte is well above what any of these
     * images need and well below what an unprocessed one costs.
     */
    public const DEFAULT_TARGET_BYTES = 524_288;

    /**
     * A decompression bomb is a small file that becomes an enormous bitmap.
     *
     * A hundred-kilobyte PNG can decode to a thirty-thousand-pixel square and
     * take the process's memory with it, so the dimensions are read from the
     * header — which costs nothing — and refused before anything is decoded.
     * Fifty megapixels is several times any real camera and nowhere near a
     * bomb.
     */
    public const DEFAULT_MAX_PIXELS = 50_000_000;

    public function __construct(
        private readonly int $maxEdge = self::DEFAULT_MAX_EDGE,
        private readonly int $quality = self::DEFAULT_QUALITY,
        private readonly int $targetBytes = self::DEFAULT_TARGET_BYTES,
        private readonly int $maxPixels = self::DEFAULT_MAX_PIXELS,
    ) {
    }

    /**
     * Configured per deployment, because the right answer is a property of the
     * connection and the storage rather than of the code.
     */
    public static function fromEnvironment(): self
    {
        return new self(
            self::setting('IMAGE_MAX_EDGE', self::DEFAULT_MAX_EDGE),
            // Clamped rather than trusted: 0 produces an unreadable image and
            // 100 produces a file larger than the original.
            max(40, min(95, self::setting('IMAGE_QUALITY', self::DEFAULT_QUALITY))),
            self::setting('IMAGE_TARGET_BYTES', self::DEFAULT_TARGET_BYTES),
            self::setting('IMAGE_MAX_PIXELS', self::DEFAULT_MAX_PIXELS),
        );
    }

    /**
     * The same picture, small enough to keep.
     *
     * Deterministic: the same bytes in produce the same bytes out. Both
     * callers hash the result to decide whether an upload is a retry of one
     * they already have, and a processor that varied would turn every retry
     * into a second copy.
     *
     * @param  string $mime as sniffed from the bytes, never as declared
     * @return array{bytes: string, mime: string}
     *
     * @throws InvalidArgumentException the image is beyond what is safe to decode
     */
    public function process(string $bytes, string $mime): array
    {
        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            throw new InvalidArgumentException('That file is not a readable image.');
        }

        [$width, $height] = $size;

        if ($width * $height > $this->maxPixels) {
            throw new InvalidArgumentException('That image is too large to process.');
        }

        $scale = min(1.0, $this->maxEdge / max($width, $height));
        $oversized = $scale < 1.0;

        if (!$oversized && strlen($bytes) <= $this->targetBytes) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        if (!function_exists('imagecreatefromstring')) {
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            // It passed the header check and will not decode. Storing what
            // arrived is the honest outcome: the file is already known to be
            // an image of a type this accepts, and refusing it here would lose
            // evidence over an encoder's opinion.
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        $keepAlpha = $mime === 'image/png' && self::pngCarriesAlpha($bytes);

        $target = $oversized
            ? $this->scaled($source, (int) round($width * $scale), (int) round($height * $scale), $keepAlpha)
            : $source;

        $encoded = $keepAlpha ? $this->asPng($target) : $this->asJpeg($target);

        if ($target !== $source) {
            imagedestroy($target);
        }

        imagedestroy($source);

        if ($encoded === null || (!$oversized && strlen($encoded['bytes']) >= strlen($bytes))) {
            // Re-encoding made it bigger, which happens with an image that is
            // already optimised. Keep the original rather than paying bytes
            // for the privilege of having processed it.
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        return $encoded;
    }

    /**
     * Whether anything in the image is see-through, read from the PNG header.
     *
     * The colour type sits at a fixed offset inside IHDR, which is always the
     * first chunk: 4 and 6 carry an alpha channel outright, and 3 — a palette
     * — carries transparency only when a tRNS chunk is present. Reading it
     * this way is exact and costs nothing, where sampling the decoded bitmap
     * would be a pixel-by-pixel scan of an image that may be twelve megapixels
     * for an answer the file already states.
     */
    private static function pngCarriesAlpha(string $bytes): bool
    {
        if (strlen($bytes) < 26) {
            return false;
        }

        $colourType = ord($bytes[25]);

        if ($colourType === 4 || $colourType === 6) {
            return true;
        }

        return $colourType === 3 && str_contains($bytes, 'tRNS');
    }

    private function scaled(\GdImage $source, int $width, int $height, bool $keepAlpha): \GdImage
    {
        $target = imagecreatetruecolor($width, $height);

        $transparent = $keepAlpha ? imagecolorallocatealpha($target, 0, 0, 0, 127) : false;

        if ($transparent !== false) {
            // Without these the transparent areas resample to black, which on
            // a signature is a rectangle over somebody's name.
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, $transparent);
        }

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($source),
            imagesy($source),
        );

        return $target;
    }

    /** @return array{bytes: string, mime: string}|null */
    private function asJpeg(\GdImage $image): ?array
    {
        ob_start();
        $ok = imagejpeg($image, null, $this->quality);
        $bytes = (string) ob_get_clean();

        return $ok && $bytes !== '' ? ['bytes' => $bytes, 'mime' => 'image/jpeg'] : null;
    }

    /** @return array{bytes: string, mime: string}|null */
    private function asPng(\GdImage $image): ?array
    {
        imagesavealpha($image, true);

        ob_start();
        // 9 is the most compression zlib offers. PNG is lossless, so this
        // costs processing time and nothing else.
        $ok = imagepng($image, null, 9);
        $bytes = (string) ob_get_clean();

        return $ok && $bytes !== '' ? ['bytes' => $bytes, 'mime' => 'image/png'] : null;
    }

    private static function setting(string $key, int $default): int
    {
        $value = env($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
