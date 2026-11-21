# Moving a site to production

The content survives the move; **the stored HTML has the old address baked into it**, and that is
the one thing worth knowing before you do it.

Internal links are resolved when a document is saved, not when a page is served — that is what
makes a page view free — and `Router::url()` prefixes `app.base_url`. So `body_html` holds
absolute URLs, and a database restored under a new domain keeps pointing at the machine it was
written on. Nothing errors: the front page renders, the theme is right, and the first link a
reader clicks leaves the site.

```bash
# on the old machine
vendor/bin/dpress export -to ./bundle
tar czf bundle.tgz bundle

# on the new one, after composer install and a dpress.ini with the new app.base_url
tar xzf bundle.tgz
vendor/bin/dpress import -from ./bundle -confirm
vendor/bin/dpress doctor
```

`import` creates the schema if it is not there, loads the rows, copies the uploads and then
**re-renders every stored document for this site's address** — not offered, not suggested, done.
A step that can be forgotten in that position is a step that will be.

## What is in a bundle

```
bundle/
  site.json          version, address, theme, plugins, row counts
  data/<table>.json  the rows
  uploads/           the files
```

**Data, not schema.** The new server builds its tables from its own migrations and the bundle only
carries rows, so the schema that ends up there is the one that server's Dpress believes in rather
than a snapshot of the old one's. It also means no `mysqldump` to have installed and on `PATH`.

**A folder, not an archive**, because `tar` and `zip` both exist already and neither has to become
a PHP extension this package requires.

**`dpress.ini` is not in it.** It holds the database password and the signing secret, and every
value in it describes the machine rather than the site.

**Sessions do not travel** — `refresh_token`, `user_token` and `auth_attempt` are left out for the
same reason they are not audited: they hold credentials and are short-lived, and a bundle is as
durable as an artifact gets. Everybody signs in again on the new server, which is the right
outcome.

Export and import want **the same Dpress version** on both sides; `import` says so and takes
`-force` if you know the difference is safe.

## After an import

`doctor` is how you find out what is left, and the checks it runs are the ones a page view will
not tell you about:

```
X   Rendered for    https://old.example.com, but this site is https://example.com
        Run `dpress content:rerender`. Every stored link still points at the old address,
        and nothing on the site will say so.
```

Two things a bundle cannot bring with it, both named by `doctor`:

- **Plugin code.** The enabled list travels in `dp_setting`; the clones do not, and a plugin that
  is enabled with nothing on disk is **skipped silently** — the site works and one feature is
  gone. A plugin's own *table* is also made only when the plugin is loaded, and the list of
  enabled plugins arrives with the import — so clone the missing ones and **run the import once
  more**, and the second pass boots with them on and loads their rows.
- **The theme.** A theme that is set but not installed leaves the site rendering the built-in
  templates, which looks deliberate.
