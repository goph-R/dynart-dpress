<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\SettingService;
use Dynart\Dpress\Service\TaxonomyService;

/**
 * The front page: the featured posts, then the published ones, newest first
 */
class HomeController extends AbstractController {

    /**
     * How many posts a featured strip is offered
     *
     * A number rather than a setting, because it is not a preference - it is how many a theme is
     * handed, and how many of those it *draws* is the theme's own business. A layout of one large
     * and four small takes all five; one that wants three takes three.
     */
    const FEATURED_MAX = 5;

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected ContentService $content,
        protected TaxonomyService $taxonomy,
        protected SettingService $settings,
        protected MediaView $mediaView,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    #[Route('GET', '/')]
    public function index(): string {
        $featured = $this->featured();
        $posts = $this->content->findAll([
            'type' => Content::TYPE_POST,
            'published_only' => true,
            // pinned to the top *and* repeated four rows down reads as a bug rather than as
            // emphasis, so a featured post is in one place on this page and not two
            'exclude_ids' => array_column($featured, 'id'),
        ]);
        return $this->render('dpress:content/list', [
            'title'           => '',
            'posts'           => $posts,
            // `featured_posts`, not `featured`: a single post's template has had `$featured` for
            // its own picture since there were templates, and one name for two things is a bug a
            // theme author writes once and debugs twice
            'featured_posts'  => $featured,
            'thumbnails'      => $this->thumbnails(array_merge($posts, $featured)),
            'authors'         => $this->authors(array_merge($posts, $featured)),
            'mediaView'       => $this->mediaView,
        ], 'home');
    }

    /**
     * The posts somebody put at the top, by giving them a tag
     *
     * **A tag rather than a column**: an author already knows how to tag a post, un-featuring is
     * removing one, and there is no new screen and no migration. Which tag is a setting, so a site
     * writing in Hungarian can call it `kiemelt`, and an empty setting means a site that does not
     * want a strip at all.
     *
     * A tag nobody has used yet is not a misconfiguration - it is a site that has not featured
     * anything - so it answers with none rather than complaining.
     *
     * @return array[] listing rows, newest first
     */
    protected function featured(): array {
        $slug = $this->taxonomy->featuredTagSlug();
        if ($slug === '') {
            return [];
        }
        $tag = $this->taxonomy->findTagBySlug($slug);
        if ($tag === null) {
            return [];
        }
        return $this->content->findByTag($tag->id, ['max' => self::FEATURED_MAX]);
    }
}
