<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use App\Entity\SeriousGameProgress;
use App\Entity\SeriousGameSession;
use App\Entity\SeriousGameUnitState;
use App\Entity\User;
use App\Enum\SeriousGameSessionMode;
use App\Repository\SeriousGameAnswerRepository;
use App\Repository\SeriousGameProgressRepository;
use App\Repository\SeriousGameSessionRepository;
use App\Repository\SeriousGameUnitStateRepository;
use App\SeriousGame\Scenario\Scenario;
use App\SeriousGame\Scenario\ScenarioInputRefused;
use App\SeriousGame\Scenario\ScenarioRules;
use App\SeriousGame\Scenario\ScenarioStep;
use App\SeriousGame\Scenario\ScenarioStepKind;
use App\SeriousGame\Scenario\ScenarioTicket;
use App\Service\SeriousGame\SeriousGameRules;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The server side of the « scénario » mechanic's SDK (design/validated/serious-games-neko-con.md
 * §3.2, §3.3): the board, the tickets, their steps and Inès' help.
 *
 * **The server corrects** the explanations, the guided questions and the password, holds the graph,
 * the board of four, the stars, the pieces of the password and the end; the game only measures its
 * repairs and sends the failed criteria. Nothing of the code delivered is kept, nor the password
 * typed (§3.4). The step in progress is designated by its identifier, and a ticket keeps the version
 * of the scenario it was started with (§3.3).
 *
 * What is on the board lives in SeriousGameUnitState (one row per ticket that came onto it); each
 * opening of a ticket is a SeriousGameSession of mode `ticket`, and its answers are the current
 * game's until the ticket closes - the opening that closes it carries the stars, the others are
 * abandoned. The game's display state (lines seen, acts presented, drafts, sound) is
 * SeriousGameProgress' data; `actsSeen` in it is the server's.
 *
 * A game whose scenario says `replay: true` (PHP pas à pas) also reads a closed ticket again with its
 * corrections and plays it again: a replay is a session of mode `replay` that keeps its own step and
 * hints, leaves the ticket closed, and whose stars can only raise the ticket's. Its answers are
 * written like any other, but the teachers' reading reads the first go's only (mode `ticket`).
 *
 * @phpstan-import-type Progress from SeriousGamePlay
 */
