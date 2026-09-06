<?php

namespace Dynart\Dpress\Service;

use Dynart\Dpress\Dpress;
use Dynart\Dpress\DpressLogger;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Plugin\PluginService;
use Dynart\Dpress\Theme\ThemeService;
use Dynart\Micro\AbstractApp;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\Logger;
use Throwable;

/**
 * Everything an install or a move can get silently wrong, asked in one place
 *
 * The failures worth a command are the ones that **give no sign of themselves**. A site with a
 * broken database says so on the first page view; a site whose stored HTML still points at the
 * machine it was written on looks perfect on the front page and is wrong one click in. So the
 * rule for what belongs here is not "could this be misconfigured" but "would anybody find out".
 *
 * `SchemaCommands::checkDatabase()` was this in miniature - configured, connectable, the charset
 * warning - and this generalises it rather than inventing a second idea. The charset check is the
 * model for all of them: a warning nobody can act on is noise, so every row that is not `OK`
 * carries the thing to *do* about it.
 *
 * **A service returning rows, not a command printing them.** The judgement is which questions to
 * ask and what the answers mean; the colours are `DoctorCommands`. That is also what lets a test
 * assert on a failing site without a console, and what would let an admin screen show the same
 * list later without a second implementation drifting away from this one.
 */
class Doctor {

    const OK = 'ok';
    const WARN = 'warn';
    const FAIL = 'fail';

    /** The one dpress needs beyond what `composer.json` declares, because the DSN chooses it */
    const PDO_DRIVER_PREFIX = 'pdo_';

    public function __construct(
        protected ConfigInterface $config,
        protected SchemaService $schema,
        protected SettingService $settings,
        protected MediaStorage $storage,
        protected PluginService $plugins,
        protected ThemeService $themes,
    ) {}

    /**
     * @return array<array{name: string, status: string, detail: string, fix: string}>
     */
    public function run(): array {
        $checks = [
            $this->php(),
            $this->extensions(),
            $this->baseUrl(),
            $this->environment(),
            $this->secret(),
            $this->logDirectory(),
            $this->uploads(),
        ];

        $database = $this->database();
        $checks[] = $database;
        if ($database['status'] === self::FAIL) {
            // Everything below reads a table. Asking anyway would bury one real failure under
            // six consequences of it.
            return $checks;
        }
        $checks[] = $this->charset();

        $migrations = $this->migrations();
        $checks[] = $migrations;
        if (!$this->schema->isInstalled()) {
            return $checks;
        }
        $checks[] = $this->renderedFor();
        $checks[] = $this->plugins();
        $checks[] = $this->theme();
        return $checks;
    }

    /** True when nothing failed, which is what an exit code is made of */
    public function passed(array $checks): bool {
        foreach ($checks as $check) {
            if ($check['status'] === self::FAIL) {
                return false;
            }
        }
        return true;
    }

    public function counts(array $checks): array {
        $counts = [self::OK => 0, self::WARN => 0, self::FAIL => 0];
        foreach ($checks as $check) {
            $counts[$check['status']]++;
        }
        return $counts;
    }

    // --- the environment ---

    protected function php(): array {
        $required = '8.0';
        return version_compare(PHP_VERSION, $required, '>=')
            ? $this->ok('PHP', PHP_VERSION)
            : $this->fail('PHP', PHP_VERSION.' is below '.$required, 'Upgrade PHP to '.$required.' or newer.');
    }

    /**
     * The extensions `composer.json` declares, read from it rather than listed again
     *
     * A second list is a list that goes stale: a `ext-` requirement added to the manifest and not
     * here would be a dependency nothing checks, and one removed from the manifest and not here
     * would be this command asking for something the code stopped using.
     */
    protected function extensions(): array {
        $missing = [];
        foreach ($this->requiredExtensions() as $name) {
            if (!extension_loaded($name)) {
                $missing[] = $name;
            }
        }
        return empty($missing)
            ? $this->ok('Extensions', 'all present')
            : $this->fail('Extensions', 'missing: '.join(', ', $missing),
                'Install them, usually `php-'.$missing[0].'`, and restart PHP-FPM.');
    }

