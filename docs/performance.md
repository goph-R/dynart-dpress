# Measuring dpress

The point of dpress is to be fast without a caching plugin, on ordinary hosting, for people who
are happy editing `themes/*.phtml` and writing markdown. That is a claim, and a claim you cannot
reproduce is a slogan — so this is how to measure it, what the numbers were the first time, and
which levers actually move them.

Everything here needs no instrumentation: dpress already logs every query, and `curl` already
reports the time.

---

## 1. Is OPcache on?

**Check it first**, though it is not the whole story: measured here it took a request from
44.9 ms to 31.1 ms — worth having, and a third of the problem rather than all of it. A dpress
page loads **153 files, 667 KB of PHP**, and compiling them is what OPcache removes.

**The CLI and the web server have separate configurations**, and `opcache.enable_cli` is off by
default. So `php -i` tells you nothing about what your site is doing. Ask the thing that serves
the site:

```bash
# Debian/Ubuntu with PHP-FPM - what FPM itself loads. Use your own version:
# `ls /etc/php/` or `systemctl list-units 'php*-fpm*'` if you are not sure which it is.
V=8.4
php-fpm$V -i | grep -E "opcache.enable|memory_consumption|max_accelerated_files|validate_timestamps"

# which ini files FPM reads at all
php-fpm$V -i | grep "Loaded Configuration\|Scan this dir"
ls -l /etc/php/$V/fpm/conf.d/ | grep -i opcache
```

If the `php-fpm` binary is not on the path, the definitive answer comes from the web server itself. Drop
a probe in the document root, read it **once**, and delete it — it reports server internals and
has no business staying there:

```bash
cd /var/www/dpress.dynart.net
cat > public/opcache-probe.php <<'PHP'
<?php
$o = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
echo json_encode([
    'opcache'  => $o ? ($o['opcache_enabled'] ? 'on' : 'off') : 'not installed',
    'memory'   => $o ? round($o['memory_usage']['used_memory'] / 1048576).' MB used' : null,
    'cached'   => $o['opcache_statistics']['num_cached_scripts'] ?? null,
    'restarts' => $o['opcache_statistics']['oom_restarts'] ?? null,
    'php'      => PHP_VERSION,
    'sapi'     => PHP_SAPI,
], JSON_PRETTY_PRINT);
PHP
curl -s https://dpress.dynart.net/opcache-probe.php
rm public/opcache-probe.php
```

`oom_restarts` above zero means the cache is too small and is being thrown away wholesale —
`num_cached_scripts` next to `max_accelerated_files` tells you the same story earlier.

Sensible production settings:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

`validate_timestamps=0` is faster still, but then **PHP never notices a deploy** — a `git pull`
changes nothing until `systemctl reload php$V-fpm`. Worth it only with a deploy script that
does the reload. `revalidate_freq=2` costs a stat per file every two seconds and forgives you.

On XAMPP, OPcache ships but is commented out: enable `zend_extension=opcache` and
`opcache.enable=1` in `php.ini`, and restart Apache. Development timings without it are a floor,
not a measurement.

---

## 2. Counting queries

`log.level = debug` makes `Database::query()` log every statement with its parameters. Nothing
else is needed.

```bash
cd /path/to/site
# dpress.ini:  log.level = debug        (and log.dir = "~/logs" - never inside public/)
L=logs/log_$(date +%Y-%m-%d).txt

count() { : > $L; curl -s -o /dev/null "$1"; sleep 0.4; printf "%-30s %s queries\n" "$1" "$(grep -c 'Query:' $L)"; }

count "https://example.com/"
count "https://example.com/some-post"
count "https://example.com/category/news"
```

To see *what* it asked for, which is where the interesting part is:

```bash
grep -o "Query: .*" $L | sed 's/Query: //' | cut -c1-160 | nl
```

**Turn it back to `error` afterwards.** A file write per query is not something to leave on, and
it distorts every timing you take next.

---

## 3. Timing

Median over enough requests to drown the noise, after one warm-up:

```bash
bench() {
  for i in $(seq 1 30); do curl -s -o /dev/null -w "%{time_total}\n" "$1"; done \
  | sort -n | awk -v p="$1" '{a[NR]=$1*1000; s+=$1*1000}
      END {printf "%-40s median %6.1f ms  mean %6.1f  min %6.1f  max %6.1f\n",
                  p, a[int(NR/2)+1], s/NR, a[1], a[NR]}'
}
curl -s -o /dev/null "https://example.com/"   # warm
bench "https://example.com/"
```

