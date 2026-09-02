<?php

namespace Dynart\Dpress\Theme;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Block\Blocks;
use Dynart\Dpress\Service\BlockService;
use Dynart\Dpress\Service\MenuService;

/**
 * A spot in the layout, and whatever a site has put in it
 *
 *     <?php $sidebar = $places->render('sidebar') ?>
 *     <?php if ($sidebar !== ''): ?><aside class="sidebar"><?= $sidebar ?></aside><?php endif ?>
 *
 * **A place is one idea, not two.** A theme declares `places[] = sidebar` once and both editors
 * offer it: a menu is assigned to a place, blocks are ordered in one. Two vocabularies for one
 * concept would mean a theme author learning `places` and `regions` and guessing which screen
 * means which.
 *
 * Handed to templates as a **variable** rather than reached for through the container - the line
 * this CMS has always drawn about templates and services - and lazy on both sides, so a layout
 * that renders no places asks for nothing and a site with no blocks pays for no query. An empty
 * place answers with `''` so the layout can leave its wrapper out; an `<aside>` with nothing in it
 * is a hole in the design at every width.
 */
class Places {

    /** Per request: a layout that asks twice - a wide and a narrow variant - reads once */
    private array $cache = [];

    public function __construct(
        protected MenuService $menus,
        protected BlockService $service,
        protected Blocks $blocks,
        protected ViewInterface $view,
        protected EventServiceInterface $events,
    ) {}

    /**
     * The menu assigned to a place, then everything in it - which is what a layout wants
     */
    public function render(string $place): string {
        return $this->menu($place).$this->blocks($place);
    }

    /**
     * The menu assigned here, or nothing when none is
     */
    public function menu(string $place): string {
        return $this->cached('menu:'.$place, function () use ($place): string {
            $items = $this->menus->tree($place);
            return $items === [] ? '' : $this->view->fetch('dpress:menu', ['items' => $items, 'place' => $place]);
        });
    }

    /**
     * The blocks here, in order, without the ones that came out empty
     *
     * A tag cloud on a site with no tags renders nothing, and nothing is what it should leave
     * behind - not a heading over a blank space.
     */
    public function blocks(string $place): string {
        return $this->cached('blocks:'.$place, function () use ($place): string {
            $blocks = $this->service->inPlace($place);
            $this->events->emit(BlockService::EVENT_BEFORE_RENDER, [$place, $blocks]);
            $items = [];
            foreach ($blocks as $block) {
                $html = $this->blocks->render($block);
                if (trim($html) === '') {
                    continue;
                }
                $items[] = ['type' => $block->type, 'title' => $block->title, 'html' => $html];
            }
            return $items === []
                ? ''
                : $this->view->fetch('dpress:blocks', ['blocks' => $items, 'place' => $place]);
        });
    }

    protected function cached(string $key, callable $build): string {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = (string)$build();
        }
        return $this->cache[$key];
    }
}
