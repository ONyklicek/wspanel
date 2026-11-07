<?php

namespace App\Support\WebRules;

/**
 * Turns the common nginx and Apache (.htaccess) rules into the Caddy rules a
 * site's edge understands: redirects, rewrites, headers, caching, compression,
 * body size and denied paths. What it does not know stays in the output as a
 * comment, so nothing is dropped silently and the result is still valid.
 */
final class RulesConverter
{
    private int $matcher = 0;

    /** An Apache RewriteCond was seen: the next RewriteRule depends on it. */
    private bool $condition = false;

    /** @var list<string> */
    private array $out = [];

    public function convert(string $source): string
    {
        $this->matcher = 0;
        $this->condition = false;
        $this->out = [];
        $lines = preg_split('/\R/', $source) ?: [];
        $this->block($lines, 0, null);

        return trim(implode("\n", $this->out));
    }

    /**
     * @param  list<string>  $lines
     * @param  ?string  $scope  matcher name of an enclosing nginx location
     */
    private function block(array $lines, int $from, ?string $scope): int
    {
        for ($i = $from; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($line === '}') {
                return $i;
            }
            // nginx: location [modifier] pattern {
            if (preg_match('/^location\s+(=|~\*?|\^~)?\s*(\S+)\s*\{$/', $line, $m)) {
                $name = '@loc'.(++$this->matcher);
                $pattern = $m[2];
                $this->out[] = match ($m[1]) {
                    '~' => "{$name} path_regexp {$pattern}",
                    '~*' => "{$name} path_regexp (?i){$pattern}",
                    '=' => "{$name} path {$pattern}",
                    default => "{$name} path {$pattern}*",
                };
                $i = $this->block($lines, $i + 1, $name);

                continue;
            }
            $converted = $this->nginx($line, $scope) ?? $this->apache($line, $scope);
            $this->out[] = $converted ?? '# nepřevedeno: '.$line;
        }

        return count($lines);
    }

    private function at(?string $scope): string
    {
        return $scope === null ? '' : $scope.' ';
    }

    private function nginx(string $line, ?string $scope): ?string
    {
        $line = rtrim($line, ';');
        $at = $this->at($scope);

        return match (true) {
            (bool) preg_match('/^return\s+(30[1278])\s+(\S+)$/', $line, $m) => "redir {$at}".$this->nginxVars($m[2])." {$m[1]}",
            (bool) preg_match('/^return\s+(4\d\d|5\d\d)$/', $line, $m) => "respond {$at}{$m[1]}",
            (bool) preg_match('/^rewrite\s+(\S+)\s+(\S+)\s+(permanent|redirect)$/', $line, $m) => $this->regexRedirect($m[1], $m[2], $m[3] === 'permanent' ? 301 : 302),
            (bool) preg_match('/^rewrite\s+(\S+)\s+(\S+)(\s+(last|break))?$/', $line, $m) => $this->regexRewrite($m[1], $m[2]),
            (bool) preg_match('/^add_header\s+(\S+)\s+"?(.*?)"?(\s+always)?$/', $line, $m) => "header {$at}{$m[1]} \"".addslashes($m[2]).'"',
            (bool) preg_match('/^expires\s+(\d+)([smhdwy]?)$/', $line, $m) => "header {$at}Cache-Control \"public, max-age=".$this->seconds((int) $m[1], $m[2]).'"',
            (bool) preg_match('/^expires\s+(max|epoch|off)$/', $line, $m) => match ($m[1]) {
                'max' => "header {$at}Cache-Control \"public, max-age=31536000, immutable\"",
                'epoch' => "header {$at}Cache-Control \"no-cache\"",
                default => null,
            },
            (bool) preg_match('/^gzip\s+on$/', $line) => 'encode gzip zstd',
            (bool) preg_match('/^client_max_body_size\s+(\d+)([kKmMgG]?)$/', $line, $m) => "request_body {$at}{\n\tmax_size {$m[1]}".strtoupper($m[2]).($m[2] === '' ? '' : 'B')."\n}",
            (bool) preg_match('/^deny\s+all$/', $line) => "respond {$at}403",
            default => null,
        };
    }

