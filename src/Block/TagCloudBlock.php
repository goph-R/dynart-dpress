<?php

namespace Dynart\Dpress\Block;

use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Block;
use Dynart\Dpress\Service\TaxonomyService;

/**
 * The tags in use, sized by how much they are used
 *
 * `TaxonomyService::tagCloud()` counted published content per tag long before there was anywhere
 * to show it - the query has been in `CoreQueries` since the taxonomy landed. This is the screen
 * it was waiting for.
 *
 * The weight is a bucket, not a font size worked out here: the template turns `1..5` into
 * whatever the theme wants a big tag to look like. A block decides what it means, a stylesheet
 * decides what it looks like.
 */
class TagCloudBlock {

    const DEFAULT_LIMIT = 30;

    /** How many steps between the least and the most used tag */
    const STEPS = 5;

    public function __construct(protected TaxonomyService $taxonomy, protected ViewInterface $view) {}

    public function render(Block $block, array $settings): string {
        // Left out of the rows entirely rather than filtered after, so it is out of the weight
        // calculation too: `weigh()` scales between the smallest and the largest total, and the
        // featured tag is on whatever the author pinned - often the highest count on the site,
        // which would flatten every real tag into the bottom bucket.
        $tags = $this->taxonomy->tagCloud(['exclude_slug' => $this->taxonomy->featuredTagSlug()]);
        if ($tags === []) {
            return '';
        }
        $limit = max(0, (int)($settings['limit'] ?? self::DEFAULT_LIMIT));
        if ($limit > 0 && count($tags) > $limit) {
            // the most used ones, then back into alphabetical order: a cloud somebody reads is
            // sorted by name, but which tags are *in* it is a question about the totals
            usort($tags, fn(array $a, array $b) => (int)$b['total'] <=> (int)$a['total']);
            $tags = array_slice($tags, 0, $limit);
            usort($tags, fn(array $a, array $b) => strcasecmp((string)$a['name'], (string)$b['name']));
        }
        return $this->view->fetch('dpress:block/tag-cloud', [
            'tags' => $this->weigh($tags),
        ]);
    }

    /**
     * Puts every tag in a bucket from 1 to `STEPS`
     *
     * Linear between the smallest and the largest total, so a site whose tags all have three posts
     * gets one size rather than a cloud that pretends the difference between 3 and 3 is meaningful.
     */
    protected function weigh(array $tags): array {
        $totals = array_map(fn(array $tag) => (int)$tag['total'], $tags);
        $low = min($totals);
        $high = max($totals);
        $span = $high - $low;
        foreach ($tags as $index => $tag) {
            $tags[$index]['weight'] = $span === 0
                ? (int)ceil(self::STEPS / 2)
                : 1 + (int)floor(((int)$tag['total'] - $low) / $span * (self::STEPS - 1));
        }
        return $tags;
    }
}
