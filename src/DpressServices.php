<?php

namespace Dynart\Dpress;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\FormWidgets;
use Dynart\Micro\JwtAuth;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\View;
use Dynart\Micro\ViewInterface;
use Dynart\Micro\Request;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\Router;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\Session;
use Dynart\Micro\SessionInterface;
use Dynart\Micro\Translation;
use Dynart\Micro\TranslationInterface;
use Dynart\Micro\Entities\AuditService;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\Database\MariaDatabase;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Migrations;
use Dynart\Micro\Entities\PdoBuilder;
use Dynart\Micro\Entities\QueryBuilder;
use Dynart\Micro\Entities\QueryBuilder\MariaQueryBuilder;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Cli\SchemaCommands;
use Dynart\Dpress\Cli\SystemCommands;
use Dynart\Dpress\Cli\UserCommands;
use Dynart\Dpress\Content\Autolinks;
use Dynart\Dpress\Content\Callouts;
use Dynart\Dpress\Content\CodeAssets;
use Dynart\Dpress\Content\CodeBlockRenderer;
use Dynart\Dpress\Content\InternalLinks;
use Dynart\Dpress\Content\LinkTargetResolverInterface;
use Dynart\Dpress\Content\LinkTargets;
use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Content\Shortcode\BreakShortcode;
use Dynart\Dpress\Content\Shortcode\IconShortcode;
use Dynart\Dpress\Content\Shortcode\VideoShortcode;
use Dynart\Dpress\Content\ShortcodeRenderer;
use Dynart\Dpress\Block\Blocks;
use Dynart\Dpress\Block\CategoryListBlock;
use Dynart\Dpress\Block\KofiBlock;
use Dynart\Dpress\Block\MarkdownBlock;
use Dynart\Dpress\Block\TagCloudBlock;
use Dynart\Dpress\Content\Shortcodes;
use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Content\TreeOrder;
use Dynart\Dpress\Entity\AuthAttempt;
use Dynart\Dpress\Entity\Block;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\ContentAttachment;
use Dynart\Dpress\Entity\ContentCategory;
use Dynart\Dpress\Entity\ContentTag;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Entity\Menu;
use Dynart\Dpress\Entity\MenuItem;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Entity\Tag;
use Dynart\Dpress\Entity\RefreshToken;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\RolePermission;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Entity\UserRole;
use Dynart\Dpress\Entity\UserToken;
use Dynart\Dpress\Cli\ContentCommands;
use Dynart\Dpress\Cli\MediaCommands;
use Dynart\Dpress\Cli\TaxonomyCommands;
use Dynart\Dpress\Cli\PluginCommands;
use Dynart\Dpress\Cli\ThemeCommands;
use Dynart\Dpress\Cli\MailCommands;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\CoreForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Middleware\TokenRefresher;
use Dynart\Dpress\Security\AuthCookies;
use Dynart\Dpress\Mail\LogMailer;
use Dynart\Dpress\Mail\MailerInterface;
use Dynart\Dpress\Mail\NativeMailer;
use Dynart\Dpress\Migration\CreateSchema;
use Dynart\Dpress\Plugin\PluginService;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Query\QueryFactory;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Security\RateLimiter;
use Dynart\Dpress\Security\PasswordHasher;
use Dynart\Dpress\Service\AuthService;
use Dynart\Dpress\Service\ContentHistoryService;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Media\MediaTypes;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Media\SvgSanitizer;
use Dynart\Dpress\Media\SvgSanitizerInterface;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\BlockService;
use Dynart\Dpress\Service\MenuService;
use Dynart\Dpress\Service\SettingService;
use Dynart\Dpress\Service\TaxonomyService;
use Dynart\Dpress\Theme\Places;
use Dynart\Dpress\Theme\ThemeService;
use Dynart\Dpress\Theme\ThemeAssets;
use Dynart\Dpress\Content\Dates;
use Dynart\Dpress\Service\RoleService;
use Dynart\Dpress\Service\SchemaService;
use Dynart\Dpress\Service\UserService;

/**
 * The service and migration registry shared by every kind of dpress application
 *
 * `CliApp` and `WebApp` have no common ancestor in the framework, so the wiring both of them
 * need lives here rather than in a base class.
 */
