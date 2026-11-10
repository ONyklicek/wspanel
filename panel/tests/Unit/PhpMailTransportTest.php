<?php

namespace Tests\Unit;

use App\Mail\PhpMailTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

class PhpMailTransportTest extends TestCase
{
    public function test_it_hands_mail_to_mail_with_to_and_subject_apart_and_the_envelope_sender(): void
    {
        $calls = [];
        $transport = new PhpMailTransport(function (string ...$args) use (&$calls) {
            $calls[] = $args;

            return true;
        });

        $transport->send((new Email)->from('wspanel@panel.example.com')->to('admin@example.com')
            ->subject('[WSPanel] Zkušební e-mail')->text("Odesílání funguje.\n"));

        $this->assertCount(1, $calls);
        [$to, $subject, $body, $headers, $params] = $calls[0];
        $this->assertSame('admin@example.com', $to);
        $this->assertStringContainsString('Zku', iconv_mime_decode($subject, 0, 'UTF-8'));
        $this->assertStringContainsString('Odes', quoted_printable_decode($body));
        $this->assertStringContainsString('From: wspanel@panel.example.com', $headers);
        $this->assertStringNotContainsString("\r\nTo:", "\r\n".$headers);
        $this->assertStringNotContainsString("\r\nSubject:", "\r\n".$headers);
        $this->assertSame('-fwspanel@panel.example.com', $params);
    }

    public function test_a_refused_message_is_an_error(): void
    {
        $transport = new PhpMailTransport(fn () => false);

        $this->expectException(TransportException::class);
        $transport->send((new Email)->from('a@example.com')->to('b@example.com')->subject('x')->text('y'));
    }
}
