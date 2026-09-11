<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\User;
use App\Repository\LessonSessionRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One name for a matière held by several titulaires - the Livret alternant's « Formateur » cell,
 * and the author credited to an evaluation born of a travail whose own creator is gone.
 *
 * **Derived, never stored.** Topic::$teachers carries no principal and no position column on
 * purpose: who mainly delivers a matière is already written in the timetable, and a column saying
 * it again would be wrong the first time a créneau moved. So the answer is "the titulaire holding
 * the most créneaux of that matière", ties broken alphabetically so the same booklet exported
 * twice never names two different people - the same rule
 * App\Service\InternshipBookletBuilder::resolveTopicGroupTeacher() already applies one level up.
 *
 * A teacher standing in a créneau without being a titulaire is a remplaçant, not the formateur of
 * the matière, so only Topic::$teachers are ever returned. When the timetable names none of them -
 * a matière with no créneau yet, or one delivered entirely by someone else - the alphabetically
 * first titulaire answers rather than nothing: the booklet prints a name it can defend.
 *
 * Counts are read once per Program and memoised for the request. The memo is cleared between
 * requests (ResetInterface) because the FrankenPHP worker outlives them.
 */
class TopicPrincipalTeacher implements ResetInterface
{
    /** @var array<int, array<int, array<int, int>>> Program id => (topic id => (teacher id => créneaux)) */
    private array $countsByProgramId = [];

    public function __construct(private readonly LessonSessionRepository $lessonSessions)
    {
    }

    public function reset(): void
    {
        $this->countsByProgramId = [];
    }

    public function resolve(Topic $topic): ?User
    {
        $candidates = $topic->getOrderedTeachers();

        if ([] === $candidates) {
            return null;
        }

        $counts = $this->countsFor($topic);
        $best = null;
        $bestCount = -1;

        // $candidates is already alphabetical, and the comparison is strict ">", so an equal count
        // keeps the earlier name - that is the alphabetical tie-break, with no second sort.
        foreach ($candidates as $candidate) {
            $count = $counts[(int) $candidate->getId()] ?? 0;
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** @return array<int, int> teacher id => créneaux held in this matière */
    private function countsFor(Topic $topic): array
    {
        $program = $topic->getProgram();

        if (!$program instanceof Program || null === $program->getId()) {
            return [];
        }

        $programId = $program->getId();
        $this->countsByProgramId[$programId] ??= $this->lessonSessions->countSessionsByTopicAndTeacherForProgram($program);

        return $this->countsByProgramId[$programId][(int) $topic->getId()] ?? [];
    }
}
