<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SharedDocument;
use App\Enum\SharedDocumentGrouping;
use App\Enum\SharedDocumentOrdering;

/**
 * The shape of the student's « Documents partagés » screen: the grouping, the order inside it, and
 * nothing else. Reading who may see what is App\Service\SharedDocumentAudience's job; this class
 * receives the answer and arranges it.
 *
 * The default is **matière ASC, then mise à disposition DESC** - the way a student thinks about
 * their own documents: which subject, then what is new. The two knobs the screen offers are
 * independent: `group` chooses the cut (matière, enseignant, or none at all), `order` chooses the
 * run inside each cut, and a flat list is simply the cut that has one group.
 *
 * **The unnamed bucket always sorts last.** A document whose matière has since been deleted has no
 * name to sort by, and dropping it at the top - where an empty string sorts - would put the least
 * identifiable rows first.
 *
 * @phpstan-type SharedDocumentGroupRow array{label: ?string, shares: list<SharedDocument>}
 */
class SharedDocumentBoard
{
    /**
     * @param list<SharedDocument> $shares
     *
     * @return list<SharedDocumentGroupRow>
     *
     * @phpstan-return list<SharedDocumentGroupRow>
     */
    public function build(array $shares, SharedDocumentGrouping $grouping, SharedDocumentOrdering $ordering): array
    {
        if (SharedDocumentGrouping::None === $grouping) {
            return [] === $shares ? [] : [['label' => null, 'shares' => $this->sort($shares, $ordering)]];
        }

        /** @var array<string, list<SharedDocument>> $buckets */
        $buckets = [];
        /** @var array<string, ?string> $labels */
        $labels = [];

        foreach ($shares as $share) {
            $label = $this->labelOf($share, $grouping);
            // Keyed on the label rather than on the id: two matières of two different classes
            // bearing the same name are one heading for the student, who has one « Mathématiques ».
            $key = null === $label ? "\u{10FFFF}" : mb_strtolower($label);
            $buckets[$key][] = $share;
            $labels[$key] = $label;
        }

        uksort($buckets, static fn (string $a, string $b): int => $a <=> $b);

        $groups = [];

        foreach ($buckets as $key => $bucket) {
            $groups[] = ['label' => $labels[$key], 'shares' => $this->sort($bucket, $ordering)];
        }

        return $groups;
    }

    /**
     * @param list<SharedDocument> $shares
     *
     * @return list<SharedDocument>
     */
    private function sort(array $shares, SharedDocumentOrdering $ordering): array
    {
        usort($shares, static function (SharedDocument $a, SharedDocument $b) use ($ordering): int {
            if (SharedDocumentOrdering::Name === $ordering) {
                return mb_strtolower($a->getLibraryNode()->getName()) <=> mb_strtolower($b->getLibraryNode()->getName());
            }

            // Newest first, and the name breaks a tie: two documents shared in the same second
            // would otherwise come back in whatever order the rows happened to arrive in.
            return [$b->availableAt(), mb_strtolower($a->getLibraryNode()->getName())]
                <=> [$a->availableAt(), mb_strtolower($b->getLibraryNode()->getName())];
        });

        return $shares;
    }

    private function labelOf(SharedDocument $share, SharedDocumentGrouping $grouping): ?string
    {
        return match ($grouping) {
            SharedDocumentGrouping::Topic => $share->getTopic()?->getName(),
            SharedDocumentGrouping::Teacher => $share->getTeacher()->getDisplayName(),
            SharedDocumentGrouping::None => null,
        };
    }
}
