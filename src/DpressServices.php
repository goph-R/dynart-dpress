<?php

namespace Dynart\Dpress;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuth;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\View;
use Dynart\Micro\ViewInterface;
use Dynart\Micro\Request;
use Dynart\Micro\RequestInterface;
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
use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\ContentAttachment;
use Dynart\Dpress\Entity\ContentCategory;
use Dynart\Dpress\Entity\ContentTag;
use Dynart\Dpress\Entity\Media;
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
use Dynart\Dpress\Cli\MailCommands;
use Dynart\Dpress\Form\CoreForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Middleware\TokenRefresher;
use Dynart\Dpress\Security\AuthCookies;
use Dynart\Dpress\Mail\LogMailer;
use Dynart\Dpress\Mail\MailerInterface;
use Dynart\Dpress\Mail\NativeMailer;
use Dynart\Dpress\Migration\CreateContentTables;
use Dynart\Dpress\Migration\CreateMediaTables;
use Dynart\Dpress\Migration\CreateTaxonomyTables;
use Dynart\Dpress\Migration\CreateIdentityTables;
use Dynart\Dpress\Migration\CreateRevisionTable;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Dpress\Query\QueryFactory;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Security\PasswordHasher;
use Dynart\Dpress\Service\AuthService;
use Dynart\Dpress\Service\ContentHistoryService;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Media\MediaTypes;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\TaxonomyService;
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
     * The core migrations, in the order they were introduced
     *
     * The runner sorts by version anyway, so a plugin can add its own and they interleave.
     */
    const MIGRATIONS = [
        CreateRevisionTable::class,
        CreateIdentityTables::class,
        CreateMediaTables::class,
        CreateContentTables::class,
        CreateTaxonomyTables::class,
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
        Micro::add(JwtAuthInterface::class, JwtAuth::class);
        Micro::add(QueryFactory::class);
        Micro::add(CoreQueries::class);
        Micro::add(Permissions::class);
        Micro::add(PasswordHasher::class);
        Micro::add(SchemaService::class);
        Micro::add(RoleService::class);
        Micro::add(UserService::class);
        Micro::add(AuthService::class);
        Micro::add(MarkdownRenderer::class);
        Micro::add(Slugger::class);
        Micro::add(ContentService::class);
        Micro::add(ContentHistoryService::class);
        Micro::add(MediaTypes::class);
        Micro::add(MediaStorage::class);
        Micro::add(ImageProcessor::class);
        Micro::add(MediaView::class);
        Micro::add(MediaService::class);
        Micro::add(TaxonomyService::class);
        Micro::add(SchemaCommands::class);
        Micro::add(SystemCommands::class);
        Micro::add(UserCommands::class);
        Micro::add(MailCommands::class);
        Micro::add(ContentCommands::class);
        Micro::add(MediaCommands::class);
        Micro::add(TaxonomyCommands::class);
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
    public static function registerViews(ViewInterface $view, TranslationInterface $translation): void {
        $view->addFolder(Dpress::VIEW_NAMESPACE, Dpress::viewsPath());
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
        Micro::add(RequestInterface::class, Request::class);
        Micro::add(SessionInterface::class, Session::class);
        Micro::add(FormFactory::class);
        Micro::add(CoreForms::class);
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
