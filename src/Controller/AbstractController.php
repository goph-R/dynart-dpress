<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Micro\WebApp;
use Dynart\Dpress\Security\DpressUser;
use Dynart\Dpress\Content\CodeAssets;
use Dynart\Dpress\Content\Shortcodes;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Content\ContentPages;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Theme\Places;
use Dynart\Dpress\Theme\ThemeAssets;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\SettingService;
use Dynart\Dpress\Service\UserService;
use Dynart\Dpress\Content\Dates;

/**
 * What every CMS controller needs
 *
 * Controllers stay thin: they read the request, call a service, and render. Anything that
 * changes state belongs in a service, or it is invisible to plugins.
 */
abstract class AbstractController {

    /** Which page of a long body to serve. A query parameter, so no route has to know about it */
    const PAGE_PARAM = 'page';

    /**
     * What every front-end template falls back to when the theme names no layout of its own
     */
    const LAYOUT = Dpress::VIEW_NAMESPACE.':layout';

    const CONFIG_SITE_NAME = 'dpress.site_name';
    const CONFIG_REGISTRATION_OPEN = 'dpress.registration_open';

    public function __construct(
        protected ViewInterface $view,
        protected RouterInterface $router,
        protected RequestInterface $request,
        protected ConfigInterface $config,
        protected JwtAuthInterface $jwtAuth,
    ) {}

    /**
     * The application, for redirecting and finishing
     *
     * Taken from the container rather than injected: `WebApp` is the running application, not a
     * service, and asking for it in a constructor would make every controller un-instantiable
     * outside a request.
     */
    protected function app(): WebApp {
        return Micro::app();
    }

    protected function currentUser(): ?DpressUser {
        $user = $this->jwtAuth->user();
        return $user instanceof DpressUser ? $user : null;
    }

    protected function isLoggedIn(): bool {
        return $this->jwtAuth->user() !== null;
    }

    /**
     * Settings win over the config, so an editor can change these while the site runs
     */
    protected function siteName(): string {
        return (string)Micro::get(SettingService::class)->get(Setting::SITE_NAME, 'dpress');
    }

    /**
     * The site's tagline, or ''
     *
     * A setting since settings existed, editable on the settings screen since that screen existed,
     * and reachable by no template until a theme wanted to print it under the site's name.
     */
    protected function siteDescription(): string {
        return trim((string)Micro::get(SettingService::class)->get(Setting::SITE_DESCRIPTION, ''));
    }

    protected function registrationOpen(): bool {
        return Micro::get(SettingService::class)->getBool(Setting::REGISTRATION_OPEN, false);
    }

    /**
     * The logo shown instead of the site's name, as a URL, or '' when there is none
     */
    protected function siteLogo(): string {
        return $this->brandingAsset(Setting::SITE_LOGO, Setting::CONFIG_DEFAULT_LOGO);
    }

    /**
     * The icon in the browser's tab, as a URL, or '' when there is none
     */
    protected function siteIcon(): string {
        return $this->brandingAsset(Setting::SITE_ICON, Setting::CONFIG_DEFAULT_ICON);
    }

    /**
     * A chosen library item, or the configured default when there is not one
     *
     * **The fallback is what makes choosing from the library safe.** A logo is chrome: it renders
     * on pages with no content on them, before anything has been uploaded, and somebody deleting
     * a file has to not be able to take the header down. Missing, deleted, purged and never-set
     * all arrive here the same way and leave by the same door.
     *
     * Soft-deleted counts as gone. An item in the library's bin is one somebody has said they do
     * not want, and going on showing it in the header until it is purged would be the surprise.
     */
    protected function brandingAsset(string $setting, string $configKey): string {
        $default = $this->siteAsset((string)$this->config->get($configKey, ''));
        $id = (int)Micro::get(SettingService::class)->get($setting, 0);
        if ($id <= 0) {
            return $default;
        }
        $media = Micro::get(MediaService::class)->findById($id);
        if ($media === null || $media->isDeleted()) {
            return $default;
        }
        return Micro::get(MediaView::class)->url($media);
    }

