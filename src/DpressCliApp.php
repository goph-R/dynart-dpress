<?php

namespace Dynart\Dpress;

use Dynart\Micro\CliApp;
use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\LoggerInterface;
use Dynart\Micro\FormWidgets;
use Dynart\Micro\Micro;
use Dynart\Micro\TranslationInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Micro\Entities\AuditService;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Migrations;
use Dynart\Dpress\Cli\ContentCommands;
use Dynart\Dpress\Cli\MailCommands;
use Dynart\Dpress\Cli\MediaCommands;
use Dynart\Dpress\Cli\TaxonomyCommands;
use Dynart\Dpress\Cli\PluginCommands;
use Dynart\Dpress\Cli\ThemeCommands;
use Dynart\Dpress\Cli\SchemaCommands;
use Dynart\Dpress\Cli\SystemCommands;
use Dynart\Dpress\Cli\UserCommands;
use Dynart\Dpress\Plugin\PluginService;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Dpress\Query\QueryFactory;

/**
 * The `dpress` command line application
 */
class DpressCliApp extends CliApp {

    /**
     * Every command, its callable, its description and its parameters
     *
     * Kept as data rather than as `add()` calls so `dpress help` can list them without a second
     * registry to keep in sync.
     */
    const COMMANDS = [
        'install' => [
            'callable' => [SchemaCommands::class, 'install'],
            'description' => 'Create the database schema and apply every migration',
            'needsConfig' => true,
        ],
        'upgrade' => [
            'callable' => [SchemaCommands::class, 'upgrade'],
            'description' => 'Apply the pending migrations',
            'needsConfig' => true,
        ],
        'migrate:status' => [
            'callable' => [SchemaCommands::class, 'status'],
            'description' => 'List the applied and the pending migrations',
            'needsConfig' => true,
        ],
        'user:create' => [
            'callable' => [UserCommands::class, 'create'],
            'description' => 'Create a user',
            'params' => ['email', 'password', 'name', 'role'],
            'needsConfig' => true,
        ],
        'user:password' => [
            'callable' => [UserCommands::class, 'password'],
            'description' => 'Change a password, generating one when none is given',
            'params' => ['email', 'password'],
            'needsConfig' => true,
        ],
        'user:list' => [
            'callable' => [UserCommands::class, 'listUsers'],
            'description' => 'List the users',
            'params' => ['status', 'search'],
            'needsConfig' => true,
        ],
        'user:status' => [
            'callable' => [UserCommands::class, 'status'],
            'description' => 'Set a user status: active, pending or blocked',
            'params' => ['email', 'status'],
            'needsConfig' => true,
        ],
        'user:role' => [
            'callable' => [UserCommands::class, 'role'],
            'description' => 'Grant a role, or revoke it with -revoke',
            'params' => ['email', 'role'],
            'flags' => ['revoke'],
            'needsConfig' => true,
        ],
        'role:list' => [
            'callable' => [UserCommands::class, 'listRoles'],
            'description' => 'List the roles and their permissions',
            'needsConfig' => true,
        ],
        'content:create' => [
            'callable' => [ContentCommands::class, 'create'],
            'description' => 'Create a post or a page',
            'params' => ['title', 'author', 'type', 'slug', 'file', 'markdown'],
            'flags' => ['publish'],
            'needsConfig' => true,
        ],
        'content:list' => [
            'callable' => [ContentCommands::class, 'list'],
            'description' => 'List the content',
            'params' => ['type', 'status', 'search'],
            'needsConfig' => true,
        ],
        'content:publish' => [
            'callable' => [ContentCommands::class, 'publish'],
            'description' => 'Publish content, or take it back to draft with -unpublish',
            'params' => ['id'],
            'flags' => ['unpublish'],
            'needsConfig' => true,
        ],
        'content:delete' => [
            'callable' => [ContentCommands::class, 'delete'],
            'description' => 'Delete content, keeping its history',
            'params' => ['id'],
            'needsConfig' => true,
        ],
        'content:history' => [
            'callable' => [ContentCommands::class, 'history'],
            'description' => 'Show the revision history of a piece of content',
            'params' => ['id'],
            'needsConfig' => true,
        ],
        'content:rerender' => [
            'callable' => [ContentCommands::class, 'rerender'],
            'description' => 'Re-render every markdown body, after a rendering change',
            'needsConfig' => true,
        ],
        'media:import' => [
            'callable' => [MediaCommands::class, 'import'],
            'description' => 'Import a file into the media library',
            'params' => ['file', 'user', 'alt', 'title', 'caption'],
            'needsConfig' => true,
        ],
        'media:list' => [
            'callable' => [MediaCommands::class, 'list'],
            'description' => 'List the media library',
            'params' => ['category', 'search'],
            'flags' => ['deleted'],
            'needsConfig' => true,
        ],
        'media:delete' => [
            'callable' => [MediaCommands::class, 'delete'],
            'description' => 'Mark media deleted, or bring it back with -restore',
            'params' => ['id'],
            'flags' => ['restore'],
            'needsConfig' => true,
        ],
        'media:purge' => [
            'callable' => [MediaCommands::class, 'purge'],
            'description' => 'Delete the file itself, breaking every revision that shows it',
            'params' => ['id'],
            'flags' => ['confirm'],
            'needsConfig' => true,
        ],
        'media:sanitize' => [
            'callable' => [MediaCommands::class, 'sanitize'],
            'description' => 'Re-sanitise SVGs stored before the sanitiser existed',
            'params' => ['id'],
            'flags' => ['confirm'],
            'needsConfig' => true,
        ],
        'media:protect' => [
            'callable' => [MediaCommands::class, 'protect'],
            'description' => 'Rewrite the uploads .htaccess that stops uploads being executed',
            'needsConfig' => true,
        ],
        'media:regenerate' => [
            'callable' => [MediaCommands::class, 'regenerate'],
            'description' => 'Clear the generated thumbnails so they are rebuilt on demand',
            'params' => ['id'],
            'needsConfig' => true,
        ],
        'taxonomy:list' => [
            'callable' => [TaxonomyCommands::class, 'list'],
            'description' => 'List the categories and the tags',
            'needsConfig' => true,
        ],
        'plugin:list' => [
            'callable' => [PluginCommands::class, 'listPlugins'],
            'description' => 'List the installed plugins and what went wrong with any of them',
            'needsConfig' => true,
        ],
        'plugin:enable' => [
            'callable' => [PluginCommands::class, 'enable'],
            'description' => 'Turn a plugin on',
            'params' => ['name'],
            'needsConfig' => true,
        ],
        'plugin:disable' => [
            'callable' => [PluginCommands::class, 'disable'],
            'description' => 'Turn a plugin off',
            'params' => ['name'],
            'needsConfig' => true,
        ],
        'theme:list' => [
            'callable' => [ThemeCommands::class, 'listThemes'],
            'description' => 'List the installed themes',
            'needsConfig' => true,
        ],
        'theme:set' => [
            'callable' => [ThemeCommands::class, 'setTheme'],
            'description' => 'Switch the theme, or go back to the built in templates',
            'params' => ['name'],
            'needsConfig' => true,
        ],
        'menu:list' => [
            'callable' => [ThemeCommands::class, 'listMenus'],
            'description' => 'List the menus, their places and their items',
            'needsConfig' => true,
        ],
        'setting:list' => [
            'callable' => [ThemeCommands::class, 'listSettings'],
            'description' => 'List the settings and where each value comes from',
            'needsConfig' => true,
        ],
        'setting:set' => [
            'callable' => [ThemeCommands::class, 'setSetting'],
            'description' => 'Change a setting',
            'params' => ['name', 'value'],
            'needsConfig' => true,
        ],
        'mail:test' => [
            'callable' => [MailCommands::class, 'test'],
            'description' => 'Render a test mail, and send it unless -render is given',
            'params' => ['email'],
            'flags' => ['render'],
            'needsConfig' => true,
        ],
        'version' => [
            'callable' => [SystemCommands::class, 'version'],
            'description' => 'Print the dpress version',
            'needsConfig' => false,
        ],
        'help' => [
            'callable' => [SystemCommands::class, 'help'],
            'description' => 'Print this help',
            'needsConfig' => false,
        ],
    ];

