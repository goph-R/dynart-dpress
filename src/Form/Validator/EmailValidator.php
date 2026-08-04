<?php

namespace Dynart\Dpress\Form\Validator;

use Dynart\Micro\AbstractValidator;

class EmailValidator extends AbstractValidator {

    public function __construct(string $message = 'Please enter a valid email address.') {
        $this->message = $message;
    }

    public function validate(mixed $value): bool {
        return filter_var((string)$value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
