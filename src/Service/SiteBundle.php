<?php

namespace Dynart\Dpress\Service;

use Dynart\Dpress\Dpress;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Micro\AbstractApp;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\Entities\Database;
use Throwable;

/**
 * A site's content, as a folder you can move
 *
 * The three things that have to travel together and are kept in three different places: the rows,
 * the uploaded files, and the handful of facts that decide whether the other two mean anything on
 * the other end. A database dump alone is the common way to move a site and it is the one that
 * loses the pictures and points every link at the old domain.
 *
 * **Data, not schema.** The target builds its own tables from its own migrations and this loads
 * rows into them, which is the opposite way round from `mysqldump` and better for the reason
 * `install` is safe to repeat: the schema that ends up on the new server is the one *that server's
 * dpress* believes in, rather than a snapshot of the old one's. It also means no DDL, no
 * collation clauses and no storage-engine lines to go stale in a file.
 *
 * **JSON, not SQL.** Rows go back in through prepared statements, so there is no escaping to get
 * wrong, nothing that can be a SQL injection, and no dependence on `mysqldump` being installed and
 * on `PATH` - which on the Windows machine this is written on it is not.
 *
 * **A folder, not an archive.** `tar` and `zip` both already exist and neither needs to be a PHP
 * extension this package requires. `dpress export -to ./bundle && tar czf bundle.tgz bundle` is
 * one more word than an archive built here, and it is one fewer dependency forever.
 *
 * **`dpress.ini` is not in it, deliberately.** It holds the database password and the JWT secret,
 * and every value in it describes the machine rather than the site. A bundle is something you
 * copy to a server, and a bundle with secrets in it is a secret on a server it does not belong to.
 */
class SiteBundle {

    const MANIFEST = 'site.json';
    const DATA_DIR = 'data';
    const UPLOADS_DIR = 'uploads';

    /**
     * What a bundle is not for
     *
     * `migration_history` is the target's own record: the schema on the new server was applied by
     * that server, so its history is a true account of what it did, and overwriting it with the
     * old machine's would replace a fact with a story about a different computer.
     *
     * The other three are **session state, not content**, and they are excluded for the reason
     * `RefreshToken` and `UserToken` are not audited: they hold credentials and are short-lived,
     * so copying them into something durable is the mistake. A bundle is as durable as it gets -
     * it is a folder somebody tars up, copies to a server and keeps as a backup - and a live
     * session from the machine a site was written on has no business travelling with the posts.
     * Everyone signing in again on the new server is the correct outcome, not a shortcoming;
     * `auth_attempt` is a sliding window that means nothing an hour later anywhere.
     */
    const SKIP_TABLES = ['migration_history', 'refresh_token', 'user_token', 'auth_attempt'];

    /** Rows per INSERT. Big enough that a long table is not a thousand round trips */
    const CHUNK = 200;

    public function __construct(
        protected ConfigInterface $config,
        protected Database $db,
        protected SchemaService $schema,
        protected SettingService $settings,
        protected MediaStorage $storage,
    ) {}

    // --- export ---

    /**
     * @return array a summary: tables, rows, files, bytes and the manifest
     * @throws DpressException
     */
    public function export(string $to): array {
        $to = rtrim(str_replace('\\', '/', $to), '/');
        if ($to === '') {
            throw new DpressException('Where to? Pass -to with a folder to write.');
        }
        if (is_dir($to) && !$this->isEmptyDir($to)) {
            throw new DpressException($to.' already has something in it. Pick an empty folder.');
        }
        $this->makeDir($to.'/'.self::DATA_DIR);

        $tables = [];
        $rows = 0;
        foreach ($this->tables() as $table) {
            $data = $this->db->fetchAll('select * from '.$this->db->escapeName($table));
            $this->write($to.'/'.self::DATA_DIR.'/'.$table.'.json', $data);
            $tables[$table] = count($data);
            $rows += count($data);
        }

        $uploads = $this->copyTree($this->storage->basePath(), $to.'/'.self::UPLOADS_DIR);

        $manifest = [
            'dpress' => Dpress::VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'base_url' => $this->baseUrl(),
            'content_rendered_for' => (string)$this->reading(
                fn() => $this->settings->get(Setting::CONTENT_RENDERED_FOR, ''), ''
            ),
            'theme' => (string)$this->reading(fn() => $this->settings->get(Setting::THEME, ''), ''),
            'plugins' => $this->enabledPlugins(),
            'tables' => $tables,
            'uploads' => $uploads,
        ];
        $this->write($to.'/'.self::MANIFEST, $manifest);

        return ['path' => $to, 'rows' => $rows, 'manifest' => $manifest];
    }

