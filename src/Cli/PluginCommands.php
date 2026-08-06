<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Plugin\Plugin;
use Dynart\Dpress\Plugin\PluginService;

/**
 * `plugin:list`, `plugin:enable`, `plugin:disable`
 *
 * Thin, like the theme commands: everything that decides anything is in `PluginService`, so the
 * admin screen and these agree by construction rather than by both being careful.
 */
class PluginCommands extends AbstractCommands {

    public function __construct(
        CliOutputInterface $output,
        protected PluginService $plugins,
    ) {
        parent::__construct($output);
    }

    /**
     * What is installed, what is on, and what went wrong
     *
     * The failures are the reason this command matters: a plugin that throws on the way up is
     * skipped so the site stays reachable, which means the only evidence is here and in the log.
     */
    public function listPlugins(array $params = []): int {
        $plugins = $this->plugins->all();
        $enabled = $this->plugins->enabledNames();
        $this->output->writeLine('Plugins in '.$this->plugins->path().':');
        if (empty($plugins) && empty($enabled)) {
            $this->output->writeLine('  none - a plugin is a folder with a '.PluginService::MANIFEST.' in it');
            return 0;
        }
        // load() has run during boot, so a failure already knows it is one
        $this->plugins->load();
        foreach ($this->plugins->all() as $name => $plugin) {
            $this->writePlugin($name, $plugin);
        }
        if ($this->plugins->isOff()) {
            $this->output->writeLine(PluginService::CONFIG_OFF.' is set, so none of them were loaded.');
        }
        return 0;
    }

    protected function writePlugin(string $name, Plugin $plugin): void {
        $this->output->setColor($this->colorOf($plugin));
        $mark = $plugin->status === Plugin::STATUS_AVAILABLE ? '  ' : '* ';
        $this->output->write('  '.str_pad($mark.$name, 22));
        $this->output->setColor(null);
        $this->output->write(str_pad($plugin->version(), 10));
        $this->output->writeLine($plugin->status);
        if ($plugin->error !== '') {
            $this->output->writeLine('      '.$plugin->error);
        }
    }

    protected function colorOf(Plugin $plugin): ?int {
        return match ($plugin->status) {
            Plugin::STATUS_ENABLED => CliOutput::GREEN,
            Plugin::STATUS_FAILED, Plugin::STATUS_MISSING => CliOutput::RED,
            default => CliOutput::CYAN,
        };
    }

    public function enable(array $params = []): int {
        $name = $this->param($params, 'name');
        try {
            $this->plugins->enable($name);
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success(
            "'$name' is enabled. Run `dpress upgrade` if it brings tables of its own."
        );
    }

    public function disable(array $params = []): int {
        $name = $this->param($params, 'name');
        $this->plugins->disable($name);
        return $this->success("'$name' is disabled.");
    }
}
