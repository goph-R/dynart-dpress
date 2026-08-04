<?php

namespace Dynart\Dpress\Form\Validator;

use Dynart\Micro\AbstractValidator;

class MinLengthValidator extends AbstractValidator {

    public function __construct(private int $minLength, string $message = '') {
        $this->message = $message !== '' ? $message : "Has to be at least $minLength characters long.";
    }

    public function validate(mixed $value): bool {
        return mb_strlen((string)$value) >= $this->minLength;
    }
}
