<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * The kinds of step of a ticket (design/validated/serious-games-neko-con.md §7,
 * serious-games-blackout.md §3): what the student does, and who corrects it (§3.2).
 */
enum ScenarioStepKind: string
{
    /** Repair the code: the browser measures the rendering and sends the failed criteria. */
    case Fix = 'fix';
    /** Choose the explanation given to the requester; a wrong one is a named misconception. */
    case Explain = 'explain';
    /** A question with options - a decision, or one of Inès' guided questions. */
    case Choice = 'choice';
    /**
     * Click a line: of the code that causes the symptom (a guided question), or of a view the game
     * draws - a log, a capture - as a step of its own (Black-out).
     */
    case Line = 'line';
    /** Type Jordan's password, the five pieces in order (the last ticket). */
    case Password = 'password';
    /** Put items in order - the timeline of Black-out's report (the last ticket). */
    case Order = 'order';

    /** Whether the server corrects it against a stored answer (every kind but the repair). */
    public function hasOptions(): bool
    {
        return self::Explain === $this || self::Choice === $this;
    }
}
