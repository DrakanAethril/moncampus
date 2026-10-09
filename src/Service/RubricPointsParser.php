<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What a teacher typed into the box of one barème question, read as points.
 *
 * A box takes less than the overall cell App\Service\GradeEntryParser reads: a number, `nt` for a
 * question the student did not treat, or nothing at all - an emptied box is a question not marked
 * yet, neither a zero nor « non traité ». And where the overall cell clamps a stray 21/20 down, a
 * question **refuses** what exceeds its own points (the design's qSet(): « if (n > pts) return; »)
 * rather than silently rewrite what was typed.
 *
 * Extracted out of App\Controller\ProgramGradebookController, like the parser it sits next to: the
 * Claude connector enters points too, and must read them exactly as the screen does.
 */
final class RubricPointsParser
{
    /**
     * @return array{points: ?float, notTested: bool}
     *
     * @throws InvalidRubricPoints
     */
    public function parse(string $raw, float $maxPoints): array
    {
        $trimmed = trim($raw);

        if ('' === $trimmed) {
            return ['points' => null, 'notTested' => false];
        }

        if ('nt' === strtolower($trimmed)) {
            return ['points' => null, 'notTested' => true];
        }

        $normalized = str_replace(',', '.', $trimmed);
        if (!is_numeric($normalized)) {
            throw new InvalidRubricPoints(InvalidRubricPoints::NOT_A_NUMBER);
        }

        $points = round((float) $normalized, 2);
        if ($points < 0 || $points > $maxPoints) {
            throw new InvalidRubricPoints(InvalidRubricPoints::EXCEEDS_MAX_POINTS);
        }

        return ['points' => $points, 'notTested' => false];
    }
}
