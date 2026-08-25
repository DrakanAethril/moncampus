<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Arranges « Wikis partagés » into the groups the screen shows.
 *
 * Flat, the list becomes an unreadable pile after two school years, so it is grouped by class - the
 * same organising principle as the supervision screen, because it is the same mental model. The
 * grouping key is **derived, never stored**: the assigned Program(s), or for a wiki assigned to
 * named students, the program those students belong to. Program being Cohort x SchoolYear, the
 * grouping separates school years by itself, which is what lets the screen expand only the current
 * one.
 *
 * Three cases are named rather than dropped into a silent catch-all:
 *
 * - a wiki with no student anywhere in it has no class at all, so it heads the page under
 *   "Entre collègues";
 * - members spread across several classes group under "Plusieurs classes";
 * - a student audience that resolves to no class at all (a member enrolled nowhere) gets its own
 *   heading too, since calling it "Plusieurs classes" would be false and folding it into
 *   "Entre collègues" would hide a student.
 *
 * Primitives in, primitives out: the caller resolves the program ids and hands them in, so this is
 * testable without an entity graph.
 */
class WikiBoard
{
    public const string GROUP_COLLEAGUES = 'colleagues';
    public const string GROUP_PROGRAM = 'program';
    public const string GROUP_SEVERAL = 'several';
    public const string GROUP_OTHER = 'other';

    /**
     * @param list<array{id: int, programIds: list<int>, hasStudentAudience: bool}> $wikis
     * @param list<int>                                                             $programOrder the caller's own ordering of the classes (school year first) - respected as given
     *
     * @return list<array{kind: string, programId: int|null, wikiIds: list<int>}>
     */
    public function group(array $wikis, array $programOrder = []): array
    {
        $colleagues = [];
        $several = [];
        $other = [];
        /** @var array<int, list<int>> $byProgram */
        $byProgram = [];

        foreach ($wikis as $wiki) {
            if (!$wiki['hasStudentAudience']) {
                $colleagues[] = $wiki['id'];

                continue;
            }

            $programIds = array_values(array_unique($wiki['programIds']));

            if (1 === \count($programIds)) {
                $byProgram[$programIds[0]][] = $wiki['id'];
            } elseif ([] === $programIds) {
                $other[] = $wiki['id'];
            } else {
                $several[] = $wiki['id'];
            }
        }

        $groups = [];

        if ([] !== $colleagues) {
            $groups[] = ['kind' => self::GROUP_COLLEAGUES, 'programId' => null, 'wikiIds' => $colleagues];
        }

        foreach ($this->orderedProgramIds($byProgram, $programOrder) as $programId) {
            $groups[] = ['kind' => self::GROUP_PROGRAM, 'programId' => $programId, 'wikiIds' => $byProgram[$programId]];
        }

        if ([] !== $several) {
            $groups[] = ['kind' => self::GROUP_SEVERAL, 'programId' => null, 'wikiIds' => $several];
        }

        if ([] !== $other) {
            $groups[] = ['kind' => self::GROUP_OTHER, 'programId' => null, 'wikiIds' => $other];
        }

        return $groups;
    }

    /**
     * @param array<int, list<int>> $byProgram
     * @param list<int>             $programOrder
     *
     * @return list<int>
     */
    private function orderedProgramIds(array $byProgram, array $programOrder): array
    {
        $ordered = [];

        foreach ($programOrder as $programId) {
            if (isset($byProgram[$programId])) {
                $ordered[] = $programId;
            }
        }

        // A class the caller did not order (it assigned a wiki without being one of "my" classes)
        // still has to appear - it lands after the ordered ones rather than vanishing.
        foreach (array_keys($byProgram) as $programId) {
            if (!\in_array($programId, $ordered, true)) {
                $ordered[] = $programId;
            }
        }

        return $ordered;
    }

    public function groupLabelKey(string $kind): string
    {
        return match ($kind) {
            self::GROUP_COLLEAGUES => 'wikiBoardColleaguesGroupLabel',
            self::GROUP_SEVERAL => 'wikiBoardSeveralClassesGroupLabel',
            self::GROUP_OTHER => 'wikiBoardOtherGroupLabel',
            default => '',
        };
    }
}
