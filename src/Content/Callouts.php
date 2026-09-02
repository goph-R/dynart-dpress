<?php

namespace Dynart\Dpress\Content;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;

/**
 * `> [!WARNING]` turns a blockquote into a panel
 *
 *     > [!WARNING]
 *     > Do not do this on a live site.
 *
 * **A blockquote with a marker on its first line**, which is GitHub's syntax and is valid
 * CommonMark either way. That is the whole reason for choosing it: anywhere without this - a
 * README, an editor preview, a document exported from here - it is still a blockquote, still
 * readable, with a visible `[!WARNING]` where the styling would have been. Nothing breaks
 * somewhere else because of a convention that only exists here.
 *
 * It also means the content is **markdown**, parsed before this runs: bold, links, lists and code
 * all work inside a panel because CommonMark has already dealt with them. A shortcode could not do
 * that - `{{ warning('…') }}` takes a string, and a panel holds prose.
 *
 * What this writes is a class, and nothing else. The colour and the icon are in the stylesheet,
 * where presentation belongs: restyling every panel on a site is then an edit to one file rather
 * than `content:rerender` over every post that has one.
 */
class Callouts {

    /**
     * The markers, and which of the three looks each gets
     *
     * GitHub defines five and this understands all of them, so a README pasted into a post works.
     * `INFO` and `DANGER` are here because they are what somebody guesses.
     */
    const KINDS = [
        'NOTE'      => 'info',
        'TIP'       => 'info',
        'IMPORTANT' => 'info',
        'INFO'      => 'info',
        'WARNING'   => 'warning',
        'CAUTION'   => 'danger',
        'DANGER'    => 'danger',
    ];

    /** The marker, alone on the line or with the text following it */
    const PATTERN = '/^\[!([A-Za-z]+)\][ \t]*/';

    /**
     * Subscribed to `MarkdownRenderer::EVENT_ENVIRONMENT`
     */
    public function onEnvironment(EnvironmentBuilderInterface $environment): void {
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed']);
    }

    public function onDocumentParsed(DocumentParsedEvent $event): void {
        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof BlockQuote) {
                $this->mark($node);
            }
        }
    }

    /**
     * Reads the marker off a blockquote and takes it out of the text
     *
     * Every blockquote gets a class, marker or not: the plain one is a panel too, and a stylesheet
     * that has to say `blockquote:not(.callout-info):not(.callout-warning)…` to reach it is a
     * stylesheet nobody wants to add the next kind to.
     */
    protected function mark(BlockQuote $quote): void {
        $kind = 'quote';
        $first = $quote->firstChild();
        if ($first instanceof Paragraph && ($text = $first->firstChild()) instanceof Text
            && preg_match(self::PATTERN, $text->getLiteral(), $matches) === 1) {
            $found = self::KINDS[strtoupper($matches[1])] ?? null;
            if ($found !== null) {
                $kind = $found;
                $this->removeMarker($text, strlen($matches[0]));
            }
        }
        $quote->data->set('attributes/class', 'callout callout-'.$kind);
    }

    /**
     * Takes the `[!WARNING]` out, and the line break after it when that is all there was
     *
     * `> [!WARNING]\n> text` parses as one paragraph: the marker, a newline, then the text. Left
     * alone the newline becomes an empty first line inside the panel. Written on one line -
     * `> [!WARNING] text` - there is no newline to remove and the text simply loses its prefix.
     */
    protected function removeMarker(Text $text, int $length): void {
        $rest = substr($text->getLiteral(), $length);
        if ($rest !== '') {
            $text->setLiteral($rest);
            return;
        }
        $next = $text->next();
        if ($next instanceof Newline) {
            $next->detach();
        }
        $text->detach();
    }
}