    /** The parameters every command accepts */
    const COMMON_PARAMS = ['config'];

    /**
     * Does this command need a config file to be found before the application starts?
     *
     * An unknown command needs none: it has to reach the application so it can answer with the
     * help, rather than complain about a missing config the user was never asked for.
     */
    public static function commandNeedsConfig(?string $name): bool {
        if ($name === null || !isset(self::COMMANDS[$name])) {
            return false;
        }
        return self::COMMANDS[$name]['needsConfig'];
    }

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
        DpressServices::register();
        DpressServices::registerMailer(Micro::get(ConfigInterface::class));
        $this->addCommands();
        // the same point as the web app's, and it has to happen on this path too: a plugin's
        // tables are built by `dpress upgrade`, which never runs a web request
        Micro::get(PluginService::class)->load();
        $this->initServices();
    }

    protected function addCommands(): void {
        foreach (self::COMMANDS as $name => $command) {
            $this->commands->add(
                $name,
                $command['callable'],
                array_merge($command['params'] ?? [], self::COMMON_PARAMS),
                $command['flags'] ?? []
            );
        }
    }

    /**
     * Instantiates the services that have to subscribe to events before anything runs
     */
    protected function initServices(): void {
        DpressServices::registerViews(
            Micro::get(ViewInterface::class),
            Micro::get(TranslationInterface::class)
        );
        DpressServices::registerWidgets(Micro::get(FormWidgets::class));
        // there is no attribute processor in a CLI run, so the entities are registered by hand
        DpressServices::registerEntities(Micro::get(EntityManager::class));
        DpressServices::addMigrations(Micro::get(Migrations::class));
        CoreQueries::register(Micro::get(QueryFactory::class));
        // constructing it wires the audit subscriptions, so a CLI change is recorded too
        $audit = Micro::get(AuditService::class);
        $audit->subscribeAll();
    }

    /**
     * Falls back to the help instead of the framework's "Unknown command" error
     */
    public function process(): void {
        $name = $this->commands->current();
        if ($name === null) {
            $this->finish(Micro::get(SystemCommands::class)->help());
            return;
        }
        if (!$this->commands->has($name)) {
            /** @var CliOutputInterface $output */
            $output = Micro::get(CliOutputInterface::class);
            $output->setColor(CliOutput::RED);
            $output->writeLine('Unknown command: '.$name);
            $output->setColor(null);
            $output->writeLine('');
            Micro::get(SystemCommands::class)->help();
            $this->finish(1);
            return;
        }
        parent::process();
    }
}
