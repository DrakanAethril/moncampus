<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HelpArticle;
use App\Entity\HelpSection;
use App\Entity\User;
use App\Enum\HelpAudience;

/**
 * The single answer to "may this person read this help entry".
 *
 * Two rules, and no third one: an admin reads everything, published or not, because an admin is
 * who writes it; anyone else reads a published entry that names one of their own audiences. A
 * section and an article are each checked on their own - an article never inherits its section's
 * audiences, so narrowing an article inside a wide section works, and widening it does not.
 */
class HelpAccess
{
    /**
     * @param array<array-key, HelpAudience> $entryAudiences
     * @param array<array-key, HelpAudience> $readerAudiences
     */
    public function allows(array $entryAudiences, array $readerAudiences, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        foreach ($entryAudiences as $audience) {
            if (in_array($audience, $readerAudiences, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<HelpAudience> */
    public function audiencesOf(?User $user): array
    {
        return null === $user ? [] : HelpAudience::fromRoles($user->getRoles());
    }

    public function isAdmin(?User $user): bool
    {
        return null !== $user && in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    public function canReadSection(HelpSection $section, ?User $user): bool
    {
        $isAdmin = $this->isAdmin($user);

        return ($section->isPublished() || $isAdmin)
            && $this->allows($section->getAudiences(), $this->audiencesOf($user), $isAdmin);
    }

    public function canReadArticle(HelpArticle $article, ?User $user): bool
    {
        $isAdmin = $this->isAdmin($user);
        $section = $article->getSection();

        if (null !== $section && !$this->canReadSection($section, $user)) {
            return false;
        }

        return ($article->isPublished() || $isAdmin)
            && $this->allows($article->getAudiences(), $this->audiencesOf($user), $isAdmin);
    }

    /**
     * @param iterable<HelpArticle> $articles
     *
     * @return list<HelpArticle>
     */
    public function filterArticles(iterable $articles, ?User $user): array
    {
        $readable = [];

        foreach ($articles as $article) {
            if ($this->canReadArticle($article, $user)) {
                $readable[] = $article;
            }
        }

        return $readable;
    }
}