class DpressServices {

    /**
     * The core migrations
     *
     * One, until 1.0: the schema was squashed while no installation held anything anybody minds
     * losing. The runner sorts by version anyway, so a plugin adds its own and they interleave.
     */
    const MIGRATIONS = [
        CreateSchema::class,
    ];

    /** The entities the CMS provides, registered explicitly rather than by a namespace scan */
    const ENTITIES = [
        User::class,
        Role::class,
        UserRole::class,
        RolePermission::class,
        RefreshToken::class,
        UserToken::class,
        Content::class,
        Media::class,
        Category::class,
        Tag::class,
        ContentCategory::class,
        ContentTag::class,
        ContentAttachment::class,
        Setting::class,
        Menu::class,
        MenuItem::class,
        Block::class,
        AuthAttempt::class,
    ];

    /**
     * Registers everything the CMS needs in the DI container
     *
     * `Micro` resolves constructor dependencies by reflection but only for classes it knows
     * about, so every one of these has to be registered even where the interface and the
     * implementation are the same class.
     */
    public static function register(): void {
        self::registerDatabase();
        self::registerServices();
    }

    /**
     * The entity layer, with the MariaDB implementations bound to their abstractions
     */
    public static function registerDatabase(): void {
        Micro::add(PdoBuilder::class);
        Micro::add(Database::class, MariaDatabase::class);
        Micro::add(EntityManager::class);
        Micro::add(QueryBuilder::class, MariaQueryBuilder::class);
        Micro::add(QueryExecutor::class);
        Micro::add(AuditService::class);
        Micro::add(Migrations::class);
    }

    /**
     * The CMS services and the CLI command holders
     */
    /** Short names accepted by the `mail.mailer` config, next to a full class name */
    const MAILERS = [
        'log'    => LogMailer::class,
        'native' => NativeMailer::class,
    ];

    public static function registerServices(): void {
        Micro::add(TranslationInterface::class, Translation::class);
        Micro::add(ViewInterface::class, View::class);
        // the request and the router are needed by the CLI too, because a menu item stores what
        // it points at rather than a URL, so listing a menu has to build one
        Micro::add(RequestInterface::class, Request::class);
        Micro::add(RouterInterface::class, Router::class);
        Micro::add(JwtAuthInterface::class, JwtAuth::class);
        Micro::add(QueryFactory::class);
        Micro::add(CoreQueries::class);
        Micro::add(ListRequest::class);
        Micro::add(Permissions::class);
        Micro::add(PasswordHasher::class);
        Micro::add(RateLimiter::class);
        Micro::add(SchemaService::class);
        Micro::add(RoleService::class);
        Micro::add(UserService::class);
        Micro::add(AuthService::class);
        Micro::add(MarkdownRenderer::class);
        Micro::add(LinkTargetResolverInterface::class, LinkTargets::class);
        Micro::add(InternalLinks::class);
        Micro::add(Shortcodes::class);
        Micro::add(Callouts::class);
        Micro::add(Autolinks::class);
        Micro::add(CodeAssets::class);
        Micro::add(CodeBlockRenderer::class);
        Micro::add(ShortcodeRenderer::class);
        Micro::add(VideoShortcode::class);
        Micro::add(IconShortcode::class);
        Micro::add(BreakShortcode::class);
        Micro::add(Slugger::class);
        Micro::add(TreeOrder::class);
        Micro::add(ContentService::class);
        Micro::add(ContentHistoryService::class);
        Micro::add(MediaTypes::class);
        Micro::add(MediaStorage::class);
        Micro::add(ImageProcessor::class);
        Micro::add(MediaView::class);
        Micro::add(SvgSanitizerInterface::class, SvgSanitizer::class);
        Micro::add(MediaService::class);
        Micro::add(TaxonomyService::class);
        Micro::add(SettingService::class);
        Micro::add(ThemeService::class);
        Micro::add(ThemeAssets::class);
        Micro::add(Dates::class);
        Micro::add(PluginService::class);
        Micro::add(MenuService::class);
        Micro::add(Blocks::class);
        Micro::add(BlockService::class);
        Micro::add(Places::class);
        Micro::add(TagCloudBlock::class);
        Micro::add(CategoryListBlock::class);
        Micro::add(MarkdownBlock::class);
        Micro::add(KofiBlock::class);
        Micro::add(SchemaCommands::class);
        Micro::add(SystemCommands::class);
        Micro::add(UserCommands::class);
        Micro::add(MailCommands::class);
        Micro::add(ContentCommands::class);
        Micro::add(MediaCommands::class);
        Micro::add(TaxonomyCommands::class);
        Micro::add(ThemeCommands::class);
        Micro::add(PluginCommands::class);
        self::registerContentEvents();
    }

