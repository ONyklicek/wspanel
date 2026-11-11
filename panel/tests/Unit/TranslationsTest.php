<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every text the panel shows in a translation call has both languages: a
 * Czech key its English in lang/en.json, an English key (the published
 * wireStack views) its Czech in lang/cs.json. Without it a new screen shows
 * Czech to an English user, or English to a Czech one, and nothing fails.
 */
class TranslationsTest extends TestCase
{
    private const CZECH = '/[ěščřžýáíéúůťďňĚŠČŘŽÝÁÍÉÚŮŤĎŇ]/u';

    /** @return array<string, string> key => file */
    private function keys(): array
    {
        $root = dirname(__DIR__, 2);
        $string = '\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"';
        $calls = '/(?:__|trans_choice|@lang)\(\s*(?:'.$string.')/';
        $counts = '/Lang::count\([^,]+,\s*(?:'.$string.')\s*,\s*(?:'.$string.')\s*,\s*(?:'.$string.')/';
        $out = [];
        foreach (['app', 'resources/views'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}"));
            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                $found = [];
                if (preg_match_all($calls, $src, $m, PREG_SET_ORDER)) {
                    foreach ($m as $match) {
                        $found[] = $this->unquote($match[1] ?? '', $match[2] ?? null);
                    }
                }
                if (preg_match_all($counts, $src, $m, PREG_SET_ORDER)) {
                    foreach ($m as $match) {
                        for ($i = 1; $i <= 6; $i += 2) {
                            if (($match[$i] ?? '') !== '' || ($match[$i + 1] ?? '') !== '') {
                                $found[] = $this->unquote($match[$i] ?? '', $match[$i + 1] ?? null);
                            }
                        }
                    }
                }
                foreach ($found as $key) {
                    $out[$key] ??= substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        return $out;
    }

    private function unquote(string $single, ?string $double): string
    {
        if ($double !== null && $double !== '') {
            return stripcslashes($double);
        }

        return str_replace(["\\'", '\\\\'], ["'", '\\'], $single);
    }

    /** @return array<string, string> */
    private function json(string $locale): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2)."/lang/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_czech_text_has_its_english(): void
    {
        $en = $this->json('en');
        $missing = array_filter($this->keys(), fn (string $file, string $key) => preg_match(self::CZECH, $key) === 1 && ! array_key_exists($key, $en), ARRAY_FILTER_USE_BOTH);

        $this->assertSame([], $missing, 'Missing in lang/en.json');
    }

    public function test_every_english_text_has_its_czech(): void
    {
        $cs = $this->json('cs');
        $en = $this->json('en'); // a Czech text without diacritics ("Port", "Firma") is there
        $missing = array_filter($this->keys(), fn (string $file, string $key) => preg_match(self::CZECH, $key) === 0 && ! array_key_exists($key, $en)
            && preg_match('/[A-Za-z]{2}/', $key) === 1 && ! str_contains($key, '::') && preg_match('/^[a-z0-9_-]+(\.[a-z0-9_.-]+)+$/', $key) === 0
            && ! array_key_exists($key, $cs), ARRAY_FILTER_USE_BOTH);

        $this->assertSame([], $missing, 'Missing in lang/cs.json');
    }
}
