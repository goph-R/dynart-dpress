<?php

namespace Dynart\Dpress\Media;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\Entity\Media;

/**
 * Turning a media item into something a template can print
 *
 * The URL of a derivative is built rather than looked up, and the file behind it may not exist
 * yet - that is the whole point of generating lazily. The browser asks for it, Apache does not
 * find it, and the request falls through to the controller that makes it.
 */
class MediaView {

    /**
     * The icon of a category
     *
     * Stored as `icon-<category>.svg.phtml`, so it goes through the view like any other
     * template and a theme can replace one - the same convention the plain text mail bodies use.
     */
    const ICON_TEMPLATE = 'dpress:media/icon-';
    const ICON_SUFFIX = '.svg';

    public function __construct(
        protected ConfigInterface $config,
        protected ViewInterface $view,
        protected MediaStorage $storage,
        protected ImageProcessor $images,
    ) {}

    /**
     * The public URL of an item, at a preset size when it has one
     */
    public function url(Media $media, string $preset = ''): string {
        if ($preset !== '' && $media->isResizable() && $this->images->hasPreset($preset)) {
            return $this->urlOfPath($this->storage->derivativePath($media->path, $preset)).$this->presetQuery($preset);
        }
        return $this->baseUrl().$this->storage->baseUrl().'/'.$media->path;
    }

    /**
     * `?v=` and the preset's fingerprint, on every derivative's address
     *
     * An upload may be cached for a month (`public/.htaccess`), and a derivative is rebuilt under
     * the same name - so after a preset changed and `media:regenerate` cleared the files, a
     * browser went on showing the old picture from its cache and never asked for the new one.
     * With the preset's size and crop in the address, a changed preset is a new address
     * everywhere at once. Apache still serves the file itself; the query only names it.
     */
    protected function presetQuery(string $preset): string {
        return '?v='.$this->images->presetVersion($preset);
    }

    /**
     * The public URL of a stored path, for a listing row that has no entity loaded
     */
    public function urlOfPath(string $relativePath): string {
        return $this->baseUrl().$this->storage->baseUrl().'/'.ltrim($relativePath, '/');
    }


    /**
     * An `<img>` for an image, or the category icon for anything else
     *
     * The alt text is the item's own, falling back to an empty one - an empty `alt` is correct
     * for a decorative image, and far better than repeating the filename to a screen reader.
     */
    public function tag(Media $media, string $preset = 'thumb', array $attributes = []): string {
        if (!$media->isImage()) {
            return $this->icon($media->category);
        }
        $attributes = array_merge([
            'src' => $this->url($media, $preset),
            'alt' => (string)($media->alt ?? ''),
            'loading' => 'lazy',
        ], $attributes);
        if ($media->isResizable() && $media->width !== null && $preset === '') {
            $attributes['width'] = (string)$media->width;
            $attributes['height'] = (string)$media->height;
        }
        $parts = [];
        foreach ($attributes as $name => $value) {
            // `srcset()` answers '' for an image it cannot describe, and an empty `srcset` is a
            // broken one - the `src` alone is right then
            if (($name === 'srcset' || $name === 'sizes') && (string)$value === '') {
                continue;
            }
            $parts[] = $name.'="'.htmlspecialchars((string)$value, ENT_QUOTES).'"';
        }
        return '<img '.join(' ', $parts).'>';
    }

    /**
     * A `srcset` of an image's preset files, each at its real width - for `tag()`'s attributes
     *
     *   $mediaView->tag($media, 'medium', [
     *       'srcset' => $mediaView->srcset($media, ['thumb', 'medium']),
     *       'sizes'  => '(min-width: 1025px) 120px, 100vw',
     *   ])
     *
     * With `sizes` saying how wide the picture is drawn, the browser takes the smallest file that
     * is sharp there - the thumb in a small card, the medium where the card is the width of a
     * phone, the bigger one on a screen with two pixels to a point. The widths are the ones
     * `resize()` makes (`ImageProcessor::outputSize()`), so nothing has to exist yet to say them;
     * two presets that come out the same size - a small original is a copy in both - are one.
     *
     * '' for anything it cannot describe: not a raster image, or no stored size.
     */
    public function srcset(Media $media, array $presets): string {
        if (!$media->isResizable() || !$media->width || !$media->height) {
            return '';
        }
        $candidates = [];
        foreach ($presets as $preset) {
            $size = $this->images->outputSize((int)$media->width, (int)$media->height, $preset);
            if ($size !== null && !isset($candidates[$size[0]])) {
                $candidates[$size[0]] = $this->url($media, $preset).' '.$size[0].'w';
            }
        }
        ksort($candidates);
        return implode(', ', $candidates);
    }

    /**
     * The same as `url()` and `tag()`, from a listing row rather than a loaded entity
     *
     * A list query returns rows, and hydrating an entity per row only to ask it for its URL would
     * be work for nothing - the two fields it needs are right there.
     */
    public function rowUrl(array $row, string $preset = ''): string {
        $path = (string)($row['path'] ?? '');
        if ($preset !== '' && $this->isRowResizable($row) && $this->images->hasPreset($preset)) {
            return $this->urlOfPath($this->storage->derivativePath($path, $preset)).$this->presetQuery($preset);
        }
        return $this->urlOfPath($path);
    }

    public function rowTag(array $row, string $preset = 'thumb'): string {
        if (($row['category'] ?? '') !== Media::CATEGORY_IMAGE) {
            return $this->icon((string)($row['category'] ?? Media::CATEGORY_OTHER));
        }
        return '<img src="'.htmlspecialchars($this->rowUrl($row, $preset), ENT_QUOTES).'"'
            .' alt="'.htmlspecialchars((string)($row['alt'] ?? ''), ENT_QUOTES).'" loading="lazy">';
    }

    protected function isRowResizable(array $row): bool {
        return ($row['category'] ?? '') === Media::CATEGORY_IMAGE
            && ($row['mime_type'] ?? '') !== 'image/svg+xml';
    }

    /**
     * The inline SVG icon of a category
     *
     * Inline rather than an `<img>`, so it inherits the surrounding colour. These are our own
     * files, not uploaded ones, so there is nothing to sanitise.
     */
    public function icon(string $category): string {
        $path = self::ICON_TEMPLATE.$category.self::ICON_SUFFIX;
        if (!$this->view->exists($path)) {
            $path = self::ICON_TEMPLATE.Media::CATEGORY_OTHER.self::ICON_SUFFIX;
        }
        return $this->view->fetch($path);
    }

    protected function baseUrl(): string {
        return rtrim((string)$this->config->get('app.base_url', ''), '/');
    }
}
