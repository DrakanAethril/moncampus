<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AssignmentMissingGradeChoice;
use App\Enum\GradeStatus;

/**
 * « Convertir en note »: what each student of a travail's audience ends up with in the carnet de
 * notes, out of the barème the teacher chose in the modal.
 *
 * Kept on primitives - a student id, points earned, points available - so the rule can be read and
 * tested without mounting a quiz, a class and a gradebook. The traversal of the graph stays with
 * App\Service\AssignmentQuizGradeConverter, which reads the same retained attempt the follow-up
 * screen already shows.
 *
 * The one thing worth spelling out is the denominator. A mark is *not* copied across: it is a rule
 * of three against **the attempt's own total**, because with « mêmes questions pour tous » open each
 * student is drawn their own set (App\Service\QuizDrawService) and a weighted question makes two
 * draws add up to different totals. A conversion dividing everybody by one class-wide barème would
 * be writing a fraction nobody was marked on.
 */
class AssignmentGradeConversionPlanner
{
    /**
     * @param list<array{studentId: int, pointsEarned: ?float, pointsAvailable: ?int}> $rows
     * @param array<int, AssignmentMissingGradeChoice>                                 $missingChoices student id => what to write for a student who did not sit it
     */
    public function plan(array $rows, float $scale, array $missingChoices): AssignmentGradeConversionPlan
    {
        $cells = [];
        $unresolved = [];

        foreach ($rows as $row) {
            $earned = $row['pointsEarned'];
            $available = $row['pointsAvailable'];

            // A mark exists: it wins over anything the modal may have said about this student. That
            // is what makes a second conversion catch a latecomer up - the row written « Absent »
            // last week becomes their real mark rather than staying an absence.
            if (null !== $earned && null !== $available && $available > 0) {
                $cells[] = [
                    'studentId' => $row['studentId'],
                    'status' => GradeStatus::Normal,
                    'value' => round($earned / $available * $scale, 2),
                ];

                continue;
            }

            $choice = $missingChoices[$row['studentId']] ?? null;

            if (null === $choice) {
                $unresolved[] = $row['studentId'];

                continue;
            }

            $cells[] = [
                'studentId' => $row['studentId'],
                'status' => $choice->gradeStatus(),
                'value' => $choice->gradeValue(),
            ];
        }

        return new AssignmentGradeConversionPlan($cells, $unresolved);
    }
}
