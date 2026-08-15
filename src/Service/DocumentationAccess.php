<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentationArticle;
use App\Enum\DocumentationAudience;
use App\Enum\DocumentationStatus;

/**
 * The single answer to "may this person read this documentation article".
 *
 * Three conditions, ANDed, and no fourth one:
 *
 * 1. the article is Published and now sits inside its diffusion window;
 * 2. it names one of the reader's audiences (Étudiants / Enseignants / Personnels / Tuteurs);
 * 3. it is posted on a section of the campus the reader belongs to - one of the article's
 *    perimeter groups is among the reader's own groups or their ancestors (that expansion is
 *    App\Service\DocumentationPerimeter's job, not this one's).
 *
 * Staff, staff-lead and admin skip all three: they read the base whole, drafts included, because
 * they are who administers it. Note the consequence for tutors as the annuaire stands today: a
 * tutor carries ROLE_TUTOR and no perimeter group at all, so condition 3 is unanswerable for them
 * and naming "Tuteurs" in the visibility changes nothing until the annuaire gives them one. That
 * is deliberate, not an oversight.
 *
 * Everything here is primitives - ids, enums, dates - so the rule is testable without an entity
 * graph and reusable from a repository row as easily as from a hydrated article.
 */
class DocumentationAccess
{
    /**
     * @param list<int>                   $articlePerimeterIds
     * @param list<DocumentationAudience> $articleAudiences
     * @param list<int>                   $readerGroupIds
     * @param list<DocumentationAudience> $readerAudiences
     */
    public function allows(
        DocumentationStatus $status,
        ?\DateTimeImmutable $publishStart,
        ?\DateTimeImmutable $publishEnd,
        array $articlePerimeterIds,
        array $articleAudiences,
        array $readerGroupIds,
        array $readerAudiences,
        bool $isManager,
        \DateTimeImmutable $now,
    ): bool {
        if ($isManager) {
            return true;
        }

        return $this->isPublishedNow($status, $publishStart, $publishEnd, $now)
            && $this->matchesAudience($articleAudiences, $readerAudiences)
            && $this->matchesPerimeter($articlePerimeterIds, $readerGroupIds);
    }

    public function isPublishedNow(
        DocumentationStatus $status,
        ?\DateTimeImmutable $publishStart,
        ?\DateTimeImmutable $publishEnd,
        \DateTimeImmutable $now,
    ): bool {
        if (DocumentationStatus::Published !== $status) {
            return false;
        }

        // Both bounds inclusive: an article published "à partir du 1er septembre 8h00" is readable
        // at 8h00, which is what the two fields of 2d read like.
        return (null === $publishStart || $publishStart <= $now)
            && (null === $publishEnd || $publishEnd >= $now);
    }

    /**
     * @param list<DocumentationAudience> $articleAudiences
     * @param list<DocumentationAudience> $readerAudiences
     */
    public function matchesAudience(array $articleAudiences, array $readerAudiences): bool
    {
        foreach ($articleAudiences as $audience) {
            if (\in_array($audience, $readerAudiences, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $articlePerimeterIds
     * @param list<int> $readerGroupIds
     */
    public function matchesPerimeter(array $articlePerimeterIds, array $readerGroupIds): bool
    {
        return [] !== array_intersect($articlePerimeterIds, $readerGroupIds);
    }

    /**
     * @param list<string> $roles
     *
     * @return list<DocumentationAudience>
     */
    public function audiencesOf(array $roles): array
    {
        return DocumentationAudience::fromRoles($roles);
    }

    /** @param list<string> $roles */
    public function isManagerRole(array $roles): bool
    {
        return \in_array('ROLE_ADMIN', $roles, true)
            || \in_array('ROLE_STAFF', $roles, true)
            || \in_array('ROLE_STAFF-LEAD', $roles, true);
    }

    /**
     * The same rule against a hydrated article, for the one caller that holds one.
     *
     * @param list<int>    $readerGroupIds
     * @param list<string> $readerRoles
     */
    public function allowsArticle(DocumentationArticle $article, array $readerGroupIds, array $readerRoles, ?\DateTimeImmutable $now = null): bool
    {
        return $this->allows(
            $article->getStatus(),
            $article->getPublishStart(),
            $article->getPublishEnd(),
            $article->getPerimeterIds(),
            $article->getAudiences(),
            $readerGroupIds,
            $this->audiencesOf($readerRoles),
            $this->isManagerRole($readerRoles),
            $now ?? new \DateTimeImmutable(),
        );
    }

    /**
     * @param iterable<DocumentationArticle> $articles
     * @param list<int>                      $readerGroupIds
     * @param list<string>                   $readerRoles
     *
     * @return list<DocumentationArticle>
     */
    public function filter(iterable $articles, array $readerGroupIds, array $readerRoles, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $readable = [];

        foreach ($articles as $article) {
            if ($this->allowsArticle($article, $readerGroupIds, $readerRoles, $now)) {
                $readable[] = $article;
            }
        }

        return $readable;
    }
}
