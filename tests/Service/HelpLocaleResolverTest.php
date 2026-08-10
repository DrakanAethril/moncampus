<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HelpLocaleResolver;
use PHPUnit\Framework\TestCase;

/**
 * Which of several language versions of the same help entry a reader gets.
 *
 * The rule has to hold entry by entry, not screen by screen: a section translated into English
 * whose last two articles are not yet translated must still show those two, in French, rather than
 * pretending they do not exist. That is the whole reason this is a resolver and not a WHERE clause.
 *
 * Stated on plain keys and locales rather than on entities: the same rule serves sections and
 * articles, and neither of them is what makes it interesting.
 */
class HelpLocaleResolverTest extends TestCase
{
    public function testPrefersTheReadersOwnLanguage(): void
    {
        $resolver = new HelpLocaleResolver();

        $kept = $resolver->keepIndices(['creer-un-travail', 'creer-un-travail'], ['fr', 'en'], 'en');

        self::assertSame([1], $kept);
    }

    public function testFallsBackToFrenchEntryByEntry(): void
    {
        $resolver = new HelpLocaleResolver();

        // Three entries, only the middle one translated: the reader gets one of each, and the two
        // untranslated ones in French rather than nothing at all.
        $keys = ['a', 'b', 'b', 'c'];
        $locales = ['fr', 'fr', 'en', 'fr'];

        self::assertSame([0, 2, 3], $resolver->keepIndices($keys, $locales, 'en'));
    }

    public function testAFrenchReaderNeverSeesATranslation(): void
    {
        $resolver = new HelpLocaleResolver();

        self::assertSame([0, 2], $resolver->keepIndices(['a', 'a', 'b'], ['fr', 'en', 'fr'], 'fr'));
    }

    public function testKeepsTheCallersOrder(): void
    {
        $resolver = new HelpLocaleResolver();

        // The English version of "b" is stored first; the result still reads a, b, c.
        $kept = $resolver->keepIndices(['b', 'a', 'b'], ['en', 'fr', 'fr'], 'en');

        self::assertSame([0, 1], $kept);
    }

    public function testAnEntryThatExistsInNeitherLanguageStillShowsUp(): void
    {
        // An article written directly in a third language, or a locale later removed from the app:
        // showing it beats hiding content nobody can reach any other way.
        $resolver = new HelpLocaleResolver();

        self::assertSame([0], $resolver->keepIndices(['a'], ['es'], 'en'));
    }

    public function testTheFrenchVersionWinsOverAnyOtherFallback(): void
    {
        $resolver = new HelpLocaleResolver();

        self::assertSame([1], $resolver->keepIndices(['a', 'a'], ['es', 'fr'], 'en'));
    }

    public function testAnEmptyIndexResolvesToNothing(): void
    {
        $resolver = new HelpLocaleResolver();

        self::assertSame([], $resolver->keepIndices([], [], 'fr'));
    }
}
