<?php

namespace Dynart\Dpress\Content;

use Dynart\Micro\RouterInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\TaxonomyService;

/**
 * Every canonical URL on the site, for a crawler
 *
 * A sitemap earns its keep exactly when a site's addresses change - which is the day it moves off
 * something else. A crawler finds new URLs on its own eventually, by following links, and
 * "eventually" for a small blog is weeks.
 *
 * **No `changefreq`, no `priority`.** Google has said for years that it ignores both, and they are
 * the two fields that make a hand-written sitemap look authoritative while saying nothing. What is
 * left - `loc` and `lastmod` - is the part a crawler actually reads, and `lastmod` only helps if
 * it is true, so it comes from the row rather than from `time()`.
 *
 * Four queries, whatever the size of the site.
 */
class Sitemap {

    const ROUTE = '/sitemap.xml';

    const ROBOTS_ROUTE = '/robots.txt';

    const CONTENT_TYPE = 'application/xml; charset=UTF-8';

    const ROBOTS_CONTENT_TYPE = 'text/plain; charset=UTF-8';

    /**
     * The ceiling from sitemaps.org, and the point at which this stops being enough
     *
     * A site past it needs a sitemap **index** pointing at several files, which is a different
     * shape and not built here. The cap is applied to the queries rather than left to memory, so
     * a site that grows into the problem gets a truncated sitemap instead of an exhausted worker.
     */
    const MAX_URLS = 50000;

    public function __construct(
        protected ContentService $content,
        protected TaxonomyService $taxonomy,
        protected RouterInterface $router,
        protected Dates $dates,
    ) {}

    public function url(): string {
        return $this->router->url(self::ROUTE);
    }

    /**
     * `loc` and `lastmod` for everything a visitor can reach
     *
     * @return array[] each `['loc' => string, 'lastmod' => string]`, `lastmod` possibly ''
     */
    public function urls(): array {
        $posts = $this->posts();
        $pages = $this->pages();
        $urls = array_merge(
            [['loc' => $this->router->url('/'), 'lastmod' => $this->newest(array_merge($posts, $pages))]],
            $posts,
            $pages,
            $this->categories(),
            $this->tags()
        );
        return array_slice($urls, 0, self::MAX_URLS);
    }

    public function xml(): string {
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($this->urls() as $url) {
            $out .= '<url>'."\n".'<loc>'.htmlspecialchars($url['loc']).'</loc>'."\n";
            if ($url['lastmod'] !== '') {
                $out .= '<lastmod>'.htmlspecialchars($url['lastmod']).'</lastmod>'."\n";
            }
            $out .= '</url>'."\n";
        }
        return $out.'</urlset>'."\n";
    }

    /**
     * A robots.txt whose only job is to say where the sitemap is
     *
     * Deliberately permissive. The admin is **not** disallowed: a `Disallow: /admin` is a public
     * file naming the door, it stops nothing that was going to try it, and the screens behind it
     * already answer 401. Robots exclusion is for crawl budget, not for access control.
     *
     * A site that wants its own puts a real `robots.txt` into `public/`, and Apache serves that
     * instead - the front controller only ever sees the request because the file is not there.
     */
    public function robotsTxt(): string {
        return "User-agent: *\n"
            ."Allow: /\n"
            ."\n"
            ."Sitemap: ".$this->url()."\n";
    }

    // --- what goes in it ---

    /** @return array[] */
    protected function posts(): array {
        $urls = [];
        foreach ($this->rows(Content::TYPE_POST, true) as $row) {
            $urls[] = [
                'loc' => $this->router->url($this->content->postPath((string)($row['slug'] ?? ''))),
                'lastmod' => $this->changedAt($row),
            ];
        }
        return $urls;
    }

