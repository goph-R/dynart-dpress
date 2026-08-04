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

    /** width, height, and whether to crop to exactly that box */
    const DEFAULT_PRESETS = [
        'thumb'  => [320, 320, true],
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

        if (!$crop && $sourceWidth <= $maxWidth && $sourceHeight <= $maxHeight) {
            imagedestroy($source);
            if (!@copy($sourcePath, $targetPath)) {
                throw new DpressException("Could not copy '$sourcePath'.");
            }
            return;
        }

        [$targetWidth, $targetHeight, $srcX, $srcY, $srcWidth, $srcHeight] =
            $this->geometry($sourceWidth, $sourceHeight, (int)$maxWidth, (int)$maxHeight, (bool)$crop);

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $this->keepTransparency($source, $target);
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
        return $image === false ? null : $image;
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
