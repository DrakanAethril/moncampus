<?php

declare(strict_types=1);

namespace App\Service\LearningPath;

use App\Entity\LearningPath;
use App\Entity\Program;
use App\Repository\ProgramRepository;

/**
 * The author's follow-up of a learning path (design/validated/cours-en-ligne.md, §11): one row per
 * person who started it - when, how far, when they were last there, when they finished.
 *
 * Every figure of a row is App\Service\LearningPath\LearningPathBoard's reading of that person, the
 * same one their own plan shows: the follow-up computes nothing of its own, so the two cannot
 * disagree about what « 3 étapes sur 6 » means.
 *
 * The class shown is the person's class **today**: a path belongs to no class, and the question a
 * teacher asks of this screen is « where are my SIO 1 », not « where were they enrolled the day
 * they began ». The author's own walk through their path is left out - it is a test, not a follower.
 *
 * @phpstan-type TrackingRow array{progress: LearningPathProgress, name: string, classes: list<string>, classIds: list<int>}
 */
class LearningPathTracking
{
    public const array SORTS = ['name', 'class', 'started', 'progress', 'quiz', 'activity', 'completed'];

    public function __construct(
        private readonly LearningPathBoard $board,
        private readonly ProgramRepository $programs,
    ) {
    }

    /**
     * @return list<TrackingRow>
     */
    public function rows(LearningPath $path, ?int $classId = null, string $sort = 'started', bool $descending = false): array
    {
        $rows = [];

        foreach ($this->board->forPath($path) as $progress) {
            $enrollment = $progress->enrollment;
            if (null === $enrollment || $path->isOwnedBy($enrollment->getUser())) {
                continue;
            }

            $user = $enrollment->getUser();
            $programs = $this->programs->findAllActiveForStudent($user);
            $classIds = array_map(static fn (Program $program): int => (int) $program->getId(), $programs);

            if (null !== $classId && !\in_array($classId, $classIds, true)) {
                continue;
            }

            $rows[] = [
                'progress' => $progress,
                'name' => $user->getDisplayName() ?? $user->getUserIdentifier(),
                'classes' => array_map(static fn (Program $program): string => $program->getDisplayShortName(), $programs),
                'classIds' => $classIds,
            ];
        }

        $key = static fn (array $row): int|string => match ($sort) {
            'name' => mb_strtolower($row['name']),
            'class' => mb_strtolower(implode(', ', $row['classes'])),
            'progress' => $row['progress']->doneCount(),
            'quiz' => $row['progress']->validatedQuizCount(),
            'activity' => $row['progress']->enrollment?->getLastActivityAt()->getTimestamp() ?? 0,
            'completed' => $row['progress']->enrollment?->getCompletedAt()?->getTimestamp() ?? 0,
            default => $row['progress']->enrollment?->getStartedAt()->getTimestamp() ?? 0,
        };

        usort($rows, static fn (array $a, array $b): int => $descending ? $key($b) <=> $key($a) : $key($a) <=> $key($b));

        return $rows;
    }

    /**
     * The classes the filter offers: the ones the followers are in, by name.
     *
     * @param list<TrackingRow> $rows the unfiltered rows
     *
     * @return array<int, string>
     */
    public function classesOf(array $rows): array
    {
        $classes = [];
        foreach ($rows as $row) {
            foreach ($row['classIds'] as $index => $id) {
                $classes[$id] = $row['classes'][$index] ?? '';
            }
        }
        asort($classes);

        return $classes;
    }
}
