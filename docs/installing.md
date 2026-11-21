# Installing a site

## Requirements

- PHP 8.0+, with `mbstring`, `json`, `pdo`, `dom`, `libxml` and `gd`
- MariaDB / MySQL
- Composer

## Steps

A site is a directory containing a `dpress.ini`. The `dpress` command finds it by walking up from
the working directory, the way git finds its root, so the command works from anywhere inside the
project.

```bash
composer install

vendor/bin/dpress init \
    -base-url https://example.com \
    -db-name mysite -db-user mysite -db-password 'from your database' \
    -site-name "My Site"

# create the database itself, which Dpress does not do:
#   create database `mysite` character set utf8mb4 collate utf8mb4_unicode_ci;

vendor/bin/dpress install
vendor/bin/dpress user:create -email you@example.com -name "You" -role admin
vendor/bin/dpress doctor
```

`init` writes the `dpress.ini` and **generates the signing secret**, which was the fiddliest step
and the one where getting it wrong is invisible: a config copied from an example and never edited
signs every session on the site with a value that is in a public repository.

Add **`-dev`** for a development site. It changes three settings that go together and are each a
separate way to lose an afternoon: `app.environment = dev` shows the error instead of hiding it,
`jwt.cookie_secure = false` lets a plain HTTP site log in at all, and `mail.mailer = log` writes
mail into `logs/` instead of sending it.

`user:create` **generates a password when `-password` is left off** and prints it once, which
keeps the first admin's password out of your shell history.

Point the web server's DocumentRoot at **`public/`**, never at the project root: `dpress.ini` holds
the database password and the signing secret, and publishing the root serves them.

`doctor` is the last step because it is the one that answers whether the rest worked. It checks
what a browser will not tell you — a log directory inside the document root, `uploads/` with no
`.htaccess`, a database that cannot hold an emoji.

Moving an installed site to another server is in [moving-a-site.md](moving-a-site.md); every command is listed in [cli.md](cli.md).
