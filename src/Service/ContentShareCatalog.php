<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentShare;
use App\Enum\ContentShareSubject;

/**
 * The catalogue's free-text search, and the trap it is built around
 * (design/validated/content-sharing-between-teachers.md, "The catalogue, and a trap in it").
 *
 * Niveau / Option / Bloc are a **private vocabulary per teacher** - App\Entity\AbstractLibraryTag
 * says so outright: "teacher builds their own private vocabulary per facet (no cross-teacher
 * sharing)". Two colleagues who both typed « SIO1 » own two different rows, which is why:
 *
 * - the catalogue searches the tag **labels as text**, alongside the title and the author's name;
 * - **no facet select is ever built from the reader's own tags.** It would silently hide every
 *   colleague's séquence whose label differs by a space. A free-text search that finds too much is
 *   recoverable; a select that finds nothing looks like an empty catalogue.
 *
 * The two selects the screen does carry - Type and Auteur - are not tags: one is an enum this
 * application owns, the other a user. Both are the same word for everybody.
 *
 * Matching is accent- and case-insensitive and requires **every** term, which is what a reader
 * typing « bts sio réseaux » means.
 */
class ContentShareCatalog
{
    /** @param list<string|null> $parts */
    public function haystack(array $parts): string
    {
        $kept = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);

            if ('' !== $part) {
                $kept[] = $part;
            }
        }

        return implode(' · ', $kept);
    }

    public function matches(string $haystack, string $query): bool
    {
        $terms = preg_split('/\s+/', trim($query)) ?: [];
        $normalisedHaystack = $this->normalise($haystack);

        foreach ($terms as $term) {
            if ('' === $term) {
                continue;
            }

            if (!str_contains($normalisedHaystack, $this->normalise($term))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Everything one catalogue row can be found by: its title, the author's name, and **the labels**
     * of whatever tags the item carries.
     */
    public function haystackOf(ContentShare $share): string
    {
        $owner = $share->getOwner();
        $parts = [$share->getSubjectTitle(), $owner->getDisplayName() ?? $owner->getUsername()];

        foreach ($this->tagLabelsOf($share) as $label) {
            $parts[] = $label;
        }

        return $this->haystack($parts);
    }

    /**
     * The tag labels a row shows, in the order the mockup lists them - Niveau, Option, then the
     * blocs. Only the séquence and the séance carry the three facets; a quiz carries one free line
     * of its own (`QuizTemplate::$subject`), which is the same kind of private vocabulary and is
     * searched the same way.
     *
     * @return list<string>
     */
    public function tagLabelsOf(ContentShare $share): array
    {
        $labels = [];

        $sequence = match ($share->getSubject()) {
            ContentShareSubject::Sequence => $share->getSequenceTemplate(),
            ContentShareSubject::Seance => $share->getSeanceTemplate()?->getSequenceTemplate(),
            default => null,
        };

        if (null !== $sequence) {
            $labels[] = (string) $sequence->getNiveau()?->getLabel();
            $labels[] = (string) $sequence->getOption()?->getLabel();

            foreach ($sequence->getBlocs() as $bloc) {
                $labels[] = (string) $bloc->getLabel();
            }
        }

        if (ContentShareSubject::Quiz === $share->getSubject()) {
            $labels[] = (string) $share->getQuizTemplate()?->getSubject();
        }

        return array_values(array_filter($labels, static fn (string $label): bool => '' !== trim($label)));
    }

    private function normalise(string $value): string
    {
        $folded = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);

        return false === $folded ? mb_strtolower($value) : $folded;
    }
}
