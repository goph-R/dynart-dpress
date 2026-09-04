<?php

namespace Dynart\Dpress\Content;

use League\CommonMark\Extension\Autolink\UrlAutolinkParser;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * A bare `http://` or `https://` in prose, and nothing else
 *
 * CommonMark ships an autolinker, and it does three things rather than one: it also links a bare
 * `www.` host and it turns an email address into a `mailto:`. Both are defensible and neither was
 * asked for, and the surprising half of a feature is the half nobody wanted - an address written
 * in a post as a fact about somebody becoming a clickable `mailto:` is a decision the author did
 * not make.
 *
 * So the library's parser does the work and this decides when it is asked to. `getMatchDefinition()`
 * is what CommonMark consults to know where a parser could possibly start, so naming the two
 * protocols is enough: at any other position the inner parser is never reached, and the `www.` and
 * the email branches simply never come up.
 *
 * Composition rather than a subclass because `UrlAutolinkParser` is `final`, which is the library
 * saying the same thing in its own way.
 *
 * **Code is not prose.** An inline parser runs where inline markup runs, which is not inside a
 * fenced block and not inside a code span, so `` `https://example.com` `` stays what it says. That
 * is a property of where this is plugged in rather than a check it performs, which is why it has a
 * test of its own: nothing here would fail if it stopped being true.
 */
class HttpAutolinkParser implements InlineParserInterface {

    /** The two the CMS links, in the order a `oneOf` should see them */
    const PROTOCOLS = ['http', 'https'];

    private UrlAutolinkParser $urls;

    public function __construct() {
        // `https` as the default protocol is only reached for a `www.` host, which this never
        // matches - it is passed for the sake of not leaving a plain-text `http` behind if it ever
        // does
        $this->urls = new UrlAutolinkParser(self::PROTOCOLS, 'https');
    }

    public function getMatchDefinition(): InlineParserMatch {
        return InlineParserMatch::oneOf(...array_map(fn(string $p): string => $p.'://', self::PROTOCOLS));
    }

    public function parse(InlineParserContext $inlineContext): bool {
        return $this->urls->parse($inlineContext);
    }
}