    /**
     * A setting that names a file, as a URL
     *
     * `/static/logo.svg` is resolved against `app.base_url`, so the value survives the site moving
     * out of a subfolder onto a domain of its own - which is exactly the move that would otherwise
     * silently break every stored absolute URL.
     *
     * Anything that already carries a scheme is left alone: another host, `//`, a `data:` URI. It
     * is a setting, so only somebody who may change settings can put anything there at all.
     */
    protected function siteAsset(string $value): string {
        $value = trim($value);
        if ($value === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $value) === 1) {
            return $value;
        }
        return rtrim((string)$this->config->get('app.base_url', ''), '/').'/'.ltrim($value, '/');
    }

    /**
     * Renders a template with the variables every page needs
     *
     * **Shortcodes are expanded over the finished page**, not over `body_html` on the way to a
     * template. Content HTML reaches a template from five places - a post, a page, and three
     * listings - and a theme may render any of them from a template of its own. Expanding at each
     * source is five chances to forget and one more for every theme; expanding here is one call
     * that nothing can miss.
     *
     * A marker is an HTML comment and can only have come from the markdown renderer, because raw
     * HTML never survives a document. So there is nothing else in a page this can match.
     *
     * A page with no shortcode in it pays for one `str_contains` - see `Shortcodes`.
     */
    protected function render(string $template, array $variables = [], string $kind = ''): string {
        $this->view->set('layout', $this->layoutFor($kind));
        $this->view->set('layout_kind', $kind);
        $this->view->set('theme', Micro::get(ThemeAssets::class));
        $this->view->set('dates', Micro::get(Dates::class));
        // The prefix a post URL is built from, because where a post lives is a setting and a
        // template has no way to read one. `route_url($post_path.$row['slug'])` in a listing,
        // and a theme that forgets it still works - the other shape answers with a 301 - it
        // just makes every link on the page a redirect.
        $this->view->set('post_path', Micro::get(ContentService::class)->postsAtRoot() ? '/' : '/post/');

        $this->view->set('current_user', $this->currentUser());
        $this->view->set('site_name', $this->siteName());
        $this->view->set('site_description', $this->siteDescription());
        $this->view->set('site_logo', $this->siteLogo());
        $this->view->set('site_icon', $this->siteIcon());
        $this->view->set('registration_open', $this->registrationOpen());
        $this->view->set('main_menu', $this->menu('main'));
        // the layout's way to fill a place with what a site put there. A variable, so a template
        // still looks up nothing itself, and lazy, so a theme that renders no places reads nothing
        $this->view->set('places', Micro::get(Places::class));
        $html = Micro::get(Shortcodes::class)->expand($this->view->fetch($template, $variables));
        return $this->withCodeAssets($html);
    }

    /**
     * The picture for each row of a listing, keyed by the row's id
     *
     * A listing row is an **array** and carries `featured_media_id`, not the item itself - so a
     * theme that wants a card with a picture on it has the id and nothing to do with it. This is
     * the missing half, in **one query for the whole page**: `$thumbnails[$post['id']]`, or
     * nothing, which is what a template should ask anyway since a post need not have a picture.
     *
     * Given to `content/list` by every listing, so a theme's own list template gets it too.
     *
     * @param array[] $rows listing rows, as `findAll()` answers with
     * @return array<int, \Dynart\Dpress\Entity\Media> keyed by **content** id, not media id
     */
    protected function thumbnails(array $rows): array {
        return $this->mapThumbnails(
            $rows, Micro::get(MediaService::class)->findByIds(array_column($rows, 'featured_media_id'))
        );
    }

    /**
     * Which row gets which picture, once the pictures have been fetched
     *
     * Split from the fetch so the mapping can be tested without a database behind it - and the
     * mapping is where the two interesting cases live: a post with no picture and a post whose
     * picture has been deleted both come out **absent**, so `isset()` is the whole of the check a
     * template writes.
     *
     * @param array<int, \Dynart\Dpress\Entity\Media> $media keyed by media id
     * @return array<int, \Dynart\Dpress\Entity\Media> keyed by content id
     */
    protected function mapThumbnails(array $rows, array $media): array {
        $found = [];
        foreach ($rows as $row) {
            $id = $row['featured_media_id'] ?? null;
            if ($id !== null && isset($media[(int)$id])) {
                $found[(int)$row['id']] = $media[(int)$id];
            }
        }
        return $found;
    }

    /**
     * Who wrote each row of a listing, keyed by the row's id
     *
     * The other half of `thumbnails()`, and the same bargain: a row carries `author_id` and not a
     * name, so *"by gopher"* under a title was not something a template could write. **One query
     * for the page** - twenty posts by three people is three names.
     *
     * A name rather than the user, because that is all a byline is, and handing a template a
     * `User` hands it an email address and a password hash to print by accident. An account
     * deleted since is simply absent, which a template asks about the same way it asks about a
     * missing picture.
     *
     * @param array[] $rows listing rows, as `findAll()` answers with
     * @return array<int, string> keyed by content id
     */
    protected function authors(array $rows): array {
        $users = Micro::get(UserService::class)->findByIds(array_column($rows, 'author_id'));
        $found = [];
        foreach ($rows as $row) {
            $id = $row['author_id'] ?? null;
            if ($id !== null && isset($users[(int)$id])) {
                $found[(int)$row['id']] = $users[(int)$id]->name;
            }
        }
        return $found;
    }

    /**
     * The one author of one piece of content, or '' - the single post's half of `authors()`
     */
    protected function authorOf(Content $content): string {
        if ($content->author_id === null) {
            return '';
        }
        $user = Micro::get(UserService::class)->findById((int)$content->author_id);
        return $user === null ? '' : $user->name;
    }

    /**
     * The layout for a kind of page, if the theme has one, and the ordinary layout if it has not
     *
     * A front page and a post being read are not the same document: one is a wide list of things
     * to open, the other is a column of prose with what belongs beside it. So a theme may write
     * `layout-home.phtml` next to its `layout.phtml`, and a template renders through whichever it
     * gets - the name is a **variable** in the template rather than a literal, which is the whole
     * change. Nine templates used to say `dpress:layout` out loud, so a theme wanting a second
     * layout had to override all nine to alter one string.
     *
     * **A theme adds a layout by dropping the file in**, the same promise a theme itself makes and
     * a plugin after it: `View::exists()` resolves through the theme folder first, so a kind with
     * no file behind it quietly comes back to the one layout. That is what makes it free to name
     * more kinds than any theme uses - `archive`, `page` and `auth` cost a theme nothing until it
     * writes one.
     *
     * The kind reaches a template too, as `$layout_kind`, because a theme that wants one layout
     * and two shapes of it wants a class on the body rather than a second file.
     *
     * A kind becomes part of a path, so it is matched rather than trusted. Controllers are where
     * kinds come from, but a plugin ships controllers too.
     */
    protected function layoutFor(string $kind): string {
        if (preg_match('/^[a-z0-9_-]+$/', $kind) !== 1) {
            return self::LAYOUT;
        }
        $named = self::LAYOUT.'-'.$kind;
        return $this->view->exists($named) ? $named : self::LAYOUT;
    }

    /**
     * Puts the highlighter into a page that has code on it, and into no other
     *
     * After the page is built rather than before, because whether it has code in it is a fact
     * about the finished HTML - a listing shows leads, a post shows a body, and a theme decides
     * which. The alternative is a view variable every layout has to remember to print.
     *
     * `</head>` is where it goes; a page whose layout has none gets nothing, and gets it silently,
     * because a front end without a `<head>` is somebody rendering a fragment on purpose.
     */
    protected function withCodeAssets(string $html): string {
        $assets = Micro::get(CodeAssets::class);
        if (!$assets->needed($html)) {
            return $html;
        }
        $at = stripos($html, '</head>');
        return $at === false ? $html : substr_replace($html, $assets->tags()."
", $at, 0);
    }

    /**
     * Renders a menu place, or nothing when no menu is assigned to it
     *
     * Rendered here rather than in the layout so a template stays free of service lookups. The
     * work itself is `Places`, which is also what a theme reaches a place through - one
     * implementation, so the header and a sidebar cannot disagree about what `main` means.
     */
    protected function menu(string $place): string {
        return Micro::get(Places::class)->menu($place);
    }

    /**
     * The page of a body this request asked for, and the way to the next one
     *
     * A body written with more than one `---` is served a page at a time. The variables come back
     * ready for a template: the HTML of this page, where it is in the sequence, and the two URLs -
     * empty at each end, so a template asks `if ($prev_url)` rather than doing arithmetic.
     *
     * **A page number that does not exist is a 404**, not the first page. `?page=7` of a
     * three-page post names something that is not there, which is what the status code is for -
     * and clamping instead would let a crawler index the same post at every number there is.
     *
     * The lead is on page one only: it is the opening of the article, not a header repeated
     * above every part of it.
     */
    protected function pagedBody(Content $content, string $route, array $extra = []): array {
        $pages = ContentPages::split($content->body_html);
        $count = count($pages);
        $number = (int)$this->request->get(self::PAGE_PARAM, 1);
        if ($number < 1 || $number > $count) {
            $this->app()->sendError(404);
        }
        return [
            'body_html'  => $pages[$number - 1],
            'page'       => $number,
            'page_count' => $count,
            'show_lead'  => $number === 1,
            'prev_url'   => $number > 1 ? $this->pageUrl($route, $number - 1, $extra) : '',
            'next_url'   => $number < $count ? $this->pageUrl($route, $number + 1, $extra) : '',
            // every page's address, so a theme can print `1 2 3 4 5` rather than only two arrows.
            // Built here because the route is the controller's - a template has no way to make one
            'page_urls'  => array_map(
                fn(int $n): string => $this->pageUrl($route, $n, $extra), range(1, max(1, $count))
            ),
        ];
    }

    /**
     * Page one is the post's own address, with nothing appended
     *
     * Two URLs for the same page - `/post/x` and `/post/x?page=1` - is two of everything for
     * search engines and for anybody sharing a link.
     */
    /**
     * @param array $extra query parameters every page of this route has to keep - the preview
     *                     carries the token that says which unsaved draft is being looked at
     */
    protected function pageUrl(string $route, int $number, array $extra = []): string {
        $params = $number > 1 ? $extra + [self::PAGE_PARAM => $number] : $extra;
        return $this->router->url($route, $params);
    }

    protected function message(string $title, string $message, array $link = []): string {
        return $this->render('dpress:auth/message', [
            'title'   => $title,
            'message' => $message,
            'link'    => $link,
        ], 'auth');
    }
}
