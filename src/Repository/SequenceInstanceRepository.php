<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\SequenceInstance;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SequenceInstance>
 */
class SequenceInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SequenceInstance::class);
    }

    /** @return list<SequenceInstance> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->setParameter('program', $program)
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The same list narrowed to one teacher's own instantiations.
     *
     * A SequenceInstance hangs off a Program, not off a Topic, so a whole class's séquences are one
     * pool shared by every teacher of that class. The progression module never plans out of that
     * pool: a progression is one teacher's own planning of their own matière (ProgressionVoter), so
     * what it offers - and what it lets delete - is that teacher's own copies.
     *
     * @return list<SequenceInstance>
     */
    public function findForProgramCreatedBy(Program $program, User $creator): array
    {
        return $this->findForProgramCreatedByAny($program, [$creator]);
    }

    /**
     * The same list for SEVERAL creators at once - what a co-animated progression needs, since it
     * carries séquences instantiated by either of its two teachers
     * (design/validated/co-animation.md, lot 2).
     *
     * Deliberately only widens WHOSE copies are offered. How many times one of them may be planned
     * is a different rule, it lives in ProgressionSequenceAvailability, and it does not move: an
     * instantiated séquence is still planned once for the whole class.
     *
     * @param list<User> $creators
     *
     * @return list<SequenceInstance>
     */
    public function findForProgramCreatedByAny(Program $program, array $creators): array
    {
        if ([] === $creators) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->andWhere('s.createdBy IN (:creators)')
            ->setParameter('program', $program)
            ->setParameter('creators', $creators)
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The Programs this template has already been instantiated against, whoever did it.
     *
     * A SequenceInstance is a frozen copy owned by the class, not by the teacher who pressed the
     * button, so a second copy of the same template for the same class is a duplicate for everybody
     * who reads that class's pool - hence the deliberate absence of a creator filter here. Rows whose
     * source template was deleted (source_template_id is SET NULL) simply stop counting, which is the
     * right answer: nothing links them to the template being instantiated any more.
     *
     * @return list<Program>
     */
    public function findProgramsInstantiatedFrom(SequenceTemplate $sequenceTemplate): array
    {
        // Rooted on Program rather than on this repository's own entity: DQL cannot select a joined
        // alias without also selecting its root, so createQueryBuilder('s')->select('p') is a
        // semantic error rather than the shortcut it looks like.
        /** @var list<Program> $programs */
        $programs = $this->getEntityManager()->createQueryBuilder()
            ->select('p')
            ->distinct()
            ->from(Program::class, 'p')
            ->join(SequenceInstance::class, 's', Join::WITH, 's.program = p')
            ->where('s.sourceTemplate = :template')
            ->setParameter('template', $sequenceTemplate)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $programs;
    }

    public function hasInstanceFor(SequenceTemplate $sequenceTemplate, Program $program): bool
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.sourceTemplate = :template')
            ->andWhere('s.program = :program')
            ->setParameter('template', $sequenceTemplate)
            ->setParameter('program', $program)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
