<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizLiveAnswer;
use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\LiveSessionStatus;
use App\Enum\QuizMode;
use App\Enum\QuizScoring;
use App\Repository\QuizLiveAnswerRepository;
use App\Repository\QuizLiveParticipantRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Service\QuizLive\LiveAnswerKind;
use App\Service\QuizLive\LiveNumberBuckets;
use App\Service\QuizLive\LiveOrderShuffle;
use App\Service\QuizLive\LiveResponse;
use App\Service\QuizLive\LiveScoring;
use App\Util\BlankTextParser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * The whole state machine for a Kahoot-style live multiplayer quiz session - see
 * design/design_campus_manager/reference/Générateur de quiz.dc.html, Turns 1t/1q/1u/1h/1i/1j/1r/1l,
 * reshaped on Kahoot's own run of a question on 2026-11-05 (App\Enum\LiveSessionStatus). Everything
 * else (controllers, Stimulus controllers, the mobile app) is a thin wrapper around this class: state
 * transitions, scoring, and Mercure publishing all live here.
 *
 * Every type a Kahoot carries is played (App\Service\QuizLive\LiveAnswerKind): one or several right
 * propositions among up to six shapes, a remise en ordre, a number, a word, a vote. A question the
 * room cannot play is left out at launch, and the launch says which (skippedQuestions()).
 *
 * All participants see the identical shape/color mapping (no per-student shuffle,
 * App\Service\QuizDrawService is never used here) since the whole point is a synchronized room.
 *
 * Every transition happens under a lock on the session row: two clicks on « Suivant », the host's
 * timer and the last student's answer may all arrive in the same instant, and each must move the
 * room one step, once.
 *
 * @phpstan-import-type UserAvatar from UserAvatars as ParticipantAvatar
 */
class QuizLiveSessionService
{
    private const int COUNTDOWN_SECONDS = 5;

    /** Longest display name the leaderboard can show without wrapping - and the column's own cap. */
    private const int DISPLAY_NAME_MAX_LENGTH = 100;

    /** Kahoot's scoreboard between two questions. */
    private const int SCOREBOARD_SIZE = 5;

    /** The most distinct typed answers the board lists after a réponse courte. */
    private const int TEXT_ROWS = 8;

