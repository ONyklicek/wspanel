<?php

namespace App\Services\Dns;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Public DNS lookups (a seam, so checks are testable without the network).
 *
 * A record is asked of the zone's own (authoritative) nameservers: a check
 * right after a record was added then sees it, where the server's resolver
 * may keep answering "no such record" for the zone's negative TTL (often an
 * hour). Anything the direct answer cannot settle — no nameserver found, a
 * CNAME into another zone, a timeout, a malformed reply — goes through the
 * system resolver as before.
 */
class Resolver
{
    /** Seconds to wait for one nameserver. */
    private const TIMEOUT = 2;

    private const TYPES = [DNS_A => 1, DNS_NS => 2, DNS_CNAME => 5, DNS_SOA => 6, DNS_MX => 15, DNS_TXT => 16, DNS_AAAA => 28, DNS_SRV => 33, DNS_CAA => 257];

    /** @var array<string, list<string>> zone => nameserver addresses, for this request */
    private array $nameservers = [];

    /** @return list<string> the given field of each record */
    public function records(string $name, int $type, string $field): array
    {
        $name = rtrim(strtolower(trim($name)), '.');
        $direct = isset(self::TYPES[$type]) && $name !== '' ? $this->authoritative($name, $type) : null;
        if ($direct !== null) {
            return array_values(array_filter(array_map(fn (array $r) => isset($r[$field]) ? (string) $r[$field] : null, $direct), fn ($v) => $v !== null));
        }
        $records = @dns_get_record($name, $type) ?: [];

        return array_values(array_filter(array_map(fn ($r) => isset($r[$field]) ? (string) $r[$field] : null, $records)));
    }

    /** The reverse (PTR) name of an IP address, null when there is none. */
    public function reverse(string $ip): ?string
    {
        $name = @gethostbyaddr($ip);

        return ($name === false || $name === $ip) ? null : rtrim(strtolower($name), '.');
    }

