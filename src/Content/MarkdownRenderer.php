<?php

namespace Dynart\Dpress\Content;

use Dynart\Micro\EventServiceInterface;
use League\CommonMark\ConverterInterface;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Turns the stored markdown into HTML, and splits the lead from the body
 *
 * A post is written as one markdown document. The first line that is nothing but `---` separates
 * the lead - what a listing shows - from the rest.
 */
class MarkdownRenderer {

    const EVENT_BEFORE_RENDER = 'markdown:before_render';
    const EVENT_RENDERED = 'markdown:rendered';

    /**
     * The converter is being built - a subscriber may add extensions to the environment
     *
     * Emitted once, lazily, and only when something actually renders markdown, which on a page
     * view is never: the HTML is written at save time. It carries the `Environment` before the
     * converter is made from it, because CommonMark seals an environment on first use.
     *
     * This is how the CMS reaches the renderer without the renderer knowing the CMS exists -
     * `InternalLinks` resolves `media#12` here, and nothing in this class knows what a media is.
     */
    const EVENT_ENVIRONMENT = 'markdown:environment';

    /** The separator, on a line of its own */
    const SEPARATOR = '---';

    /**
     * What a page break becomes in the stored HTML
     *
     * The pages of a body are one column, not a row each: the marker goes into `body_html` and
     * `ContentPages` splits on it when a page is served. The same bargain a shortcode marker
     * makes - a document stays one value, and whoever reads it decides what to do with the parts.
     */
    const PAGE_MARKER = '<!--dpress-page-->';

    private ?ConverterInterface $converter = null;

    public function __construct(protected EventServiceInterface $events) {}

    /**
     * Splits a document into its lead and its body
     *
     * The rule has to be exact, because `---` is also a horizontal rule and a YAML front matter
     * fence. It is the **first line consisting solely of `---`, not the very first line of the
     * document**: at offset 0 it would be opening front matter, and a document that starts with
     * a separator has an empty lead, which is never what was meant.
     *
     * A document with no separator is all lead and no body - a short note is exactly that.
     *
     * @return array ['lead' => string, 'body' => string]
     */
    public function split(string $markdown): array {
        $lines = preg_split('/\R/', $markdown);
        $separators = $this->separatorLines($lines);
        if ($separators === []) {
            return ['lead' => rtrim($markdown), 'body' => ''];
        }
        $index = $separators[0];
        return [
            'lead' => rtrim(join("\n", array_slice($lines, 0, $index))),
            'body' => ltrim(join("\n", array_slice($lines, $index + 1))),
        ];
    }

    /**
     * Which page of a document a line falls on, counting from 1
     *
     * What the editor asks when Preview is pressed: the tab should open on the part somebody was
     * writing, not at the top of a seven page article. It reads the document by the same two
     * rules everything else here does - the first separator ends the lead, every one after it
     * ends a page - and walks the body the way `pages()` walks it, so it cannot drift from the
     * pages that were actually rendered.
     *
     * A line in the lead is page one, because page one is where the lead is shown.
     */
    public function pageOfLine(string $markdown, int $line): int {
        $lines = preg_split('/\R/', $markdown);
        $separators = $this->separatorLines($lines);
        if ($separators === [] || $line <= $separators[0]) {
            return 1;
        }
        $bodyStart = $separators[0] + 1;
        $bodyLines = array_slice($lines, $bodyStart);
        $inBody = $line - $bodyStart;
        $page = 0;
        $start = 0;
        foreach (array_merge($this->separatorLines($bodyLines, 0), [count($bodyLines)]) as $at) {
            // the same walk, and the same skipping of an empty page, as `pages()` - a separator
            // line belongs to the page it ends
            if (trim(join("\n", array_slice($bodyLines, $start, $at - $start))) !== '') {
                $page++;
                if ($inBody <= $at) {
                    return $page;
                }
            }
            $start = $at + 1;
        }
        return max(1, $page);
    }

