<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\ImageProcessor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Bringing an uploaded image down to a size worth keeping.
 *
 * The cases below are the four things this must never get wrong: a large
 * photograph has to shrink, a small one has to be left exactly as it is, a
 * signature has to keep the transparency that stops it printing as a black box
 * over somebody's name, and an image that would decode to something enormous
 * has to be refused before it is decoded rather than after.
 *
 * The determinism case is the least obvious and the most load-bearing. Both
 * callers hash what comes out of here to decide whether an upload is a retry
 * of one they already hold, so a processor whose output varied between
 * identical inputs would turn every retry into a second stored copy.
 */
final class ImageProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is not installed; images are stored as they arrive.');
        }
    }

    public function testShrinksAnImageBeyondTheLongEdge(): void
    {
        $processor = new ImageProcessor(maxEdge: 100);

        $result = $processor->process($this->jpegBytes(400, 300), 'image/jpeg');
        $size = getimagesizefromstring($result['bytes']);

        $this->assertNotFalse($size);
        $this->assertSame(100, $size[0]);
        $this->assertSame(75, $size[1]);
        $this->assertSame('image/jpeg', $result['mime']);
    }

    /**
     * Generational loss is real: an image recompressed on every pass through a
     * system ends up visibly worse than the first copy. One already inside the
     * bounds is stored as it arrived, byte for byte.
     */
    public function testLeavesAnImageInsideTheBoundsUntouched(): void
    {
        $bytes = $this->jpegBytes(50, 50);
        $processor = new ImageProcessor(maxEdge: 1600, targetBytes: 1_000_000);

        $result = $processor->process($bytes, 'image/jpeg');

        $this->assertSame($bytes, $result['bytes']);
        $this->assertSame('image/jpeg', $result['mime']);
    }

    /** Small dimensions, large file: the bytes are the reason to recompress. */
    public function testRecompressesAnOversizedFileWithinTheDimensions(): void
    {
        $bytes = $this->photographBytes(600, 600);
        $processor = new ImageProcessor(maxEdge: 1600, targetBytes: 1024);

        $result = $processor->process($bytes, 'image/jpeg');

        $this->assertLessThan(strlen($bytes), strlen($result['bytes']));
    }

    /**
     * A signature is dark ink on nothing. Flattened to JPEG it arrives as ink
     * on a black rectangle, which is what gets printed onto the report handed
     * to the site.
     */
    public function testASignatureKeepsItsTransparency(): void
    {
        $processor = new ImageProcessor(maxEdge: 40);

        $result = $processor->process($this->transparentPngBytes(200, 100), 'image/png');

        $this->assertSame('image/png', $result['mime']);

        $image = imagecreatefromstring($result['bytes']);

        $this->assertNotFalse($image);
        // Top-left was transparent before the resize and has to still be.
        $this->assertGreaterThan(0, (imagecolorat($image, 0, 0) >> 24) & 0x7F);
        imagedestroy($image);
    }

    /** A photograph somebody's device happened to save as a PNG. */
    public function testAnOpaquePngBecomesAJpeg(): void
    {
        $processor = new ImageProcessor(maxEdge: 100);

        $result = $processor->process($this->opaquePngBytes(400, 400), 'image/png');

        $this->assertSame('image/jpeg', $result['mime']);
    }

    /**
     * A hundred-kilobyte file that decodes to a thirty-thousand-pixel square
     * takes the process's memory with it. The dimensions come from the header,
     * which costs nothing, and the refusal happens before any decoding.
     */
    public function testRefusesAnImageBeyondThePixelCeiling(): void
    {
        $processor = new ImageProcessor(maxPixels: 1000);

        $this->expectException(InvalidArgumentException::class);

        $processor->process($this->jpegBytes(100, 100), 'image/jpeg');
    }

    public function testTheSameInputAlwaysProducesTheSameOutput(): void
    {
        $bytes = $this->photographBytes(800, 600);
        $processor = new ImageProcessor(maxEdge: 200);

        $this->assertSame(
            hash('sha256', $processor->process($bytes, 'image/jpeg')['bytes']),
            hash('sha256', $processor->process($bytes, 'image/jpeg')['bytes']),
        );
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** Noise, so the encoder cannot compress it down to nothing. */
    private function photographBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                imagesetpixel($image, $x, $y, ($x * 7919 + $y * 104_729) % 0xFFFFFF);
            }
        }

        ob_start();
        imagejpeg($image, null, 95);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function transparentPngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function opaquePngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 90, 160));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
