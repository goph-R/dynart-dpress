<?php

namespace Dynart\Dpress;

use Dynart\Micro\Micro;
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
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Migration\CreateRevisionTable;
use Dynart\Dpress\Query\QueryFactory;
use Dynart\Dpress\Service\SchemaService;

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
    public static function registerServices(): void {
        Micro::add(TranslationInterface::class, Translation::class);
        Micro::add(QueryFactory::class);
        Micro::add(SchemaService::class);
        Micro::add(SchemaCommands::class);
        Micro::add(SystemCommands::class);
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