    /**
     * Pages, whose address is their ancestors and their slug
     *
     * Every page is fetched, drafts included, and only the published ones are listed. **A draft
     * ancestor still contributes its slug to a published child's path** - `ContentService::path()`
     * walks the chain without asking about status - so leaving the drafts out of the lookup would
     * produce a URL that 404s for exactly the pages nested under one.
     *
     * The chain is walked over the rows in memory rather than through `ancestors()`, which is a
     * `findById()` per level: correct for one page, a query storm over all of them.
     *
     * @return array[]
     */
    protected function pages(): array {
        $rows = $this->rows(Content::TYPE_PAGE, false);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)($row['id'] ?? 0)] = $row;
        }
        $urls = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== Content::STATUS_PUBLISHED) {
                continue;
            }
            $urls[] = [
                'loc' => $this->router->url($this->pagePath($row, $byId)),
                'lastmod' => $this->changedAt($row),
            ];
        }
        return $urls;
    }

    /**
     * `/parent/child`, built from rows
     *
     * @param array $byId every page row, drafts included, keyed by id
     */
    public function pagePath(array $row, array $byId): string {
        $parts = [(string)($row['slug'] ?? '')];
        $seen = [(int)($row['id'] ?? 0) => true];
        $parentId = $row['parent_id'] ?? null;
        while ($parentId !== null && isset($byId[(int)$parentId]) && !isset($seen[(int)$parentId])) {
            $parent = $byId[(int)$parentId];
            $seen[(int)$parentId] = true;   // a cycle is a corrupt tree; it must not hang a request
            array_unshift($parts, (string)($parent['slug'] ?? ''));
            $parentId = $parent['parent_id'] ?? null;
        }
        return '/'.join('/', $parts);
    }

    /**
     * Every category, including one with nothing in it yet
     *
     * Categories are made by hand and there are few of them, so an empty one is a section
     * somebody meant to have. Tags are the other way about - see below.
     *
     * @return array[]
     */
    protected function categories(): array {
        $urls = [];
        foreach ($this->taxonomy->categories() as $row) {
            $urls[] = [
                'loc' => $this->router->url(
                    $this->taxonomy->categoryPathBySlug((string)($row['slug'] ?? ''))
                ),
                'lastmod' => '',
            ];
        }
        return $urls;
    }

    /**
     * Only the tags something published actually carries
     *
     * A tag is created as a side effect of writing a post, so an unused one is not a section of
     * the site - it is a leftover from a post that was retagged or unpublished, and its archive
     * is an empty page. `tagCloud()` already joins on published content, so this needs no query
     * of its own.
     *
     * @return array[]
     */
    protected function tags(): array {
        $urls = [];
        foreach ($this->taxonomy->tagCloud(['exclude_slug' => $this->taxonomy->featuredTagSlug()]) as $row) {
            $urls[] = [
                'loc' => $this->router->url($this->taxonomy->tagPathBySlug((string)($row['slug'] ?? ''))),
                'lastmod' => '',
            ];
        }
        return $urls;
    }

    // --- dates ---

    /**
     * @param bool $publishedOnly false also brings back the drafts, which pages need for their paths
     * @return array[]
     */
    protected function rows(string $type, bool $publishedOnly): array {
        return $this->content->findAll([
            'type'           => $type,
            'published_only' => $publishedOnly,
            'max'            => self::MAX_URLS,
        ]);
    }

    /**
     * When this last changed, as W3C datetime
     *
     * `updated_at` and not `published_at`: a post edited last week is a post a crawler should look
     * at again, and one whose `lastmod` never moves after publication is telling it not to bother.
     */
    protected function changedAt(array $row): string {
        return $this->dates->iso($row['updated_at'] ?? null)
            ?: $this->dates->iso($row['published_at'] ?? null);
    }

    /**
     * The front page changes whenever anything on it does
     *
     * @param array[] $urls
     */
    protected function newest(array $urls): string {
        $newest = '';
        foreach ($urls as $url) {
            if ($url['lastmod'] > $newest) {
                $newest = $url['lastmod'];
            }
        }
        return $newest;
    }
}
