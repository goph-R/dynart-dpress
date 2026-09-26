<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Micro\Micro;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\DpressCliApp;
use Dynart\Dpress\Plugin\PluginCliCommands;

/**
 * The commands that work without a database
 */
class SystemCommands {

    public function __construct(protected CliOutputInterface $output) {}

    public function version(): int {
        $this->output->writeLine('dpress '.Dpress::VERSION);
        return 0;
    }

    public function help(): int {
        $this->output->setColor(CliOutput::WHITE);
        $this->output->writeLine('dpress '.Dpress::VERSION);
        $this->output->setColor(null);
        $this->output->writeLine('');
        $this->output->writeLine('Usage: dpress <command> [options]');
        $this->output->writeLine('');
        $this->output->writeLine('Commands:');
        // the enabled plugins' too, under a heading of their own and each with whose it is - only
        // inside a site, since that is the only place plugins load
        $plugins = Micro::hasInterface(PluginCliCommands::class) ? Micro::get(PluginCliCommands::class)->all() : [];
        $width = 0;
        foreach (array_merge(array_keys(DpressCliApp::COMMANDS), array_keys($plugins)) as $name) {
            $width = max($width, strlen($name));
        }
        foreach (DpressCliApp::COMMANDS as $name => $command) {
            $this->writeCommand($name, $command['description'], $width);
        }
        if ($plugins !== []) {
            $this->output->writeLine('');
            $this->output->writeLine('Plugin commands:');
            foreach ($plugins as $name => $command) {
                $this->writeCommand($name, $command['description'].' ('.$command['plugin'].')', $width);
            }
        }
        $this->output->writeLine('');
        $this->output->writeLine('Options:');
        $this->output->setColor(CliOutput::CYAN);
        $this->output->write('  '.str_pad('-config <path>', $width + 2));
        $this->output->setColor(null);
        $this->output->writeLine('Use this config file instead of searching for '.Dpress::CONFIG_FILE_NAME);
        return 0;
    }

    protected function writeCommand(string $name, string $description, int $width): void {
        $this->output->setColor(CliOutput::CYAN);
        $this->output->write('  '.str_pad($name, $width + 2));
        $this->output->setColor(null);
        $this->output->writeLine($description);
    }
}
