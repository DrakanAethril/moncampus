<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * The rules of the « scénario » mechanic (design/validated/serious-games-neko-con.md §2, §3.2), as
 * pure functions - applied by the server alone:
 *
 * - **the graph and the board**: tickets open in the scenario's order, once every ticket they
 *   follow is closed, while fewer than `capacity` are open;
 * - **the current act** is the lowest act of an open ticket;
 * - **the stars** of a ticket: 3 with neither error nor help, 2 when errors + help ≤ 2, 1 otherwise.
 *   An error is a refused delivery or a wrong answer to a step; one made **during the help** does
 *   not count (asking already costs), nor does a mistyped password;
 * - **the correction** of what the server corrects - explanations, choices, lines, the password; a
 *   repair is judged by the browser, which sends its failed criteria;
 * - the pieces of the password and the clues of the notebook come from closed tickets; the game
 *   ends when every ticket is closed.
 *
 * Nothing here reads a date: there is no notion of time (§2).
 *
 * @phpstan-import-type Reaction from ScenarioStep
 */
final class ScenarioRules
{
    public const int STORED_LINE_LENGTH = 120;

    /** @var Reaction */
    private const array WRONG_CHOICE = ['who' => 'ines', 'expr' => 'sourcil', 'text' => 'Pas tout à fait. Relis bien ce que tu observes.'];

    /** @var Reaction */
    private const array WRONG_LINE = ['who' => 'ines', 'expr' => 'sourcil', 'text' => 'Ce n’est pas cette ligne. Cherche celle qui provoque le symptôme.'];

    /** @var Reaction */
    private const array WRONG_PASSWORD = ['who' => 'ines', 'expr' => 'sourcil', 'text' => 'Refusé. Les morceaux sont dans ton carnet, dans l’ordre où tu les as trouvés.'];

    /**
     * The tickets that open now.
     *
     * @param list<string> $visible every ticket already on the board, closed or not
     * @param list<string> $closed
     *
     * @return list<string>
     */
    public static function refill(Scenario $scenario, array $visible, array $closed): array
    {
        $open = \count(array_diff($visible, $closed));
        $added = [];
        foreach ($scenario->tickets() as $ticket) {
            if ($open >= $scenario->capacity()) {
                break;
            }
            if (\in_array($ticket->id, $visible, true) || [] !== array_diff($ticket->after, $closed)) {
                continue;
            }
            $added[] = $ticket->id;
            ++$open;
        }

        return $added;
    }

    /**
     * @param list<string> $visible
     * @param list<string> $closed
     */
    public static function currentAct(Scenario $scenario, array $visible, array $closed): int
    {
        if (self::finished($scenario, $closed)) {
            return max(1, \count($scenario->acts()));
        }

        $acts = [];
        foreach (array_diff($visible, $closed) as $id) {
            $acts[] = $scenario->ticket($id)->act ?? 1;
        }
        if ([] !== $acts) {
            return min($acts);
        }

        $last = [] === $visible ? null : $scenario->ticket($visible[\count($visible) - 1]);

        return $last->act ?? 1;
    }

    /** @param list<string> $closed */
    public static function finished(Scenario $scenario, array $closed): bool
    {
        return [] !== $scenario->tickets() && [] === array_diff($scenario->ticketIds(), $closed);
    }

    public static function stars(int $errors, int $hints): int
    {
        if (0 === $errors && 0 === $hints) {
            return 3;
        }

        return $errors + $hints <= 2 ? 2 : 1;
    }

    /**
     * The errors of a ticket, out of its answers.
     *
     * @param list<array{kind: ScenarioStepKind, ok: bool, help: bool}> $answers
     */
    public static function errors(array $answers): int
    {
        return \count(array_filter($answers, static fn (array $a): bool => !$a['ok'] && !$a['help'] && ScenarioStepKind::Password !== $a['kind']));
    }

    /**
     * Corrects one step. `$input` is the option's rank for an explanation or a choice, the line's
     * text for a line, `{passed, failed}` for a delivery, the typed text for the password.
     */
    public static function grade(ScenarioStep $step, mixed $input, string $password): ScenarioGrade
    {
        switch ($step->kind) {
            case ScenarioStepKind::Explain:
            case ScenarioStepKind::Choice:
                $rank = \is_int($input) ? $input : (\is_string($input) && ctype_digit($input) ? (int) $input : -1);
                $option = $step->options[$rank] ?? throw new ScenarioInputRefused('option-unknown');
                if ($option['ok']) {
                    return new ScenarioGrade(true, null, $option['reaction'], (string) $rank);
                }

                return new ScenarioGrade(false, $option['misconception'], $option['reaction'] ?? self::WRONG_CHOICE, (string) $rank);

            case ScenarioStepKind::Line:
                $line = trim(\is_string($input) ? $input : '');
                $ok = '' !== $line && array_any($step->answers, static fn (string $answer): bool => str_contains($line, $answer));

                return new ScenarioGrade($ok, null, $ok ? null : ($step->reaction ?? self::WRONG_LINE), mb_substr($line, 0, self::STORED_LINE_LENGTH));

            case ScenarioStepKind::Fix:
                $failed = [];
                foreach (\is_array($input) && \is_array($input['failed'] ?? null) ? $input['failed'] : [] as $criterion) {
                    if (\is_string($criterion) && '' !== trim($criterion)) {
                        $failed[] = trim($criterion);
                    }
                }
                $ok = \is_array($input) && true === ($input['passed'] ?? false) && [] === $failed;

                return new ScenarioGrade($ok, null, null, [] === $failed ? null : mb_substr(implode(' · ', $failed), 0, 500));

            case ScenarioStepKind::Password:
                $ok = '' !== $password && \is_string($input) && hash_equals($password, $input);

                return new ScenarioGrade($ok, null, $ok ? null : self::WRONG_PASSWORD, null);
        }
    }

    /**
     * The pieces of the password found so far, one slot per piece.
     *
     * @param list<string> $closed
     *
     * @return list<string|null>
     */
    public static function fragments(Scenario $scenario, array $closed): array
    {
        $slots = array_fill(0, $scenario->passwordPieces(), null);
        foreach ($scenario->tickets() as $ticket) {
            if (null !== $ticket->fragment && \in_array($ticket->id, $closed, true) && $ticket->fragment['index'] >= 1) {
                $slots[$ticket->fragment['index'] - 1] = $ticket->fragment['value'];
            }
        }

        return array_values($slots);
    }

    /**
     * The clues of the notebook, in the scenario's order.
     *
     * @param list<string> $closed
     *
     * @return list<string>
     */
    public static function clues(Scenario $scenario, array $closed): array
    {
        $clues = [];
        foreach ($scenario->tickets() as $ticket) {
            if (null !== $ticket->clue && \in_array($ticket->id, $closed, true) && !\in_array($ticket->clue, $clues, true)) {
                $clues[] = $ticket->clue;
            }
        }

        return $clues;
    }
}
