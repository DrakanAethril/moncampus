<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

use App\Service\AsciiFolder;

/**
 * The spelling under which two lines - or a line and an existing account - are considered to name
 * the same person: firstname and lastname each folded, joined by a pipe.
 *
 * Folds much harder than App\Service\ClassImport\NameNormalizer, which decides how a name is
 * *written*: here `-`, `'` and `’` all become a space, so "Jean-Baptiste" and "Jean Baptiste" are
 * one person and "Bachir-bey" and "Bachir Bey" are one person. Matching on the name alone, never
 * on the address, is deliberate - two siblings sometimes share a mailbox, and an import must not
 * merge two people for that reason.
 *
 * The two parts stay separated so "Jean Paul / Dupont" can never collide with "Jean / Paul Dupont".
 */
final readonly class NameKey
{
    private function __construct(public string $value)
    {
    }

    public static function of(string $firstname, string $lastname): self
    {
        return new self(self::fold($firstname).'|'.self::fold($lastname));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function fold(string $part): string
    {
        $folded = strtr(AsciiFolder::fold($part), ['-' => ' ', "'" => ' ', '’' => ' ']);

        return trim((string) preg_replace('/\s+/u', ' ', $folded));
    }
}
