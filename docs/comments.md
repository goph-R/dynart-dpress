# Comments, through Disqus

**Status: core built, plugin planned.** §3 landed in dpress 0.62.0; the plugin itself is not
written yet. It is written down so the decisions are made once,
in the open, rather than halfway through an afternoon.

The goal is narrow and worth stating plainly: **it is the last thing missing before a WordPress
blog with comments can move here.** Not a comment system — a way to keep the comments that already
exist, at an address that already has them.

---

## 1. Why Disqus, and why not our own

A comment table is two days of work and then a permanent job. Spam is the whole of it: without
moderation queues, rate limits, a spam service, an email pipeline for notifications, and somebody
watching, a public comment form on a blog that ranks at all fills with casino links inside a week.
Then it is subscriptions, threading, editing windows, avatars, banning, and the GDPR position of
holding other people's names and email addresses in your database forever.

Disqus takes all of it, including the part that never ends. What it costs is honest and should be
written down before anybody starts:

- **The free plan puts ads on your blog.** That is how it is paid for. The paid plan removes them.
- **It is third-party JavaScript**, on a front end that otherwise ships none. See §7 — this is the
  design constraint that shapes the plugin, not a footnote.
- **The comments live somewhere else.** Disqus exports them, so it is not a trap, but it is not
  your database either.

**A plugin, not core**, and not only for tidiness: a CMS whose core cannot be described without
mentioning a third-party comment service has the wrong core. Somebody who wants Commento, isso,
Giscus or a `<form>` of their own writes another plugin and the same seams serve them.

---

## 2. What the site stores: nothing

No comment table, no moderation screen, no cron, no email. The plugin stores **a shortname** and
**an identifier per post**, and that is the whole of its data.

This is what makes the feature small enough to be worth having, and it is why the interesting
sections below are §5 and §6 rather than §4. Rendering an embed is twenty lines. Making sure the
embed asks for the *right thread* is the entire job.

---

## 3. What core was missing (three small things)

> **Built in 0.62.0**, all three. `PageContext`, the `after_content` place, and the settings
> registry. What follows is what they were decided from, and it is still what they do.


The plugin has nowhere to render, and no way to be configured. All three gaps are the same shape —
a hardcoded list where the rest of the CMS has a registry — and each is worth closing on its own
merits.

### a) Somewhere inside a post to render

`views/content/single.phtml` has no hook. Blocks (0.37.0) gave the CMS *places*, so:

- Declare **`after_content`** in `ThemeService::BUILT_IN_PLACES` and render it in `single.phtml`,
  after the tags and attachments.
- A block in it is then anything the site wants under a post — comments, a newsletter box, a
  related-posts plugin later.

### b) A block that knows which page it is on

A block renderer gets `(Block $block, array $settings)` and nothing else, so a comments block
cannot tell which post it is under. Add a **`PageContext`**: a request-scoped service holding the
content being viewed, set by `ContentController` and `PageController`, empty everywhere else.

Twenty lines, and it is the primitive several things want — a shortcode needs the same answer, and
so would "related posts". Passing context down through `Places::render()` was the alternative; a
service is better because a shortcode is not rendered through a place.

**A comments block with no page context renders nothing.** On the front page that is exactly right.

### c) A way for a plugin to add a setting

`SettingsAdminController::FIELDS` is a `const`, and `save()` iterates it — so a plugin can add a
field to the settings form through `form.admin_settings:created` and watch it silently not be
saved. That is the worst kind of extension point: one that appears to work.

Turn `FIELDS` into a registry seeded with the core fields, exactly as `FormWidgets`, `Shortcodes`
and `Blocks` are. Then a plugin adds `disqus_shortname` in one call, it appears on the Settings
screen, and it is saved and audited like everything else.

The alternative — the plugin ships its own admin screen — also needs core work, because
`AbstractAdminController::navigation()` is a hardcoded array with no way for a plugin to add a
section. Worth doing eventually; not the cheaper path today.

---

## 4. The plugin

```
plugins/disqus-comments/
  plugin.ini
  src/DisqusPlugin.php
  src/DisqusBlock.php          the block type: renders the thread
  src/Identifiers.php          the one piece of logic worth testing
  src/Entity/DisqusThread.php  content_id -> identifier, for imported posts
  src/Migration/CreateDisqusThreadTable.php
  views/block/comments.phtml   the embed, and the button in front of it
  views/count.phtml            the counts script for listings
```

What it registers, in the terms [plugins.md](plugins.md) already uses:

| | |
|---|---|
| `entities()` / `migrations()` | the `disqus_thread` table |
| `register()` | the `comments` **block type**, a `disqus_shortname` setting field, a `disqus_identifier` field on the content form |
| `views()` | its templates |

**A block type rather than a template override**, so the site owner decides whether comments are
under every post by putting one block in `after_content` — and can take them off again without
editing a template or touching a post.

The settings are two: the **shortname**, and **when to load** (see §7). Nothing is rendered at all
until a shortname is set, so an enabled plugin on a site that has not configured it is invisible
rather than broken — the same answer a menu in a missing place gets.

---

## 5. Identifiers: the one decision that cannot be taken back

Disqus keys a thread on `page.identifier`, falling back to `page.url`. **Whatever is chosen on day
one is what every existing comment is attached to forever**, and changing it later orphans the lot
— they are not lost, they are simply not found by the page any more.

The rules that follow from that:

- **Never key on the URL.** A slug edit, moving off a subfolder, or `www` appearing would each
  detach every thread on the site. dpress already refuses to store URLs anywhere for exactly this
  reason (`media#12`, `post#42`).
- **Key on `dpress-<content id>`** for anything written here. The id is the one thing about a post
  that never changes.
