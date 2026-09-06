<?php

declare(strict_types=1);

namespace App\Tests\Service\WordCloud;

use App\Service\WordCloud\WordCloudNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The aggregation key of « Regrouper les variantes (casse, accents, pluriels) ».
 *
 * It is never shown: the cloud displays the most frequent *raw* spelling of a group, so the key is
 * free to be lossy where that buys grouping. That is what pays for the two liberties taken below -
 * a token is joined by a single space whatever separated it, and a trailing `s` comes off any token
 * long enough to survive it, "virus" included. Both merge a word with itself and nothing else.
 */
class WordCloudNormalizerTest extends TestCase
{
    public function testCaseIsIgnored(): void
    {
        self::assertSame('phishing', $this->normalizer()->normalize('Phishing'));
        self::assertSame('phishing', $this->normalizer()->normalize('PHISHING'));
    }

    public function testAccentsAreRemoved(): void
    {
        self::assertSame('securite', $this->normalizer()->normalize('Sécurité'));
        self::assertSame('reseau', $this->normalizer()->normalize('réseau'));
    }

    public function testSurroundingSpaceIsTrimmed(): void
    {
        self::assertSame('vpn', $this->normalizer()->normalize('  VPN  '));
    }

    /**
     * The point of the whole thing: « pare-feu », « pare feu » and « Pare  Feu » are one word on the
     * board, so they must be one key.
     */
    public function testPunctuationAndRunsOfSpaceBecomeASingleSeparator(): void
    {
        self::assertSame('pare feu', $this->normalizer()->normalize('pare-feu'));
        self::assertSame('pare feu', $this->normalizer()->normalize('pare feu'));
        self::assertSame('pare feu', $this->normalizer()->normalize('Pare  Feu'));
        self::assertSame('pare feu', $this->normalizer()->normalize('pare_feu'));
        self::assertSame('l internet', $this->normalizer()->normalize("l'internet"));
    }

    public function testASimplePluralIsDroppedTokenByToken(): void
    {
        self::assertSame('mot de passe', $this->normalizer()->normalize('mots de passe'));
        self::assertSame('mot de passe', $this->normalizer()->normalize('Mot de passe'));
    }

    /**
     * Three letters stay whole: dropping the `s` of « les » or « bus » would merge two words that
     * have nothing to do with each other, which is the one thing this key must not do.
     */
    public function testAShortTokenKeepsItsS(): void
    {
        self::assertSame('bus', $this->normalizer()->normalize('bus'));
        self::assertSame('les', $this->normalizer()->normalize('LES'));
    }

    /**
     * « virus » is not a plural and comes out as « viru » all the same. Deliberate: every spelling
     * of it lands on that same key, and the board shows the raw word back.
     */
    public function testALongWordEndingInSIsShortenedEvenWhenItIsNotAPlural(): void
    {
        self::assertSame('viru', $this->normalizer()->normalize('virus'));
        self::assertSame('viru', $this->normalizer()->normalize('Virus'));
    }

    public function testDigitsSurvive(): void
    {
        self::assertSame('rgpd 2018', $this->normalizer()->normalize('RGPD 2018'));
    }

    public function testAnEmptyOrPunctuationOnlyStringNormalisesToNothing(): void
    {
        self::assertSame('', $this->normalizer()->normalize(''));
        self::assertSame('', $this->normalizer()->normalize('   '));
        self::assertSame('', $this->normalizer()->normalize('!?...'));
    }

    private function normalizer(): WordCloudNormalizer
    {
        return new WordCloudNormalizer();
    }
}
