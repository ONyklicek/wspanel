<?php

namespace Tests\Unit;

use App\Services\Dns\Resolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** The answer of a zone's own nameserver, read into dns_get_record()'s shape. */
class ResolverParseTest extends TestCase
{
    private function wireName(string $name): string
    {
        $out = '';
        foreach (explode('.', $name) as $label) {
            $out .= chr(strlen($label)).$label;
        }

        return $out."\0";
    }

    /** @param  list<array{int, string}>  $answers  type => rdata, each owned by the question's name (a pointer to offset 12) */
    private function reply(int $id, int $flags, string $qname, int $qtype, array $answers): string
    {
        $msg = pack('nnnnnn', $id, $flags, 1, count($answers), 0, 0).$this->wireName($qname).pack('nn', $qtype, 1);
        foreach ($answers as [$type, $rdata]) {
            $msg .= "\xC0\x0C".pack('nnNn', $type, 1, 300, strlen($rdata)).$rdata;
        }

        return $msg;
    }

    private function parse(string $msg, int $id): ?array
    {
        return (new ReflectionMethod(Resolver::class, 'parse'))->invoke(new Resolver, $msg, $id);
    }

    public function test_records_of_each_kind_are_read(): void
    {
        $msg = $this->reply(7, 0x8400, 'example.cz', 16, [
            [1, inet_pton('203.0.113.7')],
            [28, inet_pton('2001:db8::1')],
            [16, "\x05v=spf\x07 a -all"],               // a TXT of two strings is one value
            [15, pack('n', 10)."\x04mail\xC0\x0C"],     // MX with a compressed target
            [257, "\x00\x05issueletsencrypt.org"],
        ]);

        $out = $this->parse($msg, 7);

        $this->assertTrue($out['authoritative']);
        [$a, $aaaa, $txt, $mx, $caa] = $out['records'];
        $this->assertSame('203.0.113.7', $a['ip']);
        $this->assertSame('2001:db8::1', $aaaa['ipv6']);
        $this->assertSame('v=spf a -all', $txt['txt']);
        $this->assertSame(['pri' => 10, 'target' => 'mail.example.cz'], ['pri' => $mx['pri'], 'target' => $mx['target']]);
        $this->assertSame(['issue', 'letsencrypt.org'], [$caa['tag'], $caa['value']]);
    }

    public function test_an_answer_that_is_not_ours_or_a_failure_is_not_used(): void
    {
        $this->assertNull($this->parse($this->reply(7, 0x8400, 'example.cz', 1, []), 8));  // another id
        $this->assertNull($this->parse($this->reply(7, 0x8402, 'example.cz', 1, []), 7));  // SERVFAIL
        $this->assertSame([], $this->parse($this->reply(7, 0x8400, 'example.cz', 1, []), 7)['records']); // no such record: an answer
    }
}