    /**
     * What listens to what
     *
     * A Micro callable rather than a closure, and that is the whole point: `EventService` runs it
     * through the container **when the event fires**, so `InternalLinks` and the four services
     * behind it are built only when something actually renders markdown. On a page view that is
     * never - the HTML was written at save time - and the renderer stays a class that knows
     * nothing about media, posts or categories.
     */
    public static function registerContentEvents(): void {
        $events = Micro::get(EventServiceInterface::class);
        $events->subscribe(MarkdownRenderer::EVENT_ENVIRONMENT, [InternalLinks::class, 'onEnvironment']);
        $events->subscribe(MarkdownRenderer::EVENT_ENVIRONMENT, [ShortcodeRenderer::class, 'onEnvironment']);
        $events->subscribe(MarkdownRenderer::EVENT_ENVIRONMENT, [CodeBlockRenderer::class, 'onEnvironment']);
        $events->subscribe(MarkdownRenderer::EVENT_ENVIRONMENT, [Callouts::class, 'onEnvironment']);
        $events->subscribe(MarkdownRenderer::EVENT_ENVIRONMENT, [Autolinks::class, 'onEnvironment']);
    }

    /**
     * Picks the mailer from `mail.mailer`
     *
     * Defaults to the one that only logs, so a development site can walk through a password
     * reset without an SMTP server - and never accidentally mails a real address while somebody
     * is testing with production data.
     */
    public static function registerMailer(ConfigInterface $config): void {
        $name = (string)$config->get('mail.mailer', 'log');
        Micro::add(MailerInterface::class, self::MAILERS[$name] ?? $name);
    }

    /**
     * Adds the CMS view and translation folders
     *
     * Both live inside the package rather than under the site root, so a theme can override any
     * of them through the view's usual lookup.
     */
    /**
     * The field types the CMS adds to the framework's seven
     *
     * Registered through exactly the call a plugin uses. A mechanism the core does not eat is a
     * mechanism nobody has tested - and this one has to work for somebody else's code, because
     * that is the whole point of it.
     */
    const WIDGETS = [
        'markdown'    => Dpress::VIEW_NAMESPACE.':widget/markdown',
        'media'       => Dpress::VIEW_NAMESPACE.':widget/media',
        'checkboxes'  => Dpress::VIEW_NAMESPACE.':widget/checkboxes',
        'permissions' => Dpress::VIEW_NAMESPACE.':widget/permissions',
    ];

    /**
     * The shortcodes the CMS provides
     *
     * Registered through exactly the call a plugin uses, for the reason the widgets are: a
     * mechanism the core does not eat is a mechanism nobody has tested.
     */
    const SHORTCODES = [
        'video' => [[VideoShortcode::class, 'render'], Shortcodes::BLOCK],
        'icon'  => [[IconShortcode::class, 'render'], Shortcodes::INLINE],
        'br'    => [[BreakShortcode::class, 'render'], Shortcodes::INLINE],
    ];