    /**
     * The records as the zone's nameservers have them, in dns_get_record()'s
     * shape; null when that cannot be told (the caller asks the resolver).
     *
     * @return list<array<string, mixed>>|null
     */
    private function authoritative(string $name, int $type): ?array
    {
        try {
            foreach ($this->zoneNameservers($name) as $ip) {
                $answer = $this->query($ip, $name, self::TYPES[$type]);
                if ($answer === null) {
                    continue; // this one did not answer: the next
                }
                if (! $answer['authoritative']) {
                    return null;
                }
                $records = array_values(array_filter($answer['records'], fn (array $r) => $r['qtype'] === self::TYPES[$type]));
                // A name that is an alias: the records are the target's, maybe in another zone.
                if ($records === [] && $type !== DNS_CNAME && collect($answer['records'])->contains('qtype', 5)) {
                    return null;
                }

                return array_map(fn (array $r) => array_diff_key($r, ['qtype' => true]), $records);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Addresses of the nameservers of the zone a name lies in: the closest
     * enclosing name that has NS records (asked of the resolver; delegations
     * change rarely and are kept an hour).
     *
     * @return list<string>
     */
    private function zoneNameservers(string $name): array
    {
        $labels = explode('.', $name);
        while (count($labels) >= 2) {
            $zone = implode('.', $labels);
            $ips = $this->nameservers[$zone] ??= Cache::remember('dns.ns.'.$zone, 3600, function () use ($zone): array {
                $hosts = array_column(@dns_get_record($zone, DNS_NS) ?: [], 'target');
                $ips = [];
                foreach (array_slice($hosts, 0, 3) as $host) {
                    foreach (@gethostbynamel((string) $host) ?: [] as $ip) {
                        $ips[] = $ip;
                    }
                }

                return array_values(array_unique($ips));
            });
            if ($ips !== []) {
                return array_slice($ips, 0, 3);
            }
            array_shift($labels);
        }

        return [];
    }

    /**
     * One question to one nameserver (UDP, TCP when the answer is truncated).
     *
     * @return array{authoritative: bool, records: list<array<string, mixed>>}|null null: no usable answer
     */
    private function query(string $ip, string $name, int $qtype): ?array
    {
        $id = random_int(0, 0xFFFF);
        $question = pack('nnnnnn', $id, 0, 1, 0, 0, 0).$this->encodeName($name).pack('nn', $qtype, 1);

        $reply = $this->exchange('udp', $ip, $question);
        if ($reply !== null && strlen($reply) >= 12 && (unpack('n', $reply, 2)[1] & 0x0200)) {
            $reply = $this->exchange('tcp', $ip, pack('n', strlen($question)).$question);
            $reply = $reply !== null && strlen($reply) > 2 ? substr($reply, 2) : null;
        }

        return $reply === null ? null : $this->parse($reply, $id);
    }

    private function exchange(string $transport, string $ip, string $packet): ?string
    {
        $socket = @stream_socket_client("{$transport}://{$ip}:53", $errno, $error, self::TIMEOUT);
        if ($socket === false) {
            return null;
        }
        try {
            stream_set_timeout($socket, self::TIMEOUT);
            if (@fwrite($socket, $packet) !== strlen($packet)) {
                return null;
            }
            if ($transport === 'udp') {
                $reply = @fread($socket, 65535);
            } else {
                $length = @fread($socket, 2);
                if ($length === false || strlen($length) < 2) {
                    return null;
                }
                $want = unpack('n', $length)[1];
                $reply = $length;
                while (strlen($reply) < $want + 2 && ! feof($socket)) {
                    $chunk = @fread($socket, $want + 2 - strlen($reply));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $reply .= $chunk;
                }
            }

            return is_string($reply) && $reply !== '' ? $reply : null;
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return array{authoritative: bool, records: list<array<string, mixed>>}|null
     */
    private function parse(string $msg, int $id): ?array
    {
        if (strlen($msg) < 12) {
            return null;
        }
        $h = unpack('nid/nflags/nqd/nan/nns/nar', $msg);
        $rcode = $h['flags'] & 0x0F;
        if ($h['id'] !== $id || ! ($h['flags'] & 0x8000) || ! in_array($rcode, [0, 3], true)) {
            return null; // not our answer, or SERVFAIL / REFUSED: ask the resolver
        }
        $pos = 12;
        for ($i = 0; $i < $h['qd']; $i++) {
            $this->readName($msg, $pos);
            $pos += 4;
        }
        $records = [];
        for ($i = 0; $i < $h['an']; $i++) {
            $this->readName($msg, $pos);
            if ($pos + 10 > strlen($msg)) {
                return null;
            }
            $rr = unpack('ntype/nclass/Nttl/nlen', $msg, $pos);
            $pos += 10;
            $start = $pos;
            $pos += $rr['len'];
            if ($pos > strlen($msg)) {
                return null;
            }
            $record = $this->rdata($msg, $start, $rr['len'], $rr['type']);
            if ($record !== null) {
                $records[] = $record + ['qtype' => $rr['type'], 'ttl' => $rr['ttl']];
            }
        }

        return ['authoritative' => (bool) ($h['flags'] & 0x0400), 'records' => $records];
    }

    /** @return array<string, mixed>|null the fields dns_get_record() gives for the type */
    private function rdata(string $msg, int $pos, int $len, int $type): ?array
    {
        $data = substr($msg, $pos, $len);

        return match ($type) {
            1 => $len === 4 ? ['ip' => inet_ntop($data)] : null,
            28 => $len === 16 ? ['ipv6' => inet_ntop($data)] : null,
            2, 5 => (function () use ($msg, $pos) {
                $p = $pos;

                return ['target' => $this->readName($msg, $p)];
            })(),
            15 => (function () use ($msg, $pos, $data) {
                $p = $pos + 2;

                return ['pri' => unpack('n', $data)[1], 'target' => $this->readName($msg, $p)];
            })(),
            33 => (function () use ($msg, $pos, $data) {
                $f = unpack('npri/nweight/nport', $data);
                $p = $pos + 6;

                return $f + ['target' => $this->readName($msg, $p)];
            })(),
            6 => (function () use ($msg, $pos) {
                $p = $pos;

                return ['mname' => $this->readName($msg, $p)];
            })(),
            16 => (function () use ($data) {
                $entries = [];
                for ($i = 0; $i < strlen($data);) {
                    $n = ord($data[$i]);
                    $entries[] = substr($data, $i + 1, $n);
                    $i += $n + 1;
                }

                return ['txt' => implode('', $entries), 'entries' => $entries];
            })(),
            257 => $len >= 2 ? ['flags' => ord($data[0]), 'tag' => substr($data, 2, ord($data[1])), 'value' => substr($data, 2 + ord($data[1]))] : null,
            default => null,
        };
    }

    /** A (possibly compressed) name at $pos; moves $pos past it. */
    private function readName(string $msg, int &$pos): string
    {
        $labels = [];
        $jumped = false;
        $at = $pos;
        for ($guard = 0; $guard < 128; $guard++) {
            if ($at >= strlen($msg)) {
                break;
            }
            $n = ord($msg[$at]);
            if ($n === 0) {
                $at++;
                break;
            }
            if (($n & 0xC0) === 0xC0) {
                if (! $jumped) {
                    $pos = $at + 2;
                }
                $jumped = true;
                $at = ((($n & 0x3F) << 8) | ord($msg[$at + 1]));

                continue;
            }
            $labels[] = substr($msg, $at + 1, $n);
            $at += $n + 1;
        }
        if (! $jumped) {
            $pos = $at;
        }

        return strtolower(implode('.', $labels));
    }

    private function encodeName(string $name): string
    {
        $out = '';
        foreach (explode('.', $name) as $label) {
            $label = function_exists('idn_to_ascii') ? (idn_to_ascii($label) ?: $label) : $label;
            $out .= chr(strlen($label)).$label;
        }

        return $out."\0";
    }
}
