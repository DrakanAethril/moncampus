<?php

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\LessonSession;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Assignment>
 */
class AssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assignment::class);
    }

    /** @return list<Assignment> */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.program = :program')
            ->setParameter('program', $program)
            ->orderBy('a.dueDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // Student dashboard's "Travail à réaliser" card (design_handoff_dashboards etu-a): upcoming
    // items only - "pas de notion de retard côté étudiant" (§1.6), so nothing dated before $from
    // ever comes back. Audience membership is filtered by the caller via
    // AssignmentAudienceResolver::isInAudience(), same as the my-assignments list.
    /** @param list<Program> $programs @return list<Assignment> */
    public function findUpcomingForPrograms(array $programs, \DateTimeImmutable $from): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.program IN (:programs)')
            ->andWhere('a.dueDate >= :from')
            ->setParameter('programs', $programs)
            ->setParameter('from', $from)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Les travaux donnés depuis le cahier de texte d'une séance (maquette 2a), tous temps confondus
    // - le contrôleur les range ensuite par temps.
    /** @return list<Assignment> */
    public function findForLessonSession(LessonSession $session): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('o')
            ->leftJoin('a.options', 'o')
            ->where('a.lessonSession = :session')
            ->setParameter('session', $session)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tout le travail d'un étudiant, en retard compris - ce que la page « Travail à réaliser » (4a)
     * montre, contrairement à la carte du tableau de bord qui s'en tient à ce qui vient.
     * L'appartenance au public reste filtrée par l'appelant via AssignmentAudienceResolver.
     *
     * @param list<Program> $programs
     *
     * @return list<Assignment>
     */
    public function findVisibleForPrograms(array $programs, \DateTimeImmutable $now): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('o', 'l')
            ->leftJoin('a.options', 'o')
            ->leftJoin('a.lessonSession', 'l')
            ->where('a.program IN (:programs)')
            // Un travail donné depuis une séance n'existe pour l'étudiant qu'une fois publié ; les
            // devoirs de l'écran historique ont été publiés par la migration, ils passent donc tous.
            ->andWhere('a.visibleAt IS NOT NULL AND a.visibleAt <= :now')
            ->setParameter('programs', $programs)
            ->setParameter('now', $now)
            ->orderBy('a.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
