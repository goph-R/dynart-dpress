<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\MigrationHistory;
use Dynart\Micro\Entities\Migrations;
use Dynart\Micro\Entities\QueryExecutor;

/**
 * Installing and upgrading the database schema
 *
 * Sits between the migration runner and whatever drives it - the CLI now, a web installer later -
 * so neither of those has to know how the schema is put together.
 */
class SchemaService {

    public function __construct(
        protected ConfigInterface $config,
        protected Database $db,
        protected Migrations $migrations,
        protected QueryExecutor $queryExecutor,
    ) {}

    /**
     * Is there a database configured at all?
     *
     * Checked before connecting so a missing or half written config gives a clear message
     * instead of a PDO exception.
     */
    public function isConfigured(): bool {
        $dsn = $this->config->get('database.default.dsn');
        $name = $this->config->get('database.default.name');
        return !empty($dsn) && !empty($name);
    }

    /**
     * Tries to reach the database
     *
     * Returns the reason it failed, or an empty string when the connection works. One method
     * rather than a `canConnect()` / `connectionError()` pair, so a caller that wants both
     * cannot end up connecting twice.
     */
    public function connectionError(): string {
        try {
            $this->db->query('select 1');
            return '';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Has the schema been installed?
     */
    public function isInstalled(): bool {
        return $this->queryExecutor->isTableExist(MigrationHistory::class);
    }

    /**
     * Creates the schema from scratch
     *
     * @return string[] The versions that were applied
     */
    public function install(): array {
        return $this->migrations->run();
    }


    /**
     * Applies whatever is pending
     *
     * The same operation as `install()` - a fresh database simply has everything pending. They
     * are separate commands because they answer different questions for the person running them.
     *
     * @return string[] The versions that were applied
     */
    public function upgrade(): array {
        return $this->migrations->run();
    }

    /**
     * @return string[]
     */
    public function appliedVersions(): array {
        return $this->migrations->appliedVersions();
    }

    /**
     * @return string[]
     */
    public function pendingVersions(): array {
        $result = [];
        foreach ($this->migrations->pending() as $migration) {
            $result[] = $migration->version();
        }
        return $result;
    }

    public function databaseName(): string {
        return (string)$this->config->get('database.default.name');
    }
}
