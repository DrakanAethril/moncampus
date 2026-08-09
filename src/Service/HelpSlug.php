<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns a title into the ASCII slug the help uses in its URLs and heading anchors.
 *
 * Kept apart from the two callers because they must agree: App\Service\HelpArticleOutline slugs a
 * heading to anchor it, App\Controller\HelpAdminController slugs a title when the author leaves the
 * slug field empty, and both are read back by a human in an address bar.
 */
class HelpSlug
{
    private const array FOLDED = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
    ];

    public function from(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/u', '-', strtr(mb_strtolower(trim($value)), self::FOLDED)) ?? '';

        return trim($slug, '-');
    }
}
