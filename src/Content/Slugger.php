<?php

namespace Dynart\Dpress\Content;

/**
 * Makes a URL slug out of a title
 *
 * Accented characters are folded to their ASCII base rather than dropped, so a Hungarian title
 * gives a readable slug instead of a string of hyphens.
 */
class Slugger {

    const MAX_LENGTH = 200;

    /**
     * Characters that have no `iconv` transliteration worth relying on, mapped by hand
     *
     * `iconv` with `//TRANSLIT` depends on the platform's locale, and on Windows it tends to
     * turn ő into ' or ?, so the ones that matter here are done explicitly first.
     */
    const REPLACEMENTS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
        'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ö' => 'O', 'Ő' => 'O',
        'Ú' => 'U', 'Ü' => 'U', 'Ű' => 'U',
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
        'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u', 'ý' => 'y',
        'ÿ' => 'y', 'š' => 's', 'ž' => 'z', 'č' => 'c', 'ř' => 'r', 'ď' => 'd',
        'ť' => 't', 'ň' => 'n', 'ě' => 'e', 'ů' => 'u', 'ł' => 'l', 'ą' => 'a',
        'ę' => 'e', 'ś' => 's', 'ć' => 'c', 'ń' => 'n', 'ź' => 'z', 'ż' => 'z',
        'ß' => 'ss', 'æ' => 'ae', 'ø' => 'o', 'å' => 'a', 'ð' => 'd', 'þ' => 'th',
    ];

    public function slugify(string $text): string {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, array_change_key_case(self::REPLACEMENTS, CASE_LOWER));
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
        $text = trim((string)$text, '-');
        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH), '-');
        }
        return $text;
    }

    /**
     * Appends `-2`, `-3` and so on until the slug is free
     *
     * @param callable $isTaken Receives a candidate, returns whether it is already in use
     */
    public function unique(string $text, callable $isTaken): string {
        $base = $this->slugify($text);
        if ($base === '') {
            $base = 'item';
        }
        if (!$isTaken($base)) {
            return $base;
        }
        $suffix = 2;
        while ($isTaken($base.'-'.$suffix)) {
            $suffix++;
        }
        return $base.'-'.$suffix;
    }
}
