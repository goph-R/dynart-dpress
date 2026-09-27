<?php

namespace Dynart\Dpress\Content;

use Dynart\Dpress\Content\FencedDiv\FencedDiv;
use Dynart\Dpress\Content\FencedDiv\FencedDivParser;
use Dynart\Dpress\Content\FencedDiv\FencedDivRenderer;
use Dynart\Dpress\Content\FencedDiv\FencedDivStartParser;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentPreParsedEvent;

/**
 * Boxes around Markdown: Pandoc's fenced divs
 *
 * ```
 * ::: {#gallery .center .wide}
 * ![Idle](media#55) ![Running](media#56)
 * :::
 * ```
 *
 * is `<div id="gallery" class="center wide">`, with what is inside rendered as the Markdown it
 * is - paragraphs, lists, pictures, a callout. The same syntax as Pandoc, djot and MyST, so it is
 * not a dpress invention to learn, and the Docs plugin's MyST source can use it too.
 *
 * - **Three colons or more**, like a code fence's three backticks. More are allowed and mean
 *   nothing more - `::::` outside and `:::` inside is only there to be read.
 * - **`{#id .class .class}`**, or one bare class: `::: center` is `::: {.center}`. Letters,
 *   digits, `-` and `_` only - an attribute is written into the page, so nothing else gets in.
 * - **A bare `:::` closes the innermost open box.** It never opens one.
 *
 * Raw HTML is stripped from every document here, so before this a box around two pictures was
 * something only a theme could make. This is the way to ask for one without a theme.
 *
 * **Checked before a save** (`problems()`): a `:::` that closes nothing, a box that is never
 * closed or crosses a `---` page break, an attribute that is not an id or a class, an id used
 * twice. The renderer itself is forgiving - it closes what is left open at the end of a part - so
 * content saved before this existed, or through a path that does not check, still renders.
 */
class FencedDivs {

    /** An opening fence: the colons, then `{...}` or one bare class, then optional colons */
    const OPEN = '/^(:{3,})[ \t]*(?:\{([^}]*)\}|([^\s{}:]+))[ \t]*:*$/';

    /** A closing fence: colons and nothing else */
    const CLOSE = '/^:{3,}$/';

    /** What an id or a class may be made of */
    const NAME = '/^[A-Za-z0-9_-]+$/';

    /** @var FencedDivParser[] the boxes open in the document being parsed, innermost last */
    private array $open = [];

    /** Subscribed to `MarkdownRenderer::EVENT_ENVIRONMENT` */
    public function onEnvironment(EnvironmentBuilderInterface $environment): void {
        // after block quotes and headings, before fenced code (50): a `:::` line is none of those
        $environment->addBlockStartParser(new FencedDivStartParser($this), 55);
        $environment->addRenderer(FencedDiv::class, new FencedDivRenderer());
        // one environment renders many documents; a box left open by one is not open in the next
        $environment->addEventListener(DocumentPreParsedEvent::class, function (): void {
            $this->open = [];
        });
    }

    public function opened(FencedDivParser $parser): void {
        $this->open[] = $parser;
    }

    public function closed(FencedDivParser $parser): void {
        $at = array_search($parser, $this->open, true);
        if ($at !== false) {
            array_splice($this->open, $at, 1);
        }
    }

    public function innermost(): ?FencedDivParser {
        return $this->open === [] ? null : $this->open[count($this->open) - 1];
    }

    /**
     * What an opening fence asks for, or null when the line is not one
     *
     * @return array{id: ?string, classes: string[], invalid: string[]}|null
     */
    public static function opening(string $line): ?array {
        if (preg_match(self::OPEN, rtrim($line), $match) !== 1) {
            return null;
        }
        $spec = isset($match[3]) && $match[3] !== '' ? '.'.$match[3] : $match[2];
        return self::attributes($spec);
    }

    /**
     * `#id .class .class` read into its parts - anything else is `invalid`, and left out
     *
     * @return array{id: ?string, classes: string[], invalid: string[]}
     */
    public static function attributes(string $spec): array {
        $id = null;
        $classes = [];
        $invalid = [];
        foreach (preg_split('/\s+/', trim($spec), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $name = substr($token, 1);
            if (($token[0] === '#' || $token[0] === '.') && preg_match(self::NAME, $name) === 1) {
                if ($token[0] === '.') {
                    $classes[] = $name;
                } else if ($id === null) {
                    $id = $name;
                } else {
                    $invalid[] = $token;   // one id to a box, as HTML has
                }
                continue;
            }
            $invalid[] = $token;
        }
        return ['id' => $id, 'classes' => array_values(array_unique($classes)), 'invalid' => $invalid];
    }

    /**
     * What is wrong with the boxes in a document, one sentence each with its line number
     *
     * A line scanner rather than the parser, for the line numbers and the page breaks: the
     * renderer is handed the lead and each page on their own, so a box cannot reach across a
     * `---`, and only the whole document knows where those are. Code fences are skipped by the
     * same rule the page breaks are found with, so a `:::` in a code sample is left alone.
     *
     * @param int[] $breaks the page break lines, 0-based - `MarkdownRenderer::breaks()`
     * @return string[]
     */
    public static function problems(string $markdown, array $breaks = []): array {
        $problems = [];
        $open = [];     // the opening line of each open box, innermost last
        $ids = [];      // id => the line it was first given on
        $fence = null;  // the pattern that closes the code block we are in
        $breaks = array_flip($breaks);
        foreach (preg_split('/\R/', $markdown) as $index => $raw) {
            $number = $index + 1;
            $line = rtrim($raw);
            if ($fence !== null) {
                if (preg_match($fence, $line) === 1) {
                    $fence = null;
                }
                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $code) === 1) {
                $fence = '/^ {0,3}'.preg_quote($code[1][0], '/').'{'.strlen($code[1]).',}\s*$/';
                continue;
            }
            if (isset($breaks[$index])) {
                foreach ($open as $opened) {
                    $problems[] = "The box opened on line $opened is still open at the page break on line $number"
                        .' - close it with ::: before the ---.';
                }
                $open = [];
                continue;
            }
            if (preg_match('/^ {0,3}(\S.*)$/', $line, $text) !== 1) {
                continue;
            }
            if (preg_match(self::CLOSE, $text[1]) === 1) {
                if ($open === []) {
                    $problems[] = "Line $number closes a box (:::) that was never opened.";
                } else {
                    array_pop($open);
                }
                continue;
            }
            $attributes = self::opening($text[1]);
            if ($attributes === null) {
                continue;
            }
            foreach ($attributes['invalid'] as $token) {
                $problems[] = "Line $number: '$token' is not an id or a class - write #an-id or .a-class,"
                    .' with letters, digits, - and _, and one id to a box.';
            }
            if ($attributes['id'] !== null) {
                if (isset($ids[$attributes['id']])) {
                    $problems[] = "Line $number: the id #{$attributes['id']} is already used on line {$ids[$attributes['id']]}.";
                } else {
                    $ids[$attributes['id']] = $number;
                }
            }
            $open[] = $number;
        }
        foreach ($open as $opened) {
            $problems[] = "The box opened on line $opened is never closed - end it with a line of :::.";
        }
        return $problems;
    }
}
