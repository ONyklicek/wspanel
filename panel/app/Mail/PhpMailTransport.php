<?php

namespace App\Mail;

use Closure;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Hands mail to the local Postfix through PHP's mail(). The panel's PHP-FPM
 * pool disables proc_open (and so Symfony's sendmail transport); mail()
 * starts sendmail itself and is not affected, so e-mails sent from a web
 * request (a test e-mail, a password reset, a notification raised by a
 * deploy webhook) leave just like those sent from the queue.
 *
 * Recipients are read by sendmail from To and Cc (`sendmail -t`); the panel
 * sends no Bcc.
 */
class PhpMailTransport extends AbstractTransport
{
    /** @param  (Closure(string, string, string, string, string): bool)|null  $mailer  mail() in production; tests pass their own */
    public function __construct(private readonly ?Closure $mailer = null)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        [$head, $body] = explode("\r\n\r\n", $message->toString(), 2) + [1 => ''];

        $to = $subject = '';
        $headers = [];
        foreach (preg_split('/\r\n(?![ \t])/', $head) as $line) {
            $name = strtolower(strstr($line, ':', true) ?: '');
            $value = ltrim(substr($line, strlen($name) + 1));
            match ($name) {
                'to' => $to = $value,
                'subject' => $subject = $value,
                default => $headers[] = $line,
            };
        }
        if ($to === '') {
            throw new TransportException('The message has no To header.');
        }

        $sender = $message->getEnvelope()->getSender()->getAddress();
        $params = filter_var($sender, FILTER_VALIDATE_EMAIL) !== false ? '-f'.$sender : '';

        $send = $this->mailer ?? fn (string $to, string $subject, string $body, string $headers, string $params): bool => mail($to, $subject, $body, $headers, $params);
        if (! $send($to, $subject, $body, implode("\r\n", $headers), $params)) {
            $error = error_get_last()['message'] ?? 'mail() failed';
            throw new TransportException('The local mail server did not accept the message: '.$error);
        }
    }

    public function __toString(): string
    {
        return 'phpmail://default';
    }
}
