<?php

namespace Dynart\Dpress\Mail;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\ViewInterface;

/**
 * Renders a mail from templates and hands it to a transport
 *
 * The rendering is the part every mailer shares, so it lives here and a subclass only has to
 * implement `deliver()`.
 *
 * A mail is **two** templates: `<template>.phtml` for the HTML body and an optional
 * `<template>.txt.phtml` for the plain text alternative. Both go through `ViewInterface`, so a
 * theme overrides a mail template exactly the way it overrides a page template - the same
 * lookup, the same namespaces, nothing new to learn.
 *
 * <pre>
 * $mailer->send($user->name, $user->email, 'Reset your password', 'dpress:mail/password-reset', [
 *     'user' => $user,
 *     'url'  => $resetUrl
 * ]);
 * </pre>
 *
 * With `views/mail/password-reset.phtml` and, if wanted, `views/mail/password-reset.txt.phtml`.
 */
abstract class AbstractMailer implements MailerInterface {

    /**
     * Appended to the template path to find the plain text body
     *
     * `View::fetch()` appends `.phtml` itself, so `mail/reset` + `.txt` resolves to
     * `mail/reset.txt.phtml` - and the theme override check works on it unchanged.
     */
    const TEXT_TEMPLATE_SUFFIX = '.txt';

    const CONFIG_FROM_EMAIL = 'mail.from_email';
    const CONFIG_FROM_NAME = 'mail.from_name';
    const CONFIG_REPLY_TO_EMAIL = 'mail.reply_to_email';
    const CONFIG_REPLY_TO_NAME = 'mail.reply_to_name';

    const EVENT_BEFORE_SEND = 'mail:before_send';
    const EVENT_SENT = 'mail:sent';
    const EVENT_FAILED = 'mail:failed';

    public function __construct(
        protected ViewInterface $view,
        protected ConfigInterface $config,
        protected EventServiceInterface $events,
    ) {}

    /**
     * Hands the rendered mail to the transport
     *
     * @return bool Was it accepted for delivery? Accepted is not the same as arrived.
     */
    abstract protected function deliver(Mail $mail): bool;

    public function send(string $name, string $email, string $subject, string $template, array $variables = []): bool {
        $mail = $this->create($name, $email, $subject, $template, $variables);
        $this->events->emit(self::EVENT_BEFORE_SEND, [$mail]);
        $result = $this->deliver($mail);
        $this->events->emit($result ? self::EVENT_SENT : self::EVENT_FAILED, [$mail]);
        return $result;
    }

    public function create(string $name, string $email, string $subject, string $template, array $variables = []): Mail {
        $mail = new Mail(
            toEmail: $email,
            toName: $name,
            subject: $subject,
            fromEmail: $this->fromEmail(),
            fromName: $this->fromName(),
            replyToEmail: (string)$this->config->get(self::CONFIG_REPLY_TO_EMAIL, ''),
            replyToName: (string)$this->config->get(self::CONFIG_REPLY_TO_NAME, ''),
        );
        $mail->htmlBody = $this->view->fetch($template, $variables);
        $textTemplate = $template.self::TEXT_TEMPLATE_SUFFIX;
        if ($this->view->exists($textTemplate)) {
            $mail->textBody = $this->view->fetch($textTemplate, $variables);
        }
        return $mail;
    }

    public function fromEmail(): string {
        return (string)$this->config->get(self::CONFIG_FROM_EMAIL, 'no-reply@localhost');
    }

    public function fromName(): string {
        return (string)$this->config->get(self::CONFIG_FROM_NAME, '');
    }
}