    // The 6 shape/color slots a Kahoot-style answer can occupy, matching the projector's palette
    // (Turn 1h, widened to Kahoot's six). The player screen (Turn 1l) deliberately diverges from the
    // mockup here: it gets shape/color only, never the label - confirmed product decision (text
    // stays hidden from students; the mockup's own "✓ envoyé"-with-label rendering was not followed
    // for this one screen).
    public const array SHAPES = [
        0 => ['shape' => 'triangle', 'color' => 'blue'],
        1 => ['shape' => 'square', 'color' => 'gold'],
        2 => ['shape' => 'circle', 'color' => 'green'],
        3 => ['shape' => 'diamond', 'color' => 'red'],
        4 => ['shape' => 'pentagon', 'color' => 'purple'],
        5 => ['shape' => 'triangle-down', 'color' => 'teal'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuizInstantiationService $instantiationService,
        private readonly FileUploadService $fileUploadService,
        private readonly QuizAttemptGrader $grader,
        private readonly QuizAnswerChecker $checker,
        private readonly QuizLiveSessionRepository $sessionRepository,
        private readonly QuizLiveParticipantRepository $participantRepository,
        private readonly QuizLiveAnswerRepository $answerRepository,
        private readonly HubInterface $mercureHub,
        private readonly TokenFactoryInterface $subscriberTokenFactory,
        private readonly UserAvatars $userAvatars,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param non-empty-list<QuizTemplate> $templates the merged question pool, played in this order
     * @param string|null                  $name      free label for the session; blank falls back
     *                                                to the first template's own name
     */
    public function createSession(array $templates, Program $program, User $host, ?int $secondsPerQuestion = null, ?string $name = null): QuizLiveSession
    {
        $playable = array_values(array_filter($this->poolOf($templates), $this->isPlayable(...)));
        if ([] === $playable) {
            // Nothing the room can play - there is no session to run, and an empty instance would
            // strand the host on a projector with zero questions.
            throw LiveTemplateNotEligibleException::noPlayableQuestion();
        }

        // Live plays every playable question of the merged pool in order, synchronized for
        // everyone - no draw, no per-student fairness toggles (QuizDrawService is bypassed
        // entirely). The difficulty slider position (50, "équilibré") is inert here: it's only
        // consumed by the draw.
        $instance = $this->instantiationService->instantiateQuiz(
            $templates,
            $program,
            $host,
            QuizMode::Live,
            \count($playable),
            50,
            true,
            false,
            false,
            null,
            null,
            $secondsPerQuestion ?? $templates[0]->getDefaultSecondsPerQuestion(),
            null,
            QuizScoring::ScorePercent,
            true,
            $name,
            $this->isPlayable(...),
        );

        $session = new QuizLiveSession($instance, $host, $this->generateUniqueRoomCode());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * The questions of the pool a session leaves out - a texte à trous, zones, an appariement, a
     * calculée, or a question too unfinished to be played (no propositions, more than six, no
     * expected value). The launch names them rather than refusing the whole pool.
     *
     * @param list<QuizTemplate> $templates
     *
     * @return list<string> their statements, in pool order
     */
    public function skippedQuestions(array $templates): array
    {
        return array_values(array_map(
            static fn (QuizQuestion $question): string => mb_substr(trim(strip_tags((string) $question->getLabel())), 0, 120),
            array_filter($this->poolOf($templates), fn (QuizQuestion $question): bool => !$this->isPlayable($question)),
        ));
    }

    public function join(QuizLiveSession $session, User $student, string $displayName): QuizLiveParticipant
    {
        if (LiveSessionStatus::Lobby !== $session->getStatus()) {
            throw new LiveSessionStateException('This session is no longer joinable.');
        }

        if (!$session->getQuizInstance()->getProgram()->getStudents()->contains($student)) {
            throw new LiveSessionStateException('You are not enrolled in this program.');
        }

        $displayName = $this->normalizeDisplayName($displayName, $student);
        $participant = $this->participantRepository->findOneForStudent($session, $student);
        if (null !== $participant) {
            $participant->setDisplayName($displayName);
        } else {
            $participant = new QuizLiveParticipant($session, $student, $displayName);
            $session->addParticipant($participant);
            $this->entityManager->persist($participant);
        }

        $this->entityManager->flush();

        $this->publishToHost($session, $this->lobbyPayload($session));

        return $participant;
    }

    public function start(QuizLiveSession $session, User $host): void
    {
        $started = $this->locked($session, function () use ($session): bool {
            if (LiveSessionStatus::Lobby !== $session->getStatus()) {
                return false;
            }

            $now = new \DateTimeImmutable();
            $session->setStatus(LiveSessionStatus::Countdown);
            $session->setStartedAt($now);
            $session->setPhaseStartedAt($now);

            return true;
        });

        if (!$started) {
            throw new LiveSessionStateException('This session has already started.');
        }

        $this->publishState($session);
    }

    /**
     * The single entry point every host button calls - « Lancer », « Afficher les réponses »,
     * « Passer », « Suivant » - plus the host's own screen when the countdown or the question's
     * clock runs out. Which transition happens depends on the session's current status.
     *
     * $expectedStatus / $expectedIndex say which step the caller was looking at: a click and a
     * timer firing together must not move the room two steps. A request whose step is already
     * past is a no-op rather than an error - the screen catches up through Mercure.
     */
    public function advance(QuizLiveSession $session, User $host, ?LiveSessionStatus $expectedStatus = null, ?int $expectedIndex = null): void
    {
        $moved = $this->locked($session, function () use ($session, $expectedStatus, $expectedIndex): bool {
            if (null !== $expectedStatus && $expectedStatus !== $session->getStatus()) {
                return false;
            }
            if (null !== $expectedIndex && $expectedIndex !== $session->getCurrentQuestionIndex()) {
                return false;
            }

            match ($session->getStatus()) {
                LiveSessionStatus::Countdown => $this->openReading($session, 0),
                LiveSessionStatus::Reading => $this->openQuestion($session),
                LiveSessionStatus::Question => $this->closeQuestion($session),
                LiveSessionStatus::Results => $this->afterResults($session),
                LiveSessionStatus::Scoreboard => $this->openReading($session, (int) $session->getCurrentQuestionIndex() + 1),
                default => throw new LiveSessionStateException('Cannot advance from the current state.'),
            };

            return true;
        });

        if ($moved) {
            $this->publishState($session);
        }
    }

    public function submitAnswer(QuizLiveSession $session, User $student, LiveResponse $response): QuizLiveAnswer
    {
        $participant = $this->participantRepository->findOneForStudent($session, $student)
            ?? throw new LiveSessionStateException('You have not joined this session.');

        $closed = false;
        $answer = $this->locked($session, function () use ($session, $participant, $response, &$closed): QuizLiveAnswer {
            if (LiveSessionStatus::Question !== $session->getStatus() || $session->isQuestionTimeUp()) {
                throw new LiveSessionStateException('This question is no longer accepting answers.');
            }

            $question = $session->getCurrentQuestion() ?? throw new LiveSessionStateException('No active question.');

            $existing = $participant->findAnswerFor($question);
            if (null !== $existing) {
                return $existing; // first-write-wins, a duplicate/late POST is a no-op
            }

            $kind = LiveAnswerKind::of($question->getType()) ?? throw new LiveSessionStateException('This question cannot be played live.');
            $answerIds = array_map(static fn (QuizInstanceAnswer $answer): int => (int) $answer->getId(), $question->getAnswers()->toArray());
            if (!$response->isWellFormedFor($kind, array_values($answerIds))) {
                throw new LiveSessionStateException('Invalid answer for this question.'); // never trust the client
            }

            $answeredAt = new \DateTimeImmutable();
            $correct = $question->getType()->hasRightAnswer() ? $this->isCorrect($question, $response) : null;
            $points = LiveScoring::points(
                true === $correct,
                $this->secondsBetween($session->getPhaseStartedAt(), $answeredAt),
                $question->resolveSeconds($session->getQuizInstance()->getSecondsPerQuestion()),
            );

            $liveAnswer = new QuizLiveAnswer($participant, $question);
            $liveAnswer->setResponse($response->toArray());
            $liveAnswer->setIsCorrect($correct);
            $liveAnswer->setAnsweredAt($answeredAt);
            $liveAnswer->setPointsAwarded($points);
            $participant->addAnswer($liveAnswer);
            $participant->addScore($points);
            $this->entityManager->persist($liveAnswer);

            // Kahoot closes a question as soon as the whole room has answered (this answer included:
            // addAnswer() put it in the participant's collection).
            if ($this->answeredCount($session, $question) >= \count($session->getParticipants())) {
                $this->entityManager->flush();
                $this->closeQuestion($session);
                $closed = true;
            }

            return $liveAnswer;
        });

        if ($closed) {
            $this->publishState($session);
        } else {
            // Host-only tally - never broadcast who answered what to other players.
            $question = $session->getCurrentQuestion();
            $this->publishToHost($session, [
                'type' => 'answer-received',
                'answeredCount' => null !== $question ? \count($this->answerRepository->findForQuestion($question)) : 0,
                'participantCount' => \count($session->getParticipants()),
            ]);
        }

        return $answer;
    }

    public function finish(QuizLiveSession $session, User $host): void
    {
        $finished = $this->locked($session, function () use ($session): bool {
            if (LiveSessionStatus::Finished === $session->getStatus()) {
                return false; // idempotent - normally already true from the last advance() call
            }

            $this->concludeSession($session);

            return true;
        });

        if ($finished) {
            $this->publishState($session);
        }
    }

    public function cancel(QuizLiveSession $session, User $host): void
    {
        if (!\in_array($session->getStatus(), [LiveSessionStatus::Lobby, LiveSessionStatus::Countdown], true)) {
            throw new LiveSessionStateException('Only a lobby or countdown session can be cancelled.');
        }

        $session->setStatus(LiveSessionStatus::Cancelled);
        $session->setFinishedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->publishState($session);
    }

    public function hostTopic(QuizLiveSession $session): string
    {
        return \sprintf('quiz-live/%d/host', $session->getId());
    }

    public function playersTopic(QuizLiveSession $session): string
    {
        return \sprintf('quiz-live/%d/players', $session->getId());
    }

    // Both minted with the *subscriber* hub (a distinct secret from the publisher one in prod -
    // see config/packages/mercure.yaml) - never used to publish, only to authenticate an
    // EventSource/SSE subscription scoped to exactly one topic.
    public function mintHostSubscriberToken(QuizLiveSession $session): string
    {
        return $this->subscriberTokenFactory->create(subscribe: [$this->hostTopic($session)]);
    }

    public function mintPlayerSubscriberToken(QuizLiveSession $session): string
    {
        return $this->subscriberTokenFactory->create(subscribe: [$this->playersTopic($session)]);
    }

    // Current-state snapshot in the exact same envelope shape as what's pushed over Mercure -
    // lets a freshly (re)loaded projector page (or a reconnecting mobile client, via
    // Api\QuizLiveController's /state endpoint) render correctly before the first live event
    // arrives, with a single source of truth for the payload shape.
    /** @return array<string, mixed> */
    public function buildHostSnapshot(QuizLiveSession $session): array
    {
        return $this->snapshotFor($session, true);
    }

    /**
     * The same for a player - and, given who is asking, whether they already answered the open
     * question: a phone reloaded after its answer must not offer the shapes again.
     *
     * @return array<string, mixed>
     */
    public function buildPlayerSnapshot(QuizLiveSession $session, ?QuizLiveParticipant $participant = null): array
    {
        $snapshot = $this->snapshotFor($session, false);
        $question = $session->getCurrentQuestion();
        if (null !== $participant && null !== $question && LiveSessionStatus::Question === $session->getStatus()) {
            $snapshot['answered'] = null !== $participant->findAnswerFor($question);
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function snapshotFor(QuizLiveSession $session, bool $forHost): array
    {
        return match ($session->getStatus()) {
            LiveSessionStatus::Lobby => $forHost ? $this->lobbyPayload($session) : ['type' => 'lobby', 'participantCount' => \count($session->getParticipants())],
            LiveSessionStatus::Countdown => $this->countdownPayload($session),
            LiveSessionStatus::Reading => $this->readingPayload($session, $forHost),
            LiveSessionStatus::Question => $this->questionOpenedPayload($session, $forHost),
            LiveSessionStatus::Results => $this->resultsPayload($session),
            LiveSessionStatus::Scoreboard => $this->scoreboardPayload($session),
            LiveSessionStatus::Finished => $this->sessionFinishedPayload($session),
            LiveSessionStatus::Cancelled => ['type' => 'session-cancelled'],
        };
    }

    /**
     * Whether the room can play this question: a type it plays, and enough to play it with - two to
     * six propositions where shapes are tapped, an expected value, an accepted word.
     */
    private function isPlayable(QuizQuestion $question): bool
    {
        $kind = LiveAnswerKind::of($question->getType());
        if (null === $kind) {
            return false;
        }

        return match ($kind) {
            LiveAnswerKind::Choice, LiveAnswerKind::Multi, LiveAnswerKind::Order => \count($question->getAnswers()) >= 2 && \count($question->getAnswers()) <= \count(self::SHAPES),
            LiveAnswerKind::Number => null !== $question->getNumericAnswer(),
            LiveAnswerKind::Text => [] !== ($question->getBlankAnswers()[0] ?? []),
        };
    }

    /**
     * @param list<QuizTemplate> $templates
     *
     * @return list<QuizQuestion> in pool order, template by template
     */
    private function poolOf(array $templates): array
    {
        $questions = [];
        foreach ($templates as $template) {
            foreach ($template->getQuestions() as $question) {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    private function isCorrect(QuizInstanceQuestion $question, LiveResponse $response): bool
    {
        return match (LiveAnswerKind::of($question->getType())) {
            LiveAnswerKind::Number => $this->grader->isCorrect(
                $question,
                [],
                numericValue: $response->value,
                // The room answers with the number alone: a unit the question requires is the
                // question's own, never something a slider could get wrong.
                numericUnit: $question->getNumericUnit(),
            ),
            LiveAnswerKind::Text => $this->grader->isCorrect($question, [], [(string) $response->text]),
            default => $this->grader->isCorrect($question, $response->answerIds),
        };
    }

    /**
     * Runs $change with the session row locked and flushes it - every transition goes through
     * here, see the class docblock.
     *
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     */
    private function locked(QuizLiveSession $session, callable $change): mixed
    {
        // Not wrapInTransaction(): it closes the entity manager on any exception, and a refused
        // answer (too late, not of the question's shape) is an ordinary outcome here, not a failure
        // the rest of the request should die of.
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->refresh($session, LockMode::PESSIMISTIC_WRITE);
            $result = $change();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        return $result;
    }

    private function openReading(QuizLiveSession $session, int $index): void
    {
        if ($index >= $session->getQuizInstance()->getQuestions()->count()) {
            $this->concludeSession($session);

            return;
        }

        $session->setCurrentQuestionIndex($index);
        // No clock on the statement alone: the host shows the answers when the room has read it.
        $session->setPhaseStartedAt(null);
        $session->setStatus(LiveSessionStatus::Reading);
    }

    private function openQuestion(QuizLiveSession $session): void
    {
        // The clock starts with the answers, not with the statement.
        $session->setPhaseStartedAt(new \DateTimeImmutable());
        $session->setStatus(LiveSessionStatus::Question);
    }

    private function closeQuestion(QuizLiveSession $session): void
    {
        $question = $session->getCurrentQuestion() ?? throw new LiveSessionStateException('No active question.');

        // Backfill non-answerers so the answer distribution reflects everyone, not just
        // responders - even before the timer strictly elapsed, since the host can close early.
        foreach ($session->getParticipants() as $participant) {
            if (null === $participant->findAnswerFor($question)) {
                $noAnswer = new QuizLiveAnswer($participant, $question);
                $noAnswer->setIsCorrect($question->getType()->hasRightAnswer() ? false : null);
                $participant->addAnswer($noAnswer);
                $this->entityManager->persist($noAnswer);
            }
        }

        $session->setStatus(LiveSessionStatus::Results);
    }

    private function afterResults(QuizLiveSession $session): void
    {
        $index = (int) $session->getCurrentQuestionIndex();
        if ($index + 1 >= $session->getQuizInstance()->getQuestions()->count()) {
            // After the last question, the podium - no scoreboard in between.
            $this->concludeSession($session);

            return;
        }

        // A vote moved nobody's score: its ranking would only repeat the previous one.
        if (!($session->getCurrentQuestion()?->getType()->hasRightAnswer() ?? true)) {
            $this->openReading($session, $index + 1);

            return;
        }

        $session->setStatus(LiveSessionStatus::Scoreboard);
    }

    private function concludeSession(QuizLiveSession $session): void
    {
        $session->setStatus(LiveSessionStatus::Finished);
        $session->setFinishedAt(new \DateTimeImmutable());
    }

    /** Publishes the state the session is now in, to the host and to the players. */
    private function publishState(QuizLiveSession $session): void
    {
        $this->publishToHost($session, $this->snapshotFor($session, true));
        $this->publishToPlayers($session, $this->snapshotFor($session, false));
    }

    /**
     * The participant's own label, as they typed it in the app. Purely cosmetic - it is shown on
     * the host's screen and on the leaderboard, never used for identity - but it is the one string
     * of a live session that a student writes freely, so it is stripped of markup and of control
     * characters before being stored, and falls back to their directory name when nothing usable
     * is left.
     *
     * This is defence in depth, not the protection itself: what keeps injected markup harmless is
     * that both screens render it as text (see assets/controllers/quiz_live_host_controller.js).
     */
    private function normalizeDisplayName(string $displayName, User $student): string
    {
        $cleaned = strip_tags($displayName);
        $cleaned = preg_replace('/[\p{C}]+/u', ' ', $cleaned) ?? '';
        $cleaned = trim((string) preg_replace('/\s+/u', ' ', $cleaned));
        $cleaned = mb_substr($cleaned, 0, self::DISPLAY_NAME_MAX_LENGTH);

        return '' !== $cleaned ? $cleaned : ($student->getDisplayName() ?? $student->getUsername());
    }

    private function generateUniqueRoomCode(): string
    {
        // Crockford-ish alphabet, ambiguous characters (I, L, O, 0, 1) dropped - the code is never
        // typed by hand in v1 (see QuizLiveSession's class docblock) but stays support/debug-legible.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < 6; ++$i) {
                $code .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
            }
        } while (null !== $this->sessionRepository->findOneBy(['roomCode' => $code]));

        return $code;
    }

    /** @return array<string, mixed> */
    private function lobbyPayload(QuizLiveSession $session): array
    {
        $participants = array_values($session->getParticipants()->toArray());
        $avatars = $this->avatarsOf($participants);

        return [
            'type' => 'participant-joined',
            'participantCount' => \count($session->getParticipants()),
            'participants' => array_map(
                fn (QuizLiveParticipant $p): array => ['id' => $p->getId(), 'displayName' => $p->getDisplayName(), 'avatar' => $this->avatarOf($p, $avatars)],
                $participants,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function countdownPayload(QuizLiveSession $session): array
    {
        return [
            'type' => 'countdown-started',
            'countdownSeconds' => self::COUNTDOWN_SECONDS,
            'serverTime' => $session->getStartedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * The statement alone. The phones only learn which question of how many - the text is on the
     * board, as it always is in the room.
     *
     * @return array<string, mixed>
     */
    private function readingPayload(QuizLiveSession $session, bool $forHost): array
    {
        $question = $session->getCurrentQuestion() ?? throw new LiveSessionStateException('No active question.');

        $payload = [
            'type' => 'question-reading',
            'questionIndex' => $session->getCurrentQuestionIndex(),
            'totalQuestions' => $session->getQuizInstance()->getQuestions()->count(),
            'kind' => LiveAnswerKind::of($question->getType())?->value,
            'isVote' => !$question->getType()->hasRightAnswer(),
        ];

        if ($forHost) {
            $payload += $this->statementOf($question);
            $payload['participantCount'] = \count($session->getParticipants());
        }

        return $payload;
    }

    // Host gets the full question - never the correct-answer flag (kept back until Results, same
    // suspense as the players). Players get shapes only, no label and no question text.
    /** @return array<string, mixed> */
    private function questionOpenedPayload(QuizLiveSession $session, bool $forHost): array
    {
        $question = $session->getCurrentQuestion() ?? throw new LiveSessionStateException('No active question.');
        $kind = LiveAnswerKind::of($question->getType());

        $answers = [];
        foreach ($this->shownAnswers($session, $question) as $position => $answer) {
            $shape = self::SHAPES[$position] ?? null;
            if (null === $shape) {
                break; // defensive only - isPlayable() already caps this at createSession() time
            }
            // answerId is the real QuizInstanceAnswer id - an opaque integer, safe to send to
            // players (reveals nothing about content/correctness) and required so submitAnswer()
            // has something to validate against.
            $slot = ['answerId' => $answer->getId(), 'shapeIndex' => $position, 'shape' => $shape['shape'], 'color' => $shape['color']];
            $answers[] = $forHost ? $slot + ['label' => $answer->getLabel()] : $slot;
        }

        $payload = [
            'type' => 'question-opened',
            'questionIndex' => $session->getCurrentQuestionIndex(),
            'totalQuestions' => $session->getQuizInstance()->getQuestions()->count(),
            'kind' => $kind?->value,
            'isVote' => !$question->getType()->hasRightAnswer(),
            'secondsPerQuestion' => $question->resolveSeconds($session->getQuizInstance()->getSecondsPerQuestion()),
            'phaseStartedAt' => $session->getPhaseStartedAt()?->format(\DATE_ATOM),
            'answers' => $kind?->usesShapes() ? $answers : [],
        ];

        if (LiveAnswerKind::Number === $kind) {
            $payload['range'] = $question->getNumericRange();
            $payload['unit'] = $question->getNumericUnit();
            $payload['decimals'] = $question->getNumericDecimals();
        }

        if ($forHost) {
            $payload += $this->statementOf($question);
            $payload['participantCount'] = \count($session->getParticipants());
            $payload['answeredCount'] = $this->answeredCount($session, $question);
        }

        return $payload;
    }

    /**
     * What the room answered, and what was right - the same broadcast to the board and to every
     * phone. Each phone finds its own verdict and points under its participant id in `outcomes`:
     * a ranking is already public in a Kahoot-style room, and the outcome says no more than it.
     *
     * @return array<string, mixed>
     */
    private function resultsPayload(QuizLiveSession $session): array
    {
        $question = $session->getCurrentQuestion() ?? throw new LiveSessionStateException('No active question.');
        $kind = LiveAnswerKind::of($question->getType());
        $liveAnswers = $this->answerRepository->findForQuestion($question);

        $outcomes = [];
        $answered = 0;
        $correctCount = 0;
        foreach ($liveAnswers as $liveAnswer) {
            $participantId = $liveAnswer->getParticipant()?->getId();
            if (null === $participantId) {
                continue;
            }
            $outcomes[(string) $participantId] = [
                'answered' => null !== $liveAnswer->getResponse(),
                'correct' => $liveAnswer->getIsCorrect(),
                'points' => $liveAnswer->getPointsAwarded(),
            ];
            if (null !== $liveAnswer->getResponse()) {
                ++$answered;
            }
            if (true === $liveAnswer->getIsCorrect()) {
                ++$correctCount;
            }
        }

        $responses = array_values(array_filter(array_map(
            static fn (QuizLiveAnswer $liveAnswer): ?LiveResponse => null === $liveAnswer->getResponse() ? null : LiveResponse::fromArray($liveAnswer->getResponse()),
            $liveAnswers,
        )));

        $totalQuestions = $session->getQuizInstance()->getQuestions()->count();

        return [
            'type' => 'results',
            'questionIndex' => $session->getCurrentQuestionIndex(),
            'totalQuestions' => $totalQuestions,
            'kind' => $kind?->value,
            'isVote' => !$question->getType()->hasRightAnswer(),
            'answeredCount' => $answered,
            'correctCount' => $correctCount,
            'participantCount' => \count($session->getParticipants()),
            'outcomes' => $outcomes,
            'leaderboard' => $this->buildLeaderboard($session),
            'isLastQuestion' => $session->getCurrentQuestionIndex() === $totalQuestions - 1,
        ] + $this->statementOf($question) + $this->resultRows($session, $question, $kind, $responses);
    }

    /**
     * The bars of the results screen, by kind - and what is shown as « la bonne réponse ».
     *
     * @param list<LiveResponse> $responses
     *
     * @return array{rows: list<array<string, mixed>>, expected: string|null}
     */
    private function resultRows(QuizLiveSession $session, QuizInstanceQuestion $question, ?LiveAnswerKind $kind, array $responses): array
    {
        if (LiveAnswerKind::Number === $kind) {
            $expected = (float) $question->getNumericAnswer();
            $margin = $this->checker->numericMargin($question, $expected);
            $decimals = $question->getNumericDecimals();
            $bars = LiveNumberBuckets::of(
                array_values(array_filter(array_map(static fn (LiveResponse $response): ?float => $response->value, $responses), static fn (?float $value): bool => null !== $value)),
                $expected,
                $margin,
                $question->getNumericRange(),
            );

            return [
                'rows' => array_map(fn (array $bar): array => [
                    'label' => $bar['from'] === $bar['to'] ? $this->formatNumber($bar['from'], $decimals) : $this->formatNumber($bar['from'], $decimals).' – '.$this->formatNumber($bar['to'], $decimals),
                    'count' => $bar['count'],
                    'correct' => $bar['correct'],
                ], $bars),
                'expected' => $this->formatNumber($expected, $decimals).($margin > 0 ? ' ± '.$this->formatNumber($margin, $decimals) : '').(null !== $question->getNumericUnit() ? ' '.$question->getNumericUnit() : ''),
            ];
        }

        if (LiveAnswerKind::Text === $kind) {
            $variants = $question->getBlankAnswers()[0] ?? [];
            $groups = [];
            foreach ($responses as $response) {
                $text = (string) $response->text;
                $key = mb_strtolower(trim($text));
                $groups[$key] ??= ['label' => $text, 'count' => 0, 'correct' => BlankTextParser::matches($text, $variants, $question->isIgnoreCase(), $question->isTolerateTypo())];
                ++$groups[$key]['count'];
            }
            usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            return ['rows' => \array_slice($groups, 0, self::TEXT_ROWS), 'expected' => $variants[0] ?? null];
        }

        $shown = $this->shownAnswers($session, $question);

        if (LiveAnswerKind::Order === $kind) {
            // The right order, each piece with the shape it was shown under.
            $positions = [];
            foreach ($shown as $position => $answer) {
                $positions[(int) $answer->getId()] = $position;
            }
            $rows = [];
            foreach ($question->getAnswers() as $rank => $answer) {
                $position = $positions[(int) $answer->getId()] ?? 0;
                $rows[] = ['label' => $answer->getLabel(), 'rank' => $rank + 1, 'shapeIndex' => $position] + (self::SHAPES[$position] ?? []);
            }

            return ['rows' => $rows, 'expected' => null];
        }

        $counts = [];
        foreach ($responses as $response) {
            foreach ($response->answerIds as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        $rows = [];
        foreach ($shown as $position => $answer) {
            $rows[] = [
                'label' => $answer->getLabel(),
                'count' => $counts[(int) $answer->getId()] ?? 0,
                'correct' => $answer->isCorrect(),
                'shapeIndex' => $position,
            ] + (self::SHAPES[$position] ?? []);
        }

        return ['rows' => $rows, 'expected' => null];
    }

    /** @return array<string, mixed> */
    private function scoreboardPayload(QuizLiveSession $session): array
    {
        $leaderboard = $this->buildLeaderboard($session);

        return [
            'type' => 'scoreboard',
            'questionIndex' => $session->getCurrentQuestionIndex(),
            'totalQuestions' => $session->getQuizInstance()->getQuestions()->count(),
            'top' => \array_slice($leaderboard, 0, self::SCOREBOARD_SIZE),
            'leaderboard' => $leaderboard,
        ];
    }

    /** @return array<string, mixed> */
    private function sessionFinishedPayload(QuizLiveSession $session): array
    {
        $leaderboard = $this->buildLeaderboard($session);

        return [
            'type' => 'session-finished',
            'finalLeaderboard' => $leaderboard,
            'podium' => \array_slice($leaderboard, 0, 3),
        ];
    }

    /** @return array{label: string|null, questionType: string, imageUrl: string|null} */
    private function statementOf(QuizInstanceQuestion $question): array
    {
        return [
            'label' => $question->getLabel(),
            'questionType' => $question->getType()->value,
            'imageUrl' => null !== $question->getImageStorageKey() ? $this->fileUploadService->url($question->getImageStorageKey()) : null,
        ];
    }

    /**
     * The propositions in the order the room sees them - a remise en ordre shuffled the same way
     * for everybody (App\Service\QuizLive\LiveOrderShuffle), every other type as the quiz has them.
     *
     * @return list<QuizInstanceAnswer>
     */
    private function shownAnswers(QuizLiveSession $session, QuizInstanceQuestion $question): array
    {
        $answers = array_values($question->getAnswers()->toArray());
        if (LiveAnswerKind::Order !== LiveAnswerKind::of($question->getType())) {
            return $answers;
        }

        $byId = [];
        foreach ($answers as $answer) {
            $byId[(int) $answer->getId()] = $answer;
        }
        $order = LiveOrderShuffle::shown(array_keys($byId), (int) $session->getId() * 7919 + (int) $question->getId());

        return array_map(static fn (int $id): QuizInstanceAnswer => $byId[$id], $order);
    }

    private function answeredCount(QuizLiveSession $session, QuizInstanceQuestion $question): int
    {
        return \count(array_filter(
            $session->getParticipants()->toArray(),
            static fn (QuizLiveParticipant $participant): bool => null !== $participant->findAnswerFor($question)?->getResponse(),
        ));
    }

    private function secondsBetween(?\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        if (null === $from) {
            return 0.0;
        }

        return ((float) $to->format('U.u')) - ((float) $from->format('U.u'));
    }

    private function formatNumber(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', ' ');
    }

    /** @return list<array{participantId: int|null, displayName: string|null, score: int, rank: int, avatar: ParticipantAvatar}> */
    private function buildLeaderboard(QuizLiveSession $session): array
    {
        $ranked = $this->participantRepository->findRankedForSession($session);
        $avatars = $this->avatarsOf($ranked);

        $rows = [];
        foreach ($ranked as $index => $participant) {
            $rows[] = [
                'participantId' => $participant->getId(),
                'displayName' => $participant->getDisplayName(),
                'score' => $participant->getScore(),
                'rank' => $index + 1,
                'avatar' => $this->avatarOf($participant, $avatars),
            ];
        }

        return $rows;
    }

    /**
     * The faces the projector draws beside the participants: the one each chose on « Mon profil » -
     * photo or drawn avatar (App\Service\UserAvatars) - else nothing: the screen then writes the
     * pseudonym's initial. One query for the whole room.
     *
     * @param list<QuizLiveParticipant> $participants
     *
     * @return array<int, ParticipantAvatar>
     */
    private function avatarsOf(array $participants): array
    {
        return $this->userAvatars->forUsers(array_values(array_filter(array_map(
            static fn (QuizLiveParticipant $p): ?User => $p->getStudent(),
            $participants,
        ))));
    }

    /**
     * @param array<int, ParticipantAvatar> $avatars
     *
     * @return ParticipantAvatar
     */
    private function avatarOf(QuizLiveParticipant $participant, array $avatars): array
    {
        $id = $participant->getStudent()?->getId();

        return (null !== $id ? $avatars[$id] ?? null : null) ?? ['photo' => null, 'drawn' => null];
    }

    /** @param array<string, mixed> $payload */
    private function publishToHost(QuizLiveSession $session, array $payload): void
    {
        $this->publish($this->hostTopic($session), $payload);
    }

    /** @param array<string, mixed> $payload */
    private function publishToPlayers(QuizLiveSession $session, array $payload): void
    {
        $this->publish($this->playersTopic($session), $payload);
    }

    /**
     * Publishing is a comfort, never a condition - the tournaments' rule (QuizTournamentLive): the
     * change is already written when the hub is told, so a hub that does not answer must not turn a
     * recorded answer into an error. It is logged, and the screens catch up by asking for their
     * phase again (the play and projector `…/state` routes).
     *
     * @param array<string, mixed> $payload
     */
    private function publish(string $topic, array $payload): void
    {
        try {
            $this->mercureHub->publish(new Update($topic, json_encode($payload, \JSON_THROW_ON_ERROR), true));
        } catch (\Throwable $exception) {
            $this->logger->warning('A live contest screen could not be told of a change.', ['topic' => $topic, 'exception' => $exception]);
        }
    }
}