    /**
     * The kinds of block the CMS provides
     *
     * Through exactly the call a plugin uses, for the reason the widgets and the shortcodes are:
     * a mechanism the core does not eat is a mechanism nobody has tested.
     *
     * `fields` is the type's own settings, as form fields - which is what keeps the block editor
     * from being a template that branches on `type`, the mistake `FormWidgets` was built to take
     * out of form rendering. `prepare` is the save-time hook, and only the markdown block wants
     * one: it renders there so a page view never parses markdown.
     */
    const BLOCKS = [
        'tag_cloud' => [
            'title'  => 'Tag cloud',
            'render' => [TagCloudBlock::class, 'render'],
            'fields' => [
                'limit' => ['type' => 'text', 'label' => 'How many tags', 'required' => false,
                            'description' => 'The most used ones. Empty or 0 shows every tag.'],
            ],
        ],
        'category_list' => [
            'title'  => 'Category list',
            'render' => [CategoryListBlock::class, 'render'],
        ],
        'markdown' => [
            'title'   => 'Markdown',
            'render'  => [MarkdownBlock::class, 'render'],
            'prepare' => [MarkdownBlock::class, 'prepare'],
            'fields'  => [
                'markdown' => ['type' => 'markdown', 'label' => 'Markdown', 'required' => false],
            ],
        ],
        'kofi' => [
            'title'  => 'Ko-fi button',
            'render' => [KofiBlock::class, 'render'],
            'fields' => [
                'page'  => ['type' => 'text', 'label' => 'Page name or ID',
                            'description' => 'The bit after ko-fi.com in the address:'
                                .' for ko-fi.com/supportkofi, enter supportkofi. The whole'
                                .' address is accepted too.'],
                'text'  => ['type' => 'text', 'label' => 'Button text', 'required' => false,
                            'description' => 'It goes on the button, so keep it short.'
                                .' Empty is "'.KofiBlock::DEFAULT_TEXT.'".'],
                'color' => ['type' => 'text', 'label' => 'Button colour', 'required' => false,
                            'description' => 'A hex value, like '.KofiBlock::DEFAULT_COLOR.','
                                .' which is what an empty box means. The text on it turns black'
                                .' or white by itself, whichever can be read.'],
                'description' => ['type' => 'textarea', 'label' => 'Description', 'required' => false,
                            'description' => 'A line above the button. Optional.'],
            ],
        ],
    ];

    public static function registerBlocks(Blocks $blocks): void {
        foreach (self::BLOCKS as $type => $definition) {
            $blocks->add($type, $definition);
        }
    }

    public static function registerShortcodes(Shortcodes $shortcodes): void {
        foreach (self::SHORTCODES as $name => [$handler, $kind]) {
            $shortcodes->add($name, $handler, $kind);
        }
    }

    public static function registerWidgets(FormWidgets $widgets): void {
        foreach (self::WIDGETS as $type => $view) {
            $widgets->add($type, $view);
        }
    }

    public static function registerViews(ViewInterface $view, TranslationInterface $translation): void {
        $view->addFolder(Dpress::VIEW_NAMESPACE, Dpress::viewsPath());
        // not themeable: a theme is for the site's pages, and the admin is not one of them
        $view->addFolder(Dpress::ADMIN_VIEW_NAMESPACE, Dpress::viewsPath().'/admin', false);
        $translation->add(Dpress::TRANSLATION_NAMESPACE, Dpress::translationsPath());
        // replaces the framework's folder for its own namespace, so the built in form messages
        // read like something meant for a visitor rather than a developer
        $translation->add(Translation::NAMESPACE_MICRO, Dpress::translationsPath().'/micro');
    }

    /**
     * Registers the CMS entities with the entity manager
     *
     * The application's namespace scan covers its own entities; these come from the CMS package,
     * so they are registered explicitly. Also needed by the CLI, which runs no attribute
     * processor at all.
     */
    public static function registerEntities(EntityManager $em): void {
        foreach (self::ENTITIES as $className) {
            $em->registerEntity($className);
        }
    }

    /**
     * The parts that only make sense while serving a request
     *
     * Kept out of `register()` so the CLI never touches `Session`, which would start a PHP
     * session for a command that has no business having one. `FormFactory` needs both the
     * request and the session, so it lives here too.
     */
    public static function registerWeb(): void {
        Micro::add(SessionInterface::class, Session::class);
        Micro::add(FormFactory::class);
        Micro::add(CoreForms::class);
        Micro::add(AdminForms::class);
        Micro::add(AuthCookies::class);
        Micro::add(TokenRefresher::class);
    }

    /**
     * Adds the core migrations to the runner
     */
    public static function addMigrations(Migrations $migrations): void {
        foreach (self::MIGRATIONS as $className) {
            $migrations->add($className);
        }
    }
}
