<?php

namespace App\Support;

/** A cron schedule in Czech words, for the common shapes; null when it is not one of them. */
final class CronDescription
{
    private const DAYS = ['každou neděli', 'každé pondělí', 'každé úterý', 'každou středu', 'každý čtvrtek', 'každý pátek', 'každou sobotu', 'každou neděli'];

    public static function cs(string $schedule): ?string
    {
        $f = preg_split('/\s+/', trim($schedule)) ?: [];
        if (count($f) !== 5) {
            return null;
        }
        [$min, $hour, $dom, $mon, $dow] = $f;
        $num = fn (string $v) => ctype_digit($v);
        $at = fn () => sprintf('%d:%02d', (int) $hour, (int) $min);

        return match (true) {
            $f === ['*', '*', '*', '*', '*'] => __('každou minutu'),
            preg_match('/^\*\/(\d+)$/', $min, $m) === 1 && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*' => __('každých :m minut', ['m' => $m[1]]),
            $num($min) && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*' => sprintf(__('každou hodinu v :%02d'), (int) $min),
            $num($min) && preg_match('/^\*\/(\d+)$/', $hour, $m) === 1 && $dom === '*' && $mon === '*' && $dow === '*' => sprintf(__('každých %d hodin v :%02d'), (int) $m[1], (int) $min),
            $num($min) && $num($hour) && $dom === '*' && $mon === '*' && $dow === '*' => __('denně v ').$at(),
            $num($min) && $num($hour) && $dom === '*' && $mon === '*' && $dow === '1-5' => __('v pracovní dny v ').$at(),
            $num($min) && $num($hour) && $dom === '*' && $mon === '*' && $num($dow) && (int) $dow <= 7 => __(self::DAYS[(int) $dow]).__(' v ').$at(),
            $num($min) && $num($hour) && $num($dom) && $mon === '*' && $dow === '*' => __('každý měsíc :dom. den v ', ['dom' => $dom]).$at(),
            default => null,
        };
    }
}
