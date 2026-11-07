<?php

namespace App\Wire\Forms;

use Closure;
use Illuminate\Validation\Rule;

/**
 * Validation that a Select/Radio value is one of the options it offered.
 *
 * WireStack 2.9 adds this rule by itself only for enum options; a field with an
 * array of options otherwise accepts any value the browser sends.
 */
final class Options
{
    public static function rule(): Closure
    {
        return function ($component) {
            $in = Rule::in(array_map('strval', array_keys($component->getOptions())));

            return $component->isRequired() ? $in : ['nullable', $in];
        };
    }
}