final readonly class SeriousGameScenarioPlay
{
    /** The drafts of code of a few tickets in progress are display state: larger than a series'. */
    public const int DATA_LIMIT = 262144;

    /** What the server keeps in the display state, which the game's own save never overwrites. */
    private const string ACTS_SEEN = 'actsSeen';

    public function __construct(
        private SeriousGameUnitStateRepository $states,
        private SeriousGameSessionRepository $sessions,
        private SeriousGameAnswerRepository $answers,
        private SeriousGameProgressRepository $progress,
        private SeriousGameScenarioRewards $rewards,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public static function scenario(SeriousGame $game): Scenario
    {
        $scenario = $game->getManifest()['scenario'] ?? null;

        return Scenario::fromArray(\is_array($scenario) ? $scenario : []);
    }

    /**
     * The board: the acts, the current act, the cards, the pieces of the password, the clues and the
     * end. Tickets the graph now allows come onto it first.
     *
     * @return array<string, mixed>
     */
    public function board(User $player, SeriousGame $game): array
    {
        $scenario = self::scenario($game);
        $board = $this->refill($player, $game, $scenario);
        $this->entityManager->flush();

        [$visible, $closed] = self::visibleAndClosed($board);
        $act = ScenarioRules::currentAct($scenario, $visible, $closed);
        $stars = $this->sessions->latestTicketStars($player, $game);

        return [
            'acts' => array_map(static fn (array $a): array => ['n' => $a['n'], 'title' => $a['title'], 'tools' => $a['tools']], $scenario->acts()),
            'act' => $act,
            'capacity' => $scenario->capacity(),
            'cards' => self::cards($scenario, $board, $stars),
            'total' => \count($scenario->tickets()),
            'fragments' => ScenarioRules::fragments($scenario, $closed),
            'clues' => ScenarioRules::clues($scenario, $closed),
            'finished' => ScenarioRules::finished($scenario, $closed),
            'actSeen' => \in_array($act, $this->actsSeen($player, $game), true),
        ];
    }

    /** The scene of an act was presented: the board does not play it again. */
    public function markActSeen(User $player, SeriousGame $game, int $act): void
    {
        $progress = $this->progressFor($player, $game);
        $data = $progress->getData();
        $seen = $this->actsSeen($player, $game);
        if ($act >= 1 && !\in_array($act, $seen, true)) {
            $seen[] = $act;
            $data[self::ACTS_SEEN] = $seen;
            $progress->setData($data);
        }
        $progress->touch();
        $this->entityManager->flush();
    }

    /**
     * Opens a ticket of the board: a new session, the ticket with its steps **without** their
     * answers, the step and the help in progress.
     *
     * @return array<string, mixed>
     */
    public function startTicket(User $player, SeriousGame $game, string $ticketId): array
    {
        $scenario = self::scenario($game);
        $ticket = $scenario->ticket($ticketId);
        $state = null === $ticket ? null : $this->states->findOneFor($player, $game, $ticket->id);
        if (null === $ticket || null === $state || !$state->isOnTheBoard()) {
            throw new SeriousGamePlayRefused('ticket-unknown');
        }
        if ($state->isClosed()) {
            throw new SeriousGamePlayRefused('ticket-closed');
        }

        $state->startTicket($scenario->version(), $ticket->firstStepId());
        // A revision removed the step in progress: the ticket starts again rather than block.
        $stepId = $state->getStepId();
        if (Scenario::ALL_STEPS_DONE !== $stepId && null === $ticket->step($stepId)) {
            $state->moveToStep($ticket->firstStepId());
        }

        $session = new SeriousGameSession($player, $game, SeriousGameSessionMode::Ticket, []);
        $session->setUnitCode($ticket->id);
        $this->entityManager->persist($session);
        $this->progressFor($player, $game)->touch();
        $this->entityManager->flush();

        return $this->opened($session, $ticket, $ticket->stepIndex($state->getStepId()), $state->getGuideStep(), $state->getHints());
    }

    /**
     * Plays a closed ticket again (`scenario.replay`, PHP pas à pas): a session of its own, from the
     * first step, while the ticket stays closed - nothing the board, the next tickets or the rewards
     * read moves. The replay still open is resumed, unless `$fresh` asks to start it over. Same
     * shape as startTicket(), plus `replay: true`.
     *
     * @return array<string, mixed>
     */
    public function replayTicket(User $player, SeriousGame $game, string $ticketId, bool $fresh): array
    {
        [, $ticket, $state] = $this->closedTicket($player, $game, $ticketId);
        if (!$state->isClosed()) {
            throw new SeriousGamePlayRefused('ticket-not-closed');
        }

        $open = $this->sessions->findOpenReplays($player, $game, $ticket->id);
        $session = $fresh ? null : array_pop($open);
        foreach ($open as $older) {
            $older->abandon();
        }
        // A revision removed the step in progress: the replay starts again rather than block.
        $stepId = $session?->getReplayStepId();
        if (null !== $session && Scenario::ALL_STEPS_DONE !== $stepId && null === $ticket->step($stepId)) {
            $session->moveReplayTo($ticket->firstStepId());
        }
        if (null === $session) {
            $session = SeriousGameSession::replay($player, $game, $ticket->id, $ticket->firstStepId());
            $this->entityManager->persist($session);
        }
        $this->progressFor($player, $game)->touch();
        $this->entityManager->flush();

        return $this->opened($session, $ticket, $ticket->stepIndex($session->getReplayStepId()), 0, $session->getReplayHints()) + ['replay' => true];
    }

    /**
     * A closed ticket read again with its corrections (`scenario.replay`): each step's question and,
     * for a step the server corrects, which option is right and why the others are not. Once a
     * ticket is closed - in this game or a former one - its answers are no secret any more.
     *
     * @return array{id: string, steps: list<array<string, mixed>>}
     */
    public function reviewTicket(User $player, SeriousGame $game, string $ticketId): array
    {
        [, $ticket, $state] = $this->closedTicket($player, $game, $ticketId);
        if (!$state->isClosed() && null === $state->getBest()) {
            throw new SeriousGamePlayRefused('ticket-not-closed');
        }

        return ['id' => $ticket->id, 'steps' => array_map(static function (ScenarioStep $step): array {
            $view = ['id' => $step->id, 'kind' => $step->kind->value, 'prompt' => $step->prompt];
            if ($step->kind->hasOptions()) {
                $view['options'] = array_map(static fn (array $o): array => ['text' => $o['text'], 'ok' => $o['ok'], 'why' => $o['reaction']['text'] ?? null], $step->options);
            }
            if ([] !== $step->answers) {
                $view['answers'] = $step->answers;
            }
            if (ScenarioStepKind::Order === $step->kind) {
                $view['items'] = $step->items;
                $view['order'] = $step->order;
            }

            return $view;
        }, $ticket->steps)];
    }

    /**
     * What opening a ticket answers: the session, the ticket with its steps **without** their
     * answers, the step and the help in progress.
     *
     * @return array<string, mixed>
     */
    private function opened(SeriousGameSession $session, ScenarioTicket $ticket, int $step, int $guideStep, int $hints): array
    {
        return [
            'session' => $session->getId(),
            'ticket' => [
                'id' => $ticket->id,
                'act' => $ticket->act,
                'title' => $ticket->title,
                'request' => $ticket->request,
                'requester' => $ticket->requester,
                'from' => $ticket->from,
                'priority' => $ticket->priority,
                'duplicates' => $ticket->duplicates,
                'kind' => $ticket->kind,
                'category' => $ticket->category,
                'steps' => array_map(static fn (ScenarioStep $s): array => $s->publicView(self::optionOrder($session, $s)), $ticket->steps),
                'bascule' => $ticket->bascule,
                'final' => $ticket->final,
            ],
            'step' => $step,
            'guide' => array_map(static fn (ScenarioStep $s): array => $s->publicView(self::optionOrder($session, $s)), $ticket->guide),
            'guideStep' => $guideStep,
            'hints' => $hints,
        ];
    }

    /**
     * Corrects the step in progress, named by its identifier.
     *
     * @return array<string, mixed>
     */
    public function submitStep(User $player, SeriousGameSession $session, string $stepId, mixed $input): array
    {
        if ($session->isReplay()) {
            return $this->submitReplayStep($player, $session, $stepId, $input);
        }
        [$scenario, $ticket, $state] = $this->openTicket($player, $session);
        $step = $ticket->step($state->getStepId());
        if (null === $step || $step->id !== $stepId) {
            throw new SeriousGamePlayRefused('step-mismatch');
        }

        $grade = $this->grade($scenario, $session, $state->getScenarioVersion() ?? $scenario->version(), $step, $input, false);
        if ($grade['ok']) {
            $state->moveToStep($ticket->stepAfter($step->id));
        }
        $this->entityManager->flush();

        return $grade + [
            'next' => $ticket->stepIndex($state->getStepId()),
            'done' => Scenario::ALL_STEPS_DONE === $state->getStepId(),
        ];
    }

    /**
     * Corrects one of Inès' guided questions - only once it was opened by asking for help.
     *
     * @return array<string, mixed>
     */
    public function submitGuide(User $player, SeriousGameSession $session, string $stepId, mixed $input): array
    {
        if ($session->isReplay()) {
            throw new SeriousGamePlayRefused('guide-locked');
        }
        [$scenario, $ticket, $state] = $this->openTicket($player, $session);
        $step = $ticket->guide[$state->getGuideStep()] ?? null;
        if (null === $step || $step->id !== $stepId) {
            throw new SeriousGamePlayRefused('step-mismatch');
        }
        if ($state->getHints() <= $state->getGuideStep()) {
            throw new SeriousGamePlayRefused('guide-locked');
        }

        $grade = $this->grade($scenario, $session, $state->getScenarioVersion() ?? $scenario->version(), $step, $input, true);
        if ($grade['ok']) {
            $state->passGuideStep();
        }
        $this->entityManager->flush();

        return $grade + ['next' => $state->getGuideStep()];
    }

    /**
     * One more rung of Inès' help: her next guided question, then the game's written hints. Each
     * one asked counts in the stars.
     *
     * @return array{hints: int, guideStep: int}
     */
    public function useHint(User $player, SeriousGameSession $session): array
    {
        if ($session->isReplay()) {
            [, $ticket] = $this->replayedTicket($player, $session);
            if ($session->getReplayHints() < $ticket->maxHints()) {
                $session->askReplayHint();
                $this->progressFor($player, $session->getGame())->touch();
                $this->entityManager->flush();
            }

            return ['hints' => $session->getReplayHints(), 'guideStep' => 0];
        }
        [, $ticket, $state] = $this->openTicket($player, $session);
        if ($state->getHints() < $ticket->maxHints()) {
            $state->askHint();
            $this->progressFor($player, $session->getGame())->touch();
            $this->entityManager->flush();
        }

        return ['hints' => $state->getHints(), 'guideStep' => $state->getGuideStep()];
    }

    /**
     * Closes a ticket whose steps are all answered: its stars, what it gives, the tickets it opens,
     * the act it moves to, the end, the collection pieces.
     *
     * @return array<string, mixed>
     */
    public function endTicket(User $player, SeriousGameSession $session): array
    {
        if ($session->isReplay()) {
            return $this->endReplay($player, $session);
        }
        [$scenario, $ticket, $state] = $this->openTicket($player, $session);
        if (Scenario::ALL_STEPS_DONE !== $state->getStepId()) {
            throw new SeriousGamePlayRefused('ticket-not-finished');
        }
        $game = $session->getGame();

        [$visibleBefore, $closedBefore] = self::visibleAndClosed($this->states->findBoard($player, $game));
        $actBefore = ScenarioRules::currentAct($scenario, $visibleBefore, $closedBefore);

        $openings = $this->sessions->findOpenTicketSessions($player, $game, $ticket->id);
        $errors = ScenarioRules::errors(array_map(
            static fn (SeriousGameAnswer $a): array => ['kind' => self::kindOf($ticket, $a), 'ok' => $a->isOk(), 'help' => $a->isHelp()],
            $this->answers->findForSessions($openings),
        ));
        $stars = ScenarioRules::stars($errors, $state->getHints());
        $state->closeTicket($ticket->reassigns(), $stars);
        $session->end($stars);
        foreach ($openings as $opening) {
            $opening->abandon();
        }
        $this->forgetDraft($player, $game, $ticket->id);

        $out = ['status' => $state->getStatus()?->value, 'quality' => $stars];
        if (null !== $ticket->fragment) {
            $out['fragment'] = $ticket->fragment;
        }
        $board = $this->refill($player, $game, $scenario);
        $this->entityManager->flush();
        [$visible, $closed] = self::visibleAndClosed($board);
        if (null !== $ticket->clue) {
            $out['clue'] = $ticket->clue;
            // Its rank in the notebook, which lists the clues in the scenario's order.
            $out['clueIndex'] = 1 + (int) array_search($ticket->clue, ScenarioRules::clues($scenario, $closed), true);
        }
        $added = array_values(array_diff($visible, $visibleBefore));
        $stateById = [];
        foreach ($board as $s) {
            $stateById[$s->getUnitCode()] = $s;
        }
        $out['newCards'] = self::cards($scenario, array_values(array_filter(array_map(static fn (string $id): ?SeriousGameUnitState => $stateById[$id] ?? null, $added))), []);
        $out['bascule'] = $ticket->bascule;
        $finished = ScenarioRules::finished($scenario, $closed);
        if ($finished) {
            $out['finished'] = true;
        } elseif (($actAfter = ScenarioRules::currentAct($scenario, $visible, $closed)) !== $actBefore) {
            $out['newAct'] = $actAfter;
        }

        $out['rewards'] = SeriousGameRules::canCollect($player->getRoles()) ? $this->rewards->grant($player, $game) : [];
        $this->entityManager->flush();

        return $out;
    }

    /**
     * « Nouvelle partie », after the certificate (§3.5) - or at any time when the manifest says so
     * (`restart_anytime`, Black-out): the tickets leave the board. Rewards, the grade reached, the
     * best stars and the history stay, and so do the keys of the display state the scenario names
     * (`keep_on_new_game`: PHP pas à pas' notebook and archive of answers).
     */
    public function newGame(User $player, SeriousGame $game): void
    {
        $scenario = self::scenario($game);
        [, $closed] = self::visibleAndClosed($this->states->findBoard($player, $game));
        if (!$scenario->restartAnytime() && !ScenarioRules::finished($scenario, $closed)) {
            throw new SeriousGamePlayRefused('game-not-finished');
        }

        foreach ($this->states->findForPlayer($player, $game) as $state) {
            $state->resetForNewGame();
        }
        foreach ([...$this->sessions->findOpenTicketSessions($player, $game), ...$this->sessions->findOpenReplays($player, $game)] as $session) {
            $session->abandon();
        }
        $progress = $this->progressFor($player, $game);
        $data = $progress->getData();
        $kept = \is_bool($data['mute'] ?? null) ? ['mute' => $data['mute']] : [];
        foreach ($scenario->keptOnNewGame() as $key) {
            if (\array_key_exists($key, $data)) {
                $kept[$key] = $data[$key];
            }
        }
        $progress->setData($kept)->touch();
        $this->entityManager->flush();
    }

    /**
     * The game's display state. The acts already presented are the server's and survive the save.
     *
     * @param array<string, mixed> $data
     */
    public function saveProgress(User $player, SeriousGame $game, array $data): void
    {
        $progress = $this->progressFor($player, $game);
        unset($data[self::ACTS_SEEN]);
        $seen = $progress->getData()[self::ACTS_SEEN] ?? null;
        if (\is_array($seen)) {
            $data[self::ACTS_SEEN] = $seen;
        }

        $encoded = json_encode($data);
        if (false === $encoded || \strlen($encoded) > self::DATA_LIMIT) {
            throw new SeriousGamePlayRefused('data-too-large');
        }
        $progress->setData($data)->touch();
        $this->entityManager->flush();
    }

    /**
     * What every game's getProgress() says (SeriousGamePlay's Progress shape), read on the tickets:
     * a unit is a ticket, « lit » once it was closed, its best is its best stars; a chapter is an
     * act. `finished` means the site went online once - a new game does not take it back.
     *
     * @return Progress
     */
    public function progress(User $player, SeriousGame $game): array
    {
        $scenario = self::scenario($game);
        $manifest = SeriousGamePlay::manifest($game);
        $states = $this->states->findForPlayer($player, $game);

        $units = [];
        $chapters = [];
        $lit = 0;
        foreach ($scenario->acts() as $act) {
            $actLit = 0;
            $tickets = $scenario->ticketsOfAct($act['n']);
            foreach ($tickets as $ticket) {
                $best = ($states[$ticket->id] ?? null)?->getBest();
                $units[] = [
                    'code' => $ticket->id,
                    'label' => $ticket->title,
                    'chapter' => self::chapterCode($act['n']),
                    'best' => $best,
                    'lit' => null !== $best,
                    'brightness' => null,
                    'series' => ($states[$ticket->id] ?? null)?->getSeriesCount() ?? 0,
                    'hidden' => false,
                ];
                $actLit += null === $best ? 0 : 1;
            }
            $lit += $actLit;
            $chapters[] = ['code' => self::chapterCode($act['n']), 'label' => $act['title'], 'topic' => '', 'lit' => $actLit, 'total' => \count($tickets)];
        }

        $final = null;
        foreach ($scenario->tickets() as $ticket) {
            if ($ticket->final) {
                $final = $ticket;
            }
        }
        $finalBest = null === $final ? null : ($states[$final->id] ?? null)?->getBest();
        $collects = SeriousGameRules::canCollect($player->getRoles());
        $progress = $this->progress->findOneFor($player, $game);
        $total = \count($scenario->tickets());

        return [
            'units' => $units,
            'chapters' => $chapters,
            'lit' => $lit,
            'total' => $total,
            'grade' => $manifest->gradeFor($lit),
            'finished' => null !== $finalBest || ($total > 0 && $lit === $total),
            'comets' => [],
            'bestiary' => [],
            'bestiaryTotal' => 0,
            'final' => [
                'code' => $final->id ?? 'final',
                'label' => $final->title ?? '',
                'unlocked' => null !== $final && ($states[$final->id] ?? null)?->isOnTheBoard() === true,
                'best' => $finalBest,
                'size' => \count($final->steps ?? []),
                'timeLimitSeconds' => 0,
            ],
            'collects' => $collects,
            'rewards' => $collects ? $this->rewards->held($player, $game) : [],
            'data' => null === $progress ? new \stdClass() : ($progress->getData() ?: new \stdClass()),
        ];
    }

    /**
     * The order this session shows a step's options in (ScenarioRules::optionOrder()): a new one each
     * time the ticket is opened, the same for as long as it stays open.
     *
     * @return list<int> position => the option's written rank
     */
    private static function optionOrder(SeriousGameSession $session, ScenarioStep $step): array
    {
        return ScenarioRules::optionOrder(\count($step->options), $session->getId().':'.$session->getUnitCode().':'.$step->id);
    }

    /** The chapter code of an act - the manifest's `chapters`, built by the sync, use the same. */
    public static function chapterCode(int $act): string
    {
        return 'acte'.$act;
    }

    /** A ticket's session of the player - an opening or a replay -, still open. */
    public function ownedTicketSession(User $player, SeriousGame $game, int $id): SeriousGameSession
    {
        $session = $this->sessions->findOwned($id, $player, $game);
        if (null === $session || !\in_array($session->getMode(), [SeriousGameSessionMode::Ticket, SeriousGameSessionMode::Replay], true)) {
            throw new SeriousGamePlayRefused('session-unknown');
        }
        if ($session->isEnded()) {
            throw new SeriousGamePlayRefused('session-ended');
        }

        return $session;
    }

    /**
     * @return array{0: Scenario, 1: ScenarioTicket, 2: SeriousGameUnitState}
     */
    private function openTicket(User $player, SeriousGameSession $session): array
    {
        $scenario = self::scenario($session->getGame());
        $ticket = $scenario->ticket((string) $session->getUnitCode());
        $state = null === $ticket ? null : $this->states->findOneFor($player, $session->getGame(), $ticket->id);
        if (null === $ticket || null === $state || !$state->isOnTheBoard()) {
            throw new SeriousGamePlayRefused('ticket-unknown');
        }
        if ($state->isClosed()) {
            throw new SeriousGamePlayRefused('ticket-closed');
        }

        return [$scenario, $ticket, $state];
    }

    /**
     * A ticket of a game that replays, as the player's board knows it.
     *
     * @return array{0: Scenario, 1: ScenarioTicket, 2: SeriousGameUnitState}
     */
    private function closedTicket(User $player, SeriousGame $game, string $ticketId): array
    {
        $scenario = self::scenario($game);
        if (!$scenario->replay()) {
            throw new SeriousGamePlayRefused('replay-off');
        }
        $ticket = $scenario->ticket($ticketId);
        $state = null === $ticket ? null : $this->states->findOneFor($player, $game, $ticket->id);
        if (null === $ticket || null === $state) {
            throw new SeriousGamePlayRefused('ticket-unknown');
        }

        return [$scenario, $ticket, $state];
    }

    /**
     * The ticket a replay plays - still closed, which a « Nouvelle partie » would have undone (it
     * also ends the replays).
     *
     * @return array{0: Scenario, 1: ScenarioTicket, 2: SeriousGameUnitState}
     */
    private function replayedTicket(User $player, SeriousGameSession $session): array
    {
        $result = $this->closedTicket($player, $session->getGame(), (string) $session->getUnitCode());
        if (!$result[2]->isClosed()) {
            throw new SeriousGamePlayRefused('ticket-not-closed');
        }

        return $result;
    }

    /**
     * Corrects the replay's step in progress: the same correction and the same answer row as a
     * first go, the step kept on the session.
     *
     * @return array<string, mixed>
     */
    private function submitReplayStep(User $player, SeriousGameSession $session, string $stepId, mixed $input): array
    {
        [$scenario, $ticket] = $this->replayedTicket($player, $session);
        $step = $ticket->step((string) $session->getReplayStepId());
        if (null === $step || $step->id !== $stepId) {
            throw new SeriousGamePlayRefused('step-mismatch');
        }

        $grade = $this->grade($scenario, $session, $scenario->version(), $step, $input, false);
        if ($grade['ok']) {
            $session->moveReplayTo($ticket->stepAfter($step->id));
        }
        $this->entityManager->flush();

        return $grade + [
            'next' => $ticket->stepIndex($session->getReplayStepId()),
            'done' => Scenario::ALL_STEPS_DONE === $session->getReplayStepId(),
        ];
    }

    /**
     * Ends a replay whose steps are all answered: its stars, counted like a first go's on its own
     * answers and hints, raise the ticket's best and the board's stars when better, and lower
     * nothing. `quality` is what the board now shows, `stars` this go's.
     *
     * @return array<string, mixed>
     */
    private function endReplay(User $player, SeriousGameSession $session): array
    {
        [, $ticket, $state] = $this->replayedTicket($player, $session);
        if (Scenario::ALL_STEPS_DONE !== $session->getReplayStepId()) {
            throw new SeriousGamePlayRefused('ticket-not-finished');
        }
        $game = $session->getGame();

        $before = $this->sessions->latestTicketStars($player, $game)[$ticket->id] ?? $state->getBest() ?? 0;
        $errors = ScenarioRules::errors(array_map(
            static fn (SeriousGameAnswer $a): array => ['kind' => self::kindOf($ticket, $a), 'ok' => $a->isOk(), 'help' => $a->isHelp()],
            $this->answers->findForSessions([$session]),
        ));
        $stars = ScenarioRules::stars($errors, $session->getReplayHints());
        $session->end($stars);
        $state->recordReplay($stars);
        $this->progressFor($player, $game)->touch();
        $this->entityManager->flush();
        $rewards = SeriousGameRules::canCollect($player->getRoles()) ? $this->rewards->grant($player, $game) : [];
        $this->entityManager->flush();

        return [
            'status' => $state->getStatus()?->value,
            'replay' => true,
            'stars' => $stars,
            'quality' => max($before, $stars),
            'improved' => $stars > $before,
            'newCards' => [],
            'rewards' => $rewards,
        ];
    }

    /**
     * Corrects a step and writes the answer.
     *
     * @return array{ok: bool, misconception?: string, reaction?: array{who: string, expr: string, text: string}}
     */
    private function grade(Scenario $scenario, SeriousGameSession $session, int $version, ScenarioStep $step, mixed $input, bool $help): array
    {
        if ($step->kind->hasOptions()) {
            // The game answers a rank in the order it was shown: back to the rank written.
            $shown = \is_int($input) ? $input : (\is_string($input) && ctype_digit($input) ? (int) $input : -1);
            $input = self::optionOrder($session, $step)[$shown] ?? -1;
        }
        try {
            $grade = ScenarioRules::grade($step, $input, $scenario->password(), $scenario->helper());
        } catch (ScenarioInputRefused $e) {
            throw new SeriousGamePlayRefused($e->getMessage());
        }

        $previous = $this->answers->findForSession($session);
        $last = [] === $previous ? null : $previous[array_key_last($previous)];
        $answer = SeriousGameAnswer::forStep($session, \count($previous), (string) $session->getUnitCode(), $version, $step->id, $help, $last?->getAnsweredAt() ?? $session->getStartedAt());
        $answer->answer($grade->stored, $grade->ok, $grade->misconception, false);
        $this->entityManager->persist($answer);
        $this->progressFor($session->getPlayer(), $session->getGame())->touch();

        $out = ['ok' => $grade->ok];
        if (null !== $grade->misconception) {
            $out['misconception'] = $grade->misconception;
        }
        if (null !== $grade->reaction) {
            $out['reaction'] = $grade->reaction;
        }

        return $out;
    }

    /**
     * Brings onto the board the tickets the graph allows, and returns the whole board.
     *
     * @return list<SeriousGameUnitState>
     */
    private function refill(User $player, SeriousGame $game, Scenario $scenario): array
    {
        $board = $this->states->findBoard($player, $game);
        [$visible, $closed] = self::visibleAndClosed($board);
        $added = ScenarioRules::refill($scenario, $visible, $closed);
        if ([] === $added) {
            return $board;
        }

        $all = $this->states->findForPlayer($player, $game);
        foreach ($added as $id) {
            $state = $all[$id] ?? null;
            if (null === $state) {
                $state = new SeriousGameUnitState($player, $game, $id);
                $this->entityManager->persist($state);
            }
            $board[] = $state->putOnTheBoard();
        }

        return $board;
    }

    /**
     * @param list<SeriousGameUnitState> $board
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function visibleAndClosed(array $board): array
    {
        $visible = array_map(static fn (SeriousGameUnitState $s): string => $s->getUnitCode(), $board);
        $closed = array_map(static fn (SeriousGameUnitState $s): string => $s->getUnitCode(), array_values(array_filter($board, static fn (SeriousGameUnitState $s): bool => $s->isClosed())));

        return [$visible, $closed];
    }

    /**
     * @param list<SeriousGameUnitState> $board
     * @param array<string, int>         $stars ticket => stars at its latest closing
     *
     * @return list<array<string, mixed>>
     */
    private static function cards(Scenario $scenario, array $board, array $stars): array
    {
        $cards = [];
        foreach ($board as $state) {
            $ticket = $scenario->ticket($state->getUnitCode());
            if (null === $ticket) {
                continue;
            }
            $cards[] = [
                'id' => $ticket->id,
                'act' => $ticket->act,
                'title' => $ticket->title,
                'requester' => $ticket->requester,
                'from' => $ticket->from,
                'priority' => $ticket->priority,
                'duplicates' => $ticket->duplicates,
                'kind' => $ticket->kind,
                'category' => $ticket->category,
                'status' => $state->getStatus()?->value,
                'step' => $ticket->stepIndex($state->getStepId()),
                'steps' => \count($ticket->steps),
                'quality' => $state->isClosed() ? ($stars[$ticket->id] ?? $state->getBest()) : null,
            ];
        }

        return $cards;
    }

    private static function kindOf(ScenarioTicket $ticket, SeriousGameAnswer $answer): ScenarioStepKind
    {
        $step = $answer->isHelp() ? $ticket->guideStep((string) $answer->getStepId()) : $ticket->step($answer->getStepId());

        // A step a revision removed counted as an answer to a question.
        return $step->kind ?? ScenarioStepKind::Choice;
    }

    /** @return list<int> */
    private function actsSeen(User $player, SeriousGame $game): array
    {
        $seen = $this->progressFor($player, $game)->getData()[self::ACTS_SEEN] ?? [];

        return array_values(array_filter(\is_array($seen) ? $seen : [], is_int(...)));
    }

    /** The drafts of a closed ticket are dropped (§3.4): what nobody reads is not kept. */
    private function forgetDraft(User $player, SeriousGame $game, string $ticket): void
    {
        $progress = $this->progressFor($player, $game);
        $data = $progress->getData();
        if (\is_array($data['drafts'] ?? null) && \array_key_exists($ticket, $data['drafts'])) {
            unset($data['drafts'][$ticket]);
            $progress->setData($data);
        }
        $progress->touch();
    }

    private function progressFor(User $player, SeriousGame $game): SeriousGameProgress
    {
        $progress = $this->progress->findOneFor($player, $game);
        if (null === $progress) {
            foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
                if ($scheduled instanceof SeriousGameProgress && $scheduled->getPlayer() === $player && $scheduled->getGame() === $game) {
                    return $scheduled;
                }
            }
            $progress = new SeriousGameProgress($player, $game);
            $this->entityManager->persist($progress);
        }

        return $progress;
    }
}
