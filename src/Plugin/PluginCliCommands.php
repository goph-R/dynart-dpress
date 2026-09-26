<?php

namespace Dynart\Dpress\Plugin;

use Dynart\Micro\LoggerInterface;

/**
 * The `dpress` commands the enabled plugins add
 *
 * The core's are a constant, `DpressCliApp::COMMANDS`, which is what lets `bin/dpress` ask whether
 * a command needs a config before anything has started. A plugin's cannot be in it, so they are
 * collected here while the plugins load and added to the command line after - the Docs plugin's
 * `docs:build` is the first, which is work that belongs in a deploy or a cron job and not only
 * behind a button.
 *
 * A command is the core's shape:
 *
 * ```php
 * 'docs:build' => [
 *     'callable'    => [DocsCommands::class, 'build'],
 *     'description' => 'Build the documentation from its source folder',
 *     'params'      => ['source'],
 *     'flags'       => ['quiet'],
 * ]
 * ```
 *
 * Plugins load only when there is a site - a config and a database to read the enabled list from -
 * so a plugin's command run outside one is an unknown command, answered with the help.
 */
class PluginCliCommands {

    /** @var array<string, array> name => command, with `plugin` - which one added it */
    private array $commands = [];

    public function __construct(protected LoggerInterface $logger) {}

    /**
     * @param string[] $taken the names already in use - the core's - which a plugin cannot take
     */
    public function add(string $name, array $command, string $plugin, array $taken = []): void {
        $name = trim($name);
        $callable = $command['callable'] ?? null;
        if ($name === '' || !is_array($callable) || count($callable) !== 2) {
            $this->logger->warning("Dpress: the plugin '$plugin' offers a command without a name or a [class, method] callable.");
            return;
        }
        // `dpress upgrade` answering with a plugin's code instead of the core's is not a thing
        // a plugin gets to do by naming its own the same - the first to claim a name keeps it
        if (in_array($name, $taken, true) || isset($this->commands[$name])) {
            $this->logger->warning("Dpress: the command '$name' from the plugin '$plugin' is already taken, and was left out.");
            return;
        }
        $this->commands[$name] = [
            'callable'    => $callable,
            'description' => (string)($command['description'] ?? ''),
            'params'      => array_values(array_map('strval', (array)($command['params'] ?? []))),
            'flags'       => array_values(array_map('strval', (array)($command['flags'] ?? []))),
            'plugin'      => $plugin,
        ];
    }

    public function has(string $name): bool {
        return isset($this->commands[$name]);
    }

    /**
     * @return array<string, array> name => command, in the order the plugins added them
     */
    public function all(): array {
        return $this->commands;
    }
}
