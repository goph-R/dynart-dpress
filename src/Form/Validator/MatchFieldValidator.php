<?php

namespace Dynart\Dpress\Form\Validator;

use Dynart\Micro\AbstractValidator;

/**
 * Checks that a field equals another field of the same form
 *
 * `AbstractValidator::setForm()` is what makes this possible - the validator can read the other
 * value at validation time rather than having it passed in at build time.
 */
class MatchFieldValidator extends AbstractValidator {

    public function __construct(private string $otherField, string $message = 'The two values do not match.') {
        $this->message = $message;
    }

    public function validate(mixed $value): bool {
        return (string)$value === (string)$this->form()->value($this->otherField);
    }
}
