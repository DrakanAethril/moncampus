<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HelpArticle;
use App\Entity\HelpSection;

/**
 * Keeps the language versions of one help entry in the same place in the list.
 *
 * Order belongs to the entry, not to the version: a reader in English must meet the same sections
 * in the same order as a reader in French, and an article translated last must not land at the
 * bottom of its section just because it was written last. So the French row's position wins for
 * every version of the same slug - and when there is no French row (an entry written directly in
 * another language), the first one seen sets it for the others.
 *
 * Applied on save rather than on read: two rows out of step would otherwise reorder a screen every
 * time somebody edits one of them.
 */
class HelpOrdering
{
    /**
     * @param list<HelpSection>|list<HelpArticle> $siblings every language version of one entry
     */
    public function align(array $siblings): void
    {
        if (count($siblings) < 2) {
            return;
        }

        $reference = $siblings[0];
        foreach ($siblings as $sibling) {
            if (HelpLocaleResolver::DEFAULT_LOCALE === $sibling->getLocale()) {
                $reference = $sibling;
                break;
            }
        }

        foreach ($siblings as $sibling) {
            $sibling->setPosition($reference->getPosition());
        }
    }
}
