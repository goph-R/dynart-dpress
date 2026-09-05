<?php

namespace Dynart\Dpress\Content;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\SettingService;

/**
 * The site's posts, as RSS
 *
 * **RSS 2.0 rather than Atom**, and at `/feed` rather than anywhere nicer, because the job here is
 * continuity: WordPress served `/feed/` and every reader on earth already understands what came
 * out of it. A blog that moves and quietly stops publishing a feed does not hear about it - nobody
 * emails to say their reader went silent - so the shape that keeps existing subscriptions alive
 * wins over the shape that is tidier.
 *
 * **Both bodies go out.** `<description>` carries the lead and `<content:encoded>` the whole post,
 * which is what lets a reader show either without the site having an opinion. That is deliberately
 * *not* a setting: "summary or full text" is the kind of preference that generates a support
 * question for every reader that resolves it differently, and sending both costs one extra element.
 *
 * The HTML is the same `lead_html` and `body_html` the page renders, already absolute since
 * `content:rerender` writes URLs against `app.base_url` - so a feed item is the post, not a
 * re-render of it, and there is no second markdown pipeline to keep in step with the first.
 */
class Feed {

    const ROUTE = '/feed';

    const CONTENT_TYPE = 'application/rss+xml; charset=UTF-8';

    /** What a site gets when it has never said. WordPress's own default, for the same reasons. */
    const DEFAULT_ITEMS = 20;

    /**
     * A ceiling, because the number is a setting and a feed is one query plus one string
     *
     * Nothing stops somebody typing 5000, and the cost of that is paid on every fetch by every
     * reader that has ever subscribed, forever.
     */
    const MAX_ITEMS = 100;

    public function __construct(
        protected ContentService $content,
        protected SettingService $settings,
        protected ConfigInterface $config,
        protected RouterInterface $router,
        protected Dates $dates,
    ) {}

    public function url(): string {
        return $this->router->url(self::ROUTE);
    }

    /**
     * The `<link>` that tells a browser and a reader the feed is there
     *
     * Registered with `PageAssets` and no needle, so it is on every page of every theme - which
     * is the point. A theme that has to remember this is a theme that forgets it, and a feed
     * nothing links to is a feed nobody finds.
     */
    public function headLink(string $html = ''): string {
        $title = $this->siteName().' feed';
        return '<link rel="alternate" type="application/rss+xml" title="'
            .htmlspecialchars($title, ENT_QUOTES)
            .'" href="'.htmlspecialchars($this->url(), ENT_QUOTES).'">';
    }

    /**
     * How many posts go out, clamped
     *
     * @return int between 1 and `MAX_ITEMS`
     */
    public function itemCount(): int {
        $count = $this->settings->getInt(Setting::FEED_ITEMS, self::DEFAULT_ITEMS);
        return max(1, min(self::MAX_ITEMS, $count));
    }

    /** @var array[]|null the one query, kept because the controller wants the rows as well */
    private ?array $rows = null;

    /**
     * @return array[] listing rows, newest first
     */
    public function items(): array {
        if ($this->rows === null) {
            $this->rows = $this->content->findAll([
                'type'           => Content::TYPE_POST,
                'published_only' => true,
                'max'            => $this->itemCount(),
            ]);
        }
        return $this->rows;
    }

    public function xml(): string {
        $items = $this->items();
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            .' xmlns:atom="http://www.w3.org/2005/Atom">'."\n"
            .'<channel>'."\n"
            .$this->element('title', $this->siteName())
            .$this->element('link', $this->router->url('/'))
            .$this->element('description', $this->siteDescription())
            .$this->element('language', (string)$this->config->get('translation.default', 'en'))
            .$this->element('generator', 'dpress')
            .'<atom:link href="'.htmlspecialchars($this->url(), ENT_QUOTES)
            .'" rel="self" type="application/rss+xml" />'."\n";
        $built = $this->builtAt($items);
        if ($built !== '') {
            $out .= $this->element('lastBuildDate', $built);
        }
        foreach ($items as $item) {
            $out .= $this->item($item);
        }
        return $out.'</channel>'."\n".'</rss>'."\n";
    }

    /**
     * When the feed last changed: the newest post's date, or nothing on an empty site
     */
    public function builtAt(array $items): string {
        return $items === [] ? '' : $this->dates->rss($items[0]['published_at'] ?? null);
    }

    protected function item(array $row): string {
        $url = $this->router->url($this->content->postPath((string)($row['slug'] ?? '')));
        $lead = trim((string)($row['lead_html'] ?? ''));
        $body = trim((string)($row['body_html'] ?? ''));
        $out = '<item>'."\n"
            .$this->element('title', (string)($row['title'] ?? ''))
            .$this->element('link', $url)
            // `isPermaLink="true"`: the URL *is* the identity, which is true now that a post lives
            // at one address. A reader keys "have I shown this" on it, so it must not wobble -
            // which is the argument for the shape being settled before a feed exists, not after.
            .'<guid isPermaLink="true">'.htmlspecialchars($url).'</guid>'."\n";
        $date = $this->dates->rss($row['published_at'] ?? null);
        if ($date !== '') {
            $out .= $this->element('pubDate', $date);
        }
        if ($lead !== '') {
            $out .= '<description>'.$this->cdata($lead).'</description>'."\n";
        }
        if ($body !== '') {
            $out .= '<content:encoded>'.$this->cdata($body).'</content:encoded>'."\n";
        }
        return $out.'</item>'."\n";
    }

    protected function element(string $name, string $value): string {
        return $value === '' ? '' : '<'.$name.'>'.htmlspecialchars($value).'</'.$name.'>'."\n";
    }

    /**
     * HTML inside XML, without escaping every tag in the post
     *
     * The one thing that can break a CDATA section is the terminator appearing inside it, which a
     * post *can* contain - a code block about XML, on a blog that writes about file formats,
     * is not a hypothetical. Splitting it across two sections is the standard escape and leaves
     * the bytes a reader sees unchanged.
     */
    protected function cdata(string $html): string {
        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $html).']]>';
    }

    protected function siteName(): string {
        return (string)$this->settings->get(Setting::SITE_NAME, 'dpress');
    }

    protected function siteDescription(): string {
        return trim((string)$this->settings->get(Setting::SITE_DESCRIPTION, ''));
    }
}
