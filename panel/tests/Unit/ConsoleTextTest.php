<?php

namespace Tests\Unit;

use App\Support\ConsoleText;
use PHPUnit\Framework\TestCase;

class ConsoleTextTest extends TestCase
{
    public function test_the_dots_a_terminal_pads_with_are_shortened(): void
    {
        $this->assertSame('  nyoncode/wire-panels … DONE', ConsoleText::tidy('  nyoncode/wire-panels '.str_repeat('.', 50).' DONE'));
        $this->assertSame('  2026_01_01_create_users_table … 3.51ms DONE', ConsoleText::tidy('  2026_01_01_create_users_table ........ 3.51ms DONE'));
        $this->assertSame('Loading... done', ConsoleText::tidy('Loading... done')); // an ellipsis in a sentence stays
    }
}
