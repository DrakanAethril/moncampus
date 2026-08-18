<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Lowercases a string and strips the accents the school's files actually carry.
 *
 * Deliberately not iconv('ASCII//TRANSLIT'), whose output depends on the runtime's locale - text
 * folded here decides whether two spellings are the same thing (a CSV header, a person's name),
 * so it must behave identically in the container, in CI and on a dev Mac.
 *
 * Extracted from App\Service\QuizCsvImporter, whose header matching was the first caller; the
 * table is unchanged, so a quiz file that imported before still imports.
 */
final class AsciiFolder
{
    private const array ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ÿ' => 'y', 'ñ' => 'n',
    ];

    public static function fold(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), self::ACCENTS);
    }
}
