<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\TaxonomyService;

/**
 * Reading content on the front end
 *
 * A single post lives at `/post/<slug>`, one segment, which the router handles today. The
 * hierarchical page paths belong to the presentation phase, along with the catch-all route the
 * framework still needs for them.
 */
class ContentController extends AbstractController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected ContentService $content,
        protected TaxonomyService $taxonomy,
        protected MediaService $media,
        protected MediaView $mediaView,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    #[Route('GET', '/tag/?')]
    public function tag(string $slug): string {
        $tag = $this->taxonomy->findTagBySlug($slug);
        if ($tag === null) {
            $this->app()->sendError(404);
        }
        $posts = $this->content->findByTag($tag->id);
        return $this->render('dpress:content/list', [
            'title' => 'Tagged '.$tag->name,
            'heading' => 'Tagged “'.$tag->name.'”',
            'posts' => $posts,
            'thumbnails' => $this->thumbnails($posts),
            'authors' => $this->authors($posts),
            'mediaView' => $this->mediaView,
        ], 'archive');
    }

    #[Route('GET', '/category/?')]
    public function category(string $slug): string {
        $category = $this->taxonomy->findCategoryBySlug($slug);
        if ($category === null) {
            $this->app()->sendError(404);
        }
        $posts = $this->content->findByCategory($category->id);
        return $this->render('dpress:content/list', [
            'title' => $category->name,
            'heading' => $category->name,
            'posts' => $posts,
            'thumbnails' => $this->thumbnails($posts),
            'authors' => $this->authors($posts),
            'mediaView' => $this->mediaView,
        ], 'archive');
    }

    /**
     * A post under `/post/<slug>`
     *
     * Kept answering whatever `post_path` says, because a site that has moved its posts to the
     * root still has every old address written down somewhere it cannot edit. It **301s** to
     * wherever the post lives now, which is the same rule a page has always followed for a
     * path that is not its own: one piece of content, one address, and the other one points
     * at it.
     */
    #[Route('GET', '/post/?')]
    public function post(string $slug): string {
        $content = $this->content->findBySlug($slug, !$this->maySeeDrafts());
        if ($content === null || !$content->isPost()) {
            $this->app()->sendError(404);
        }
        if ($this->content->postsAtRoot()) {
            // the page number travels with it, the way it does for a page that has moved
            $number = (int)$this->request->get(self::PAGE_PARAM, 1);
            $this->app()->redirect(
                $this->content->publicPath($content),
                $number > 1 ? [self::PAGE_PARAM => $number] : [],
                301
            );
            return '';
        }
        return $this->render('dpress:content/single', $this->pagedBody($content, $this->content->publicPath($content)) + [
            'title'       => $content->title,
            'content'     => $content,
            'author'      => $this->authorOf($content),
            'tags'        => $this->taxonomy->tagsOf($content->id),
            'categories'  => $this->taxonomy->categoriesOf($content->id),
            'attachments' => $this->media->attachmentsOf($content->id),
            'featured'    => $content->featured_media_id !== null
                ? $this->media->findById($content->featured_media_id) : null,
            'mediaView'   => $this->mediaView,
        ], 'post');
    }

    /**
     * Somebody who may edit posts can follow a link to a draft; a visitor gets a 404
     */
    protected function maySeeDrafts(): bool {
        $user = $this->currentUser();
        return $user !== null && $user->hasPermission(Permissions::forContent(Content::TYPE_POST, 'update'));
    }
}
