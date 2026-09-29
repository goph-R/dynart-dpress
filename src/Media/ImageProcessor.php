<?php

namespace Dynart\Dpress\Media;

use Dynart\Micro\ConfigInterface;
use Dynart\Dpress\DpressException;

/**
 * Resizes images with GD
 *
 * Behind its own class so ImageMagick can replace it without anything else noticing. The
 * presets are configuration, because what a theme wants is a theme's business.
 */
class ImageProcessor {

    const CONFIG_PRESETS = 'media.presets';

    /**
     * width, height, and whether to crop to exactly that box
     *
     * **None is cropped**, the thumb included (0.87.0): a list shows a picture's own shape - a
     * banner as a strip, a divider as a line - which a square cut out of its middle hid. A
     * change here wants `dpress media:regenerate`, since the files already made keep the old one.
     */
    const DEFAULT_PRESETS = [
        'thumb'  => [320, 320, false],
        'medium' => [768, 768, false],
        'large'  => [1600, 1600, false],
    ];

    const JPEG_QUALITY = 85;
    const WEBP_QUALITY = 85;
    const PNG_COMPRESSION = 6;

    public function __construct(protected ConfigInterface $config) {}

    public function isAvailable(): bool {
        return extension_loaded('gd');
    }

    /**
     * @return array [preset => [width, height, crop]]
     */
    public function presets(): array {
        $configured = $this->config->getArray(self::CONFIG_PRESETS, []);
        return empty($configured) ? self::DEFAULT_PRESETS : $configured;
    }

    public function hasPreset(string $name): bool {
        return array_key_exists($name, $this->presets());
    }

    /**
     * A short fingerprint of what a preset makes - its size and whether it crops - which changes
     * when the preset does, for the `?v=` on a derivative's address (`MediaView`)
     */
    public function presetVersion(string $name): string {
        $preset = array_values(array_pad((array)($this->presets()[$name] ?? []), 3, false));
        return substr(md5(json_encode([(int)$preset[0], (int)$preset[1], (bool)$preset[2]])), 0, 6);
    }

    /**
     * @return array|null [width, height] or null when it is not a readable raster image
     */
    public function dimensions(string $path): ?array {
        if (!is_file($path)) {
            return null;
        }
        $size = @getimagesize($path);
        if ($size === false) {
            return null;
        }
        return [(int)$size[0], (int)$size[1]];
    }

