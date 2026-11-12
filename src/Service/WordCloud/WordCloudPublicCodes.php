<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\WordCloud;
use App\Repository\WordCloudRepository;

/**
 * The code of a public cloud's address, /cloud/{code}: given once, the first time the cloud is
 * public, and never changed nor reused - a QR code printed for it stays right.
 */
class WordCloudPublicCodes
{
    // The live contest's alphabet: no I, L, O, 0 or 1, the code is also read aloud and typed.
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly WordCloudRepository $clouds)
    {
    }

    /** Gives the cloud its code when it is public and has none yet. */
    public function ensure(WordCloud $cloud): void
    {
        if (!$cloud->isPublic() || null !== $cloud->getPublicCode()) {
            return;
        }

        do {
            $code = '';
            for ($i = 0; $i < 6; ++$i) {
                $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
        } while (null !== $this->clouds->findOneBy(['publicCode' => $code]));

        $cloud->setPublicCode($code);
    }

    /**
     * The code in what was typed: the code itself, whatever its case and its spaces, or the whole
     * address pasted from somewhere. Null when nothing in it can be one.
     */
    public static function codeOf(string $typed): ?string
    {
        if (1 === preg_match('#/cloud/([A-Za-z0-9]{4,8})(?:[/?\#]|$)#', $typed, $match)) {
            return strtoupper($match[1]);
        }
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed));

        return 1 === preg_match('/^[A-Z0-9]{4,8}$/', $code) ? $code : null;
    }
}
