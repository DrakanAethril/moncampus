<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier;
use App\Entity\User;
use App\Repository\ProgramRepository;

/**
 * Who a dossier is actually asked of, and what class each of them reads as.
 *
 * Two axes, unioned and deduplicated: **whole formations** and **students named one by one**. It is
 * recomputed at every read rather than frozen at publication, exactly like an Assignment's audience
 * - a student who joins SIO-2 in November is a cible of the SIO-2 dossier the day they join, and one
 * who leaves stops being counted in its denominators.
 *
 * That is deliberately *not* the SurveyTarget rule, and the difference is worth stating: a survey
 * freezes its target because the target is the denominator of a response rate somebody will quote
 * months later. A dossier's cibles are a class list, and a class list that lies about who is in the
 * class is worse than one that moves.
 *
 * The « classe » shown next to a name is the formation the cible was reached through, and for a
 * student named individually it is their own active formation - read once here rather than by each
 * screen, so nobody has to query per row.
 */
class DossierTargetResolver
{
    public function __construct(
        private readonly ProgramRepository $programs,
    ) {
    }

    /**
     * The cibles, ordered by name, each with the label of the formation they read as.
     *
     * @return list<array{student: User, className: string}>
     */
    public function resolve(Dossier $dossier): array
    {
        /** @var array<int, array{student: User, className: string}> $byId */
        $byId = [];

        foreach ($dossier->getTargetPrograms() as $program) {
            $label = $program->getDisplayShortName();

            foreach ($program->getStudents() as $student) {
                $id = $student->getId();

                if (null !== $id && !isset($byId[$id])) {
                    $byId[$id] = ['student' => $student, 'className' => $label];
                }
            }
        }

        foreach ($dossier->getTargetStudents() as $student) {
            $id = $student->getId();

            if (null === $id || isset($byId[$id])) {
                continue;
            }

            // Only for the students the formations did not already bring in: a named student who is
            // also in a target class keeps that class's label, and costs no query.
            $byId[$id] = [
                'student' => $student,
                'className' => $this->programs->findActiveForStudent($student)?->getDisplayShortName() ?? '',
            ];
        }

        $cibles = array_values($byId);

        usort($cibles, static fn (array $a, array $b): int => ($a['student']->getDisplayName() ?? '') <=> ($b['student']->getDisplayName() ?? ''));

        return $cibles;
    }

    /** @return list<User> */
    public function students(Dossier $dossier): array
    {
        return array_map(static fn (array $cible): User => $cible['student'], $this->resolve($dossier));
    }

    public function isTarget(Dossier $dossier, User $user): bool
    {
        if ($dossier->getTargetStudents()->contains($user)) {
            return true;
        }

        foreach ($dossier->getTargetPrograms() as $program) {
            if ($program->getStudents()->contains($user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The labels of the formations a dossier names - the « Cibles » column of the list screen.
     *
     * **Never an effectif** (handoff, « Règles produit »): « SIO-2 », not « SIO-2 · 10 étudiants ».
     *
     * @return list<string>
     */
    public function programLabels(Dossier $dossier): array
    {
        $labels = [];

        foreach ($dossier->getTargetPrograms() as $program) {
            $labels[] = $program->getDisplayShortName();
        }

        sort($labels);

        return $labels;
    }
}
