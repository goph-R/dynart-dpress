# Development

## Layout

```
bin/
  dpress          the command itself, a PHP file with a php shebang
  dpress.bat      batch launcher, for running from a source checkout
  autoload.php    finds the Composer autoloader
config/
  dpress.ini.template   what `dpress init` writes, with the placeholders filled in
src/
  Dpress.php            version and the shared constants
  DpressCliApp.php      the CLI application and its command table
  DpressWebApp.php      the web application and its middleware order
  DpressServices.php    DI registrations and the core migration list
  Block/                the block registry and the three core types
  Cli/                  the command implementations
  Content/              the markdown pipeline: links, callouts, shortcodes, feed, sitemap
  Controller/           the front end, and Admin/ behind it
  Entity/               the tables
  Form/                 the form factory and the validators
  Mail/                 the mailers
  Media/                storage, image processing, the SVG sanitiser
  Migration/            the schema
  Plugin/               the loader
  Query/                the query factory and the core queries
  Security/             permissions, hashing, rate limiting, the auth cookies
  Service/              the CMS services, and the doctor
  Theme/                themes, places, page assets
views/                  the built-in templates, front end and admin
assets/                 the admin's CSS and JS, served from the package
icons/                  the admin's inline SVGs
translations/           en.ini, and micro's own strings
```

`bin/dpress` starts `#!/usr/bin/env php` rather than `#!/usr/bin/env bash`, and that one line is
what makes the command work on Windows. Composer reads it to decide what the `vendor/bin/dpress.bat`
it writes should call: a php shebang gets `php`, anything else gets `bash` - and in a plain
`cmd.exe` the only `bash` on the PATH is usually WSL's, which cannot open a `C:\...` path and
reports the command as missing while it is sitting right there. On Linux it costs nothing, since
the file was always run through php anyway.

The admin's assets are **served from the package** by `AssetController`, so installing the package
installs the admin — there is no publish step to forget after an upgrade, which would otherwise
leave last version's list code talking to this version's endpoints.

## Tests

```bash
# the PHP suite, from https://github.com/goph-R/dynart-dpress-test/
php vendor/bin/phpunit --stderr

# the browser side, from this repo — a stub DOM, no dependency, no build step
node assets/dynamic-list.test.js
node assets/admin.test.js
node assets/markdown-highlight.test.js
node assets/css-highlight.test.js
node assets/html-highlight.test.js
node assets/enlighter-tokenize.test.js
node assets/emoji.test.js
```

The PHP suite covers what the server sends and the four JS suites cover what the browser does with
it. Run them all when touching the admin: a list whose constructor could not run was released once,
because only the first of those existed.

## Related repositories

| Repository | What it is |
|---|---|
| [`dynart-micro`](https://github.com/goph-R/dynart-micro) | the framework underneath |
| [`dynart-micro-entities`](https://github.com/goph-R/dynart-micro-entities) | the ORM, the migrations and the audit trail |
| `dynart-dpress` | this package, the CMS itself |
| `dynart-dpress-test` | the PHPUnit suite, symlinking this via a path repository |
| `dynart-dpress-app` | a runnable site: the config, the themes, the uploads, the front controller |
| [`dynart-dpress-disqus`](https://github.com/goph-R/dynart-dpress-disqus) | comments |
| [`dynart-dpress-kofi`](https://github.com/goph-R/dynart-dpress-kofi) | a Ko-fi button, and the proof that a block type can leave core |
| [`dynart-dpress-fontawesome`](https://github.com/goph-R/dynart-dpress-fontawesome) | an icon shortcode |

`CLAUDE.md` in the repository root is the long version: what is built and, more usefully, why each decision went the way it did.
