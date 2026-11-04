<?php

declare(strict_types=1);

namespace App\Service\Play;

use App\Entity\QuizTournament;
use App\Entity\User;
use App\Enum\QuizTournamentStandingBlock;
use App\Enum\QuizTournamentStatus;
use App\Repository\QuizTournamentRepository;
use App\Service\QuizTournament\QuizTournamentAccess;
use App\Service\QuizTournament\QuizTournamentElo;
use App\Service\QuizTournament\QuizTournamentLive;
use App\Service\QuizTournament\QuizTournamentStandingsAssembler;
use App\Service\SeriousGame\SeriousGameAccess;
use App\Service\SeriousGame\SeriousGameShelf;

/**
 * The facts of the student's « Jouer » entry and page (design/validated/quiz-tournament.md §15),
 * the decisions being PlayHubRule's. Two sources, each read through its own rule:
 *
 * - the **tournaments** that reach the student - the feature lit for them, opened, eligible
 *   (QuizTournamentAccess, the standings page's VIEW for a student) - listed by
 *   PlayHubRule::listsTournament();
 * - the **serious games** open to them (SeriousGameAccess / SeriousGameShelf: the module, the game
 *   published, its targeting).
 *
 * Nothing is memoised here: the menu asks through App\Twig\PlayExtension, which holds the answer for
 * the request; the page asks once.
 *
 * @phpstan-import-type ShelfCard from SeriousGameShelf
 */
class PlayHub
{
    public function __construct(
        private readonly QuizTournamentRepository $tournaments,
        private readonly QuizTournamentAccess $tournamentAccess,
        private readonly QuizTournamentStandingsAssembler $standings,
        private readonly QuizTournamentLive $live,
        private readonly SeriousGameAccess $gameAccess,
        private readonly SeriousGameShelf $shelf,
    ) {
    }

    /** Does the student's bar carry « Jouer », and does /play exist for this person? */
    public function isOpenTo(User $user, \DateTimeImmutable $now): bool
    {
        if (!PlayHubRule::isPureStudent($user->getRoles())) {
            return false;
        }

        // The tournaments first: one query when none is opened, and the games are not asked at all
        // when a tournament already answers.
        $hasTournament = [] !== $this->tournamentsFor($user, $now);

        return PlayHubRule::isVisible(true, $hasTournament, $hasTournament || $this->gameAccess->hasPlayableGame($user));
    }

    /**
     * The tournaments on the page, the latest to start first.
     *
     * @return list<QuizTournament>
     */
    public function tournamentsFor(User $user, \DateTimeImmutable $now): array
    {
        if (!PlayHubRule::isPureStudent($user->getRoles()) || !$this->tournamentAccess->isFeatureLit($user)) {
            return [];
        }

        return array_values(array_filter(
            $this->tournaments->findOpened(),
            fn (QuizTournament $tournament): bool => PlayHubRule::listsTournament($tournament->isOpened(), $tournament->getEndsAt(), $now)
                && $this->tournamentAccess->isEligible($tournament, $user),
        ));
    }

    /**
     * Each tournament with the student's place in it. The standings are computed whole - a rank is
     * a position among the others - but nothing is written (QuizTournamentStandingsAssembler::standings()).
     *
     * @return list<PlayTournamentCard>
     */
    public function tournamentCards(User $user, \DateTimeImmutable $now): array
    {
        $cards = [];
        foreach ($this->tournamentsFor($user, $now) as $tournament) {
            $status = $tournament->statusAt($now);
            $row = $this->standings->standings($tournament, $now)->rowFor((int) $user->getId());
            $cards[] = new PlayTournamentCard(
                $tournament,
                $status,
                $row->block ?? QuizTournamentStandingBlock::NotPlayed,
                $row?->rank,
                $row->entry->rating ?? QuizTournamentElo::START,
                QuizTournamentStatus::InProgress === $status ? $this->live->counterFor($tournament, $user, $now) : 0,
            );
        }

        return $cards;
    }

    /**
     * The serious games open to the student, one card each with their progress.
     *
     * @return list<ShelfCard>
     */
    public function gameCards(User $user): array
    {
        return PlayHubRule::isPureStudent($user->getRoles()) ? $this->shelf->cardsFor($user) : [];
    }
}
