<?php

namespace Dynart\Dpress\Mail;

interface MailerInterface {

    /**
     * Renders a template and sends it
     *
     * @param string $name The recipient's name, may be empty
     * @param string $email The recipient's address
     * @param string $subject The subject line
     * @param string $template The view path of the HTML body, without the `.phtml` extension
     * @param array $variables The variables handed to both templates
     * @return bool Was it accepted for delivery?
     */
    public function send(string $name, string $email, string $subject, string $template, array $variables = []): bool;

    /**
     * Renders a template into a `Mail` without sending it
     */
    public function create(string $name, string $email, string $subject, string $template, array $variables = []): Mail;
}
