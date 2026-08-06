<?php

namespace Dynart\Dpress\Content;

use Dynart\Micro\EventServiceInterface;
use League\CommonMark\ConverterInterface;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
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
        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue; // a separator on line one is front matter, not a lead break
            }
            if (rtrim($line) !== self::SEPARATOR) {
                continue;
            }
            return [
                'lead' => rtrim(join("\n", array_slice($lines, 0, $index))),
                'body' => ltrim(join("\n", array_slice($lines, $index + 1))),
            ];
        }
        return ['lead' => rtrim($markdown), 'body' => ''];
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
            'body' => $this->render($parts['body']),
        ];
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
            // plain CommonMark, the same as the shorthand converter this replaced. An
            // environment rather than that shorthand only so a subscriber can reach it.
            $environment->addExtension(new CommonMarkCoreExtension());
            $this->events->emit(self::EVENT_ENVIRONMENT, [$environment]);
            $this->converter = new MarkdownConverter($environment);
        }
        return $this->converter;
    }
}
