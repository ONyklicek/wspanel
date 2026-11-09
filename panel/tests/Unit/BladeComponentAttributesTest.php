<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BladeComponentAttributesTest extends TestCase
{
    /**
     * Blade does not compile directives inside a component tag's attributes:
     * `<x-wire::button wire:click="go(@js($x))">` reaches the browser as is and
     * the click does nothing. Values go in as `{{ Js::from($x) }}` instead.
     */
    public function test_no_directive_sits_inside_a_component_tag(): void
    {
        $offenders = [];
        $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views'));
        foreach ($views as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            preg_match_all('/<x-[\w:.-]+\b[^>]*>/s', (string) file_get_contents($file->getPathname()), $tags);
            foreach ($tags[0] as $tag) {
                if (preg_match('/=\s*"[^"]*@(js|json)\(/', $tag)) {
                    $offenders[] = $file->getFilename().': '.substr($tag, 0, 120);
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
