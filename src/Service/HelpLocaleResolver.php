<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HelpArticle;
use App\Entity\HelpSection;

/**
 * Picks, among the language versions of a help entry, the one this reader gets.
 *
 * Every version is a row of its own - an English article is not a field on the French one - so the
 * choice is made when reading, per entry: the reader's own language when it exists, French
 * otherwise. The fallback is deliberately entry by entry rather than screen by screen, so a
 * half-translated section shows its untranslated articles in French instead of dropping them.
 *
 * The URL does not carry the language: /help/travaux/creer-un-travail is the same address for both
 * readers, and what it answers depends on their locale. That keeps a link shareable between two
 * colleagues who do not read the app in the same language.
 */
class HelpLocaleResolver
{
    /** The language the app is written in, and the one every entry falls back to (framework.default_locale). */
    public const string DEFAULT_LOCALE = 'fr';

    /**
     * The core rule, on plain keys: one entry per distinct key, in the order the caller read them.
     *
     * @param list<string> $keys    one key per item - what makes two rows "the same entry"
     * @param list<string> $locales one locale per item, same order
     *
     * @return list<int> the indices to keep, in the caller's own order
     */
    public function keepIndices(array $keys, array $locales, string $locale): array
    {
        /** @var array<string, int> $chosen */
        $chosen = [];

        foreach ($keys as $index => $key) {
            $itemLocale = $locales[$index] ?? self::DEFAULT_LOCALE;
            $current = $chosen[$key] ?? null;

            if (null === $current || $this->beats($itemLocale, $locales[$current] ?? self::DEFAULT_LOCALE, $locale)) {
                $chosen[$key] = $index;
            }
        }

        $kept = array_values($chosen);
        sort($kept);

        return $kept;
    }

    /**
     * @param list<HelpSection> $sections
     *
     * @return list<HelpSection>
     */
    public function sections(array $sections, string $locale): array
    {
        return $this->pick(
            $sections,
            array_map(static fn (HelpSection $section): string => $section->getSlug(), $sections),
            array_map(static fn (HelpSection $section): string => $section->getLocale(), $sections),
            $locale,
        );
    }

    /**
     * Articles are keyed on their section's slug as well as their own: two sections may hold an
     * article called "vue-densemble" without being translations of each other.
     *
     * @param list<HelpArticle> $articles
     *
     * @return list<HelpArticle>
     */
    public function articles(array $articles, string $locale): array
    {
        return $this->pick(
            $articles,
            array_map(
                static fn (HelpArticle $article): string => ($article->getSection()?->getSlug() ?? '').'/'.$article->getSlug(),
                $articles,
            ),
            array_map(static fn (HelpArticle $article): string => $article->getLocale(), $articles),
            $locale,
        );
    }

    /**
     * @template T of object
     *
     * @param list<T>      $items
     * @param list<string> $keys
     * @param list<string> $locales
     *
     * @return list<T>
     */
    private function pick(array $items, array $keys, array $locales, string $locale): array
    {
        return array_map(
            static fn (int $index): object => $items[$index],
            $this->keepIndices($keys, $locales, $locale),
        );
    }

    /** Reader's language beats everything; French beats anything else; otherwise first one wins. */
    private function beats(string $candidate, string $incumbent, string $locale): bool
    {
        if ($incumbent === $locale) {
            return false;
        }

        return $candidate === $locale
            || (self::DEFAULT_LOCALE === $candidate && self::DEFAULT_LOCALE !== $incumbent);
    }
}
