<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The characters every e-CO code is drawn from - the course join code a teacher reads out, and
 * the short code printed under each checkpoint's QR. 0/O and 1/I are left out: both codes are
 * read off a screen or a sheet of paper nailed to a tree and typed by hand, and a character that
 * can be mistaken for another is a scan refused for nothing.
 *
 * Drawn with random_int(), never derived from anything: a checkpoint code that could be worked out
 * from another one (the previous scheme, « PVT-B03 » after « PVT-B02 ») lets a runner validate a
 * flag they never reached.
 */
final class EcoRandomCode
{
    public const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function draw(int $length): string
    {
        $last = \strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; ++$i) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }
}
