<?php

namespace Dynart\Dpress\Cli;

use Dynart\Dpress\Dpress;
use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;

/**
 * `dpress init` - the one command that runs before there is a site
 *
 * Everything else needs a `dpress.ini` to find the site at all, and this is what writes one. So
 * it is declared `needsConfig => false` and **takes nothing from the container**: no config to
 * read, no database to reach, nothing but the arguments and the working directory. A command that
 * creates the configuration cannot depend on the configuration.
 *
 * **The secret is generated, not typed.** It was the fiddliest step of an install - a `php -r`
 * one-liner somebody had to know about, in a file somebody had to remember to edit - and it is
 * the step where getting it wrong is invisible: the example config the app skeleton used to ship
 * said "change me", and a site copied from it and never edited signed its sessions with a value
 * that is in a public repository. `dpress doctor` catches that afterwards; this stops it
 * happening, and is why that example is gone - `init` writes from the template in this package,
 * so there is one shape of the file rather than two that drift apart.
 */
class InitCommands extends AbstractCommands {

    const TEMPLATE = 'config/dpress.ini.template';
    const CONFIG_NAME = 'dpress.ini';

    /** 32 bytes, which HS256 wants, written as the 64 characters that survive an ini file */
    const SECRET_BYTES = 32;

    public function __construct(CliOutputInterface $output) {
        parent::__construct($output);
    }

    /**
     * `dpress init -base-url https://example.com -db-name mysite -db-user mysite [-db-password ...]`
     * `            [-db-host localhost] [-db-prefix dp_] [-site-name "My Site"] [-email ...] [-dev]`
     *
     * Writes `dpress.ini` into the working directory, which is what makes that directory a site.
     */
    public function init(array $params = []): int {
        $root = str_replace('\\', '/', (string)getcwd());
        $path = $root.'/'.self::CONFIG_NAME;
        if (is_file($path)) {
            return $this->fail(
                $path.' already exists. Delete it if you mean to start again - it holds the secret '
                .'that signs every session on this site, and overwriting it logs everybody out.'
            );
        }
        $baseUrl = rtrim($this->param($params, 'base-url'), '/');
        if ($baseUrl === '') {
            return $this->fail(
                'A -base-url is required: `dpress init -base-url https://example.com -db-name mysite '
                .'-db-user mysite`. There is no sensible default - guessing localhost would be wrong '
                .'on every server.'
            );
        }
        $dbName = $this->param($params, 'db-name');
        $dbUser = $this->param($params, 'db-user');
        if ($dbName === '' || $dbUser === '') {
            return $this->fail('A -db-name and a -db-user are required. dpress does not create the database.');
        }

        $template = Dpress::path(self::TEMPLATE);
        if (!is_file($template)) {
            return $this->fail('The template is missing from the package: '.$template);
        }

        // A development site needs three settings changed together and they are easy to get
        // separately wrong: a secure cookie on a plain HTTP site means the login never sticks,
        // and `native` mail on a laptop means a password reset that goes nowhere with no sign of
        // it. One flag rather than three chances to miss one.
        $dev = $this->flag($params, 'dev');
        $siteName = $this->param($params, 'site-name', 'A dpress site');

        $written = strtr((string)file_get_contents($template), [
            '%%GENERATED_AT%%' => gmdate('Y-m-d'),
            '%%ROOT_PATH%%'    => $root,
            '%%BASE_URL%%'     => $baseUrl,
            '%%ENVIRONMENT%%'  => $dev ? 'dev' : 'prod',
            '%%DB_HOST%%'      => $this->param($params, 'db-host', 'localhost'),
            '%%DB_NAME%%'      => $dbName,
            '%%DB_USER%%'      => $dbUser,
            '%%DB_PASSWORD%%'  => $this->param($params, 'db-password'),
            '%%DB_PREFIX%%'    => $this->param($params, 'db-prefix', 'dp_'),
            '%%JWT_SECRET%%'   => bin2hex(random_bytes(self::SECRET_BYTES)),
            '%%COOKIE_SECURE%%' => $dev ? 'false' : 'true',
            '%%SITE_NAME%%'    => $siteName,
            '%%MAILER%%'       => $dev ? 'log' : 'native',
            '%%FROM_EMAIL%%'   => $this->param($params, 'email', 'no-reply@'.$this->hostOf($baseUrl)),
        ]);
        if (file_put_contents($path, $written) === false) {
            return $this->fail('Could not write '.$path);
        }
        // `chmod` does nothing on Windows and is the difference between a readable password and a
        // private one everywhere else. Failing over it would be refusing to work on Windows.
        @chmod($path, 0600);

        $this->output->writeLine('Wrote '.$path);
        $this->output->writeLine('  '.str_pad('site', 14).$siteName.' at '.$baseUrl);
        $this->output->writeLine('  '.str_pad('database', 14).$dbName.' as '.$dbUser);
        $this->output->writeLine('  '.str_pad('environment', 14).($dev ? 'dev - errors shown, mail written to logs/' : 'prod'));
        $this->output->writeLine('  '.str_pad('jwt.secret', 14).'generated, '.(self::SECRET_BYTES * 2).' characters');
        $this->makeLogDirectory($root);
        $this->output->writeLine('');
        $this->next($dbName);
        return $this->success('Initialised.');
    }

    /**
     * Made here so it is above `public/` from the start
     *
     * `DpressLogger` would create it on the first write anyway, and where that lands is decided by
     * the `log.dir` just written - so making it now is only about the doctor row and about the
     * directory existing before anything needs it. A failure is a note, not an error: the site
     * works and the first log write says so again.
     */
    protected function makeLogDirectory(string $root): void {
        $logs = $root.'/logs';
        if (is_dir($logs)) {
            return;
        }
        if (@mkdir($logs, 0775, true) || is_dir($logs)) {
            $this->output->writeLine('  '.str_pad('logs', 14).$logs);
            return;
        }
        $this->output->writeLine('  '.str_pad('logs', 14).'could not create '.$logs.' - make it by hand');
    }

    protected function next(string $dbName): void {
        $this->output->setColor(CliOutput::DARK_YELLOW);
        $this->output->writeLine('  Next, if the database does not exist yet:');
        $this->output->writeLine('    create database `'.$dbName.'` character set utf8mb4 collate utf8mb4_unicode_ci;');
        $this->output->writeLine('  Then:');
        $this->output->writeLine('    dpress install');
        $this->output->writeLine('    dpress user:create -email you@example.com -name "You" -role admin');
        $this->output->writeLine('    dpress doctor');
        $this->output->setColor(null);
    }

    /**
     * The host, for a from-address that is at least the right domain
     */
    protected function hostOf(string $baseUrl): string {
        $host = (string)parse_url($baseUrl, PHP_URL_HOST);
        return $host !== '' ? $host : 'example.com';
    }
}
