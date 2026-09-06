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
vendor/bin/dpress install                       # the schema
vendor/bin/dpress user:create -email you@example.com -password '...' -name 'You' -role admin
vendor/bin/dpress doctor                        # everything else
```

Point the web server's DocumentRoot at **`public/`**, never at the project root: `dpress.ini`
holds the database password and the signing secret, and publishing the root serves them.

`doctor` is the last step because it is the one that answers whether the rest worked. It checks
what a browser will not tell you — the placeholder secret still in `dpress.ini`, a log directory
inside the document root, `uploads/` with no `.htaccess`, a database that cannot hold an emoji.

## Moving a site to production

The content survives the move; **the stored HTML has the old address baked into it**, and that is
the one thing worth knowing before you do it.

Internal links are resolved when a document is saved, not when a page is served — that is what
makes a page view free — and `Router::url()` prefixes `app.base_url`. So `body_html` holds
absolute URLs, and a database restored under a new domain keeps pointing at the machine it was
written on. Nothing errors: the front page renders, the theme is right, and the first link a
reader clicks leaves the site.

```bash
# on the old machine
mysqldump -u user -p olddb > site.sql
tar czf uploads.tar.gz -C public uploads

# on the new one, after composer install and a dpress.ini with the new app.base_url
mysql -u user -p newdb < site.sql
tar xzf uploads.tar.gz -C public
vendor/bin/dpress upgrade            # the target may be a newer dpress than the dump
vendor/bin/dpress content:rerender   # rewrites every stored URL for the new address
vendor/bin/dpress doctor
```

`content:rerender` is the step that matters, and `doctor` is how you find out you forgot it: the
site records the address its HTML was rendered for, and the check compares that against
`app.base_url`.

```
X   Rendered for    https://old.example.com, but this site is https://example.com
        Run `dpress content:rerender`. Every stored link still points at the old address,
        and nothing on the site will say so.
```

Three more things do not travel in a database dump:

- **`public/uploads/`** — files on disk. The rows know their paths; the paths are relative and
  survive the move, but the files have to be copied.
- **Plugins.** The enabled list is in `dp_setting`, the clones are not, and a plugin that is
  enabled with nothing on disk is **skipped silently** — the site works and one feature is gone.
  `doctor` names them.
- **The theme**, the same way. A theme that is set but not installed leaves the site rendering the
  built-in templates, which looks deliberate.

## The `dpress` command

| Command | What it does |
|---|---|
| `dpress install` | Create the database schema and apply every migration |
| `dpress doctor` | Check everything an install or a move can get silently wrong |
| `dpress upgrade` | Apply the pending migrations |
| `dpress migrate:status` | List the applied and the pending migrations |
| `dpress version` | Print the dpress version |
| `dpress help` | Print the command list |

`-config <path>` points at a specific `dpress.ini` instead of searching for one. `help` and `version` work outside a site; everything else needs a config.

`doctor` exits **1** when something is broken and **0** when there are only warnings, so a deploy
script can end with it. A warning is something a site runs with — `utf8`, a development
environment, an unrecorded render address — and failing a deploy over one is how a check becomes
something people append `|| true` to. `-quiet` prints only what is not `ok`.

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
