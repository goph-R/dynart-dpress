<?php

namespace Dynart\Dpress\Content\Shortcode;

use Dynart\Dpress\Content\InternalLinks;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\MediaService;

/**
 * `{{ video('media#13') }}`
 *
 * One shortcode for every kind of video, dispatching on what it was handed. A library reference
 * and a direct file become a `<video>`; a YouTube or Vimeo link becomes that site's player.
 *
 * That is why the name is `video` and not `embed_video`: what an author means is "put this video
 * here", and which of those it turns out to be is not their problem.
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
        $embed = $this->embedUrl($source);
        if ($embed !== null) {
            return $this->frame($embed, (string)($arguments['title'] ?? 'Embedded video'));
        }
        // A watch page handed to a `<video>` element fails silently, which looks like the CMS is
        // broken rather than like the link is one this does not know.
        return $this->cannot('that is not a video file or an address this understands');
    }

    /**
     * The player address for a link somebody pasted, or null for one this does not know
     *
     * **`youtube-nocookie.com`**, which serves the same player and sets nothing until somebody
     * presses play. A CMS puts this on other people's sites for other people's visitors, and the
     * quieter default is the one to pick when both work identically.
     */
    protected function embedUrl(string $url): ?string {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        $id = null;
        if ($host === 'youtu.be') {
            $id = $path;                                  // youtu.be/<id>
        } else if (preg_match('/(^|\.)youtube(-nocookie)?\.com$/', $host)) {
            $id = str_starts_with($path, 'embed/') ? substr($path, 6) : (string)($query['v'] ?? '');
        } else if (preg_match('/(^|\.)vimeo\.com$/', $host)) {
            // vimeo.com/<id>, and player.vimeo.com/video/<id>
            $id = str_starts_with($path, 'video/') ? substr($path, 6) : $path;
            return preg_match('/^\d+$/', $id) === 1 ? 'https://player.vimeo.com/video/'.$id : null;
        }
        if ($id === null || preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) !== 1) {
            return null;
        }
        $embed = 'https://www.youtube-nocookie.com/embed/'.$id;
        // `?t=90` on a watch link is where somebody wanted it to start, and the player spells the
        // same thing `start`. Dropping it silently loses the one thing they took care over.
        $start = (int)($query['t'] ?? $query['start'] ?? 0);
        return $start > 0 ? $embed.'?start='.$start : $embed;
    }

    /**
     * The player, as one element
     *
     * No wrapper `div` holding a padding-top trick: `aspect-ratio` is in the stylesheet and the
     * markup stays something a theme can restyle without unpicking it.
     */
    protected function frame(string $url, string $title): string {
        return '<iframe class="dpress-video dpress-embed" src="'.htmlspecialchars($url).'"'
            .' title="'.htmlspecialchars($title).'" loading="lazy" allowfullscreen'
            .' referrerpolicy="strict-origin-when-cross-origin"'
            .' allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"'
            .'></iframe>';
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
