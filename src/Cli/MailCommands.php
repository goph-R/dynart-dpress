<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\Mail\MailerInterface;

/**
 * Sending a test mail
 *
 * Mail is the part of a site that fails quietly in production, so being able to render and send
 * one from the console - and see which mailer is actually in use - is worth a command.
 */
class MailCommands extends AbstractCommands {

    const TEST_TEMPLATE = 'dpress:mail/password-reset';

    public function __construct(
        CliOutputInterface $output,
        protected MailerInterface $mailer,
    ) {
        parent::__construct($output);
    }

    /**
     * `dpress mail:test -email x [-render]`
     *
     * With `-render` it only renders and prints the mail, without handing it to the transport.
     */
    public function test(array $params = []): int {
        $email = $this->param($params, 'email');
        if ($email === '' && !$this->flag($params, 'render')) {
            return $this->fail('An -email is required, or use -render to only render it.');
        }
        $this->output->writeLine('Mailer: '.get_class($this->mailer));
        $variables = [
            'name'       => 'Test Recipient',
            'url'        => 'https://example.com/reset-password?token=test-token',
            'expires_in' => '1 hour',
            'site_name'  => 'dpress',
            'subject'    => 'Test mail',
        ];
        $mail = $this->mailer->create('Test Recipient', $email ?: 'test@example.com', 'Test mail', self::TEST_TEMPLATE, $variables);

        $this->output->writeLine('Subject: '.$mail->subject);
        $this->output->writeLine('From:    '.$mail->from());
        $this->output->writeLine('To:      '.$mail->to());
        $this->output->writeLine('Text body: '.($mail->hasTextBody() ? 'yes' : 'no (HTML only)'));

        if ($this->flag($params, 'render')) {
            if ($mail->hasTextBody()) {
                $this->output->writeLine('');
                $this->output->writeLine('--- text ---');
                $this->output->writeLine($mail->textBody);
            }
            $this->output->writeLine('');
            $this->output->writeLine('--- html ---');
            $this->output->writeLine($mail->htmlBody);
            return 0;
        }

        $sent = $this->mailer->send('Test Recipient', $email, 'Test mail', self::TEST_TEMPLATE, $variables);
        if (!$sent) {
            return $this->fail('The mailer refused it.');
        }
        $this->output->setColor(CliOutput::GREEN);
        $this->output->writeLine('Accepted for delivery.');
        $this->output->setColor(null);
        return 0;
    }

}