    /**
     * @return string[]
     */
    public function requiredExtensions(): array {
        $names = [];
        $manifest = Dpress::path('composer.json');
        if (is_file($manifest)) {
            $json = json_decode((string)file_get_contents($manifest), true);
            foreach (array_keys((array)($json['require'] ?? [])) as $package) {
                if (str_starts_with((string)$package, 'ext-')) {
                    $names[] = substr((string)$package, 4);
                }
            }
        }
        // The DSN picks the driver, so this one cannot come from a manifest: a site on MariaDB
        // needs `pdo_mysql` and the package cannot say that on every site's behalf.
        $dsn = (string)$this->config->get('database.default.dsn', '');
        $scheme = strtolower(trim(explode(':', $dsn, 2)[0] ?? ''));
        if ($scheme !== '') {
            $names[] = self::PDO_DRIVER_PREFIX.$scheme;
        }
        return array_values(array_unique($names));
    }

    // --- the config ---

    protected function baseUrl(): array {
        $url = (string)$this->config->get(AbstractApp::CONFIG_BASE_URL, '');
        if ($url === '') {
            return $this->fail('Base URL', 'not set',
                'Set `app.base_url` in dpress.ini to the address the site is reached at.');
        }
        if (str_ends_with($url, '/')) {
            return $this->warn('Base URL', $url.' ends with a slash',
                'Drop it: every generated URL appends its own, so links come out with `//` in them.');
        }
        if ($this->isProduction() && !str_starts_with(strtolower($url), 'https://')) {
            return $this->warn('Base URL', $url.' is not https',
                'A production site served over http makes every generated link mixed content.');
        }
        return $this->ok('Base URL', $url);
    }

    protected function environment(): array {
        $environment = $this->environmentName();
        return $this->isProduction()
            ? $this->ok('Environment', $environment)
            : $this->warn('Environment', $environment.' shows error detail to visitors',
                'Set `app.environment = prod` in dpress.ini on a public site.');
    }

    /**
     * The secret that signs every session on the site
     *
     * The placeholder is the one worth catching: `dpress.ini.example` ships the words "change me",
     * and a site copied from it and never edited signs its sessions with a value that is in a
     * public repository.
     */
    protected function secret(): array {
        $secret = (string)$this->config->get('jwt.secret', '');
        if ($secret === '') {
            return $this->fail('JWT secret', 'not set', $this->secretFix());
        }
        if (stripos($secret, 'change me') !== false) {
            return $this->fail('JWT secret', 'still the placeholder from dpress.ini.example',
                $this->secretFix());
        }
        if (strlen($secret) < 32) {
            return $this->fail('JWT secret', strlen($secret).' characters, HS256 needs 32',
                $this->secretFix());
        }
        return $this->ok('JWT secret', 'set, '.strlen($secret).' characters');
    }

    protected function secretFix(): string {
        return 'Generate one and put it in dpress.ini: php -r "echo bin2hex(random_bytes(32));"';
    }

    /**
     * A log directory inside the document root is a secret leak, not an error page
     *
     * A log holds stack traces, absolute paths and bound SQL parameters. Served, it is the whole
     * site's inside, and nothing about it looks wrong from the front page - which is why this is
     * a `fail` while most config problems here are warnings.
     */
    protected function logDirectory(): array {
        $configured = (string)$this->config->get(Logger::CONFIG_DIR, DpressLogger::DEFAULT_DIR);
        $path = $this->config->getFullPath($configured);

        // **Asked of the configured path, not of a directory that exists.** A site set to log
        // into `public/` that has not logged yet is the one moment this is still free to fix,
        // and `realpath()` on a missing directory answers `false` - so checking the resolved one
        // would stay quiet until the first stack trace was already being served.
        if ($this->isInside($path, $this->config->getFullPath('~/public'))) {
            return $this->fail('Log directory', $path.' is inside the document root',
                'Move it above `public/`: set `log.dir = "~/logs"` in dpress.ini. A log holds stack '
                .'traces, absolute paths and bound SQL parameters, and anybody who guesses the URL '
                .'can read them.');
        }
        if (!is_dir($path)) {
            return $this->warn('Log directory', $path.' does not exist yet',
                'It is created on the first write; make sure PHP can write there.');
        }
        return is_writable($path)
            ? $this->ok('Log directory', $path)
            : $this->fail('Log directory', $path.' is not writable',
                'Give the web user write access, or nothing is logged at all.');
    }

