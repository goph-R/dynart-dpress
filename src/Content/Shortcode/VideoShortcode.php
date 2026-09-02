<?php

namespace Dynart\Dpress\Content\Shortcode;

use Dynart\Dpress\Content\InternalLinks;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\MediaService;

/**
 * `{{ video('media#13') }}`
 *
 * One shortcode for every kind of video there is going to be, dispatching on what it was handed.
 * A library reference becomes a `<video>` element; a link somewhere else becomes whatever that
 * somewhere else needs, which today is a direct file and tomorrow is an embed.
 *
 * That is why the name is `video` and not `embed_video`: what an author means is "put this video
 * here", and which of those two it turns out to be is not their problem.
 */
class VideoShortcode {

    /** Named so a `<video>` can be given one that a browser will actually play */
    const DIRECT_EXTENSIONS = ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v'];

    public function __construct(
        protected MediaService $media,
        protected MediaView $view,
    ) {}

    /**
     * @param array $arguments `0` the reference or URL, `poster` an optional media reference
     */
    public function render(array $arguments): string {
        $source = trim((string)($arguments[0] ?? $arguments['src'] ?? ''));
        if ($source === '') {
            return $this->cannot('a video needs something to play');
        }
        if (preg_match(InternalLinks::PATTERN, $source, $matches) && $matches[1] === 'media') {
            return $this->fromLibrary((int)$matches[2]);
        }
        if ($this->isDirectFile($source)) {
            return $this->tag($source);
        }
        // Where YouTube and the rest will go. Until then this says so rather than handing a
        // `<video>` a watch page, which fails silently and looks like the CMS is broken.
        return $this->cannot('only a media reference or a direct video file is understood yet');
    }

    /**
     * A library item, which has to actually be a video
     *
     * `video('media#2')` naming an SVG would otherwise render a player that plays nothing, and
     * the author would have no way of telling why.
     */
    protected function fromLibrary(int $id): string {
        $media = $this->media->findById($id);
        if ($media === null || $media->isDeleted()) {
            return $this->cannot('that file is not in the library any more');
        }
        if ($media->category !== Media::CATEGORY_VIDEO) {
            return $this->cannot('media#'.$id.' is a '.$media->category.', not a video');
        }
        return $this->tag($this->view->url($media), (string)($media->alt ?? ''));
    }

    protected function tag(string $url, string $label = ''): string {
        return '<video class="dpress-video" controls preload="metadata" src="'.htmlspecialchars($url).'"'
            .($label === '' ? '' : ' aria-label="'.htmlspecialchars($label).'"')
            .'>'
            .'<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($label !== '' ? $label : 'Download the video').'</a>'
            .'</video>';
    }

    protected function isDirectFile(string $url): bool {
        $extension = strtolower((string)pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return in_array($extension, self::DIRECT_EXTENSIONS, true);
    }

    /**
     * What a shortcode that cannot do what it was asked leaves behind
     *
     * A comment rather than nothing, for the same reason an unregistered shortcode leaves one:
     * the page still renders, and whoever looks at the source finds out why.
     */
    protected function cannot(string $why): string {
        return '<!-- video: '.htmlspecialchars($why).' -->';
    }
}
