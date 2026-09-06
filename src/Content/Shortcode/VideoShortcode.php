<?php

namespace Dynart\Dpress\Content\Shortcode;

use Dynart\Dpress\Entity\Media;

/**
 * `{{ video('media#13') }}`
 *
 * One shortcode for every kind of video, dispatching on what it was handed. A library reference
 * and a direct file become a `<video>`; a YouTube or Vimeo link becomes that site's player.
 *
 * That is why the name is `video` and not `embed_video`: what an author means is "put this video
 * here", and which of those it turns out to be is not their problem.
 *
 * The library reference, the direct file and the refusals are `AbstractMediaShortcode`, which is
 * everything `audio` needed too. What is here is the part only video has: other people's players.
 */
class VideoShortcode extends AbstractMediaShortcode {

    const CATEGORY = Media::CATEGORY_VIDEO;
    const NOUN = 'video';
    const DIRECT_EXTENSIONS = ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v'];

    /**
     * A link somebody pasted, which may be a player this knows
     */
    protected function elsewhere(string $source, array $arguments): string {
        $embed = $this->embedUrl($source);
        if ($embed !== null) {
            return $this->frame($embed, (string)($arguments['title'] ?? 'Embedded video'));
        }
        return parent::elsewhere($source, $arguments);
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

    protected function tag(string $url, string $label = ''): string {
        return '<video class="dpress-video" controls preload="metadata" src="'.htmlspecialchars($url).'"'
            .($label === '' ? '' : ' aria-label="'.htmlspecialchars($label).'"')
            .'>'
            .'<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($label !== '' ? $label : 'Download the video').'</a>'
            .'</video>';
    }
}
