<?php

namespace App\Services\Mail;

use App\Models\DmarcRecord;
use App\Models\DmarcReport;
use App\Models\MailDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

/**
 * DMARC aggregate reports (RFC 7489, appendix C) for the hosted mail domains.
 *
 * Postfix hands every message for dmarc-reports@<domain> to the panel's spool
 * (provision/files/dmarc-receive); each one carries the report as an XML
 * attachment, plain, gzipped or zipped. A report about a domain this server
 * does not host is dropped, and so is anything that is not a report.
 */
class DmarcReports
{
    /** Largest report accepted, unpacked (bytes). */
    public const MAX_XML = 20 * 1024 * 1024;

    /** Most sources stored from one report. */
    public const MAX_RECORDS = 10_000;

    /**
     * Reads every message in the spool and deletes it (a message that is not
     * a report is deleted too: nobody reads that address).
     *
     * @return array{reports: int, ignored: int}
     */
    public function processSpool(string $dir): array
    {
        $out = ['reports' => 0, 'ignored' => 0];
        foreach (glob(rtrim($dir, '/').'/*.eml') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $stored = is_string($raw) ? $this->receive($raw) : 0;
            $out[$stored > 0 ? 'reports' : 'ignored'] += max($stored, 1);
            @unlink($file);
        }

        return $out;
    }

    /** Stores the reports in one message; returns how many were new. */
    public function receive(string $message): int
    {
        $stored = 0;
        foreach ($this->attachments($message) as $xml) {
            try {
                $stored += $this->store($xml) ? 1 : 0;
            } catch (Throwable) {
                // Not a report (or a broken one): nothing to keep.
            }
        }

        return $stored;
    }

    /** Deletes reports older than DmarcReport::KEEP_DAYS. */
    public function prune(): int
    {
        return DmarcReport::withoutGlobalScopes()->where('ends_at', '<', now()->subDays(DmarcReport::KEEP_DAYS))->delete();
    }

