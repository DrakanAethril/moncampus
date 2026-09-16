<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier;
use App\Entity\DossierTargetProgram;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;

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
        private readonly ProgramStudentOptionRepository $studentOptions,
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

        foreach ($dossier->getTargetPrograms() as $target) {
            // The class reads as itself even when the target is narrowed: the cible's row says
            // « SIO-2 », and which part of SIO-2 was asked is the dossier's business, not theirs.
            $label = $target->getProgram()?->getDisplayShortName() ?? '';

            foreach ($this->studentsOf($target) as $student) {
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

    /**
     * The students one target actually reaches: the whole class, or - once options are named - only
     * those carrying one of them.
     *
     * The union, never the intersection: a student in SLAM *or* SISR is reached when both are named,
     * which is the same rule an Assignment's option audience follows.
     *
     * @return list<User>
     */
    private function studentsOf(DossierTargetProgram $target): array
    {
        $program = $target->getProgram();

        if (null === $program) {
            return [];
        }

        if ($target->isWholeClass()) {
            return array_values($program->getStudents()->toArray());
        }

        return $this->studentOptions->findStudentsForProgramAndOptions($program, $target->getOptions());
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

        foreach ($dossier->getTargetPrograms() as $target) {
            $program = $target->getProgram();

            if (null === $program || !$program->getStudents()->contains($user)) {
                continue;
            }

            if ($target->isWholeClass()) {
                return true;
            }

            // Narrowed: being in the class is not enough, and the options are read now rather than
            // frozen - a student who picks the option up in November is a cible from that day.
            foreach ($this->studentOptions->findOptionsForStudent($program, $user) as $option) {
                if ($target->getOptions()->contains($option)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The labels of the formations a dossier names - the « Cibles » column of the list screen.
     *
     * **Never an effectif** (handoff, « Règles produit »): « SIO-2 », not « SIO-2 · 10 étudiants ».
     * A narrowed target does name its options - « SIO-2 (SLAM) » - because that is which part of the
     * class is being asked, not how many of them there are.
     *
     * @return list<string>
     */
    public function programLabels(Dossier $dossier): array
    {
        $labels = [];

        foreach ($dossier->getTargetPrograms() as $target) {
            $labels[] = $target->label();
        }

        sort($labels);

        return $labels;
    }
}
