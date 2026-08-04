<?php

namespace Dynart\Dpress\Mail;

/**
 * One rendered message, on its way to a mailer
 *
 * A value object rather than a pile of parameters, so `AbstractMailer::deliver()` has one thing
 * to receive and a subscriber has one thing to change before it goes out.
 */
class Mail {

    public function __construct(
        public string $toEmail = '',
        public string $toName = '',
        public string $subject = '',
        public string $htmlBody = '',
        public ?string $textBody = null,
        public string $fromEmail = '',
        public string $fromName = '',
        public string $replyToEmail = '',
        public string $replyToName = '',
    ) {}

    /**
     * Is there a plain text alternative?
     *
     * The text template is optional, so a mail can be HTML only.
     */
    public function hasTextBody(): bool {
        return $this->textBody !== null && $this->textBody !== '';
    }

    /**
     * Formats an address the way a mail header wants it: `Name <email>`
     *
     * A name with a non ASCII character is encoded, otherwise it would arrive as mojibake.
     */
    public static function address(string $email, string $name = ''): string {
        if ($name === '') {
            return $email;
        }
        return self::encodeHeader($name).' <'.$email.'>';
    }

    /**
     * Encodes a header value when it is not plain ASCII
     */
    public static function encodeHeader(string $value): string {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?'.base64_encode($value).'?=';
    }

    public function to(): string {
        return self::address($this->toEmail, $this->toName);
    }

    public function from(): string {
        return self::address($this->fromEmail, $this->fromName);
    }

    public function replyTo(): string {
        return $this->replyToEmail === '' ? '' : self::address($this->replyToEmail, $this->replyToName);
    }
}
