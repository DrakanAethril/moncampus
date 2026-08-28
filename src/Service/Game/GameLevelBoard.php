<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\GameTrack;
use App\Repository\GameLevelLabelRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The six levels with the wording of one filière - the board of screen 2, and the single answer to
 * « what is this person called at level 3 ».
 *
 * Three steps down, and never an empty cell (screen 3's own note): the formation's own wording, then
 * the generic « Niveau n ». There is deliberately no per-formation override of the *thresholds* -
 * only of the words - because the ring is drawn on every screen of the application and a threshold
 * that moved between two formations would make it mean nothing.
 *
 * The section-level fallback the design mentions between the two is **not built**: the establishment
 * has one wording table, keyed by filière, and a second one keyed by section would be a table nobody
 * fills. The generic wording covers the same case.
 */
final class GameLevelBoard
{
    /** @var array<string, string>|null */
    private ?array $labels = null;

    public function __construct(
        private readonly GameLevelLabelRepository $repository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function titleFor(?GameTrack $track, int $level): string
    {
        $this->labels ??= $this->repository->allByTrackAndLevel();

        if (null !== $track) {
            $stored = $this->labels[$track->value.'|'.$level] ?? null;

            if (null !== $stored && '' !== $stored) {
                return $stored;
            }
        }

        return $this->translator->trans('gameLevelGenericTitle', ['%level%' => $level]);
    }

    /**
     * The wordings of one level across several filières, without a duplicate and never empty.
     *
     * A student whose option names no filière plays in all of their formation's, and reads what
     * that level is called in each - « Chasseur·se de bugs » and « Chasseur·se de pannes » side by
     * side rather than the generic « Niveau 3 » that answering with no filière used to give them.
     *
     * @param list<GameTrack> $tracks
     *
     * @return list<string>
     */
    public function titlesFor(array $tracks, int $level): array
    {
        $titles = [];

        foreach ($tracks as $track) {
            $title = $this->titleFor($track, $level);

            if (!\in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        return [] === $titles ? [$this->titleFor(null, $level)] : $titles;
    }

    /**
     * @param list<GameTrack> $tracks
     *
     * @return list<array{level: GameLevel, title: string, titles: list<string>}>
     */
    public function boardFor(array $tracks): array
    {
        return array_map(
            function (GameLevel $level) use ($tracks): array {
                $titles = $this->titlesFor($tracks, $level->level);

                // `title` stays the single wording a screen with one slot shows; `titles` is what a
                // board with room for both prints.
                return ['level' => $level, 'title' => $titles[0], 'titles' => $titles];
            },
            GameLevels::all(),
        );
    }

    /**
     * The whole editable board, filière by filière - screen 3's grid.
     *
     * The stored value is returned raw, empty string included, so the form can tell « nothing was
     * ever written » from « somebody wrote this ». The reader's fallback is titleFor()'s business.
     *
     * @return array<string, array<int, string>> track value => level => stored wording, missing when none
     */
    public function matrix(): array
    {
        $this->labels ??= $this->repository->allByTrackAndLevel();

        $matrix = [];
        foreach (GameTrack::cases() as $track) {
            foreach (GameLevels::all() as $level) {
                $stored = $this->labels[$track->value.'|'.$level->level] ?? null;

                if (null !== $stored) {
                    $matrix[$track->value][$level->level] = $stored;
                }
            }
        }

        return $matrix;
    }

    /** Drops the memo - the settings screen saves and redraws in the same request. */
    public function reset(): void
    {
        $this->labels = null;
    }
}