**Median, not mean** — one 300 ms outlier from a garbage collection or a disk hiccup drags a
mean around and tells you nothing about what a visitor experiences.

Always take the two floors as well, or you cannot tell your code's cost from the stack's:

```bash
bench "https://example.com/favicon.png"    # the web server alone
echo '<?php echo 1;' > public/tiny.php     # + PHP startup   (delete it afterwards)
bench "https://example.com/tiny.php"
```

Anything above the second number is dpress and its database. Anything below it is somebody
else's problem — and `ab`/`wrk` measure concurrency, which is a different question from *how
long does one page take*, the one that decides whether the site feels fast.

### How many files a request compiles

The number that decides how much OPcache is worth:

```php
// public/files-probe.php - a copy of index.php with one hook, deleted after use
register_shutdown_function(function () {
    $bytes = 0;
    foreach (get_included_files() as $f) { $bytes += @filesize($f) ?: 0; }
    file_put_contents(__DIR__.'/../logs/included.txt', count(get_included_files()).' files, '.round($bytes/1024).' KB');
});
```

---

## 4. The first baseline

Measured 2026-08-05 on the development machine — XAMPP, Windows, PHP 8.2, MariaDB 10.4 — as
`time_starttransfer - time_pretransfer`, median of 20 requests. **`/admin` answering 401 is the
useful one**: it boots everything and then does almost no work, so it separates the cost of
*being dpress* from the cost of *rendering a page*.

| | no OPcache | OPcache on |
|---|---|---|
| static file (server floor) | 0.7 ms | 0.7 ms |
| `/admin` → 401 (boot, no work) | 44.9 ms | **31.1 ms** |
| front page (9 queries) | 48.5 ms | **32.1 ms** |
| single post, `/post/<slug>` (13 queries) | — | 34.1 ms |

Queries per page:

| Page | Queries |
|---|---|
| `/` (front, 5 posts) | 9 |
| `/post/welcome-to-dpress` (single post, 3 attachments) | 13 |
| `/about` (page, in a menu) | 12 |
| `/category/news` | 11 |
| `/page/2` | 4 |

**Check the status code of every URL you measure.** An earlier version of this table put a
single post at 5 queries — it was measuring `/welcome-to-dpress`, which is a **404**. Posts live
at `/post/<slug>`; the bare slug is the page catch-all correctly refusing a post. A 404 is cheap
and looks like a wonderful result.

Two things fall out of that table, and both were surprises:

**The page work is about 1 ms.** Routing, nine queries and rendering the whole front page cost
one millisecond more than a request that authenticates nobody and returns 401. Query tuning
cannot move a number that is already one millisecond.

**Boot is everything, and OPcache only fixes a third of it.** Compiling 153 files / 667 KB costs
~14 ms; the remaining ~31 ms is work done at runtime on every request. Where that goes, by
direct measurement:

| | |
|---|---|
| Composer autoload + `new DpressWebApp(...)` | 1.6 ms |
| config, logger, events | 0.5 ms |
| `init()` → registering services, middlewares, controllers | 1.4 ms |
| `init()` → **`applyTheme()`, which reads a setting: the first DB connect** | **11.7 ms** |
| `init()` → registering entities, migrations, queries, forms, audit | 4.1 ms |
| **`runMiddlewares()`** — attribute processing (~3 ms) plus the JWT and locale middlewares | **8.8 ms** |
| routing, the 401 itself | ~2 ms |

Measured by putting `microtime()` marks in `AbstractApp::fullInit()` and `DpressWebApp::initServices()`,
reading them from a probe, and reverting. Stable to a few tenths across runs — except the very
first request after an idle period, which comes in around 7 ms for the whole of `init()` because
the database connection has not yet been made the slow way. Do not measure this once.

**The framework is not the problem**: a bare `WebApp` with the same config boots in half a
millisecond. Nor is attribute reflection, which was the obvious suspect and turned out to cost
3 ms.

**Treat the 11.7 ms connect as a Windows artifact until it is measured on Linux.** MariaDB over
TCP on Windows is slow to hand-shake — 127.0.0.1 and localhost measured the same, so it is not
the usual IPv6-fallback trap — while a Linux host connecting over a unix socket is normally
under a millisecond. Do not go adding persistent connections on the strength of this number.

