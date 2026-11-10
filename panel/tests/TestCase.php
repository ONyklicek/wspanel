<?php

namespace Tests;

use App\Enums\Game;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Features\SupportTesting\Testable;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The games the packages make are remembered per process: each test anew.
        Game::flush();

        if (! Testable::hasMacro('assertNotified')) {
            /*
             * A WireStack toast was raised, optionally of a type ("success",
             * "danger"…) and with the given text in its title or body.
             */
            Testable::macro('assertNotified', function (?string $contains = null, ?string $type = null): Testable {
                /** @var Testable $this */
                return $this->assertDispatched('table-notification', function (string $name, array $params) use ($type, $contains) {
                    $text = ($params['title'] ?? '').' '.($params['message'] ?? '');

                    return ($type === null || ($params['type'] ?? null) === $type)
                        && ($contains === null || str_contains($text, $contains));
                });
            });
        }
    }
}
