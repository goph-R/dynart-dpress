<?php

namespace Dynart\Dpress;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\LoggerInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\TranslationInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Micro\WebApp;
use Dynart\Micro\Middleware\JwtCookieReader;
use Dynart\Micro\Middleware\JwtValidator;
use Dynart\Micro\Middleware\LocaleResolver;
use Dynart\Micro\Entities\AuditService;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Migrations;
use Dynart\Dpress\Controller\Admin\AssetController;
use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Controller\Admin\DashboardController;
use Dynart\Dpress\Controller\Admin\MediaAdminController;
use Dynart\Dpress\Controller\Admin\MenuAdminController;
use Dynart\Dpress\Controller\Admin\RoleAdminController;
use Dynart\Dpress\Controller\Admin\SettingsAdminController;
use Dynart\Dpress\Controller\Admin\TaxonomyAdminController;
use Dynart\Dpress\Controller\Admin\UserAdminController;
use Dynart\Dpress\Controller\AuthController;
use Dynart\Dpress\Controller\ContentController;
use Dynart\Dpress\Controller\HomeController;
use Dynart\Dpress\Controller\MediaController;
use Dynart\Dpress\Controller\PageController;
use Dynart\Dpress\Controller\ProfileController;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\CoreForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Middleware\TokenRefresher;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Dpress\Query\QueryFactory;
use Dynart\Dpress\Service\AuthService;
use Dynart\Dpress\Theme\ThemeService;

/**
 * The dpress web application
 */
class DpressWebApp extends WebApp {

    /** Where the theme lives, relative to the site root */
    const CONFIG_THEME = 'dpress.theme';

    /**
     * The controllers the CMS provides
     *
     * `PageController` is last because it holds the catch-all route - though the router matches
     * every exact and segment route before any catch-all anyway, so the order here is
     * documentation rather than something the routing depends on.
     */
    const CONTROLLERS = [
        HomeController::class,
        AuthController::class,
        ProfileController::class,
        ContentController::class,
        MediaController::class,
        AssetController::class,
        DashboardController::class,
        ContentAdminController::class,
        MediaAdminController::class,
        TaxonomyAdminController::class,
        MenuAdminController::class,
        UserAdminController::class,
        RoleAdminController::class,
        SettingsAdminController::class,
        PageController::class,
    ];

    /**
     * The middleware order that makes cookie based login work
     *
     * `JwtCookieReader` lifts the access token out of its cookie into the Authorization header,
     * `TokenRefresher` renews it from the refresh cookie when it has aged out, and only then
     * does `JwtValidator` decode whatever ended up in the header.
     */
    const PRIORITY_JWT_COOKIE_READER = 40;
    const PRIORITY_TOKEN_REFRESHER = 45;

    /**
     * Swaps the logger before `fullInit()` builds it
     *
     * The framework's default log directory is relative, so it lands in the working directory -
     * which for a web request is the document root. A log file in the document root is a URL.
     */
    public function __construct(array $configPaths) {
        parent::__construct($configPaths);
        Micro::add(LoggerInterface::class, DpressLogger::class);
    }

    public function init(): void {
        parent::init();
        $this->useJwtAuth();
        $this->addMiddleware(JwtCookieReader::class, self::PRIORITY_JWT_COOKIE_READER);
        $this->addMiddleware(TokenRefresher::class, self::PRIORITY_TOKEN_REFRESHER);

        DpressServices::register();
        DpressServices::registerWeb();
        DpressServices::registerMailer(Micro::get(ConfigInterface::class));
        $this->registerControllers();
        $this->initServices();
    }

    /**
     * Registers the CMS controllers so the attribute processor finds their `#[Route]`s
     */
    protected function registerControllers(): void {
        foreach (self::CONTROLLERS as $className) {
            Micro::add($className);
        }
    }

    protected function initServices(): void {
        $view = Micro::get(ViewInterface::class);
        DpressServices::registerViews($view, Micro::get(TranslationInterface::class));
        $this->applyTheme($view);

        DpressServices::registerEntities(Micro::get(EntityManager::class));
        DpressServices::addMigrations(Micro::get(Migrations::class));
        CoreQueries::register(Micro::get(QueryFactory::class));
        $formFactory = Micro::get(FormFactory::class);
        CoreForms::register($formFactory);
        AdminForms::register($formFactory);

        // the resolver turns a decoded token back into a DpressUser
        Micro::get(AuthService::class);

        $audit = Micro::get(AuditService::class);
        $audit->subscribeAll();
        $this->setAuditUser($audit);
    }

    /**
     * Points the view at the active theme
     *
     * The theme is a setting rather than a config value, so this needs the database - which is
     * why it runs here rather than in the constructor.
     */
    protected function applyTheme(ViewInterface $view): void {
        Micro::get(ThemeService::class)->apply();
    }

    /**
     * Tells the audit who is making the changes
     *
     * Subscribed rather than read here, because the user is only known once the token has been
     * validated, which happens in a middleware that has not run yet at this point.
     */
    protected function setAuditUser(AuditService $audit): void {
        $this->eventService->subscribe(\Dynart\Micro\JwtAuth::EVENT_USER_SET, function($user) use ($audit) {
            $audit->setUserId($user->sub());
        });
    }
}