**But the connect itself is a design finding, whatever it costs.** `applyTheme()` runs during
`init()`, and the active theme is a *setting*, so **every request opens a database connection
before it has even routed**. A request for `/admin/assets/admin.css`, a 404, a 401 — none of
them need the database, and all of them pay for it. Making the theme resolve lazily, on the
first `View::fetch()` rather than at boot, would let those requests skip the connection
entirely. That is worth doing on a Linux host too, where it is cheaper but not free.

### A budget

Not measurements — targets to notice a regression against:

- **a public page: 12 queries or fewer**, and flat as content grows. A count that rises with the
  number of posts, menu items or categories on the page is an N+1 and is the bug.
- **the page's own work: a few ms.** It is ~1 ms today; if that grows, something started doing
  work per request that used to be done once.
- **the admin: one request per screen** (0.13.0 made the lists seed their first page).

---

## 5. What the first measurement found

In the order they are worth fixing:

1. **Boot is ~95% of a request, and it is not compilation.** See the table above. Two items
   dominate it: the database connection forced by `applyTheme()` during `init()`, and the
   middleware pass. Nothing else in the boot is above 2 ms.
2. **The menu resolves its targets one at a time.** Every item runs its own
   `select * from dp_content where id = ?` or `dp_category`, so a ten-item menu is ten extra
   queries **on every page of the site**. Worth fixing because it *scales with content*, which
   flat one-millisecond page work does not — but it will not move the total today.
3. **Loading a row takes two queries.** The pattern is "query for the id, then `findById()`" —
   `findByEmail`, the slug lookup, the menu place lookup all do it. Correct, and one round trip
   more than necessary each time.
4. **Two wasted round trips per request**: `use <database>` and `set names 'utf8'` are sent on
   every connect. Both vanish if the DSN carries them —
   `mysql:host=localhost;dbname=dpress;charset=utf8mb4`.

And two things that are already right, which is worth recording so nobody "optimises" them:

- **Settings are one query**, not one per setting.
- **Markdown is never parsed on a page view.** `lead_html` / `body_html` are rendered at save
  time, so a request is a select and a template — no CommonMark, no cache to invalidate.

---

## 6. Comparing with WordPress fairly

The comparison is the point, so it should be one nobody can wave away:

- **Same host, same PHP version, same OPcache settings, same database server.** A number from a
  laptop against a number from a VPS is not a comparison.
- **Same content**: a comparable number of posts, a menu of the same size, the same page depth.
- **Both stock.** dpress with no plugins against WordPress with no plugins and a default theme.
  Adding a caching plugin to one side is a different claim — "faster than WordPress with a page
  cache in front of it" is a much harder claim and probably not a true one.
- **Measure server time**, not a Lighthouse score. Lighthouse mostly measures the theme's assets
  and the network, which is a real thing to care about but not the thing being compared here.
- **Report the query count next to the milliseconds.** It is the number that does not depend on
  whose machine it was, and the one that predicts what happens when the site grows.
- **Say what was measured.** "Front page, 20 posts, 8 menu items, PHP 8.2 + OPcache, MariaDB on
  the same host, median of 30 requests" is a result. "3× faster" is marketing.

The honest framing: dpress is not faster because of a clever trick, it is faster because it does
less. No options table to load, no block parser, no theme customiser, and the markdown is rendered
once at save time rather than on every view. If a measurement ever shows otherwise, the
measurement is right and this document is wrong.

### The plugin system, measured

This document used to say "no plugin API to boot" in that list. **That stopped being true in
0.23.0**, so it was measured rather than quietly deleted.

Serving `/post/<slug>` over HTTP, 60 requests a run, three rounds alternating to cancel drift:

| | per request |
|---|---|
| no plugins enabled | 65–67 ms |
| one plugin enabled | 67 ms |

So one plugin costs about **1 ms**, and none costs nothing measurable. (These are higher than the
in-process figures elsewhere in this document because they include curl and the HTTP round trip;
what matters here is the difference between the rows, not the rows.)

Two things keep it that way, and both are load-bearing rather than incidental:

- **The enabled list is read from settings that were already being read** for the theme, so
  discovering that there are no plugins costs one array lookup.
- **A subscription is a Micro callable, not a closure.** The event service resolves it through the
  container *when the event fires*, so an enabled plugin that hooks the content form costs one
  array entry on a page view and builds nothing. The example plugin does exactly this — its
  service and controller exist only on the screens that use them.

A plugin that does work on every request will of course cost what that work costs. The claim is
only that **the mechanism** is free, not that what somebody hangs off it is.
