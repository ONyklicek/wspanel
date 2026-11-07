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
            $f === ['*', '*', '*', '*', '*'] => 'každou minutu',
            preg_match('/^\*\/(\d+)$/', $min, $m) === 1 && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*' => "každých {$m[1]} minut",
            $num($min) && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*' => sprintf('každou hodinu v :%02d', (int) $min),
            $num($min) && preg_match('/^\*\/(\d+)$/', $hour, $m) === 1 && $dom === '*' && $mon === '*' && $dow === '*' => sprintf('každých %d hodin v :%02d', (int) $m[1], (int) $min),
            $num($min) && $num($hour) && $dom === '*' && $mon === '*' && $dow === '*' => 'denně v '.$at(),
            $num($min) && $num($hour) && $dom === '*' && $mon === '*' && $dow === '1-5' => 'v pracovní dny v '.$at(),
            $num($min) && $num($hour) && $dom === '*' && $mon === '*' && $num($dow) && (int) $dow <= 7 => self::DAYS[(int) $dow].' v '.$at(),
            $num($min) && $num($hour) && $num($dom) && $mon === '*' && $dow === '*' => "každý měsíc {$dom}. den v ".$at(),
            default => null,
        };
    }
}
