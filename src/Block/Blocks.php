<?php

namespace Dynart\Dpress\Block;

use Dynart\Micro\LoggerInterface;
use Dynart\Micro\Micro;
use Dynart\Dpress\Entity\Block;

/**
 * The kinds of block a site can put in a place
 *
 * The same registry `Shortcodes` is, for the same reason: what the CMS ships and what a plugin
 * adds go in through one call, so the three core types are not a privileged `if` chain somebody
 * else has to get inside of.
 *
 *     $blocks->add('kofi', [
 *         'title'  => 'Ko-fi button',
 *         'render' => [KofiBlock::class, 'render'],
 *         'fields' => ['page' => ['type' => 'text', 'label' => 'Page name']],
 *     ]);
 *
 * That one is not made up and is not here: the Ko-fi button is a plugin, and this is the call
 * it makes. A block type is a registration and never a migration, which is what lets a folder
 * dropped into `plugins/` bring a new kind of block and take it away again.
 *
 * **`fields` is what makes a type more than markup.** It is a form field list, merged into the
 * block editor, so a type describes its own settings and no template ever branches on `type` -
 * the mistake `FormWidgets` exists to have taken out of form rendering.
 *
 * **`prepare` is the save-time hook.** A type that renders something expensive - markdown, above
 * all - turns it into HTML there, once, and `render()` on a page view only prints it. That is the
 * content rule (`lead_html` / `body_html` are a cache of `markdown`) applied one level down.
 *
 * Everything is a Micro callable resolved when it is actually needed, so a site with no blocks
 * builds none of these classes, and neither does a page whose theme renders no places.
 */
class Blocks {

    /** @var array type => ['title' => string, 'render' => callable, 'fields' => array|callable, 'prepare' => ?callable] */
    private array $types = [];

    public function __construct(protected LoggerInterface $logger) {}

    /**
     * @param array $definition `title`, `render` (fn(Block, array $settings): string), and
     *                          optionally `fields` and `prepare` (fn(array $settings): array)
     */
    public function add(string $type, array $definition): void {
        $this->types[$type] = $definition + ['title' => $type, 'render' => null, 'fields' => [], 'prepare' => null];
    }

    public function has(string $type): bool {
        return isset($this->types[$type]);
    }

    /** @return string[] type => title, for the chooser and the list */
    public function titles(): array {
        return array_map(fn(array $definition) => (string)$definition['title'], $this->types);
    }

    public function title(string $type): string {
        return (string)($this->types[$type]['title'] ?? $type);
    }

    /**
     * The settings fields of one type, as a form field list
     *
     * A plain array rather than something to call: a type that needs options worked out at the
     * time - a select of categories, say - subscribes to `form.admin_block:created` like anything
     * else adding to somebody's form. One mechanism for that, and it is the one that already
     * exists.
     */
    public function fields(string $type): array {
        return (array)($this->types[$type]['fields'] ?? []);
    }

    /**
     * Turns what somebody typed into what gets stored, for a type that asked to
     *
     * Nothing to do for most types, and the settings pass through untouched.
     */
    public function prepare(string $type, array $settings): array {
        $prepare = $this->types[$type]['prepare'] ?? null;
        return $prepare === null ? $settings : (array)Micro::getCallable($prepare)($settings);
    }

    /**
     * Renders one block
     *
     * A type that is not registered leaves a comment and a log line rather than an exception:
     * the plugin that provided it may simply be switched off this morning, and a sidebar is not
     * broken because one thing in it has nothing to draw with - it is a sidebar with one thing
     * missing, in a page that still renders. Same answer as an unknown shortcode.
     */
    public function render(Block $block): string {
        if (!isset($this->types[$block->type])) {
            $this->logger->warning(
                "dpress: no block type called '$block->type'. Registered: "
                .(join(', ', array_keys($this->types)) ?: 'none')
            );
            return '<!-- no block type called '.htmlspecialchars($block->type).' -->';
        }
        $render = Micro::getCallable($this->types[$block->type]['render']);
        return (string)$render($block, $block->settings());
    }
}
