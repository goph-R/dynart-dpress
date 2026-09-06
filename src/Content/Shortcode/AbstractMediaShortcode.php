<?php

namespace Dynart\Dpress\Content\Shortcode;

use Dynart\Dpress\Content\InternalLinks;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\MediaService;

/**
 * What `video` and `audio` both are: "put this thing here, and work out what it is"
 *
 * The three questions are the same for either - is it a library reference, is it a file this can
 * play, and if it is neither then what - and only the noun, the category and the list of
 * extensions change. Written twice, the copy is what goes stale: the check that a `media#`
 * reference is actually the right *kind* of file exists because `video('media#2')` naming an SVG
 * used to render a player that plays nothing, and a second shortcode reaching the same conclusion
 * separately would have to learn that separately too.
 *
 * A subclass supplies the three constants and `tag()`. `elsewhere()` is the fourth question and
 * has a default that refuses, because most media has no third-party player to fall back to;
 * `video` overrides it, which is where YouTube and Vimeo live.
 */
abstract class AbstractMediaShortcode {

    /** The `Media::CATEGORY_*` a library reference has to be */
    const CATEGORY = '';

    /** What this is called, in a message an author reads */
    const NOUN = '';

    /** Named so the element can be given something a browser will actually play */
    const DIRECT_EXTENSIONS = [];

    public function __construct(
        protected MediaService $media,
        protected MediaView $view,
    ) {}

    /**
     * @param array $arguments `0` the reference or URL
     */
    public function render(array $arguments): string {
        $source = trim((string)($arguments[0] ?? $arguments['src'] ?? ''));
        if ($source === '') {
            return $this->cannot($this->article().' needs something to play');
        }
        if (preg_match(InternalLinks::PATTERN, $source, $matches) && $matches[1] === 'media') {
            return $this->fromLibrary((int)$matches[2]);
        }
        if ($this->isDirectFile($source)) {
            return $this->tag($source);
        }
        return $this->elsewhere($source, $arguments);
    }

    /**
     * An address this cannot play itself
     *
     * Refusing is the right default: handing a watch page or a web page to a media element fails
     * silently, which looks like the CMS is broken rather than like the link is one this does not
     * know.
     */
    protected function elsewhere(string $source, array $arguments): string {
        return $this->cannot('that is not '.$this->article().' file or an address this understands');
    }

    /**
     * A library item, which has to actually be the right kind of file
     */
    protected function fromLibrary(int $id): string {
        $media = $this->media->findById($id);
        if ($media === null || $media->isDeleted()) {
            return $this->cannot('that file is not in the library any more');
        }
        if ($media->category !== static::CATEGORY) {
            return $this->cannot('media#'.$id.' is a '.$media->category.', not '.$this->article());
        }
        return $this->tag($this->view->url($media), (string)($media->alt ?? ''));
    }

    /** The element itself */
    abstract protected function tag(string $url, string $label = ''): string;

    protected function isDirectFile(string $url): bool {
        $extension = strtolower((string)pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return in_array($extension, static::DIRECT_EXTENSIONS, true);
    }

    /** `a video`, `an audio` - the sentence reads badly without it */
    protected function article(): string {
        return (str_contains('aeiou', substr(static::NOUN, 0, 1)) ? 'an ' : 'a ').static::NOUN;
    }

    /**
     * What a shortcode that cannot do what it was asked leaves behind
     *
     * A comment rather than nothing, for the same reason an unregistered shortcode leaves one:
     * the page still renders, and whoever looks at the source finds out why.
     */
    protected function cannot(string $why): string {
        return '<!-- '.static::NOUN.': '.htmlspecialchars($why).' -->';
    }
}