    /**
     * Is `$path` at or under `$parent`, decided lexically
     *
     * Windows matches without case and Linux matches with it, which is what the two filesystems
     * actually do. Getting that backwards either misses a real leak or accuses a site of one.
     */
    protected function isInside(string $path, string $parent): bool {
        $normalise = function (string $value): string {
            $value = str_replace('\\', '/', $value);
            while (str_contains($value, '//')) {
                $value = str_replace('//', '/', $value);
            }
            return rtrim($value, '/');
        };
        $path = $normalise($path);
        $parent = $normalise($parent);
        if ($parent === '' || $path === '') {
            return false;
        }
        return DIRECTORY_SEPARATOR === '\\'
            ? stripos($path.'/', $parent.'/') === 0
            : str_starts_with($path.'/', $parent.'/');
    }

    protected function uploads(): array {
        $path = $this->storage->basePath();
        if (!is_dir($path)) {
            return $this->warn('Uploads', $path.' does not exist yet',
                'It is created on the first upload. On a site restored from a backup, this usually '
                .'means the files were not copied across.');
        }
        if (!is_writable($path)) {
            return $this->fail('Uploads', $path.' is not writable',
                'Give the web user write access, or no file can be uploaded.');
        }
        if (!is_file($path.'/.htaccess')) {
            return $this->fail('Uploads', 'no .htaccess in '.$path,
                'Run `dpress media:protect`. Without it an uploaded .php file is a remote shell.');
        }
        return $this->ok('Uploads', $path);
    }

    // --- the database ---

    protected function database(): array {
        if (!$this->schema->isConfigured()) {
            return $this->fail('Database', 'not configured',
                'Set `database.default.dsn` and `database.default.name` in dpress.ini.');
        }
        $error = $this->schema->connectionError();
        return $error === ''
            ? $this->ok('Database', $this->schema->databaseName())
            : $this->fail('Database', $error, 'Check the credentials in dpress.ini and that the server is up.');
    }

    protected function charset(): array {
        $charset = $this->schema->charset();
        if ($charset === '') {
            return $this->warn('Charset', 'could not be read', 'The database has no information_schema to ask.');
        }
        if ($charset === SchemaService::CHARSET) {
            return $this->ok('Charset', $charset);
        }
        return $this->warn('Charset', $charset.' holds three bytes per character',
            'An emoji is four and is stored as `????` with no error. '
            .'alter database `'.$this->schema->databaseName().'` character set utf8mb4 collate utf8mb4_unicode_ci;');
    }

    protected function migrations(): array {
        if (!$this->schema->isInstalled()) {
            return $this->fail('Migrations', 'the schema is not installed', 'Run `dpress install`.');
        }
        $pending = $this->schema->pendingVersions();
        return empty($pending)
            ? $this->ok('Migrations', count($this->schema->appliedVersions()).' applied, none pending')
            : $this->fail('Migrations', count($pending).' pending: '.join(', ', $pending), 'Run `dpress upgrade`.');
    }

    // --- what a move gets wrong ---

