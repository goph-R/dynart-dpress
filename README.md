# dpress

A markdown based CMS built on [dynart-micro](../dynart-micro) and [dynart-micro-entities](../dynart-micro-entities).

Status: **early**. The package skeleton and the `dpress` command line tool exist; the content model, the web front end and the admin UI do not yet. See `dynart-dpress-plan.md` for the full plan.

## Requirements

- PHP 8.0+
- MariaDB / MySQL
- Composer

## Installing a site

A site is a directory containing a `dpress.ini`. The `dpress` command finds it by walking up from the working directory, the way git finds its root, so the command works from anywhere inside the project.

```ini
; dpress.ini
app.root_path   = "."
app.base_url    = "http://localhost/mysite/public"
app.environment = dev

; the DSN has to be quoted, a bare = breaks parse_ini_file
database.default.dsn          = "mysql:host=localhost"
database.default.name         = mysite
database.default.username     = root
database.default.password     =
database.default.table_prefix = dp_

translation.all     = en
translation.default = en
```

Then:

```bash
composer install
vendor/bin/dpress install
```

## The `dpress` command

| Command | What it does |
|---|---|
| `dpress install` | Create the database schema and apply every migration |
| `dpress upgrade` | Apply the pending migrations |
| `dpress migrate:status` | List the applied and the pending migrations |
| `dpress version` | Print the dpress version |
| `dpress help` | Print the command list |

`-config <path>` points at a specific `dpress.ini` instead of searching for one. `help` and `version` work outside a site; everything else needs a config.

`install` is safe to repeat — it applies whatever is pending. That matters because a migration that fails part way leaves the site half installed, and refusing to run again would strand it there.

## Layout

```
bin/
  dpress          bash launcher (Linux, macOS)
  dpress.bat      batch launcher (Windows)
  dpress.php      the real entry point, both launchers delegate here
  autoload.php    finds the Composer autoloader
src/
  Dpress.php            version and the shared constants
  DpressCliApp.php      the CLI application and its command table
  DpressServices.php    DI registrations and the core migration list
  Cli/                  the command implementations
  Migration/            the schema migrations
  Service/              the CMS services
```

The two launchers stay deliberately dumb: they resolve their own directory and hand off to `dpress.php`, so all the logic lives in PHP and there is one implementation rather than two.

## Related repositories

| Repository | What it is |
|---|---|
| `dynart-dpress` | this package, the CMS itself |
| `dynart-dpress-test` | the PHPUnit suite, symlinking this via a path repository |
| `dynart-dpress-app` | a runnable site used for development |
