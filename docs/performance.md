# Measuring dpress

The point of dpress is to be fast without a caching plugin, on ordinary hosting, for people who
are happy editing `themes/*.phtml` and writing markdown. That is a claim, and a claim you cannot
reproduce is a slogan — so this is how to measure it, what the numbers were the first time, and
which levers actually move them.

Everything here needs no instrumentation: dpress already logs every query, and `curl` already
reports the time.

---

## 1. Is OPcache on?

**This is the first thing to check and usually the largest single factor.** Without it, PHP
recompiles every file on every request. A dpress page loads **153 files, 667 KB of PHP** — that
compile is most of the response time on a cold setup and nearly free on a warm one.

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

Measured 2026-08-05 on the development machine — **XAMPP, Windows, PHP 8.2, no OPcache**, which
is a floor rather than a representative number. Recorded so the *shape* can be compared later.

| | |
|---|---|
| static file (web server alone) | 1.6 ms |
| one line of PHP (startup) | 1.9 ms |
| dpress front page | 50.4 ms |
| compiled per request | 153 files, 667 KB |

| Page | Queries |
|---|---|
| `/` (front, 5 posts) | 9 |
| `/welcome-to-dpress` (single post) | 5 |
| `/about` (page, in a menu) | 12 |
| `/category/news` | 11 |
| `/page/2` | 4 |

What that says: with no OPcache, ~48 of those 50 ms is dpress being *compiled*, not run. The
query counts are the number worth watching, because they are the part that grows with content
and with features.

### A budget

Not measurements — targets to notice a regression against:

- **a public page: 12 queries or fewer**, and flat as content grows. A count that rises with the
  number of posts, menu items or categories on the page is an N+1 and is the bug.
- **a public page: under 20 ms of server time** on a warm OPcache with a local database.
- **the admin: one request per screen** (0.13.0 made the lists seed their first page).

---

## 5. What the first measurement found

In the order they are worth fixing:

1. **The menu resolves its targets one at a time.** Every item runs its own
   `select * from dp_content where id = ?` or `dp_category`, so a ten-item menu is ten extra
   queries **on every page of the site**. This is the only finding that scales with content, and
   the only one that matters much.
2. **Loading a row takes two queries.** The pattern is "query for the id, then `findById()`" —
   `findByEmail`, the slug lookup, the menu place lookup all do it. Correct, and one round trip
   more than necessary each time.
3. **Two wasted round trips per request**: `use <database>` and `set names 'utf8'` are sent on
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
less. No plugin API to boot, no options table to load, no block parser, no theme customiser, and
the markdown is rendered once at save time rather than on every view. If a measurement ever shows
otherwise, the measurement is right and this document is wrong.
