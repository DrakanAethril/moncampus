<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentShare;
use App\Entity\FileLibraryNode;
use App\Entity\Progression;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\ContentShareScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentShare>
 */
class ContentShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentShare::class);
    }

    /**
     * The candidate rows of « Reçus »: everything addressed to a person or to a group, revoked or
     * not, minus the reader's own shares.
     *
     * The `group` scope is **not** narrowed here on purpose. Deciding whether a group reaches this
     * reader means walking the hierarchy downwards, and that walk lives in
     * App\Service\ContentShareAccess - one direction, one implementation. A school holds a handful
     * of group-scoped shares, so fetching them and filtering in PHP is the same trade this
     * repository already makes for role matching (see UserRepository::findActiveMatchingRoles()).
     *
     * @return list<ContentShare>
     */
    public function findReceivedCandidates(User $reader): array
    {
        $qb = $this->withSubjects()
            ->andWhere('s.owner != :reader')
            ->andWhere('(s.scope = :group OR (s.scope = :users AND :reader MEMBER OF s.users))')
            ->setParameter('reader', $reader)
            ->setParameter('group', ContentShareScope::Group)
            ->setParameter('users', ContentShareScope::Users)
            ->orderBy('s.creationDate', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * The catalogue: everything published to the whole establishment, the reader's own excluded -
     * one does not need a catalogue to find one's own séquence.
     *
     * @return list<ContentShare>
     */
    public function findCatalogCandidates(User $reader): array
    {
        $qb = $this->withSubjects()
            ->andWhere('s.owner != :reader')
            ->andWhere('s.scope = :catalog')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('reader', $reader)
            ->setParameter('catalog', ContentShareScope::Catalog)
            ->orderBy('s.creationDate', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * « Mes partages » - one row per share, not per content: the same séquence given by name and to
     * a team is two lines, because they are withdrawn separately.
     *
     * @return list<ContentShare>
     */
    public function findOwnedBy(User $owner): array
    {
        return $this->withSubjects()
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The shares already in place on one item - the list the modal shows under its form, and where
     * « Retirer » lives.
     *
     * @return list<ContentShare>
     */
    public function findForSubject(SequenceTemplate|SeanceTemplate|QuizTemplate|FileLibraryNode|Progression $subject): array
    {
        $field = match (true) {
            $subject instanceof SequenceTemplate => 'sequenceTemplate',
            $subject instanceof SeanceTemplate => 'seanceTemplate',
            $subject instanceof QuizTemplate => 'quizTemplate',
            $subject instanceof FileLibraryNode => 'libraryNode',
            default => 'progression',
        };

        return $this->createQueryBuilder('s')
            ->leftJoin('s.users', 'u')->addSelect('u')
            ->leftJoin('s.groups', 'g')->addSelect('g')
            ->andWhere(\sprintf('s.%s = :subject', $field))
            ->setParameter('subject', $subject)
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Fetch-joined once rather than N+1 times: every list screen reads the owner, the audience and
     * the subject's own title on each row.
     */
    private function withSubjects(): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.owner', 'o')->addSelect('o')
            ->leftJoin('s.users', 'u')->addSelect('u')
            ->leftJoin('s.groups', 'g')->addSelect('g')
            ->leftJoin('s.sequenceTemplate', 'seq')->addSelect('seq')
            ->leftJoin('s.seanceTemplate', 'sea')->addSelect('sea')
            ->leftJoin('s.quizTemplate', 'qz')->addSelect('qz')
            ->leftJoin('s.libraryNode', 'fn')->addSelect('fn')
            ->leftJoin('s.progression', 'pr')->addSelect('pr');
    }
}
