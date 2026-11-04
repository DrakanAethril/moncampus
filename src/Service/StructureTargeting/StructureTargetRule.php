<?php

declare(strict_types=1);

namespace App\Service\StructureTargeting;

/**
 * « Ce ciblage atteint-il cet étudiant ? » (design/validated/quiz-tournament.md §2 and §14), as pure
 * functions on primitives. App\Service\StructureTargeting\StructureTargeting gathers the facts.
 *
 * A Program is reached when it is **active** and either targeted itself, or its cohort belongs to a
 * targeted Track, or that Track to a targeted Section. A person is reached through any Program they
 * are enrolled in - with the test zone of StructureAccessChecker::matchesTestMode(), deliberately
 * asymmetric: a test account is reached through test Programs only, a real account through both.
 *
 * Read at every gesture, never frozen: a student who leaves the targeted classes stops being reached
 * the moment the roster says so.
 */
final class StructureTargetRule
{
    public static function reachesProgram(StructureTargets $targets, StructureProgramFacts $program): bool
    {
        if (!$program->active) {
            return false;
        }

        return \in_array($program->programId, $targets->programIds, true)
            || \in_array($program->trackId, $targets->trackIds, true)
            || \in_array($program->sectionId, $targets->sectionIds, true);
    }

    /**
     * The person's Programs the targeting reaches, most recent first (highest id, the order of
     * ProgramRepository::findActiveForStudent()) - the first one is « la classe affichée ».
     *
     * @param list<StructureProgramFacts> $enrolments every Program the person is enrolled in
     *
     * @return list<StructureProgramFacts>
     */
    public static function reachedPrograms(StructureTargets $targets, array $enrolments, bool $testAccount): array
    {
        $reached = array_values(array_filter(
            $enrolments,
            static fn (StructureProgramFacts $program): bool => (!$testAccount || $program->testProgram) && self::reachesProgram($targets, $program),
        ));
        usort($reached, static fn (StructureProgramFacts $a, StructureProgramFacts $b): int => $b->programId <=> $a->programId);

        return $reached;
    }

    /** @param list<StructureProgramFacts> $enrolments */
    public static function reaches(StructureTargets $targets, array $enrolments, bool $testAccount): bool
    {
        return [] !== self::reachedPrograms($targets, $enrolments, $testAccount);
    }
}