- **Except where a thread already exists**, which is the whole of §6. `disqus_thread` holds the
  identifier the old site used, per post; `Identifiers::of($content)` answers with that when there
  is one and `dpress-<id>` otherwise.

Also send `page.url` as the **absolute canonical URL**. Disqus uses it for the links in
notification emails and in the moderation view, so a wrong one means every notification points at
the wrong page even when the thread is right.

---

## 6. Bringing the WordPress comments across

The order matters, and the middle step is the one that goes wrong.

1. **Before touching anything**, export from Disqus (Admin → Community → Export). If the WordPress
   site never used Disqus, its comments are inside the WordPress WXR export, and Disqus imports
   that file directly — do that first, on the old site, while the old URLs still resolve.
2. **Write down the identifiers the old site used.** This was the step to do slowly, and for
   gopherlab.net it is already done: the Disqus admin shows identifiers of the form

   ```
   573 https://gopherlab.net/?p=573
   ```

   which is the WordPress plugin's `<post id> <guid>`. Two things follow, and the first is the
   good news.

   **The threads are keyed on the WordPress post id, not on the URL** — so they do not care what
   the post's address becomes. The migration needs the old id carried across, and nothing else.
   That id is in the WXR export against every post, so it can come over with the content rather
   than being collected by hand: the map is one number per post, not a CSV somebody assembles.

   **Copy the identifier as exported; never rebuild it from the id.** The second half is the
   WordPress *guid*, which is stored at publish time and never updated — so a post written before
   the site moved to HTTPS carries `http://`, and one written before a domain change carries the
   old host. `"$id https://gopherlab.net/?p=$id"` would be right for most of the archive and
   silently wrong for the oldest posts, which are exactly the ones with the comments on them.
3. **Import into dpress**, so every post has its new id.
4. **Fill `disqus_thread`** — old identifier against new content id. A CLI command,
   `disqus:map -file map.csv`, taking `old_identifier,new_slug` and resolving slugs to ids, with a
   dry run by default. This is the step that has to be re-runnable, because the first attempt will
   have a few wrong.
5. **Verify on a handful of posts** before announcing the move: a post with many comments, one
   with none, and one whose slug changed in the process.
6. **Then, and only then**, use Disqus's URL Mapper for anything left over, so old URLs in Disqus
   point at the new ones. It is a cleanup, not the mechanism.

**Do not migrate by URL if it can be avoided.** It works until the first slug edit.

**And the URL question is now genuinely separate.** [roadmap.md](roadmap.md) §1 listed the
comments as one of the things riding on whether post URLs survive the move. Step 2 settles it:
these threads are identifier-keyed, so they never cared. What still rides on the URL is backlinks
and search rankings, which is reason enough on its own — but the comments are no longer an
argument in it either way.

---

## 7. Third-party JavaScript on a front end that has none

This is the part to get right, because it is the only thing here that touches every reader.

The front end ships zero JavaScript. Disqus is a script that loads more scripts, sets cookies, and
knows who is reading. Loading it on page load would mean **every visitor to every post is announced
to a third party**, whether or not they scroll far enough to see a comment — the thing that
`youtube-nocookie.com` and the vendored EnlighterJS were both chosen to avoid.

So: **click to load, as the default.**

```html
<div class="comments">
  <button type="button" data-disqus-load>Show comments</button>
  <noscript><p>Comments need JavaScript.</p></noscript>
</div>
```

The embed script is appended on the click and not before. A reader who never clicks loads nothing
and is told about nothing; a reader who wants to read the comments gets them a beat later, which
is the correct trade and the one most people would make for themselves if asked.

Make it a setting — `on click` (default) or `on load` — because a site that already carries
analytics has no such property to protect and may prefer the comments simply be there.

Two details that will otherwise bite: the button has to say how many there are (see §8) or nobody
clicks it, and the script must not be appended twice if somebody clicks twice.

---

## 8. Counts on listings

`count.js` turns any `<a href="…#disqus_thread" data-disqus-identifier="…">` into a comment count.
It is one more third-party script, so it follows the same rule as the embed: **off by default**,
one setting, and never on a page that is not showing posts.

The count is also what makes §7's button honest — "Show comments (12)" is a button people press,
and "Show comments" is one they wonder about.

---

## 9. What this will not do

- **Moderate.** That is disqus.com, and it should be — a moderation queue in the admin would be a
  second interface to somebody else's data.
- **Work offline, or without JavaScript.** Nothing can, with a hosted service.
- **Survive Disqus.** Export regularly. The exports are the reason this is a reasonable choice and
  not a lock-in.
- **Comment on pages**, initially. Posts only, until somebody wants otherwise; the block is in a
  place, so allowing it is putting one there.

---

## 10. Order of work

Core first, because the plugin is unwritable without it, and each core piece stands on its own:

1. `PageContext`, and `after_content` in the built-in places and in `single.phtml`. (small)
2. Settings as a registry rather than a `const`, so a plugin can add one. (small)
3. The plugin: block type, template, click-to-load, shortname setting. (half a day)
4. `disqus_thread` + the `disqus:map` command + tests on `Identifiers`. (the actual work)
5. Counts, behind their own setting. (small)

**Tests never touch the network.** What is worth testing is all local: the identifier for a post
with a mapping and one without, that no shortname renders nothing, that no page context renders
nothing, that the config JSON is escaped, and that `disqus:map` is idempotent and dry by default.

---

## 11. Before starting, decide

- **Shortname**: a new Disqus site, or the one the WordPress blog already uses? Reusing it keeps
  every thread where it is and makes §6 much shorter — this is the single biggest lever on how
  hard the migration is.
- **Paid or free**, given the ads.
- **Pages as well as posts?**
- **Counts on the front page**: worth a second third-party script, or not?
