<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * Passwords for the accounts laid into a machine.
 *
 * Pronounceable on purpose, and the reason is practical rather than aesthetic: **these are read off
 * a screen and typed by hand**, often from a printed sheet, by a room of students at once. A
 * password nobody stores (see App\Service\Guest\GuestAccountSyncer - none of these is ever written
 * down anywhere) is a password that gets mistyped, and `xK3$vQ9!mB2z` gets mistyped far more often
 * than `tanoli-ferube-42`.
 *
 * The entropy comes from the count, not from the alphabet: three syllable-pairs drawn from 20
 * consonants and 6 vowels plus two digits is on the order of 2^45 - far beyond anything that
 * matters for a lab machine that lives six weeks behind a VLAN, and the sort of thing a good
 * passphrase policy would recognise as reasonable.
 *
 * Random bytes come from random_int(), not mt_rand(): the difference costs nothing here.
 */
class PasswordGenerator
{
    /** No l/1/i/O/0 anywhere: the whole point is that these get read aloud and copied by hand. */
    private const string CONSONANTS = 'bcdfghjkmnprstvwxyz';
    private const string VOWELS = 'aeouy';

    public function generate(int $syllables = 3): string
    {
        $parts = [];

        for ($group = 0; $group < 3; ++$group) {
            $word = '';

            for ($syllable = 0; $syllable < $syllables; ++$syllable) {
                $word .= self::CONSONANTS[random_int(0, \strlen(self::CONSONANTS) - 1)];
                $word .= self::VOWELS[random_int(0, \strlen(self::VOWELS) - 1)];
            }

            $parts[] = $word;
        }

        // Two digits at the end rather than sprinkled through: a password that reads as words plus
        // a number survives being spoken across a room, which is how these actually travel.
        return implode('-', $parts).'-'.random_int(10, 99);
    }
}
