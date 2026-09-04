<?php

namespace Dynart\Dpress\Content\Shortcode;

/**
 * `{{ icon('star') }}`, `{{ icon('rust', style='brands') }}`
 *
 * An icon in the middle of a sentence, which raw HTML cannot be: the renderer strips it
 * (`html_input => 'strip'`), so an author writing `<i class="fa-solid fa-star">` gets a gap where
 * they wanted a star. That strip is not an obstacle to work around - it is what stops a compromised
 * account putting a script on every page - so the way in is a shortcode, which is a lookup table
 * rather than a hole.
 *
 * **The CMS ships no icons.** This writes the classes and nothing else; whether anything appears is
 * a question about the theme's stylesheet. That division is deliberate: an icon set is a design
 * decision with a licence attached, and a CMS bundling one makes it for every site that ever uses
 * it. Font Awesome's own class names are the vocabulary here because they are the ones an author
 * looks up.
 */
class IconShortcode {

    const DEFAULT_STYLE = 'solid';

    /**
     * Font Awesome's families, Free and Pro alike
     *
     * An allowlist because the value goes into a class attribute, and the shortest way to be sure
     * a class attribute cannot be talked into being something else is to never write a word that
     * was not on a list.
     */
    const STYLES = ['solid', 'regular', 'light', 'thin', 'duotone', 'brands', 'sharp'];

    /**
     * @param array $arguments `0`/`name` the icon, `1`/`style` the family, `label` what it means
     */
    public function render(array $arguments): string {
        $name = $this->name($arguments);
        if ($name === null) {
            return $this->cannot(
                trim((string)($arguments[0] ?? $arguments['name'] ?? '')) === ''
                    ? 'an icon needs a name'
                    : 'an icon name is letters, digits and hyphens'
            );
        }
        $style = strtolower(trim((string)($arguments[1] ?? $arguments['style'] ?? self::DEFAULT_STYLE)));
        if (!in_array($style, self::STYLES, true)) {
            return $this->cannot("there is no '".$style."' style - ".join(', ', self::STYLES));
        }
        return '<i class="fa-'.$style.' fa-'.$name.'"'.$this->meaning($arguments).'></i>';
    }

    /**
     * The icon's name, or null when it is not one
     *
     * Validated rather than escaped, because escaping is the wrong tool for a class attribute: the
     * point is not that the value cannot break out of the quotes, it is that a *class* somebody
     * else's stylesheet defines should not be nameable from inside a post.
     *
     * A leading `fa-` is dropped, because that is how the name is written everywhere an author
     * would have read it, and `fa-fa-star` is a silent nothing rather than an error.
     */
    protected function name(array $arguments): ?string {
        $name = strtolower(trim((string)($arguments[0] ?? $arguments['name'] ?? '')));
        if (str_starts_with($name, 'fa-')) {
            $name = substr($name, 3);
        }
        return preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) === 1 ? $name : null;
    }

    /**
     * Whether the icon says anything, and to whom
     *
     * **Silent unless it is given a label.** An icon beside a word that already says the same thing
     * is decoration, and a screen reader announcing "star star" is worse than one that says it
     * once - so `aria-hidden` is the default. A `label` is the author saying this one carries the
     * meaning on its own, and then it needs to be a thing rather than a picture: `role="img"`.
     */
    protected function meaning(array $arguments): string {
        $label = trim((string)($arguments['label'] ?? ''));
        return $label === ''
            ? ' aria-hidden="true"'
            : ' role="img" aria-label="'.htmlspecialchars($label, ENT_QUOTES).'"';
    }

    /**
     * What a shortcode that cannot do what it was asked leaves behind
     *
     * A comment rather than nothing, the same as the video shortcode: the page still renders, and
     * whoever looks at the source finds out why.
     */
    protected function cannot(string $why): string {
        return '<!-- icon: '.htmlspecialchars($why).' -->';
    }
}
