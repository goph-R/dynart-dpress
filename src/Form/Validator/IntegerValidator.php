<?php

namespace Dynart\Dpress\Form\Validator;

use Dynart\Micro\AbstractValidator;

/**
 * A whole number, positive or negative
 *
 * Because `(int)` never fails: `1o` is 1, `x` is 0, and a weight somebody mistyped would quietly
 * become "the same as everything else" with the screen reporting that it saved. An empty value
 * passes - `Form` skips a validator on an empty optional field - so "leave it alone" still means
 * what it says.
 */
class IntegerValidator extends AbstractValidator {

    public function __construct(string $message = '') {
        $this->message = $message !== '' ? $message : 'Has to be a whole number, like 0, 5 or -5.';
    }

    public function validate(mixed $value): bool {
        $text = trim((string)$value);
        return $text === '' || preg_match('/^-?\d+$/', $text) === 1;
    }
}
