<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\Service\SchemaService;

/**
 * The install / upgrade commands
 */
class SchemaCommands extends AbstractCommands {

    public function __construct(
        CliOutputInterface $output,
        protected SchemaService $schema,
    ) {
        parent::__construct($output);
    }

    /**
     * Installing is applying every migration, so it is safe to repeat
     *
     * It deliberately does not refuse when the migration history table already exists: that is
     * exactly the state a failed migration leaves behind, and refusing would strand the site
     * half installed with no way forward.
     */
    public function install(): int {
        if (($error = $this->checkDatabase()) !== 0) {
            return $error;
        }
        $wasInstalled = $this->schema->isInstalled();
        $this->output->writeLine('Installing into database `'.$this->schema->databaseName().'`');
        $applied = $this->schema->install();
        if (empty($applied)) {
            $this->output->writeLine($wasInstalled ? 'Already up to date.' : 'Nothing to install.');
            return 0;
        }
        $this->reportApplied($applied);
        $this->success('Installed.');
        return 0;
    }

    public function upgrade(): int {
        if (($error = $this->checkDatabase()) !== 0) {
            return $error;
        }
        if (!$this->schema->isInstalled()) {
            $this->fail('Not installed yet. Run `dpress install` first.');
            return 1;
        }
        $applied = $this->schema->upgrade();
        if (empty($applied)) {
            $this->output->writeLine('Already up to date.');
            return 0;
        }
        $this->reportApplied($applied);
        $this->success('Upgraded.');
        return 0;
    }

    public function status(): int {
        if (($error = $this->checkDatabase()) !== 0) {
            return $error;
        }
        if (!$this->schema->isInstalled()) {
            $this->output->writeLine('Not installed. Run `dpress install`.');
            return 0;
        }
        $applied = $this->schema->appliedVersions();
        $pending = $this->schema->pendingVersions();
        $this->output->writeLine('Applied ('.count($applied).'):');
        foreach ($applied as $version) {
            $this->output->setColor(CliOutput::DARK_GREEN);
            $this->output->writeLine('  + '.$version);
            $this->output->setColor(null);
        }
        $this->output->writeLine('Pending ('.count($pending).'):');
        foreach ($pending as $version) {
            $this->output->setColor(CliOutput::DARK_YELLOW);
            $this->output->writeLine('  - '.$version);
            $this->output->setColor(null);
        }
        return 0;
    }

    /**
     * @return int 0 when the database is usable, otherwise the exit code to return
     */
    protected function checkDatabase(): int {
        if (!$this->schema->isConfigured()) {
            $this->fail('No database configured.');
            $this->output->writeLine('Set `database.default.dsn` and `database.default.name` in your dpress.ini.');
            return 1;
        }
        $connectionError = $this->schema->connectionError();
        if ($connectionError !== '') {
            $this->fail('Could not connect to the database.');
            $this->output->writeLine($connectionError);
            return 1;
        }
        $this->warnAboutCharset();
        return 0;
    }

    /**
     * Says so when the database cannot hold a four byte character
     *
     * A warning and not a refusal: a site that never writes an emoji works perfectly well on
     * `utf8`, and refusing to install would be this command inventing a requirement. But it is
     * the failure that gives no sign of itself - nothing errors, and `????` is what a post says
     * afterwards - so it has to be said out loud once, while somebody is looking at a terminal.
     */
    protected function warnAboutCharset(): void {
        $charset = $this->schema->charset();
        if ($charset === '' || $charset === SchemaService::CHARSET) {
            return;
        }
        $this->output->setColor(CliOutput::YELLOW);
        $this->output->writeLine('The database is `'.$charset.'`, which holds three bytes per character.');
        $this->output->setColor(null);
        $this->output->writeLine('An emoji is four, and it will be stored as `????` with no error.');
        $this->output->writeLine('');
        $this->output->writeLine('  alter database `'.$this->schema->databaseName().'`');
        $this->output->writeLine('    character set utf8mb4 collate utf8mb4_unicode_ci;');
        $this->output->writeLine('');
        $this->output->writeLine('Existing tables keep the old one until they are converted too:');
        $this->output->writeLine('  alter table `<name>` convert to character set utf8mb4 collate utf8mb4_unicode_ci;');
        $this->output->writeLine('');
    }

    protected function reportApplied(array $applied): void {
        foreach ($applied as $version) {
            $this->output->setColor(CliOutput::DARK_GREEN);
            $this->output->writeLine('  + '.$version);
            $this->output->setColor(null);
        }
    }

}
