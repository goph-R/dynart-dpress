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