    /**
     * Writes a resized copy
     *
     * An image smaller than the preset is copied rather than scaled up: enlarging a small image
     * only makes a bigger file that looks worse.
     *
     * @throws DpressException if GD is missing or the source cannot be read
     */
    public function resize(string $sourcePath, string $targetPath, string $preset): void {
        if (!$this->isAvailable()) {
            throw new DpressException('The GD extension is not available, images cannot be resized.');
        }
        $presets = $this->presets();
        if (!isset($presets[$preset])) {
            throw new DpressException("There is no image preset named '$preset'.");
        }
        [$maxWidth, $maxHeight, $crop] = array_pad((array)$presets[$preset], 3, false);

        $source = $this->load($sourcePath);
        if ($source === null) {
            throw new DpressException("Could not read the image '$sourcePath'.");
        }
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        // A copy only when the file stays what it was: a GIF's thumb is a JPEG (`MediaStorage::
        // STILL_PRESET`), and a copy would be a GIF - animation and all - under a .jpg name
        $sameFormat = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION))
            === strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        if (!$crop && $sameFormat && $sourceWidth <= $maxWidth && $sourceHeight <= $maxHeight) {
            imagedestroy($source);
            if (!@copy($sourcePath, $targetPath)) {
                throw new DpressException("Could not copy '$sourcePath'.");
            }
            return;
        }

        [$targetWidth, $targetHeight, $srcX, $srcY, $srcWidth, $srcHeight] = !$crop && $sourceWidth <= $maxWidth && $sourceHeight <= $maxHeight
            ? [$sourceWidth, $sourceHeight, 0, 0, $sourceWidth, $sourceHeight]   // re-encoded, never enlarged
            : $this->geometry($sourceWidth, $sourceHeight, (int)$maxWidth, (int)$maxHeight, (bool)$crop);

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (in_array(strtolower(pathinfo($targetPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) {
            // a JPEG has no transparency, and GD writes a transparent pixel as black - so what was
            // see-through in the GIF or PNG is white
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, imagecolorallocate($target, 255, 255, 255));
        } else {
            $this->keepTransparency($source, $target);
        }
        imagecopyresampled(
            $target, $source,
            0, 0, $srcX, $srcY,
            $targetWidth, $targetHeight, $srcWidth, $srcHeight
        );
        $this->save($target, $targetPath);
        imagedestroy($target);
        imagedestroy($source);
    }

    /**
     * The size a preset's file of an image comes out at, without making it
     *
     * The same arithmetic `resize()` does - a copy when the image already fits and is not
     * cropped, `geometry()` otherwise - so a `srcset` can say each file's real width.
     *
     * @return array{0: int, 1: int}|null [width, height], or null for a preset there is not
     */
    public function outputSize(int $width, int $height, string $preset): ?array {
        $presets = $this->presets();
        if (!isset($presets[$preset]) || $width < 1 || $height < 1) {
            return null;
        }
        [$maxWidth, $maxHeight, $crop] = array_pad((array)$presets[$preset], 3, false);
        if (!$crop && $width <= $maxWidth && $height <= $maxHeight) {
            return [$width, $height];
        }
        $geometry = $this->geometry($width, $height, (int)$maxWidth, (int)$maxHeight, (bool)$crop);
        return [$geometry[0], $geometry[1]];
    }

    /**
     * Works out the target box and the source rectangle to take it from
     *
     * @return array [targetW, targetH, srcX, srcY, srcW, srcH]
     */
    protected function geometry(int $width, int $height, int $maxWidth, int $maxHeight, bool $crop): array {
        if (!$crop) {
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            return [max(1, (int)round($width * $ratio)), max(1, (int)round($height * $ratio)), 0, 0, $width, $height];
        }
        // cover the box, then take the middle of whatever is left over
        $ratio = max($maxWidth / $width, $maxHeight / $height);
        $scaledWidth = $width * $ratio;
        $scaledHeight = $height * $ratio;
        $srcWidth = (int)round($width * ($maxWidth / $scaledWidth));
        $srcHeight = (int)round($height * ($maxHeight / $scaledHeight));
        return [
            $maxWidth, $maxHeight,
            max(0, (int)round(($width - $srcWidth) / 2)),
            max(0, (int)round(($height - $srcHeight) / 2)),
            min($width, $srcWidth), min($height, $srcHeight),
        ];
    }

    /**
     * @return \GdImage|null
     */
    protected function load(string $path) {
        $size = @getimagesize($path);
        if ($size === false) {
            return null;
        }
        $image = match ($size[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => false,
        };
        if ($image !== false && $size[2] === IMAGETYPE_GIF) {
            $image = $this->onCanvas($image, (string)@file_get_contents($path));
        }
        return $image === false ? null : $image;
    }

    /**
     * A GIF's first frame where it belongs on the GIF's canvas
     *
     * An optimised GIF's first frame is often only the part of the canvas that has something on
     * it - a 171x191 box at some offset on a 256x256 one - and GD answers the box alone, its
     * offset lost. Resized like that the picture came out the wrong size and off its middle. So
     * the canvas is read from the header and the frame's place from its descriptor, and the
     * frame is put back on a transparent canvas of the whole size.
     *
     * @param \GdImage $frame
     * @return \GdImage
     */
    protected function onCanvas($frame, string $bytes) {
        $place = self::gifFirstFrame($bytes);
        if ($place === null || ($place['left'] === 0 && $place['top'] === 0
                && imagesx($frame) === $place['width'] && imagesy($frame) === $place['height'])) {
            return $frame;
        }
        $canvas = imagecreatetruecolor($place['width'], $place['height']);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $place['width'], $place['height'], imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);
        imagecopy($canvas, $frame, $place['left'], $place['top'], 0, 0, imagesx($frame), imagesy($frame));
        imagedestroy($frame);
        return $canvas;
    }

    /**
     * The canvas of a GIF and the place of its first frame on it, from the bytes
     *
     * The logical screen is bytes 6-9; after it the global colour table, if the flags say there
     * is one; then extension blocks until the first image descriptor, which holds the frame's
     * left, top, width and height. Null for anything that does not read as a GIF.
     *
     * @return array{width: int, height: int, left: int, top: int}|null
     */
    public static function gifFirstFrame(string $bytes): ?array {
        $length = strlen($bytes);
        if ($length < 13 || !str_starts_with($bytes, 'GIF')) {
            return null;
        }
        $screen = unpack('vwidth/vheight/Cflags', substr($bytes, 6, 5));
        $at = 13 + (($screen['flags'] & 0x80) ? 3 * (2 << ($screen['flags'] & 0x07)) : 0);
        while ($at < $length) {
            $block = ord($bytes[$at]);
            if ($block === 0x2C) {
                if ($at + 9 > $length) {
                    return null;
                }
                $frame = unpack('vleft/vtop', substr($bytes, $at + 1, 4));
                return ['width' => $screen['width'], 'height' => $screen['height'],
                        'left' => $frame['left'], 'top' => $frame['top']];
            }
            if ($block !== 0x21) {
                return null;   // the trailer, or not a GIF after all
            }
            // an extension: its label, then sub-blocks until an empty one
            $at += 2;
            while ($at < $length && ($size = ord($bytes[$at])) !== 0) {
                $at += $size + 1;
            }
            $at++;
        }
        return null;
    }

    protected function save($image, string $path): void {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $saved = match ($extension) {
            'jpg', 'jpeg' => imagejpeg($image, $path, self::JPEG_QUALITY),
            'png'         => imagepng($image, $path, self::PNG_COMPRESSION),
            'gif'         => imagegif($image, $path),
            'webp'        => imagewebp($image, $path, self::WEBP_QUALITY),
            default       => false,
        };
        if (!$saved) {
            throw new DpressException("Could not write the image '$path'.");
        }
    }

    /**
     * Keeps a PNG or GIF transparent instead of filling it with black
     */
    protected function keepTransparency($source, $target): void {
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
        imagefilledrectangle($target, 0, 0, imagesx($target), imagesy($target), $transparent);
        imagealphablending($target, true);
    }
}
