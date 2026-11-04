<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\Program;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentPlayer;
use App\Entity\User;
use App\Enum\QuizTournamentStandingBlock;
use App\Repository\QuizTournamentChallengeRepository;
use App\Repository\QuizTournamentDuelRepository;
use App\Repository\QuizTournamentPlayerRepository;
use App\Service\StructureTargeting\ReachedStudent;
use App\Service\StructureTargeting\StructureTargeting;
use App\Service\UserAvatars;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A tournament's standings, gathered from the database for the page (design/validated/quiz-tournament.md
 * §2 and §6.2). The decisions stay QuizTournamentStanding's; this class only reads the facts it decides
 * on, in a fixed number of queries whatever the size of the tournament:
 *
 * - the players (QuizTournamentPlayer) with their counters, and the **eligible** students the
 *   targeting reaches (StructureTargeting::reachedStudents() + ROLE_STUDENT) - an eligible student
 *   without a row is « Pas encore joué », a player no longer reached keeps their line, ineligible;
 * - over the 14 days before the standings' moment (now, or the end once the tournament is over): the
 *   finished duels per player, the ladder facts (a ladder duel, or a ladder challenge refused or
 *   expired), and what each rating moved (QuizTournamentRatingTrend);
 * - « la classe affichée »: the player's most recent reached formation, else the one last shown. The
 *   first is **written** on the player as it is shown (§2) - that is what lets a student who leaves the
 *   targeted classes keep a class beside their name.
 *
 * Eligibility is read now even for a tournament that is over: who the targeting reached on its last
 * day is not something the rosters remember.
 */
