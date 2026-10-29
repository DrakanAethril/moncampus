<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * What the teacher's reading of a scenario says of a student (design/validated/serious-games-neko-con.md
 * §5), as pure functions - readings, never a consequence for anybody:
 *
 * - **« Bloqués »**: a ticket in progress with at least two refused deliveries and nothing since -
 *   neither an accepted delivery, nor an answer, nor a guided question of Inès;
 * - **« Beaucoup d'aide »**: Inès' help asked on at least half of the tickets opened, two at least;
 * - per course, **the share of tickets resolved without help**, read on the closed ones.
 */
final class ScenarioReadingRules
{
    /** @param list<array{kind: ScenarioStepKind, ok: bool, help: bool}> $answers a ticket's answers, oldest first */
    public static function blocked(array $answers): bool
    {
        $refused = 0;
        foreach ($answers as $answer) {
            if (ScenarioStepKind::Fix === $answer['kind'] && !$answer['ok'] && !$answer['help']) {
                ++$refused;
                continue;
            }
            // Anything else after a refusal is a move: the count starts again from there.
            $refused = 0;
        }

        return $refused >= 2;
    }

    public static function muchHelp(int $opened, int $helped): bool
    {
        return $helped >= 2 && 2 * $helped >= $opened;
    }

    /**
     * @param list<int> $hints the help asked on each closed ticket
     */
    public static function shareWithoutHelp(array $hints): ?float
    {
        if ([] === $hints) {
            return null;
        }

        return \count(array_filter($hints, static fn (int $h): bool => 0 === $h)) / \count($hints);
    }
}
