<?php

namespace Dynart\Dpress\Content;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;

/**
 * ` ```php ` becomes a code block a highlighter can find
 *
 * **The colours are not in here, and are not in `body_html` either.** What this writes is the
 * language, as an attribute:
 *
 *     <pre data-enlighter-language="php"><code class="language-php">…</code></pre>
 *
 * and EnlighterJS colours it in the browser. A server-side highlighter would write a `<span>` per
 * token into the stored document, which is markup about how a thing *looks* living inside the
 * content - the same mistake `media#12` exists to avoid, and it would mean re-rendering every
 * post to change a theme or to upgrade the highlighter. This way the stored HTML is semantic and
 * permanent, and the highlighting is entirely presentation.
 *
 * What it costs is a script on pages that have code in them. Only those: see
 * `AbstractController::codeAssets()`.
 */
class CodeBlockRenderer implements NodeRendererInterface {

    /**
     * What an author is likely to type, against what EnlighterJS calls it
     *
     * Only where they differ. Everything not in here is passed through as written, so a language
     * added to the highlighter works without anything here changing.
     */
    const ALIASES = [
        'c++' => 'cpp',
        'c#' => 'csharp',
        'cs' => 'csharp',
        'js' => 'javascript',
        'ts' => 'typescript',
        'py' => 'python',
        'rb' => 'ruby',
        'sh' => 'shell',
        'bash' => 'shell',
        'zsh' => 'shell',
        'yml' => 'yaml',
        'html' => 'xml',
        'htm' => 'xml',
        'md' => 'markdown',
        'golang' => 'go',
        'objective-c' => 'objc',
        'plaintext' => 'raw',
        'text' => 'raw',
        'txt' => 'raw',
    ];

    /**
     * Subscribed to `MarkdownRenderer::EVENT_ENVIRONMENT`
     *
     * A higher priority than the core renderer for the same node, which is how CommonMark lets
     * one be replaced without the extension being taken apart.
     */
    public function onEnvironment(EnvironmentBuilderInterface $environment): void {
        $environment->addRenderer(FencedCode::class, $this, 10);
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string {
        if (!$node instanceof FencedCode) {
            return '';
        }
        $code = '<code'.$this->languageClass($node).'>'
            .Xml::escape($node->getLiteral())
            .'</code>';
        $language = $this->language($node);
        // no language, no attribute: a fence with nothing after it is a code block and not a
        // failed one, and it renders exactly as it did before any of this existed
        return $language === ''
            ? '<pre>'.$code.'</pre>'
            : '<pre data-enlighter-language="'.Xml::escape($language).'">'.$code.'</pre>';
    }

    /**
     * The language an author wrote, resolved to what the highlighter calls it
     *
     * The info string is whatever came after the backticks, and CommonMark hands over every word
     * of it - only the first names the language, the rest are somebody else's convention.
     */
    protected function language(FencedCode $node): string {
        $words = $node->getInfoWords();
        $first = strtolower(trim((string)($words[0] ?? '')));
        if ($first === '' || preg_match('/^[a-z0-9+#_.-]+$/', $first) !== 1) {
            return '';
        }
        return self::ALIASES[$first] ?? $first;
    }

    /**
     * `class="language-php"` stays on the `<code>`, as CommonMark writes it
     *
     * Nothing of ours needs it - EnlighterJS reads the attribute on the `<pre>` - but it is what
     * every other tool expects to find, and a document that leaves the CMS should still say what
     * its code is written in.
     */
    protected function languageClass(FencedCode $node): string {
        $words = $node->getInfoWords();
        $first = trim((string)($words[0] ?? ''));
        return $first === '' ? '' : ' class="language-'.Xml::escape($first).'"';
    }
}