    /** Stores one report; false when it is not one, is about a domain not hosted here, or is stored already. */
    public function store(string $xml): bool
    {
        if (strlen($xml) > self::MAX_XML || stripos($xml, '<!DOCTYPE') !== false) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if (! $doc instanceof SimpleXMLElement || $doc->getName() !== 'feedback') {
            return false;
        }
        $domainName = strtolower(trim((string) $doc->policy_published->domain));
        $domain = MailDomain::withoutGlobalScopes()->where('domain', $domainName)->first();
        $reportId = trim((string) $doc->report_metadata->report_id);
        $org = trim((string) $doc->report_metadata->org_name);
        if ($domain === null || $reportId === '' || $org === '') {
            return false;
        }
        $exists = DmarcReport::withoutGlobalScopes()->where([
            'mail_domain_id' => $domain->id, 'org_name' => mb_substr($org, 0, 255), 'report_id' => mb_substr($reportId, 0, 255),
        ])->exists();
        if ($exists) {
            return false;
        }

        $records = [];
        $messages = $failed = 0;
        foreach ($doc->record as $r) {
            if (count($records) >= self::MAX_RECORDS) {
                break;
            }
            $ip = trim((string) $r->row->source_ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $count = max(0, (int) $r->row->count);
            $dkim = $this->result((string) $r->row->policy_evaluated->dkim);
            $spf = $this->result((string) $r->row->policy_evaluated->spf);
            $messages += $count;
            if ($dkim !== 'pass' && $spf !== 'pass') {
                $failed += $count;
            }
            $records[] = [
                'source_ip' => $ip,
                'count' => $count,
                'disposition' => $this->word((string) $r->row->policy_evaluated->disposition, 'none'),
                'dkim' => $dkim,
                'spf' => $spf,
                'header_from' => mb_substr(strtolower(trim((string) $r->identifiers->header_from)) ?: $domainName, 0, 255),
                'dkim_domain' => $this->domainOrNull((string) ($r->auth_results->dkim[0]->domain ?? '')),
                'spf_domain' => $this->domainOrNull((string) ($r->auth_results->spf[0]->domain ?? '')),
            ];
        }

        DB::transaction(function () use ($domain, $doc, $org, $reportId, $records, $messages, $failed) {
            $report = new DmarcReport([
                'mail_domain_id' => $domain->id,
                'org_name' => mb_substr($org, 0, 255),
                'org_email' => mb_substr(trim((string) $doc->report_metadata->email), 0, 255) ?: null,
                'report_id' => mb_substr($reportId, 0, 255),
                'begins_at' => CarbonImmutable::createFromTimestampUTC((int) $doc->report_metadata->date_range->begin),
                'ends_at' => CarbonImmutable::createFromTimestampUTC((int) $doc->report_metadata->date_range->end),
                'policy' => $this->word((string) $doc->policy_published->p, '') ?: null,
                'messages' => $messages,
                'failed' => $failed,
            ]);
            $report->client_id = $domain->client_id;
            $report->save();
            foreach (array_chunk($records, 500) as $chunk) {
                DmarcRecord::query()->insert(array_map(fn (array $row) => $row + ['dmarc_report_id' => $report->id], $chunk));
            }
        });

        return true;
    }

    /**
     * The XML documents a message carries: attachments (and bodies) that are
     * XML, gzipped XML or a zip of XML.
     *
     * @return list<string>
     */
    public function attachments(string $message): array
    {
        $out = [];
        foreach ($this->parts($message) as [$headers, $body]) {
            $type = strtolower($headers['content-type'] ?? 'text/plain');
            $name = strtolower($this->param($headers['content-disposition'] ?? '', 'filename') ?? $this->param($type, 'name') ?? '');
            if (str_contains($type, 'zip') && ! str_contains($type, 'gzip') || str_ends_with($name, '.zip')) {
                array_push($out, ...$this->unzip($body));
            } elseif (str_contains($type, 'gzip') || str_ends_with($name, '.gz')) {
                $xml = @gzdecode($body, self::MAX_XML);
                if (is_string($xml)) {
                    $out[] = $xml;
                }
            } elseif (str_contains($type, 'xml') || str_ends_with($name, '.xml')) {
                $out[] = $body;
            }
        }

        return $out;
    }

    /**
     * The leaf parts of a MIME message, decoded: [headers (lower-case names), body].
     *
     * @return list<array{0: array<string, string>, 1: string}>
     */
    private function parts(string $entity, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }
        $split = preg_split("/\r?\n\r?\n/", $entity, 2);
        $headers = $this->headers($split[0] ?? '');
        $body = $split[1] ?? '';
        $type = $headers['content-type'] ?? 'text/plain';
        if (str_starts_with(strtolower(trim($type)), 'multipart/')) {
            $boundary = $this->param($type, 'boundary');
            if ($boundary === null || $boundary === '') {
                return [];
            }
            $out = [];
            $chunks = explode('--'.$boundary, $body);
            array_shift($chunks); // the preamble
            foreach ($chunks as $chunk) {
                if (str_starts_with($chunk, '--')) {
                    break; // the closing boundary
                }
                array_push($out, ...$this->parts(ltrim($chunk, "\r\n"), $depth + 1));
            }

            return $out;
        }
        $body = match (strtolower(trim($headers['content-transfer-encoding'] ?? ''))) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };

        return [[$headers, $body]];
    }

    /** @return array<string, string> */
    private function headers(string $block): array
    {
        $out = [];
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $block) ?? '';
        foreach (preg_split("/\r?\n/", $unfolded) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $out[strtolower(trim($name))] = trim($value);
            }
        }

        return $out;
    }

    private function param(string $header, string $name): ?string
    {
        return preg_match('/(?:^|;)\s*'.preg_quote($name, '/').'\*?="?([^";]*)"?/i', $header, $m) ? trim($m[1]) : null;
    }

    /** @return list<string> */
    private function unzip(string $data): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dmarc');
        if ($tmp === false) {
            return [];
        }
        $out = [];
        try {
            file_put_contents($tmp, $data);
            $zip = new ZipArchive;
            if ($zip->open($tmp) !== true) {
                return [];
            }
            for ($i = 0; $i < min($zip->numFiles, 10); $i++) {
                $stat = $zip->statIndex($i);
                if ($stat !== false && str_ends_with(strtolower($stat['name']), '.xml') && $stat['size'] <= self::MAX_XML) {
                    $xml = $zip->getFromIndex($i, self::MAX_XML);
                    if (is_string($xml)) {
                        $out[] = $xml;
                    }
                }
            }
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return $out;
    }

    private function result(string $value): string
    {
        return strtolower(trim($value)) === 'pass' ? 'pass' : 'fail';
    }

    private function word(string $value, string $default): string
    {
        $v = strtolower(trim($value));

        return preg_match('/^[a-z]{1,16}$/', $v) ? $v : $default;
    }

    private function domainOrNull(string $value): ?string
    {
        $v = strtolower(trim($value));

        return $v !== '' && strlen($v) <= 255 && preg_match('/^[a-z0-9._-]+$/', $v) ? $v : null;
    }
}
