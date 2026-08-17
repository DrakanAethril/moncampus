<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FileLibraryNode;
use App\Entity\Program;
use App\Entity\SharedDocument;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SharedDocument>
 */
class SharedDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedDocument::class);
    }

    /**
     * Every class this file has been put at the disposal of - the share screen's own list, and what
     * App\Service\FileLibraryLinks turns into usage lines.
     *
     * @return list<SharedDocument>
     */
    public function findForNode(FileLibraryNode $node): array
    {
        return $this->createQueryBuilder('sd')
            ->addSelect('p', 't', 'o', 'm')
            ->innerJoin('sd.program', 'p')
            ->leftJoin('sd.topic', 't')
            ->leftJoin('sd.options', 'o')
            ->leftJoin('sd.modalities', 'm')
            ->where('sd.libraryNode = :node')
            ->setParameter('node', $node)
            ->orderBy('p.shortName', 'ASC')
            ->addOrderBy('sd.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The shares of one class, whatever their window - the raw material of the student screen, which
     * narrows them to this student and to now through App\Service\SharedDocumentAudience.
     *
     * The window is deliberately **not** filtered in SQL: `visible_from` and `visible_until` are both
     * nullable, so the condition is four cases, and the same four are already written once in
     * SharedDocument::isVisibleAt(). One reading of the rule, in PHP, where it can be tested.
     *
     * @param list<Program> $programs
     *
     * @return list<SharedDocument>
     */
    public function findForPrograms(array $programs): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('sd')
            ->addSelect('n', 'p', 't', 'te', 'o', 'm')
            ->innerJoin('sd.libraryNode', 'n')
            ->innerJoin('sd.program', 'p')
            ->innerJoin('sd.teacher', 'te')
            ->leftJoin('sd.topic', 't')
            ->leftJoin('sd.options', 'o')
            ->leftJoin('sd.modalities', 'm')
            ->where('sd.program IN (:programs)')
            // A file in the corbeille has left every screen at once, this one included - its bytes
            // are already scheduled to go.
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('programs', $programs)
            ->getQuery()
            ->getResult();
    }

    /**
     * Has this teacher already put this file at this class's disposal? Guards the share form against
     * the double submit that would otherwise show the student the same document twice.
     */
    public function findOneForNodeAndProgram(FileLibraryNode $node, User $teacher, Program $program): ?SharedDocument
    {
        return $this->findOneBy([
            'libraryNode' => $node,
            'teacher' => $teacher,
            'program' => $program,
        ]);
    }
}
