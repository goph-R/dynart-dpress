<?php

namespace Dynart\Dpress\Cli;

use Dynart\Dpress\Service\Doctor;
use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;

/**
 * `dpress doctor`
 *
 * The colours and the layout; `Doctor` holds every judgement about what is wrong and what to do
 * about it. The split is what lets the checks be tested against a half-configured site with no
 * console, and it is why an admin screen showing the same list later would not be a second
 * implementation of any of it.
 */
class DoctorCommands extends AbstractCommands {

    public function __construct(
        CliOutputInterface $output,
        protected Doctor $doctor,
    ) {
        parent::__construct($output);
    }

    /**
     * `dpress doctor [-quiet]`
     *
     * **Exit code 1 when anything failed**, so a deploy script can end with this and stop. A
     * warning is not a failure: `utf8`, a development environment and an unrecorded render
     * address are all things a site can run with, and a check that fails a deploy over one would
     * be a check people learn to pass with `|| true`.
     */
    public function doctor(array $params = []): int {
        $checks = $this->doctor->run();
        $quiet = $this->flag($params, 'quiet');

        foreach ($checks as $check) {
            if ($quiet && $check['status'] === Doctor::OK) {
                continue;
            }
            $this->writeCheck($check);
        }

        $counts = $this->doctor->counts($checks);
        $this->output->writeLine('');
        if ($this->doctor->passed($checks)) {
            $summary = $counts[Doctor::WARN] === 0
                ? 'Everything checks out.'
                : $counts[Doctor::WARN].' warning(s), nothing broken.';
            return $this->success($summary);
        }
        return $this->fail($counts[Doctor::FAIL].' problem(s) to fix, '.$counts[Doctor::WARN].' warning(s).');
    }

    protected function writeCheck(array $check): void {
        $this->output->setColor($this->colorFor($check['status']));
        $this->output->write('  '.str_pad($this->markFor($check['status']), 4));
        $this->output->setColor(null);
        $this->output->write(str_pad($check['name'], 16));
        $this->output->writeLine($check['detail']);
        if ($check['fix'] !== '') {
            // Indented under the row it belongs to rather than collected at the end: a fix read
            // next to what it fixes needs no cross-referencing.
            $this->output->setColor(CliOutput::DARK_YELLOW);
            $this->output->writeLine('        '.$check['fix']);
            $this->output->setColor(null);
        }
    }

    protected function markFor(string $status): string {
        return match ($status) {
            Doctor::OK => 'ok',
            Doctor::WARN => '!',
            default => 'X',
        };
    }

    protected function colorFor(string $status): int {
        return match ($status) {
            Doctor::OK => CliOutput::DARK_GREEN,
            Doctor::WARN => CliOutput::YELLOW,
            default => CliOutput::RED,
        };
    }
}
