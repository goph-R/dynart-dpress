<?php

namespace Dynart\Dpress\Mail;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\LoggerInterface;
use Dynart\Micro\ViewInterface;

/**
 * Writes the mail to the log instead of sending it
 *
 * The development default. A password reset flow can be walked through end to end without an
 * SMTP server, and the reset URL is right there in the log where it can be clicked.
 *
 * Also keeps the last mail in memory, so a test can assert on what would have been sent.
 */
class LogMailer extends AbstractMailer {

    private ?Mail $lastMail = null;

    /** @var Mail[] */
    private array $sent = [];

    public function __construct(
        ViewInterface $view,
        ConfigInterface $config,
        EventServiceInterface $events,
        private LoggerInterface $logger,
    ) {
        parent::__construct($view, $config, $events);
    }

    protected function deliver(Mail $mail): bool {
        $this->lastMail = $mail;
        $this->sent[] = $mail;
        $this->logger->info($this->format($mail));
        return true;
    }

    public function format(Mail $mail): string {
        $lines = [
            'Mail (not sent, LogMailer is in use)',
            '  To:      '.$mail->to(),
            '  From:    '.$mail->from(),
            '  Subject: '.$mail->subject,
        ];
        if ($mail->hasTextBody()) {
            $lines[] = '  --- text body ---';
            $lines[] = $mail->textBody;
        }
        $lines[] = '  --- html body ---';
        $lines[] = $mail->htmlBody;
        return join("\n", $lines);
    }

    public function lastMail(): ?Mail {
        return $this->lastMail;
    }

    /**
     * @return Mail[]
     */
    public function sent(): array {
        return $this->sent;
    }

    public function clear(): void {
        $this->lastMail = null;
        $this->sent = [];
    }
}
