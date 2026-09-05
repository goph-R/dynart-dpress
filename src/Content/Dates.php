<?php

namespace Dynart\Dpress\Content;

use DateTimeImmutable;
use DateTimeZone;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\SettingService;

/**
 * How a stored moment is written out on a page
 *
 * Every timestamp in the database is **UTC** - `ContentService` writes them with `gmdate()` - and
 * that is right, because a stored moment should not change meaning when a site moves or a country
 * changes its clocks. It also means nothing can be printed without being converted first: a post
 * published at half past midnight in Budapest is stored on the previous day, and a template that
 * prints the first ten characters of the column shows that previous day to everybody.
 *
 * So the two settings arrive together. **A format with no timezone is a date that is wrong for a
 * few hours every day**, which is worse than the plain `Y-m-d` it replaces, because it looks
 * deliberate.
 *
 * Both are settings rather than config: an editor changing how dates read on their own site
 * should not need a file, and the change is audited like every other setting.
 */
class Dates {

    /** What a site gets when it has never said - what the templates printed before this existed */
    const DEFAULT_FORMAT = 'Y-m-d';

    /** Storage is UTC, so printing UTC is the one choice that is never a lie about the data */
    const DEFAULT_TIMEZONE = 'UTC';

    /** The format an attribute wants, whatever the site's own format is */
    const ISO = 'c';

    /** How every timestamp in the database is written */
    const STORED = 'Y-m-d H:i:s';

    /**
     * What a date typed into the admin may look like, plainest first
     *
     * The `!` resets the fields the format does not mention, so `1999-01-02` is midnight rather
     * than midnight-with-today's-seconds-attached.
     */
    const INPUT_FORMATS = ['!Y-m-d', '!Y-m-d H:i', '!Y-m-d H:i:s'];

    private ?DateTimeZone $zone = null;

    public function __construct(protected SettingService $settings) {}

    /**
     * A stored timestamp in the site's timezone and the site's format
     *
     * An empty or unparseable value answers with '' rather than with 1970: a draft has no
     * `published_at`, and a template asking for one should get nothing to print, not a date that
     * looks like a real one.
     *
     * @param string $format overrides the site's, for a template that wants a month name in one
     *                       place and a full date in another
     */
    public function format(?string $stored, string $format = ''): string {
        $moment = $this->moment($stored);
        if ($moment === null) {
            return '';
        }
        return $moment->setTimezone($this->timezone())
            ->format($format !== '' ? $format : $this->siteFormat());
    }

    /**
     * The same moment for a `datetime` attribute
     *
     * `<time datetime="...">` is what tells a reader, a feed or a search engine what the printed
     * date actually means - and it has to be machine readable whatever the site's format is.
     */
    public function iso(?string $stored): string {
        $moment = $this->moment($stored);
        return $moment === null ? '' : $moment->setTimezone($this->timezone())->format(self::ISO);
    }

    /**
     * The same moment as RFC 2822, which is what RSS reads
     *
     * A separate method rather than `format($stored, DateTimeInterface::RSS)` because the site's
     * timezone must **not** apply here: `pubDate` carries its own offset and a reader converts it
     * for whoever is reading. Printing a local wall-clock time with a UTC offset stapled on is the
     * one way to get this wrong, and it is wrong silently - the date merely reads a few hours out
     * in somebody else's reader.
     */
    public function rss(?string $stored): string {
        $moment = $this->moment($stored);
        return $moment === null ? '' : $moment->format(\DateTimeInterface::RSS);
    }

    /**
     * The whole element, since the two halves are never wanted apart
     */
    public function tag(?string $stored, string $format = '', string $class = 'date'): string {
        $text = $this->format($stored, $format);
        if ($text === '') {
            return '';
        }
        return '<time class="'.htmlspecialchars($class, ENT_QUOTES).'"'
            .' datetime="'.htmlspecialchars($this->iso($stored), ENT_QUOTES).'">'
            .htmlspecialchars($text, ENT_QUOTES).'</time>';
    }

    public function siteFormat(): string {
        $format = trim((string)$this->settings->get(Setting::DATE_FORMAT, self::DEFAULT_FORMAT));
        return $format !== '' ? $format : self::DEFAULT_FORMAT;
    }

    /**
     * The site's timezone, or UTC when the setting names one that does not exist
     *
     * A settings screen can be typed into, and a site whose clock setting is a typo should show
     * the wrong hours rather than an error page on every URL it has - the same bargain a missing
     * theme makes.
     */
    public function timezone(): DateTimeZone {
        if ($this->zone !== null) {
            return $this->zone;
        }
        $name = trim((string)$this->settings->get(Setting::TIMEZONE, self::DEFAULT_TIMEZONE));
        try {
            $this->zone = new DateTimeZone($name !== '' ? $name : self::DEFAULT_TIMEZONE);
        } catch (\Exception $e) {
            $this->zone = new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
        return $this->zone;
    }

    /**
     * A date somebody typed, as a stored UTC timestamp
     *
     * `1999-01-02`, or with a time after it when the time matters - which for posts brought over
     * from another blog it does: two published on the same day have to keep the order they were
     * in. Read in the **site's** timezone, because that is the clock the person typing is looking
     * at, and written back as UTC like everything else in the database.
     *
     * Deliberately not `strtotime()`. It has an opinion about which half of `02/01/1999` is the
     * month, and it reads trailing rubbish as a modifier rather than refusing it - so a typo
     * becomes a date somewhere near the one that was meant, silently. A field whose whole job is
     * to be exact is not the place for a parser that guesses.
     *
     * @return string|null null when it cannot be read, so the caller can say so rather than
     *                     storing a moment nobody chose
     */
    public function parse(?string $typed): ?string {
        $typed = trim((string)$typed);
        if ($typed === '') {
            return null;
        }
        foreach (self::INPUT_FORMATS as $format) {
            $moment = DateTimeImmutable::createFromFormat($format, $typed, $this->timezone());
            // `createFromFormat` takes `1999-13-45` and rolls it into 2000, so whether it was
            // really that date is a question only the warnings answer
            $errors = DateTimeImmutable::getLastErrors();
            if ($moment !== false && empty($errors['warning_count']) && empty($errors['error_count'])) {
                return $moment->setTimezone(new DateTimeZone(self::DEFAULT_TIMEZONE))->format(self::STORED);
            }
        }
        return null;
    }

    /**
     * The reverse: a stored moment as the field it was typed into should show it
     *
     * Exactly what round-trips, which is why the time is left off when there is none. A date typed
     * as `1999-01-02` comes back as `1999-01-02` rather than having grown an `00:00:00` nobody
     * wrote, and a moment that does have a time keeps its seconds - shown to the minute, saving
     * would quietly round them away.
     */
    public function input(?string $stored): string {
        $moment = $this->moment($stored);
        if ($moment === null) {
            return '';
        }
        $local = $moment->setTimezone($this->timezone());
        return $local->format($local->format('H:i:s') === '00:00:00' ? 'Y-m-d' : self::STORED);
    }

    /**
     * The stored value as a moment, read as UTC because that is how it was written
     */
    protected function moment(?string $stored): ?DateTimeImmutable {
        $stored = trim((string)$stored);
        if ($stored === '' || str_starts_with($stored, '0000')) {
            return null;
        }
        try {
            return new DateTimeImmutable($stored, new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
