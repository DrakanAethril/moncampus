<?php

namespace App\Repository;

use App\Entity\Assignment;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
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

    /**
     * La liste « Travaux » de l'enseignant (design_handoff_creation_travail 2b) : ses travaux sur
     * toutes ses classes à la fois, du plus proche au plus lointain - un travail échu se lit en
     * tête parce que c'est celui dont les rendus arrivent, pas parce qu'il est vieux.
     *
     * $creator restreint aux travaux donnés par l'enseignant lui-même ; null (personnel) rend ceux
     * de toute l'équipe sur les classes visées.
     *
     * @param list<Program> $programs
     *
     * @return list<Assignment>
     */
    public function findForPrograms(array $programs, ?User $creator = null): array
    {
        if ([] === $programs) {
            return [];
        }

        $builder = $this->createQueryBuilder('a')
            ->addSelect('o', 'p', 't', 'e')
            ->leftJoin('a.options', 'o')
            ->leftJoin('a.program', 'p')
            ->leftJoin('a.topic', 't')
            ->leftJoin('a.expectedProductions', 'e')
            ->where('a.program IN (:programs)')
            ->setParameter('programs', $programs)
            ->orderBy('a.dueDate', 'ASC');

        if (null !== $creator) {
            $builder->andWhere('a.createdBy = :creator')->setParameter('creator', $creator);
        }

        return $builder->getQuery()->getResult();
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