class QuizTournamentStandingsAssembler
{
    public function __construct(
        private readonly QuizTournamentPlayerRepository $players,
        private readonly QuizTournamentDuelRepository $duels,
        private readonly QuizTournamentChallengeRepository $challenges,
        private readonly StructureTargeting $targeting,
        private readonly UserAvatars $avatars,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function assemble(QuizTournament $tournament, \DateTimeImmutable $now): QuizTournamentStandingsView
    {
        [$standings, $players, $reached, $moment, $since] = $this->compute($tournament, $now);
        $deltas = QuizTournamentRatingTrend::deltas($this->duels->ratingMoves($tournament, $since, $moment));

        $users = [];
        foreach ($players as $userId => $player) {
            $users[$userId] = $player->getUser();
        }
        foreach ($reached as $userId => $student) {
            $users[$userId] ??= $student->user;
        }
        $avatars = $this->avatars->forUsers(array_values($users));

        $remembered = false;
        $blocks = [];
        foreach (QuizTournamentStandingBlock::cases() as $block) {
            $blocks[$block->value] = [];
            foreach ($standings->rows($block) as $row) {
                $userId = $row->entry->userId;
                $player = $players[$userId] ?? null;
                $program = $this->displayedProgram($reached[$userId] ?? null, $player);
                if (null !== $player && null !== $program && $player->getDisplayedProgram() !== $program) {
                    $player->setDisplayedProgram($program);
                    $remembered = true;
                }

                $blocks[$block->value][] = new QuizTournamentStandingLine(
                    $row,
                    $users[$userId],
                    $program,
                    $player?->getWins() ?? 0,
                    $player?->getDraws() ?? 0,
                    $player?->getLosses() ?? 0,
                    $deltas[$userId] ?? 0,
                    $avatars[$userId] ?? ['photo' => null, 'drawn' => null],
                );
            }
        }

        if ($remembered) {
            $this->entityManager->flush();
        }

        return new QuizTournamentStandingsView($standings, $blocks, $moment);
    }

    /**
     * The standings alone - no line, no avatar, nothing written: what a challenge sent or a duel
     * started stamps its ladder flags from (QuizTournamentStandings::rankedRatings()).
     */
    public function standings(QuizTournament $tournament, \DateTimeImmutable $now): QuizTournamentStandings
    {
        return $this->compute($tournament, $now)[0];
    }

    /**
     * @return array{0: QuizTournamentStandings, 1: array<int, QuizTournamentPlayer>, 2: array<int, ReachedStudent>, 3: \DateTimeImmutable, 4: \DateTimeImmutable}
     */
    private function compute(QuizTournament $tournament, \DateTimeImmutable $now): array
    {
        $moment = $tournament->standingsMoment($now);
        $since = $moment->modify(\sprintf('-%d days', QuizTournamentStanding::ACTIVITY_DAYS));

        $players = $this->players->findByTournamentIndexedByUser($tournament);
        $reached = array_filter(
            $this->targeting->reachedStudents($tournament),
            static fn (ReachedStudent $student): bool => QuizTournamentEligibility::isEligible($student->user->getRoles(), true),
        );

        $ladder = array_values(array_unique([
            ...$this->duels->ladderPlayerIds($tournament, $since, $moment),
            ...$this->challenges->ladderDeclinedChallengerIds($tournament, $since, $moment),
        ]));

        $entries = self::entries(
            array_map(static fn (QuizTournamentPlayer $player): array => [
                'sortName' => self::sortName($player->getUser()),
                'rating' => $player->getRating(),
                'duels' => $player->getDuels(),
            ], $players),
            array_map(static fn (ReachedStudent $student): string => self::sortName($student->user), $reached),
            $this->duels->finishedDuelCounts($tournament, $since, $moment),
            $ladder,
        );

        return [QuizTournamentStanding::compute($entries), $players, $reached, $moment, $since];
    }

    /**
     * The standings' entries: one per player, with their counters, and one per eligible student who
     * has not played yet (no duel, the starting rating). A player the targeting no longer reaches is
     * ineligible and keeps their line; QuizTournamentStanding drops an ineligible person with no duel.
     *
     * @param array<int, array{sortName: string, rating: int, duels: int}> $players     user id => counters
     * @param array<int, string>                                           $eligible    user id => sort name, the eligible students
     * @param array<int, int>                                              $recentDuels user id => duels finished over the last 14 days
     * @param list<int>                                                    $ladderIds   the players who hold the ladder rule over those days
     *
     * @return list<QuizTournamentStandingEntry>
     */
    public static function entries(array $players, array $eligible, array $recentDuels, array $ladderIds): array
    {
        $ladder = array_fill_keys($ladderIds, true);

        $entries = [];
        foreach ($players as $userId => $player) {
            $entries[] = new QuizTournamentStandingEntry(
                $userId,
                $player['sortName'],
                $player['rating'],
                $player['duels'],
                $recentDuels[$userId] ?? 0,
                isset($ladder[$userId]),
                isset($eligible[$userId]),
            );
        }

        foreach ($eligible as $userId => $sortName) {
            if (!isset($players[$userId])) {
                $entries[] = new QuizTournamentStandingEntry($userId, $sortName, QuizTournamentElo::START, 0, 0, false, true);
            }
        }

        return $entries;
    }

    /** « La classe affichée » (§2): the reached formation, else the one last shown, else none. */
    public static function displayedProgramId(?int $reachedProgramId, ?int $lastShownProgramId): ?int
    {
        return $reachedProgramId ?? $lastShownProgramId;
    }

    private function displayedProgram(?ReachedStudent $reached, ?QuizTournamentPlayer $player): ?Program
    {
        $last = $player?->getDisplayedProgram();
        $id = self::displayedProgramId($reached?->program->getId(), $last?->getId());

        return match (true) {
            null === $id => null,
            null !== $reached && $reached->program->getId() === $id => $reached->program,
            default => $last,
        };
    }

    private static function sortName(User $user): string
    {
        $name = trim(\sprintf('%s %s', $user->getLastname() ?? '', $user->getFirstname() ?? ''));

        return '' !== $name ? $name : $user->getUsername();
    }
}