    /**
     * The check this command was built around
     *
     * Internal links are resolved at save time and `Router::url()` prefixes `app.base_url`, so
     * `body_html` holds absolute URLs. A dump restored under a new domain keeps every one of them
     * pointing at the old one, and there is no error anywhere: the front page renders, the theme
     * is right, and the first link a reader clicks leaves the site.
     */
    protected function renderedFor(): array {
        $recorded = (string)$this->reading(fn() => $this->settings->get(Setting::CONTENT_RENDERED_FOR, ''), '');
        $current = rtrim((string)$this->config->get(AbstractApp::CONFIG_BASE_URL, ''), '/');
        if ($recorded === '') {
            return $this->warn('Rendered for', 'never recorded',
                'Run `dpress content:rerender` once to render the stored HTML for this address and '
                .'record it. A site installed before this check existed has simply never said.');
        }
        if (rtrim($recorded, '/') !== $current) {
            return $this->fail('Rendered for', $recorded.', but this site is '.$current,
                'Run `dpress content:rerender`. Every stored link still points at the old address, '
                .'and nothing on the site will say so.');
        }
        return $this->ok('Rendered for', $recorded);
    }

    /**
     * A plugin that is enabled and not on disk is skipped **silently**, by design
     *
     * That is the right behaviour at runtime - a missing clone should not take the site down -
     * and it is exactly why it needs saying here. The site works, and one feature is gone.
     */
    protected function plugins(): array {
        $enabled = $this->reading(fn() => $this->plugins->enabledNames(), []);
        $missing = [];
        foreach ($enabled as $name) {
            if (!$this->plugins->has($name)) {
                $missing[] = $name;
            }
        }
        if (!empty($missing)) {
            return $this->fail('Plugins', 'enabled but not installed: '.join(', ', $missing),
                'Clone them into `plugins/`, or turn them off with `dpress plugin:disable`. '
                .'They are being skipped without a word.');
        }
        return $this->ok('Plugins', empty($enabled) ? 'none enabled' : join(', ', $enabled));
    }

    /**
     * `ThemeService::active()` falls back to the built-in templates rather than failing, so the
     * *setting* has to be read rather than the active name, or a missing theme answers "none" and
     * looks deliberate
     */
    protected function theme(): array {
        $name = (string)$this->reading(fn() => $this->settings->get(Setting::THEME, ''), '');
        if ($name === '' || $name === ThemeService::FALLBACK) {
            return $this->ok('Theme', 'the built-in templates');
        }
        return $this->themes->has($name)
            ? $this->ok('Theme', $name)
            : $this->fail('Theme', $name.' is set but not installed',
                'Put it in `themes/`, or pick another with `dpress theme:set`. '
                .'The site is rendering the built-in templates instead.');
    }

    // --- helpers ---

    /**
     * A read that must not take the whole report down
     *
     * Every one of these touches a table, and the report is at its most useful on a site that is
     * half set up - which is the site where one of them throws.
     */
    protected function reading(callable $read, mixed $default): mixed {
        try {
            return $read();
        } catch (Throwable $e) {
            return $default;
        }
    }

    protected function real(string $path): string {
        $real = realpath($path);
        return $real === false ? '' : rtrim($real, '/\\');
    }

    protected function environmentName(): string {
        return (string)$this->config->get(AbstractApp::CONFIG_ENVIRONMENT, AbstractApp::PRODUCTION_ENVIRONMENT);
    }

    protected function isProduction(): bool {
        return $this->environmentName() === AbstractApp::PRODUCTION_ENVIRONMENT;
    }

    protected function ok(string $name, string $detail): array {
        return ['name' => $name, 'status' => self::OK, 'detail' => $detail, 'fix' => ''];
    }

    protected function warn(string $name, string $detail, string $fix): array {
        return ['name' => $name, 'status' => self::WARN, 'detail' => $detail, 'fix' => $fix];
    }

    protected function fail(string $name, string $detail, string $fix): array {
        return ['name' => $name, 'status' => self::FAIL, 'detail' => $detail, 'fix' => $fix];
    }
}
