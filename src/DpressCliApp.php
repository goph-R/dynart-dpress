<?php

namespace Dynart\Dpress;

use Dynart\Micro\CliApp;
use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\Entities\AuditService;
use Dynart\Micro\Entities\Migrations;
use Dynart\Dpress\Cli\SchemaCommands;
use Dynart\Dpress\Cli\SystemCommands;

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

    public function init(): void {
        parent::init();
        DpressServices::register();
        $this->addCommands();
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
        DpressServices::addMigrations(Micro::get(Migrations::class));
        // constructing it wires the audit subscriptions, so a CLI change is recorded too
        Micro::get(AuditService::class);
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