    // --- import ---

    /**
     * Replaces this site's content with a bundle's
     *
     * @return array a summary: what was loaded, and whether the address moved
     * @throws DpressException
     */
    public function import(string $from, bool $force = false): array {
        $from = rtrim(str_replace('\\', '/', $from), '/');
        $manifest = $this->readManifest($from);

        $version = (string)($manifest['dpress'] ?? '');
        if ($version !== Dpress::VERSION && !$force) {
            throw new DpressException(
                'The bundle is dpress '.($version === '' ? 'unknown' : $version).' and this site is '
                .Dpress::VERSION.'. Rows are loaded into the schema this site\'s migrations built, so '
                .'a column added since the export takes its default and one removed is dropped. '
                .'Upgrade the other side to match, or pass -force if you know the difference is safe.'
            );
        }

        // Idempotent, and the reason import needs no separate install step: the tables have to
        // exist before rows can go into them, and `install` applies whatever is pending.
        $this->schema->install();

        $loaded = [];
        $skipped = [];
        $mine = $this->tables();
        $this->foreignKeysOff(function () use ($from, $mine, &$loaded, &$skipped) {
            foreach ($this->bundleTables($from) as $table => $path) {
                if (!in_array($table, $mine, true)) {
                    // A table this dpress does not have - a plugin's, most likely, that is not
                    // installed here. Reported rather than dropped in silence.
                    $skipped[] = $table;
                    continue;
                }
                $loaded[$table] = $this->load($table, $path);
            }
            return null;
        });

        $files = $this->copyTree($from.'/'.self::UPLOADS_DIR, $this->storage->basePath());

        // The whole reason a bundle carries a manifest. Stored HTML holds absolute URLs, so a
        // site restored under a new address points at the old one until this runs - and nothing
        // on the site says so. Doing it here rather than telling somebody to is the difference
        // between a step that can be forgotten and one that cannot.
        $wasFor = (string)($manifest['base_url'] ?? '');
        $moved = rtrim($wasFor, '/') !== $this->baseUrl();

        return [
            'manifest' => $manifest,
            'loaded' => $loaded,
            'skipped' => $skipped,
            'files' => $files['files'],
            'bytes' => $files['bytes'],
            'moved' => $moved,
            'from_url' => $wasFor,
            'to_url' => $this->baseUrl(),
        ];
    }

    /**
     * Called by the command once the rerender has run, so the site's claim about itself is only
     * made when it is true
     */
    public function recordRenderAddress(): void {
        $this->settings->set(Setting::CONTENT_RENDERED_FOR, $this->baseUrl());
    }

    // --- the pieces ---

