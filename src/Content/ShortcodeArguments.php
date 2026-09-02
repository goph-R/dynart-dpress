<?php

namespace Dynart\Dpress\Content;

/**
 * The arguments of a shortcode call, and nothing more than that
 *
 * `('media#13', limit=6, wide=true)` - positional and named, strings, numbers, booleans and null.
 * **No expressions, no nesting, no shortcode inside a shortcode.** The moment an argument can
 * contain an argument this is a language with an evaluator in it, and an evaluator running over
 * text that an author typed is a different thing to be responsible for than a lookup table.
 *
 * Positional arguments arrive under `0`, `1`, … and named ones under their name, so a handler
 * reads `$args[0] ?? ''` or `$args['limit'] ?? 6` and never has to know which was written.
 */
class ShortcodeArguments {

    /**
     * @param string $source the bracketed part, `(…)`, or '' for a call without one
     * @return array|null the arguments, or null when the text is not a call at all
     */
    public static function parse(string $source): ?array {
        $source = trim($source);
        if ($source === '') {
            return [];
        }
        if ($source[0] !== '(' || substr($source, -1) !== ')') {
            return null;
        }
        $inner = trim(substr($source, 1, -1));
        if ($inner === '') {
            return [];
        }
        $arguments = [];
        $position = 0;
        foreach (self::split($inner) as $part) {
            $part = trim($part);
            if ($part === '') {
                return null; // `(a,,b)` - a missing argument is a typo, not an empty one
            }
            if (preg_match('/^([a-z_][a-z0-9_]*)\s*=\s*(.*)$/is', $part, $named)) {
                $value = self::value(trim($named[2]));
                if ($value === self::INVALID) {
                    return null;
                }
                $arguments[$named[1]] = $value;
                continue;
            }
            $value = self::value($part);
            if ($value === self::INVALID) {
                return null;
            }
            $arguments[$position++] = $value;
        }
        return $arguments;
    }

    /** Returned for anything that is not a value this grammar knows */
    private const INVALID = "\0invalid\0";

    /**
     * One literal
     *
     * A quoted string keeps whatever is inside it, `\'` included, because a caption is the most
     * likely argument there is and an apostrophe is the most likely thing in one.
     */
    private static function value(string $text): mixed {
        $quote = $text[0] ?? '';
        if (($quote === "'" || $quote === '"') && strlen($text) > 1 && substr($text, -1) === $quote) {
            return str_replace('\\'.$quote, $quote, substr($text, 1, -1));
        }
        $lower = strtolower($text);
        if ($lower === 'true' || $lower === 'false') {
            return $lower === 'true';
        }
        if ($lower === 'null') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $text)) {
            return (int)$text;
        }
        if (preg_match('/^-?\d*\.\d+$/', $text)) {
            return (float)$text;
        }
        // A bare word is not a value. It reads like one - `size=large` - and accepting it would
        // mean guessing where an unquoted string ends, which is where a small grammar stops being
        // small. Quote it.
        return self::INVALID;
    }

    /**
     * Splits on commas that are not inside quotes
     *
     * @return string[]
     */
    private static function split(string $inner): array {
        $parts = [];
        $current = '';
        $quote = '';
        for ($i = 0, $length = strlen($inner); $i < $length; $i++) {
            $char = $inner[$i];
            if ($quote !== '' && $char === '\\' && ($inner[$i + 1] ?? '') === $quote) {
                $current .= $char.$inner[++$i];
                continue;
            }
            if ($quote === '' && ($char === "'" || $char === '"')) {
                $quote = $char;
            } else if ($quote !== '' && $char === $quote) {
                $quote = '';
            }
            if ($char === ',' && $quote === '') {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;
        return $parts;
    }
}
