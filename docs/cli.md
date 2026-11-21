# The `dpress` command

`-config <path>` points at a specific `dpress.ini` instead of searching for one. `init`, `help` and
`version` work outside a site — `init` is what makes one — and everything else needs a config.
`dpress help` prints the same list, out of the same table the commands are declared in.

## The site

| Command | What it does |
|---|---|
| `dpress init -base-url … -db-name … -db-user …` | Write a `dpress.ini` here, with a generated signing secret |
| `dpress install` | Create the database schema and apply every migration |
| `dpress upgrade` | Apply the pending migrations |
| `dpress migrate:status` | List the applied and the pending migrations |
| `dpress doctor` | Check everything an install or a move can get silently wrong |
| `dpress export -to <dir>` | Write the content, the uploads and the manifest to a folder |
| `dpress import -from <dir> -confirm` | Replace this site with a bundle, and re-render it here |
| `dpress version` / `dpress help` | Print the version, or the command list |

## People

| Command | What it does |
|---|---|
| `dpress user:create -email … -name … -role …` | Create a user, generating a password when none is given |
| `dpress user:password -email …` | Change a password, generating one when none is given |
| `dpress user:list` | List the users |
| `dpress user:status -email … -status …` | Set a user active, pending or blocked |
| `dpress user:role -email … -role … [-revoke]` | Grant a role, or revoke it |
| `dpress user:delete -email … -confirm` | Delete a user, keeping what they wrote |
| `dpress role:list` | List the roles and their permissions |

## Content

| Command | What it does |
|---|---|
| `dpress content:create -title … -author …` | Create a post or a page, from `-markdown` or a `-file` |
| `dpress content:list` | List the content |
| `dpress content:publish -id … [-unpublish]` | Publish content, or take it back to draft |
| `dpress content:delete -id …` | Delete content, keeping its history |
| `dpress content:history -id …` | Show the revision history of a piece of content |
| `dpress content:rerender` | Re-render every markdown body, after a rendering change |
| `dpress content:prune` | Remove the unsaved drafts "New" made that nobody came back to |
| `dpress taxonomy:list` | List the categories and the tags |

## Media

| Command | What it does |
|---|---|
| `dpress media:import -file …` | Import a file into the media library |
| `dpress media:list` | List the media library |
| `dpress media:delete -id … [-restore]` | Mark media deleted, or bring it back |
| `dpress media:purge -id … -confirm` | Delete the file itself, or the whole bin with `-all` |
| `dpress media:sanitize -confirm` | Re-sanitise SVGs stored before the sanitiser existed |
| `dpress media:protect` | Rewrite the uploads `.htaccess` that stops uploads being executed |
| `dpress media:regenerate` | Clear the generated thumbnails so they are rebuilt on demand |

## Presentation and plugins

| Command | What it does |
|---|---|
| `dpress theme:list` / `dpress theme:set -name …` | List the installed themes, or switch |
| `dpress plugin:list` | List the installed plugins and what went wrong with any of them |
| `dpress plugin:enable -name …` / `plugin:disable -name …` | Turn a plugin on or off |
| `dpress menu:list` | List the menus, their places and their items |
| `dpress setting:list` / `dpress setting:set -name … -value …` | List the settings and where each value comes from, or change one |
| `dpress mail:test -email … [-render]` | Render a test mail, and send it unless `-render` is given |

`doctor` exits **1** when something is broken and **0** when there are only warnings, so a deploy
script can end with it. A warning is something a site runs with — `utf8`, a development
environment, an unrecorded render address — and failing a deploy over one is how a check becomes
something people append `|| true` to. `-quiet` prints only what is not `ok`.

`install` is safe to repeat — it applies whatever is pending. That matters because a migration that
fails part way leaves the site half installed, and refusing to run again would strand it there.
