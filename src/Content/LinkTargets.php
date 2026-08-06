<?php

namespace Dynart\Dpress\Content;

use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\TaxonomyService;
use Dynart\Micro\RouterInterface;

/**
 * The lookups behind `media#12`, `post#42`, `category#21`
 *
 * Every URL is built by the thing that owns it - `MediaView` for a file, `ContentService` for a
 * page's path down the tree, the router for everything - so a reference resolves to exactly the
 * URL the site would have linked to itself.
 *
 * **Stateless on purpose.** This asks the database every time, and an earlier version that
 * memoised the answers was wrong within one request: renaming a post re-renders everything that
 * links to it, and those renders were handed the URL cached before the rename. The dedup that is
 * safe - the same picture twice in one document - belongs to a single document and lives in
 * `InternalLinks`, which is the only place that knows where a document starts and ends.
 */
class LinkTargets implements LinkTargetResolverInterface {

    public function __construct(
        protected ContentService $content,
        protected TaxonomyService $taxonomy,
        protected MediaService $media,
        protected MediaView $mediaView,
        protected RouterInterface $router,
    ) {}

    public function resolve(string $kind, int $id): ?string {
        switch ($kind) {
            case 'media':
                return $this->mediaUrl($id);
            case 'category':
                $category = $this->taxonomy->findCategory($id);
                return $category === null ? null : $this->router->url($this->taxonomy->categoryPath($category));
            case 'tag':
                $tag = $this->taxonomy->findTag($id);
                return $tag === null ? null : $this->router->url($this->taxonomy->tagPath($tag));
            default:
                return $this->contentUrl($id);
        }
    }

    /**
     * The full size of an image, and the file itself for anything else
     *
     * Never a preset: a picture inside an article is the picture, and a theme that wants a
     * smaller one has the derivative machinery for that. A soft deleted item counts as gone,
     * which is the same answer the public attachment list gives.
     */
    protected function mediaUrl(int $id): ?string {
        $media = $this->media->findById($id);
        return $media === null || $media->isDeleted() ? null : $this->mediaView->url($media);
    }

    /**
     * A post, a page, or whichever of the two that id turns out to be
     *
     * `publicPath()` decides from the entity's own type, so the prefix somebody wrote is a note
     * to the next reader rather than something that has to be right.
     *
     * A draft resolves too. Linking to something not published yet is an ordinary thing to do
     * while writing, and the link starts working the moment it is - refusing here would leave a
     * bare word in the text instead, and no way to tell why.
     */
    protected function contentUrl(int $id): ?string {
        $content = $this->content->findById($id);
        return $content === null ? null : $this->router->url($this->content->publicPath($content));
    }
}
