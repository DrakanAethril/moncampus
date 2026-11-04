<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentRound;
use App\Entity\User;
use App\Repository\QuizTournamentChallengeRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the tournament screens follow live over Mercure (design/validated/quiz-tournament.md §5.2 and
 * §7) - **an update of the page, never an alert**: nothing here reaches anybody outside a tournament
 * screen (§1, decision 7).
 *
 * Two kinds of private topic, each subscribed through the bundle's cookie (the `subscriber` hub,
 * like the walls and the live quiz) by the screen that needs it, never published to anyone else:
 *
 * - **the « Défis » counter** of one person in one tournament,
 *   `/quiz-tournaments/{tournament}/players/{user}/challenges`: `{"count": n}` (received + open
 *   rooms, QuizTournamentChallengeBoard), published whenever a challenge to or from them changes.
 *   Every tournament screen of a student subscribes to it;
 * - **a duel's room**, `/quiz-tournaments/{tournament}/rooms/{challenge}`, subscribed by the room's
 *   two players on the room's screen: `{"event": "ready", "ready": {"<user id>": bool, …}}` when
 *   somebody clicks « Prêt » or leaves, `{"event": "start", "url": …}` when the duel is created -
 *   both browsers then go to the duel;
 * - **a duel**, `/quiz-tournaments/{tournament}/duels/{duel}`, subscribed by its two players on the
 *   duel's screen: the duel's public state (QuizTournamentDuelEngine - the question in play, who has
 *   answered, right or wrong for each once it closed, the scores), published whenever it moves -
 *   an answer, a question served, the end. **Never an answer's content**: what a player picked is
 *   only in the answers to their own requests;
 * - **a round in class**, `/quiz-tournaments/{tournament}/rounds/{round}`, subscribed by the
 *   projected screen of the teacher who opened it (§8, §10): `{"event": "roster"}` when somebody
 *   joins or leaves (the screen asks for its board again), and, once paired, a summary of each of
 *   its duels as it moves - `{"event": "duel", "duel": id, "phase", "rank", "total", "scores"}`,
 *   the scores only, never a question.
 *
 * The students of a round are told on their own counter topic, which every tournament screen of
 * theirs already follows: `{"event": "round"}` when a round they are expected in opens, is
 * cancelled, or leaves them out, and `{"event": "duel", "url": …}` when the pairing gives them a
 * duel - the page then goes to it.
 *
 * Publishing is a comfort, never a condition: a hub that does not answer is logged and the screens
 * catch up at their next request (the room's heartbeat answers the same state).
 */
class QuizTournamentLive
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly Authorization $authorization,
        private readonly QuizTournamentChallengeRepository $challenges,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function counterTopic(QuizTournament $tournament, User $user): string
    {
        return \sprintf('/quiz-tournaments/%d/players/%d/challenges', (int) $tournament->getId(), (int) $user->getId());
    }

    public function roomTopic(QuizTournamentChallenge $challenge): string
    {
        return \sprintf('/quiz-tournaments/%d/rooms/%d', (int) $challenge->getTournament()->getId(), (int) $challenge->getId());
    }

    public function roundTopic(QuizTournamentRound $round): string
    {
        return \sprintf('/quiz-tournaments/%d/rounds/%d', (int) $round->getTournament()->getId(), (int) $round->getId());
    }

    public function duelTopic(QuizTournamentDuel $duel): string
    {
        return \sprintf('/quiz-tournaments/%d/duels/%d', (int) $duel->getTournament()->getId(), (int) $duel->getId());
    }

    /**
     * Lets this browser subscribe to these topics (the bundle's cookie - one per response, so a
     * screen names all its topics at once). False when the hub is not configured: the screen then
     * draws no live link and still works.
     *
     * @param list<string> $topics
     */
    public function subscribe(Request $request, array $topics): bool
    {
        try {
            $this->authorization->setCookie($request, $topics, [], [], 'subscriber');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * What every tournament screen hands its layout: the « Défis » counter of a student (null for
     * anybody else - teachers and administrators have no challenges) and the live link that keeps
     * it current, plus whatever topics the screen itself follows (the room). Subscribes this browser
     * to all of them.
     *
     * @param list<string> $screenTopics
     *
     * @return array{url: string, counterTopic: string|null, challengeCount: int|null}
     */
    public function screen(Request $request, QuizTournament $tournament, User $user, \DateTimeImmutable $now, array $screenTopics = []): array
    {
        $player = \in_array('ROLE_STUDENT', $user->getRoles(), true);
        $counterTopic = $player ? $this->counterTopic($tournament, $user) : null;
        $topics = null === $counterTopic ? $screenTopics : [$counterTopic, ...$screenTopics];
        $live = [] !== $topics && $this->subscribe($request, $topics);

        return [
            'url' => $live ? $this->publicUrl() : '',
            'counterTopic' => $counterTopic,
            'challengeCount' => $player ? $this->counterFor($tournament, $user, $now) : null,
        ];
    }

    public function publicUrl(): string
    {
        try {
            return $this->hub->getPublicUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    /** The « Défis » counter of this person in this tournament: received challenges + open rooms. */
    public function counterFor(QuizTournament $tournament, User $user, \DateTimeImmutable $now): int
    {
        return QuizTournamentChallengeBoard::countFor(
            $this->challenges->findForBoard($tournament, $user, $now->modify(\sprintf('-%d hours', 2 * QuizTournamentChallengeBoard::OUTCOME_HOURS))),
            $user,
            $now,
        );
    }

    public function publishCounters(QuizTournament $tournament, \DateTimeImmutable $now, User ...$users): void
    {
        foreach ($users as $user) {
            $this->publish($this->counterTopic($tournament, $user), ['count' => $this->counterFor($tournament, $user, $now)]);
        }
    }

    public function publishReadiness(QuizTournamentChallenge $challenge, \DateTimeImmutable $now): void
    {
        $this->publish($this->roomTopic($challenge), [
            'event' => 'ready',
            'ready' => [
                (string) $challenge->getChallenger()->getId() => $challenge->isReady($challenge->getChallenger(), $now),
                (string) $challenge->getChallenged()->getId() => $challenge->isReady($challenge->getChallenged(), $now),
            ],
        ]);
    }

    public function publishStart(QuizTournamentChallenge $challenge, QuizTournamentDuel $duel): void
    {
        $this->publish($this->roomTopic($challenge), ['event' => 'start', 'url' => $this->duelUrl($duel)]);
    }

    /** @param array<string, mixed> $state the duel's public state - nothing personal */
    public function publishDuel(QuizTournamentDuel $duel, array $state): void
    {
        $this->publish($this->duelTopic($duel), $state);

        $round = $duel->getRound();
        if (null !== $round) {
            $this->publish($this->roundTopic($round), [
                'event' => 'duel',
                'duel' => $duel->getId(),
                'phase' => $state['phase'] ?? null,
                'rank' => $state['rank'] ?? null,
                'total' => $state['total'] ?? null,
                'scores' => $state['scores'] ?? null,
            ]);
        }
    }

    /** Something changed in a round the projected screen draws: it asks for its board again. */
    public function publishRoster(QuizTournamentRound $round): void
    {
        $this->publish($this->roundTopic($round), ['event' => 'roster']);
    }

    /**
     * A round concerns these students: their tournament screens ask for the round's card again
     * (QuizTournamentLive's class comment).
     */
    public function publishRoundChanged(QuizTournament $tournament, User ...$students): void
    {
        foreach ($students as $student) {
            $this->publish($this->counterTopic($tournament, $student), ['event' => 'round']);
        }
    }

    /** The pairing gave this student a duel: their tournament screen goes to it. */
    public function publishRoundDuel(QuizTournamentDuel $duel, User $student): void
    {
        $this->publish($this->counterTopic($duel->getTournament(), $student), ['event' => 'duel', 'url' => $this->duelUrl($duel)]);
    }

    public function duelUrl(QuizTournamentDuel $duel): string
    {
        return $this->urls->generate('app_quiz_tournament_duel', ['id' => $duel->getTournament()->getId(), 'duel' => $duel->getId()]);
    }

    /** @param array<string, mixed> $payload */
    private function publish(string $topic, array $payload): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($payload, \JSON_THROW_ON_ERROR), true));
        } catch (\Throwable $exception) {
            $this->logger->warning('A tournament screen could not be told of a change.', ['topic' => $topic, 'exception' => $exception]);
        }
    }
}
