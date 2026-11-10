<?php

namespace App\Support;

/**
 * Command output made for a terminal, shown in a page. Laravel's two-column
 * lines ("nyoncode/wire-panels ........ DONE", migrations) pad with dots to
 * the terminal's width; in a narrower box the line wraps and DONE ends up
 * alone below it. The dots are what a terminal needed, not the output.
 */
final class ConsoleText
{
    public static function tidy(string $text): string
    {
        return (string) preg_replace('/ \.{3,} /', ' … ', $text);
    }
}
