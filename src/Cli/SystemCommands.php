<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\DpressCliApp;

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
        $width = 0;
        foreach (array_keys(DpressCliApp::COMMANDS) as $name) {
            $width = max($width, strlen($name));
        }
        foreach (DpressCliApp::COMMANDS as $name => $command) {
            $this->output->setColor(CliOutput::CYAN);
            $this->output->write('  '.str_pad($name, $width + 2));
            $this->output->setColor(null);
            $this->output->writeLine($command['description']);
        }
        $this->output->writeLine('');
        $this->output->writeLine('Options:');
        $this->output->setColor(CliOutput::CYAN);
        $this->output->write('  '.str_pad('-config <path>', $width + 2));
        $this->output->setColor(null);
        $this->output->writeLine('Use this config file instead of searching for '.Dpress::CONFIG_FILE_NAME);
        return 0;
    }
}
