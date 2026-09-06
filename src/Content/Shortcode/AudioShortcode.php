<?php

namespace Dynart\Dpress\Content\Shortcode;

use Dynart\Dpress\Entity\Media;

/**
 * `{{ audio('media#5') }}`
 *
 * The library already knows what an audio file is - `Media::CATEGORY_AUDIO` has been a category
 * since the media library existed - and until now the only thing an author could do with one was
 * link to it. A link to an mp3 is a download; this is a player.
 *
 * Everything except the element is `AbstractMediaShortcode`, which is the reason this file is
 * short: a library reference, a direct file, and the same refusals in the same words.
 *
 * **No third-party embeds**, so `elsewhere()` is not overridden. Video has YouTube and Vimeo
 * because that is where people's videos already are; the equivalent for audio would be a list of
 * services this would then owe an update to forever, and a site that wants a Spotify player has
 * always been able to write the iframe. If that changes it is one override, in this class.
 */
class AudioShortcode extends AbstractMediaShortcode {

    const CATEGORY = Media::CATEGORY_AUDIO;
    const NOUN = 'audio';

    /**
     * `ogg` is on both this list and video's, deliberately: the extension genuinely says nothing
     * about which one a file is, and the author already said which they meant by the name they
     * typed. `opus` and `weba` are what a modern recorder actually writes.
     */
    const DIRECT_EXTENSIONS = ['mp3', 'm4a', 'aac', 'oga', 'ogg', 'opus', 'wav', 'flac', 'weba'];

    /**
     * A player, and a link for a browser that will not play it
     *
     * `preload="metadata"` for the reason video uses it: a page with three tracks on it should
     * cost three headers and not three files. No `width` - an audio element is a control strip,
     * and the stylesheet decides how wide it is.
     */
    protected function tag(string $url, string $label = ''): string {
        return '<audio class="dpress-audio" controls preload="metadata" src="'.htmlspecialchars($url).'"'
            .($label === '' ? '' : ' aria-label="'.htmlspecialchars($label).'"')
            .'>'
            .'<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($label !== '' ? $label : 'Download the audio').'</a>'
            .'</audio>';
    }
}
