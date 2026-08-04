<?php

namespace Dynart\Dpress\Mail;

/**
 * Sends through PHP's own `mail()`
 *
 * Enough for a site on shared hosting with a working sendmail. Anything that needs SMTP
 * authentication, a queue or bounce handling wants a different subclass - which is the whole
 * point of the transport being one method.
 */
class NativeMailer extends AbstractMailer {

    protected function deliver(Mail $mail): bool {
        if (!function_exists('mail')) {
            return false;
        }
        [$headers, $body] = $this->build($mail);
        return mail($mail->to(), Mail::encodeHeader($mail->subject), $body, $headers);
    }

    /**
     * Builds the headers and the body
     *
     * With a text alternative it is a `multipart/alternative`, and the **text part comes first**:
     * a mail client picks the last part it can display, so the HTML has to be the later one.
     *
     * @return array [headers string, body string]
     */
    public function build(Mail $mail): array {
        $headers = [
            'From: '.$mail->from(),
            'MIME-Version: 1.0',
        ];
        if ($mail->replyTo() !== '') {
            $headers[] = 'Reply-To: '.$mail->replyTo();
        }
        if (!$mail->hasTextBody()) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return [join("\r\n", $headers), $this->encodeBody($mail->htmlBody)];
        }
        $boundary = 'dpress_'.bin2hex(random_bytes(16));
        $headers[] = 'Content-Type: multipart/alternative; boundary="'.$boundary.'"';
        $body = join("\r\n", [
            '--'.$boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $this->encodeBody($mail->textBody),
            '--'.$boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $this->encodeBody($mail->htmlBody),
            '--'.$boundary.'--',
            ''
        ]);
        return [join("\r\n", $headers), $body];
    }

    /**
     * base64 in 76 character lines, as the MIME specification wants
     */
    protected function encodeBody(string $body): string {
        return chunk_split(base64_encode($body), 76, "\r\n");
    }
}
