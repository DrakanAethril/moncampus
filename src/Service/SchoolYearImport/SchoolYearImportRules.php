<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

/**
 * The rules of the school-year import that decide something, on primitives
 * (design/validated/school-year-import.md): which target formation a source formation is completed
 * into (R5), which teacher is taken over (R6), how an element is recognised (the keys of §3), how
 * the portfolio's exam session moves.
 */
final class SchoolYearImportRules
{
    public const string TEACHER_INACTIVE = 'inactive';
    public const string TEACHER_NOT_TEACHER = 'not_teacher';

    /**
     * R5. A source formation is completed into the target formation imported from it, else into the
     * one of the same cohort and the same name (after R8). Several candidates: the one carrying
     * `importedFrom`, else the oldest - and the match says it was ambiguous. A target formation
     * serves one source formation only; the others are created.
     *
     * @param list<array{id: int, cohortId: int, name: string}>                                             $sources the name already rewritten (R8)
     * @param list<array{id: int, cohortId: int, name: string, importedFromId: int|null, created: string}> $targets
     *
     * @return array<int, array{target: int, ambiguous: bool}> by source id; a source absent is created
     */
    public static function matchPrograms(array $sources, array $targets): array
    {
        $claimed = [];
        $matches = [];
        foreach ($sources as $source) {
            $candidates = array_values(array_filter($targets, static fn (array $target): bool => !isset($claimed[$target['id']]) && (
                $target['importedFromId'] === $source['id']
                || ($target['cohortId'] === $source['cohortId'] && self::key($target['name']) === self::key($source['name']))
            )));
            if ([] === $candidates) {
                continue;
            }

            usort($candidates, static fn (array $a, array $b): int => [$a['importedFromId'] === $source['id'] ? 0 : 1, $a['created'], $a['id']]
                <=> [$b['importedFromId'] === $source['id'] ? 0 : 1, $b['created'], $b['id']]);

            $chosen = $candidates[0]['id'];
            $claimed[$chosen] = true;
            $matches[$source['id']] = ['target' => $chosen, 'ambiguous' => \count($candidates) > 1];
        }

        return $matches;
    }

    /**
     * R6: why a teacher of the source formation is not taken over, or null when they are - an
     * account still active and still a teacher on the day of the import.
     *
     * @param list<string> $roles
     */
    public static function teacherRefusal(bool $inactive, array $roles): ?string
    {
        if ($inactive) {
            return self::TEACHER_INACTIVE;
        }

        return \in_array('ROLE_TEACHER', $roles, true) ? null : self::TEACHER_NOT_TEACHER;
    }

    /** How a name is recognised: case, surrounding and repeated spaces ignored. */
    public static function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /** The portfolio's exam session moves by as many years as the two years' starts are apart. */
    public static function examSession(?int $session, int $sourceStartYear, int $targetStartYear): ?int
    {
        return null === $session ? null : $session + $targetStartYear - $sourceStartYear;
    }
}
