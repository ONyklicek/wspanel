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

    /**
     * A count with the Czech plural of its noun (1 záznam, 2–4 záznamy, 5+
     * záznamů), each form a key of its own so English can say it its way.
     * Not trans_choice(): it falls back to English for any key the current
     * locale's file lacks, and Czech has no file (the keys are the Czech).
     */
    public static function count(int $count, string $one, string $few, string $many): string
    {
        $form = match (true) {
            $count === 1 => $one,
            $count >= 2 && $count <= 4 => $few,
            default => $many,
        };

        return __($form, ['count' => $count]);
    }
}