    private function apache(string $line, ?string $scope): ?string
    {
        $at = $this->at($scope);
        if (preg_match('/^RewriteCond\b/i', $line)) {
            $this->condition = true;

            return '# nepřevedeno (podmínka): '.$line;
        }
        if (preg_match('/^RewriteRule\s+\S+\s+(\S+)/i', $line, $rule)) {
            $conditional = $this->condition;
            $this->condition = false;
            if (preg_match('#^/?index\.php#i', $rule[1])) {
                return '# bez náhrady (front controller řeší panel sám): '.$line;
            }
            if ($conditional) {
                return '# nepřevedeno (pravidlo s podmínkou, upravte ručně): '.$line;
            }
        }

        return match (true) {
            (bool) preg_match('/^Redirect(Match)?\s+(permanent|301|302|temp|303|307|308)?\s*(\S+)\s+(\S+)$/i', $line, $m) => $m[1] !== ''
                ? $this->regexRedirect($m[3], $m[4], $this->apacheCode($m[2]))
                : "redir {$m[3]} {$m[4]} ".$this->apacheCode($m[2]),
            (bool) preg_match('/^RewriteRule\s+(\S+)\s+(\S+)\s*\[([^\]]*)\]$/i', $line, $m) && preg_match('/R(=(\d{3}))?/i', $m[3], $r) => $this->regexRedirect($this->apachePath($m[1]), $this->slash($m[2]), (int) ($r[2] ?? 302)),
            (bool) preg_match('/^RewriteRule\s+(\S+)\s+-\s*\[[^\]]*F[^\]]*\]$/i', $line, $m) => $this->regexRespond($this->apachePath($m[1]), 403),
            (bool) preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(\s+\[[^\]]*\])?$/i', $line, $m) => $this->regexRewrite($this->apachePath($m[1]), $this->slash($m[2])),
            (bool) preg_match('/^Header\s+(always\s+)?(set|append)\s+(\S+)\s+"?(.*?)"?$/i', $line, $m) => "header {$at}".(strtolower($m[2]) === 'append' ? '+' : '').$m[3].' "'.addslashes($m[4]).'"',
            (bool) preg_match('/^Header\s+(always\s+)?unset\s+(\S+)$/i', $line, $m) => "header {$at}-{$m[2]}",
            (bool) preg_match('/^(Deny\s+from\s+all|Require\s+all\s+denied)$/i', $line) => "respond {$at}403",
            (bool) preg_match('/^(RewriteEngine|RewriteBase|Options|DirectoryIndex|Order|Allow\s+from|Require\s+all\s+granted)\b/i', $line) => '# bez náhrady (Caddy to nepotřebuje): '.$line,
            default => null,
        };
    }

    private function regexRedirect(string $regex, string $to, int $code): string
    {
        $id = ++$this->matcher;

        return "@r{$id} path_regexp r{$id} {$regex}\nredir @r{$id} ".$this->captures($to, "r{$id}")." {$code}";
    }

    private function regexRewrite(string $regex, string $to): string
    {
        $id = ++$this->matcher;

        return "@w{$id} path_regexp w{$id} {$regex}\nrewrite @w{$id} ".$this->captures($to, "w{$id}");
    }

    private function regexRespond(string $regex, int $code): string
    {
        $name = '@f'.(++$this->matcher);

        return "{$name} path_regexp {$regex}\nrespond {$name} {$code}";
    }

    /** $1 backreferences → Caddy's {re.<regexp name>.1}, nginx/Apache variables → placeholders. */
    private function captures(string $to, string $regexpName): string
    {
        $to = preg_replace('/\$(\d)/', '{re.'.$regexpName.'.$1}', $to) ?? $to;

        return $this->nginxVars($to);
    }

    private function nginxVars(string $s): string
    {
        return strtr($s, [
            '$request_uri' => '{uri}', '$uri' => '{path}', '$host' => '{host}', '$args' => '{query}', '$query_string' => '{query}',
            '$scheme' => '{scheme}', '%{REQUEST_URI}' => '{uri}', '%{HTTP_HOST}' => '{host}', '%{QUERY_STRING}' => '{query}',
        ]);
    }

    /** .htaccess patterns match the path without its leading slash; Caddy's include it. */
    private function apachePath(string $regex): string
    {
        $anchored = str_starts_with($regex, '^');

        return ($anchored ? '^' : '').'/'.ltrim(ltrim($regex, '^'), '/');
    }

    private function slash(string $to): string
    {
        return preg_match('#^(https?://|/)#', $to) ? $to : '/'.$to;
    }

    private function apacheCode(string $code): int
    {
        return match (strtolower($code)) {
            'permanent', '301', '' => 301,
            'temp', '302' => 302,
            default => (int) $code,
        };
    }

    private function seconds(int $n, string $unit): int
    {
        return $n * match ($unit) {
            'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, 'y' => 31536000, default => 1,
        };
    }
}
