<?php

namespace App\Support;

/**
 * Translations of labels kept in constants (a constant cannot call __()):
 * translated where they are shown, keyed by the Czech text like the rest.
 */
final class Lang
{
    /**
     * @template T of array
     *
     * @param  T  $values
     * @return T the same keys, string values translated
     */
    public static function values(array $values): array
    {
        return array_map(fn ($v) => is_string($v) ? __($v) : (is_array($v) ? self::values($v) : $v), $values);
    }
}
