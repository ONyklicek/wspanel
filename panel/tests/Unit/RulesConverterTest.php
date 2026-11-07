<?php

namespace Tests\Unit;

use App\Support\WebRules\RulesConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RulesConverterTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function rules(): array
    {
        return [
            'nginx return' => ['return 301 https://example.com$request_uri;', 'redir https://example.com{uri} 301'],
            'nginx regex redirect' => ['rewrite ^/old/(.*)$ /new/$1 permanent;', "@r1 path_regexp r1 ^/old/(.*)$\nredir @r1 /new/{re.r1.1} 301"],
            'nginx header' => ['add_header X-Frame-Options "SAMEORIGIN" always;', 'header X-Frame-Options "SAMEORIGIN"'],
            'nginx caching location' => ["location ~* \\.(css|js)$ {\n    expires 30d;\n}", "@loc1 path_regexp (?i)\\.(css|js)$\nheader @loc1 Cache-Control \"public, max-age=2592000\""],
            'nginx denied location' => ["location /private {\n    deny all;\n}", "@loc1 path /private*\nrespond @loc1 403"],
            'nginx gzip' => ['gzip on;', 'encode gzip zstd'],
            'nginx body size' => ['client_max_body_size 64M;', "request_body {\n\tmax_size 64MB\n}"],
            'apache redirect' => ['Redirect 301 /a /b', 'redir /a /b 301'],
            'apache rewrite redirect' => ['RewriteRule ^blog/(.*)$ /news/$1 [R=301,L]', "@r1 path_regexp r1 ^/blog/(.*)$\nredir @r1 /news/{re.r1.1} 301"],
            'apache forbidden' => ['RewriteRule ^secret - [F]', "@f1 path_regexp ^/secret\nrespond @f1 403"],
            'apache header' => ['Header set X-Test "a b"', 'header X-Test "a b"'],
            'apache deny' => ['Require all denied', 'respond 403'],
        ];
    }

    #[DataProvider('rules')]
    public function test_common_rules_convert(string $source, string $caddy): void
    {
        $this->assertSame($caddy, (new RulesConverter)->convert($source));
    }

    public function test_a_conditional_rewrite_is_left_for_a_person(): void
    {
        $out = (new RulesConverter)->convert("RewriteCond %{HTTP_HOST} ^www\\.\nRewriteRule ^(.*)$ https://x/$1 [R=301]");

        $this->assertStringNotContainsString("\nredir", "\n".$out);
        $this->assertStringContainsString('# nepřevedeno (pravidlo s podmínkou, upravte ručně)', $out);
    }

    public function test_a_front_controller_needs_nothing(): void
    {
        $out = (new RulesConverter)->convert("RewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(.*)$ index.php [QSA,L]");

        $this->assertStringContainsString('# bez náhrady (front controller řeší panel sám)', $out);
        $this->assertStringNotContainsString('rewrite ', $out);
    }

    public function test_unknown_rules_stay_as_comments(): void
    {
        $this->assertSame('# nepřevedeno: proxy_pass http://backend;', (new RulesConverter)->convert('proxy_pass http://backend;'));
    }
}