    /**
     * Splits a body into its pages
     *
     * Every separator after the first one is a page break - the same character doing a second
     * job, and it reads the way it behaves, since `---` has always meant "and now something
     * else". A body with no further separator is one page, so nothing already written changes
     * shape.
     *
     * @return string[] at least one, and never an empty one
     */
    public function pages(string $body): array {
        $lines = preg_split('/\R/', $body);
        // a separator on line 0 of a *body* is a page break like any other: the front matter
        // reasoning is about the start of a document, and this is already past it
        $separators = $this->separatorLines($lines, 0);
        if ($separators === []) {
            return [trim($body)];
        }
        $pages = [];
        $start = 0;
        foreach (array_merge($separators, [count($lines)]) as $at) {
            $page = trim(join("\n", array_slice($lines, $start, $at - $start)));
            if ($page !== '') {
                $pages[] = $page;   // `---` right after `---` is a typo, not an empty page
            }
            $start = $at + 1;
        }
        return $pages === [] ? [''] : $pages;
    }

    /**
     * The lines that are a separator and nothing else
     *
     * **Fenced code is skipped**, which is the reason this is one method rather than a match at
     * each call site: a post explaining YAML front matter has `---` inside a code block, and
     * splitting a document there would tear it in half at the exact place its author was writing
     * about. The same care the shortcode parser takes by being an inline parser rather than a
     * regular expression over the markdown.
     *
     * @param int $from the first line that may count - 1 for a whole document, where a separator
     *                  on line 0 is opening front matter rather than a break
     * @return int[]
     */
    protected function separatorLines(array $lines, int $from = 1): array {
        $found = [];
        $fence = '';
        foreach ($lines as $index => $line) {
            $trimmed = rtrim($line);
            if ($fence !== '') {
                if (preg_match('/^\s{0,3}'.$fence.'\s*$/', $trimmed) === 1) {
                    $fence = '';
                }
                continue;
            }
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $trimmed, $match) === 1) {
                $fence = $match[1][0] === '`' ? '`{3,}' : '~{3,}';
                continue;
            }
            if ($index >= $from && $trimmed === self::SEPARATOR) {
                $found[] = $index;
            }
        }
        return $found;
    }

    public function hasLeadSeparator(string $markdown): bool {
        return $this->split($markdown)['body'] !== '';
    }

    /**
     * Renders one markdown string to HTML
     */
    public function render(string $markdown): string {
        if ($markdown === '') {
            return '';
        }
        $this->events->emit(self::EVENT_BEFORE_RENDER, [&$markdown]);
        $html = (string)$this->converter()->convert($markdown);
        $this->events->emit(self::EVENT_RENDERED, [&$html, $markdown]);
        return $html;
    }

    /**
     * Splits and renders in one go
     *
     * @return array ['lead' => string, 'body' => string] as HTML
     */
    public function renderSplit(string $markdown): array {
        $parts = $this->split($markdown);
        return [
            'lead' => $this->render($parts['lead']),
            'body' => $this->renderPages($parts['body']),
        ];
    }

    /**
     * A body, as its pages, joined by the marker
     *
     * Each page is rendered on its own rather than the whole body being rendered and cut up
     * afterwards, because cutting HTML in half is how a `<ul>` ends up with no closing tag. What
     * it costs is that a reference-style link defined on one page cannot be used on another -
     * which is the cost the lead/body split has always had, for the same reason.
     */
    protected function renderPages(string $body): string {
        if ($body === '') {
            return '';
        }
        return join(
            self::PAGE_MARKER,
            array_map(fn(string $page): string => $this->render($page), $this->pages($body))
        );
    }

    /**
     * Lets an application or a plugin swap the converter, for its own extensions
     */
    public function setConverter(ConverterInterface $converter): void {
        $this->converter = $converter;
    }

    public function converter(): ConverterInterface {
        if ($this->converter === null) {
            $environment = new Environment([
                // the markdown is written by editors, not visitors, but a compromised account
                // should still not be able to inject a script into every page
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
            // CommonMark, plus tables. An environment rather than the shorthand converter this
            // replaced only so a subscriber can reach it.
            $environment->addExtension(new CommonMarkCoreExtension());
            // Here rather than as a subscriber, because a table is *syntax* and not a policy:
            // it has nothing to configure and nothing to ask a service, and it only appears
            // where somebody wrote one. The subscribers are the things that had a decision to
            // make - what a URL points at, whether prose gets linked, what a callout looks
            // like. Tables have none.
            $environment->addExtension(new TableExtension());
            $this->events->emit(self::EVENT_ENVIRONMENT, [$environment]);
            $this->converter = new MarkdownConverter($environment);
        }
        return $this->converter;
    }
}
