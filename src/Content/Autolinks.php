<?php

namespace Dynart\Dpress\Content;

use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\SettingService;
use League\CommonMark\Environment\EnvironmentBuilderInterface;

/**
 * Turning a bare URL in prose into a link, unless the site says not to
 *
 * Somebody writing a post writes `https://example.com` and means a link; making them write it
 * twice is a tax on the common case. So it is on by default.
 *
 * It is a **setting** and not a decision, because it is genuinely arguable: a site whose posts
 * quote URLs as *examples* - a page about writing markdown, say - wants the text to stay text, and
 * on that site the feature is a nuisance rather than a convenience.
 *
 * Subscribed to `MarkdownRenderer::EVENT_ENVIRONMENT` as a Micro callable, so neither this nor the
 * settings behind it is built until something actually renders markdown - which on a page view is
 * never, because the HTML was written at save time. That is also the thing to remember about
 * changing the setting: **it takes effect when a document is next rendered**, so `dpress
 * content:rerender` is part of switching it, exactly as it is for the post URL shape.
 */
class Autolinks {

    public function __construct(protected SettingService $settings) {}

    public function onEnvironment(EnvironmentBuilderInterface $environment): void {
        if (!$this->enabled()) {
            return;
        }
        $environment->addInlineParser(new HttpAutolinkParser());
    }

    /**
     * On unless the site has turned it off
     *
     * The default is *true* rather than false, which is the opposite of how a new setting usually
     * arrives - because this one describes what somebody writing prose already meant.
     */
    public function enabled(): bool {
        return $this->settings->getBool(Setting::AUTOLINK, true);
    }
}
