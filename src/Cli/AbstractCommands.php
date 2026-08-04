<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;

/**
 * What every command group needs
 */
abstract class AbstractCommands {

    public function __construct(protected CliOutputInterface $output) {}

    /**
     * Reads a parameter, treating an empty one as absent
     *
     * `CliCommands::matchCurrent()` pre-fills every declared parameter with an empty string, so
     * `$params['x'] ?? $default` never reaches the default - the key is always there. This is
     * the difference between "not given" and "given as empty", which for a CLI is the same
     * thing.
     */
    protected function param(array $params, string $name, string $default = ''): string {
        $value = trim((string)($params[$name] ?? ''));
        return $value !== '' ? $value : $default;
    }

    protected function flag(array $params, string $name): bool {
        return !empty($params[$name]);
    }

    protected function success(string $text): int {
        $this->output->setColor(CliOutput::GREEN);
        $this->output->writeLine($text);
        $this->output->setColor(null);
        return 0;
    }

    protected function fail(string $text): int {
        $this->output->setColor(CliOutput::RED);
        $this->output->writeLine($text);
        $this->output->setColor(null);
        return 1;
    }
}