    /**
     * Every table this site has, by prefix, in the order the database lists them
     *
     * Read from the database rather than from the entity registry so a **plugin's own table** is
     * carried too. A bundle that quietly left one behind would be a plugin's data lost on every
     * move, discovered much later.
     */
    public function tables(): array {
        $prefix = (string)$this->db->configValue('table_prefix');
        $found = [];
        foreach ($this->db->fetchColumn('show tables') as $name) {
            $name = (string)$name;
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }
            if (in_array(substr($name, strlen($prefix)), self::SKIP_TABLES, true)) {
                continue;
            }
            $found[] = $name;
        }
        sort($found);
        return $found;
    }

    /**
     * @return array<string, string> table => the json file holding its rows
     */
    protected function bundleTables(string $from): array {
        $dir = $from.'/'.self::DATA_DIR;
        if (!is_dir($dir)) {
            throw new DpressException('No '.self::DATA_DIR.'/ in '.$from.'. Is that a bundle?');
        }
        $tables = [];
        foreach ((array)scandir($dir) as $entry) {
            if (str_ends_with((string)$entry, '.json')) {
                $tables[substr((string)$entry, 0, -5)] = $dir.'/'.$entry;
            }
        }
        ksort($tables);
        return $tables;
    }

    /**
     * Replaces one table's rows
     *
     * **Only the columns both sides have.** A bundle from a version with a column this schema
     * lost would fail the insert outright, and one missing a column this schema gained would
     * fail it too - so the intersection is taken and the rest take their defaults. That is what
     * makes `-force` across a version a usable answer rather than a wish.
     */
    protected function load(string $table, string $path): int {
        $rows = json_decode((string)file_get_contents($path), true);
        if (!is_array($rows)) {
            throw new DpressException($path.' is not readable as JSON.');
        }
        $this->db->query('delete from '.$this->db->escapeName($table));
        if (empty($rows)) {
            return 0;
        }
        $columns = array_intersect(array_keys((array)$rows[0]), $this->columnsOf($table));
        if (empty($columns)) {
            throw new DpressException($table.' and its bundle file have no columns in common.');
        }
        $names = join(', ', array_map(fn($c) => $this->db->escapeName($c), $columns));
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $placeholders = [];
            $values = [];
            foreach ($chunk as $row) {
                $placeholders[] = '('.join(', ', array_fill(0, count($columns), '?')).')';
                foreach ($columns as $column) {
                    $values[] = $row[$column] ?? null;
                }
            }
            $this->db->query(
                'insert into '.$this->db->escapeName($table).' ('.$names.') values '.join(', ', $placeholders),
                $values
            );
        }
        return count($rows);
    }

    protected function columnsOf(string $table): array {
        $columns = [];
        foreach ($this->db->fetchAll('describe '.$this->db->escapeName($table)) as $row) {
            $columns[] = (string)($row['Field'] ?? '');
        }
        return $columns;
    }

    /**
     * Loads with the foreign keys off, and puts them back whatever happens
     *
     * The alternative is working out an order that inserts every parent before its children, and
     * that order is a fact about the schema that would have to be maintained alongside it - wrong
     * the first time somebody adds a table. Turning the checks off for a restore is what a restore
     * does; the data being loaded was consistent when it left.
     */
    protected function foreignKeysOff(callable $work): mixed {
        $this->db->query('set foreign_key_checks = 0');
        try {
            return $work();
        } finally {
            $this->db->query('set foreign_key_checks = 1');
        }
    }

    protected function enabledPlugins(): array {
        $value = (string)$this->reading(fn() => $this->settings->get(Setting::PLUGINS, ''), '');
        $names = array_map('trim', explode(',', $value));
        return array_values(array_filter($names, fn($name) => $name !== ''));
    }

    protected function readManifest(string $from): array {
        $path = $from.'/'.self::MANIFEST;
        if (!is_file($path)) {
            throw new DpressException('No '.self::MANIFEST.' in '.$from.'. Is that a bundle?');
        }
        $manifest = json_decode((string)file_get_contents($path), true);
        if (!is_array($manifest)) {
            throw new DpressException($path.' is not readable as JSON.');
        }
        return $manifest;
    }

    // --- files ---

    /**
     * @return array{files: int, bytes: int}
     */
    protected function copyTree(string $from, string $to): array {
        $result = ['files' => 0, 'bytes' => 0];
        if (!is_dir($from)) {
            return $result;
        }
        $this->makeDir($to);
        foreach ((array)scandir($from) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = $from.'/'.$entry;
            $target = $to.'/'.$entry;
            if (is_dir($source)) {
                $inner = $this->copyTree($source, $target);
                $result['files'] += $inner['files'];
                $result['bytes'] += $inner['bytes'];
                continue;
            }
            if (!copy($source, $target)) {
                throw new DpressException('Could not copy '.$source);
            }
            $result['files']++;
            $result['bytes'] += (int)filesize($source);
        }
        return $result;
    }

    protected function isEmptyDir(string $path): bool {
        foreach ((array)scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }
        return true;
    }

    protected function makeDir(string $path): void {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new DpressException('Could not create '.$path);
        }
    }

    protected function write(string $path, mixed $value): void {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new DpressException('Could not encode '.$path.': '.json_last_error_msg());
        }
        if (file_put_contents($path, $json) === false) {
            throw new DpressException('Could not write '.$path);
        }
    }

    protected function baseUrl(): string {
        return rtrim((string)$this->config->get(AbstractApp::CONFIG_BASE_URL, ''), '/');
    }

    protected function reading(callable $read, mixed $default): mixed {
        try {
            return $read();
        } catch (Throwable $e) {
            return $default;
        }
    }
}
