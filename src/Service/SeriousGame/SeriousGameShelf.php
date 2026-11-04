<?php

declare(strict_types=1);

namespace App\Service\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\User;
use App\Repository\SeriousGameRewardGrantRepository;
use App\Repository\SeriousGameUnitStateRepository;
use App\SeriousGame\SeriousGameManifest;

/**
 * The games a person may play, each with their progress - one card per game, in title order: the
 * catalogue's shelf (`/serious-games`), and whatever else lists « the games open to me » with how
 * far one has gone (the student's « Jouer » page, design/validated/quiz-tournament.md §15).
 *
 * Who may play is SeriousGameAccess's (for a student: the game's targeting reaches them, §14); a
 * card's progress is the units lit (`value` over `total`), read in one query. The trophy shows only
 * for a person who collects.
 *
 * @phpstan-type ShelfCard array{game: SeriousGame, value: int, total: int, status: 'new'|'progress'|'done', thumbnail: array<string, string>, desktopOnly: bool, trophy: array{label: string, finish: \App\Enum\SeriousGameFinish|null}|null}
 */
class SeriousGameShelf
{
    public function __construct(
        private readonly SeriousGameAccess $access,
        private readonly SeriousGameUnitStateRepository $states,
        private readonly SeriousGameRewardGrantRepository $grants,
    ) {
    }

    /** @return list<ShelfCard> */
    public function cardsFor(User $user): array
    {
        $games = $this->access->playableGames($user);
        if ([] === $games) {
            return [];
        }

        $lit = $this->states->litCountsFor($user);
        $held = [];
        foreach ($this->access->canCollect($user) ? $this->grants->findForStudent($user) : [] as $grant) {
            $held[$grant->getRewardCode()] = $grant;
        }

        $cards = [];
        foreach ($games as $game) {
            $manifest = new SeriousGameManifest($game->getManifest());
            $value = $lit[(int) $game->getId()] ?? 0;
            $total = max(1, $game->getUnitsTotal());
            $trophy = $manifest->trophy();
            $cards[] = [
                'game' => $game,
                'value' => min($value, $total),
                'total' => $total,
                'status' => 0 === $value ? 'new' : ($value >= $total ? 'done' : 'progress'),
                'thumbnail' => $manifest->thumbnail(),
                'desktopOnly' => $manifest->desktopOnly(),
                'trophy' => null === $trophy || !isset($held[$trophy['code']]) ? null : ['label' => $trophy['label'], 'finish' => $held[$trophy['code']]->getFinish()],
            ];
        }

        return $cards;
    }
}
