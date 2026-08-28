<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\GameTrack;

/**
 * The titles a student may display, and the one check that has to hold behind the form.
 *
 * A displayed title is chosen rather than deduced (App\Entity\GameProfile::$displayedTitle): it
 * survives a level change, so somebody who liked « Chasseur·se de bugs » keeps it at level 4. The
 * screen exists because a student playing in two filières has two wordings for each level - a SIO
 * student whose option names neither SLAM nor SISR plays in both - and nothing else in the
 * application could pick between them for them.
 *
 * **A title is only offered from a level already reached**, and that rule lives here rather than in
 * the template: the form posts a string, so a check drawn only in Twig would be a lock on a door
 * with no wall. `allows()` is what the controller asks before storing anything.
 *
 * Level 1 asks zero points, so the list is never empty and nobody is left with no choice at all.
 */
final class GameTitleBoard
{
    public function __construct(private readonly GameLevelBoard $board)
    {
    }

    /**
     * One column per filière - the shape the screen draws, with the six levels of each.
     *
     * A student in no filière at all gets a single column of the generic wording rather than no
     * column: the screen then says « Niveau 3 » and still lets them pin it.
     *
     * @param list<GameTrack> $tracks
     *
     * @return list<array{track: GameTrack|null, entries: list<array{level: GameLevel, title: string, reached: bool}>}>
     */
    public function columnsFor(array $tracks, int $totalPoints): array
    {
        $columns = [];

        foreach ([] === $tracks ? [null] : $tracks as $track) {
            $entries = [];

            foreach (GameLevels::all() as $level) {
                $entries[] = [
                    'level' => $level,
                    'title' => $this->board->titleFor($track, $level->level),
                    'reached' => $totalPoints >= $level->xpMin,
                ];
            }

            $columns[] = ['track' => $track, 'entries' => $entries];
        }

        return $columns;
    }

    /**
     * Every wording this student may pin, without a duplicate - two filières naming a level the same
     * way offer it once.
     *
     * @param list<GameTrack> $tracks
     *
     * @return list<string>
     */
    public function unlockedTitles(array $tracks, int $totalPoints): array
    {
        $titles = [];

        foreach ($this->columnsFor($tracks, $totalPoints) as $column) {
            foreach ($column['entries'] as $entry) {
                if ($entry['reached'] && !\in_array($entry['title'], $titles, true)) {
                    $titles[] = $entry['title'];
                }
            }
        }

        return $titles;
    }

    /**
     * Whether this exact wording is one of theirs to display.
     *
     * @param list<GameTrack> $tracks
     */
    public function allows(array $tracks, int $totalPoints, string $title): bool
    {
        return \in_array($title, $this->unlockedTitles($tracks, $totalPoints), true);
    }
}
