<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentationArticle;
use App\Entity\DocumentationCounterReset;
use App\Entity\User;
use App\Repository\DocumentationArticleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The two read counters of the documentation base (handoff 2c/2f).
 *
 * A read is one opening of the article page - not a unique reader, not a session: the dashboard
 * says "12 480 lectures", and that is what it counts. Two populations are left out on purpose, so
 * the figures only ever measure the audience the article was written for: the author, who reads
 * their own article while writing it, and staff/staff-lead/admin, who read everything by trade.
 *
 * The increment is a single UPDATE rather than a hydrate-modify-flush, so two readers opening the
 * same article at the same moment cannot lose a count to each other.
 */
class DocumentationReadCounter
{
    public function __construct(
        private readonly DocumentationArticleRepository $articles,
        private readonly DocumentationAccess $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function registerRead(DocumentationArticle $article, ?User $reader): bool
    {
        $articleId = $article->getId();

        if (null === $articleId || !$this->counts($article, $reader)) {
            return false;
        }

        $this->articles->incrementReadCounters($articleId);

        return true;
    }

    /**
     * Puts every "depuis la remise à zéro" counter back to zero, campus-wide, and keeps the date -
     * the historical total is untouched.
     */
    public function reset(?User $actor): DocumentationCounterReset
    {
        $cleared = $this->articles->sumReads()['sinceReset'];

        $this->articles->resetCountersSinceReset();

        $reset = new DocumentationCounterReset($actor, $cleared);
        $this->entityManager->persist($reset);
        $this->entityManager->flush();

        // Nothing hydrates an article on this path, so no in-memory counter can survive the bulk
        // UPDATE and be written back: the caller redirects and the dashboard re-reads from SQL.
        return $reset;
    }

    private function counts(DocumentationArticle $article, ?User $reader): bool
    {
        if (null === $reader) {
            return false;
        }

        if ($this->access->isManagerRole($reader->getRoles())) {
            return false;
        }

        // Compared as objects, like App\Security\Voter\DocumentationArticleVoter does.
        return $article->getAuthor() !== $reader;
    }
}
