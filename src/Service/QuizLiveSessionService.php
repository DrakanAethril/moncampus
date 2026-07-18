<?php

namespace App\Service;

use App\Entity\Program;
use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizLiveAnswer;
use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\LiveSessionStatus;
use App\Enum\QuestionType;
use App\Enum\QuizMode;
use App\Enum\QuizScoring;
use App\Repository\QuizLiveAnswerRepository;
use App\Repository\QuizLiveParticipantRepository;
use App\Repository\QuizLiveSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * The whole state machine for a Kahoot-style live multiplayer quiz session - see
 * design/design_campus_manager/reference/Générateur de quiz.dc.html, Turns 1t/1q/1u/1h/1i/1j/1r/1l.
 * Everything else (controllers, Stimulus controllers, the mobile app) is a thin wrapper around this
 * class: state transitions, scoring, and Mercure publishing all live here.
 *
 * The teacher manually advances through every step via advance() - Lobby (start()) -> Countdown ->
 * Question -> Reveal -> next Question -> ... -> Finished. Reveal combines the correct-answer
 * disclosure and the ranked leaderboard into a single broadcast (one screen, one "Question
 * suivante" click - see Turn 1i), not three separate steps.
 *
 * Only Qcm/VraiFaux/Image question types with <= 4 answers are eligible (see
 * findEligibilityIssues()) - the 4-shape-button UI can't represent QcmMulti/Ordre or a 5th option.
 * All participants see the identical shape/color mapping (no per-student shuffle,
 * App\Service\QuizDrawService is never used here) since the whole point is a synchronized room.
 */
class QuizLiveSessionService
{
    private const int COUNTDOWN_SECONDS = 5;

    // The 4 shape/color slots a Kahoot-style answer can occupy, matching the projector (full
    // label) and player (shape/color only, no label) screens - see Turn 1h/1l.
    private const array SHAPES = [
        0 => ['shape' => 'triangle', 'color' => 'red'],
        1 => ['shape' => 'diamond', 'color' => 'blue'],
        2 => ['shape' => 'circle', 'color' => 'yellow'],
        3 => ['shape' => 'square', 'color' => 'green'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuizInstantiationService $instantiationService,
        private readonly FileUploadService $fileUploadService,
        private readonly QuizAttemptGrader $grader,
        private readonly QuizLiveSessionRepository $sessionRepository,
        private readonly QuizLiveParticipantRepository $participantRepository,
        private readonly QuizLiveAnswerRepository $answerRepository,
        private readonly HubInterface $mercureHub,
        private readonly TokenFactoryInterface $subscriberTokenFactory,
    ) {
    }

    public function createSession(QuizTemplate $template, Program $program, User $host, ?int $secondsPerQuestion = null): QuizLiveSession
    {
        $issues = $this->findEligibilityIssues($template);
        if ([] !== $issues) {
            throw new LiveTemplateNotEligibleException($issues);
        }

        $questionCount = \count($template->getQuestions());

        // Live plays every template question in order, synchronized for everyone - no draw, no
        // per-student fairness toggles (QuizDrawService is bypassed entirely). The difficulty
        // slider position (50, "équilibré") is inert here: it's only ever consumed by the draw.
        $instance = $this->instantiationService->instantiateQuiz(
            $template,
            $program,
            $host,
            QuizMode::Live,
            $questionCount,
            50,
            true,
            false,
            false,
            null,
            null,
            $secondsPerQuestion ?? $template->getDefaultSecondsPerQuestion(),
            null,
            QuizScoring::ScorePercent,
            true,
        );

        $session = new QuizLiveSession($instance, $host, $this->generateUniqueRoomCode());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    public function join(QuizLiveSession $session, User $student, string $displayName): QuizLiveParticipant
    {
        if (LiveSessionStatus::Lobby !== $session->getStatus()) {
            throw new LiveSessionStateException('This session is no longer joinable.');
        }

        if (!$session->getQuizInstance()->getProgram()->getStudents()->contains($student)) {
            throw new LiveSessionStateException('You are not enrolled in this program.');
        }

        $participant = $this->participantRepository->findOneForStudent($session, $student);
        if (null !== $participant) {
            $participant->setDisplayName($displayName);
        } else {
            $participant = new QuizLiveParticipant($session, $student, $displayName);
            $session->addParticipant($participant);
            $this->entityManager->persist($participant);
        }

        $this->entityManager->flush();

        $this->publishToHost($session, [
            'type' => 'participant-joined',
            'participantCount' => \count($session->getParticipants()),
            'participants' => array_values(array_map(
                static fn (QuizLiveParticipant $p): array => ['id' => $p->getId(), 'displayName' => $p->getDisplayName()],
                $session->getParticipants()->toArray(),
            )),
        ]);

        return $participant;
    }

    public function start(QuizLiveSession $session, User $host): void
    {
        if (LiveSessionStatus::Lobby !== $session->getStatus()) {
            throw new LiveSessionStateException('This session has already started.');
        }

        $now = new \DateTimeImmutable();
        $session->setStatus(LiveSessionStatus::Countdown);
        $session->setStartedAt($now);
        $session->setPhaseStartedAt($now);
        $this->entityManager->flush();

        $payload = [
            'type' => 'countdown-started',
            'countdownSeconds' => self::COUNTDOWN_SECONDS,
            'serverTime' => $now->format(\DATE_ATOM),
        ];
        $this->publishToHost($session, $payload);
        $this->publishToPlayers($session, $payload);
    }

    // Single entry point the "Suivant"/"Lancer"/"Question suivante" button always calls - which
    // transition happens depends only on the session's current status.
    public function advance(QuizLiveSession $session, User $host): void
    {
        match ($session->getStatus()) {
            LiveSessionStatus::Countdown => $this->openQuestion($session, 0),
            LiveSessionStatus::Question => $this->revealCurrentQuestion($session),
            LiveSessionStatus::Reveal => $this->advanceFromReveal($session),
            default => throw new LiveSessionStateException('Cannot advance from the current state.'),
        };
    }

    public function submitAnswer(QuizLiveSession $session, User $student, int $instanceAnswerId): QuizLiveAnswer
    {
        if (LiveSessionStatus::Question !== $session->getStatus() || $session->isQuestionTimeUp()) {
            throw new LiveSessionStateException('This question is no longer accepting answers.');
        }

        $participant = $this->participantRepository->findOneForStudent($session, $student)
            ?? throw new LiveSessionStateException('You have not joined this session.');

        $question = $session->getCurrentQuestion() ?? throw new LiveSessionStateException('No active question.');

        $existing = $participant->findAnswerFor($question);
        if (null !== $existing) {
            return $existing; // first-write-wins, a duplicate/late POST is a no-op
        }

        $selected = null;
        foreach ($question->getAnswers() as $candidate) {
            if ($candidate->getId() === $instanceAnswerId) {
                $selected = $candidate;
                break;
            }
        }
        if (null === $selected) {
            throw new LiveSessionStateException('Invalid answer for this question.'); // never trust the client
        }

        $answeredAt = new \DateTimeImmutable();
        $isCorrect = $this->grader->isCorrect($question, [$selected->getId()]);
        $points = $isCorrect ? $this->computePoints($answeredAt, $session) : 0;

        $liveAnswer = new QuizLiveAnswer($participant, $question);
        $liveAnswer->setSelectedAnswer($selected);
        $liveAnswer->setIsCorrect($isCorrect);
        $liveAnswer->setAnsweredAt($answeredAt);
        $liveAnswer->setPointsAwarded($points);
        $participant->addAnswer($liveAnswer);
        $participant->addScore($points);

        $this->entityManager->persist($liveAnswer);
        $this->entityManager->flush();

        // Host-only tally - never broadcast who answered what to other players.
        $this->publishToHost($session, [
            'type' => 'answer-received',
            'answeredCount' => \count($this->answerRepository->findForQuestion($question)),
            'participantCount' => \count($session->getParticipants()),
        ]);

        return $liveAnswer;
    }

    public function finish(QuizLiveSession $session, User $host): void
    {
        if (LiveSessionStatus::Finished === $session->getStatus()) {
            return; // idempotent - normally already true from the last advance() call
        }

        $this->concludeSession($session);
    }

    public function cancel(QuizLiveSession $session, User $host): void
    {
        if (!\in_array($session->getStatus(), [LiveSessionStatus::Lobby, LiveSessionStatus::Countdown], true)) {
            throw new LiveSessionStateException('Only a lobby or countdown session can be cancelled.');
        }

        $session->setStatus(LiveSessionStatus::Cancelled);
        $session->setFinishedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $payload = ['type' => 'session-cancelled'];
        $this->publishToHost($session, $payload);
        $this->publishToPlayers($session, $payload);
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

    /** @return list<string> offending question labels, empty if the whole template is eligible */
    private function findEligibilityIssues(QuizTemplate $template): array
    {
        $issues = [];
        foreach ($template->getQuestions() as $question) {
            if (QuestionType::QcmMulti === $question->getType() || QuestionType::Ordre === $question->getType()) {
                $issues[] = $question->getLabel();
                continue;
            }
            if (\count($question->getAnswers()) > 4) {
                $issues[] = $question->getLabel();
            }
        }

        return $issues;
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

    private function openQuestion(QuizLiveSession $session, int $index): void
    {
        $session->setCurrentQuestionIndex($index);
        $session->setPhaseStartedAt(new \DateTimeImmutable());
        $session->setStatus(LiveSessionStatus::Question);
        $this->entityManager->flush();

        $question = $session->getCurrentQuestion();
        $totalQuestions = $session->getQuizInstance()->getQuestions()->count();

        $hostAnswers = [];
        $playerAnswers = [];
        foreach ($question->getAnswers() as $position => $answer) {
            if ($position >= \count(self::SHAPES)) {
                break; // defensive only - findEligibilityIssues() already caps this at createSession() time
            }
            $shape = self::SHAPES[$position];
            $hostAnswers[] = ['shapeIndex' => $position, 'shape' => $shape['shape'], 'color' => $shape['color'], 'label' => $answer->getLabel()];
            $playerAnswers[] = ['shapeIndex' => $position, 'shape' => $shape['shape'], 'color' => $shape['color']];
        }

        $common = [
            'questionIndex' => $index,
            'totalQuestions' => $totalQuestions,
            'secondsPerQuestion' => $session->getQuizInstance()->getSecondsPerQuestion(),
            'phaseStartedAt' => $session->getPhaseStartedAt()->format(\DATE_ATOM),
        ];

        // Host gets the full question - never the correct-answer flag (kept back until Reveal,
        // same suspense as the players). Players get shape/color only, no label/no question text.
        $this->publishToHost($session, ['type' => 'question-opened'] + $common + [
            'questionType' => $question->getType()->value,
            'label' => $question->getLabel(),
            'imageUrl' => null !== $question->getImageStorageKey() ? $this->fileUploadService->url($question->getImageStorageKey()) : null,
            'answers' => $hostAnswers,
        ]);

        $this->publishToPlayers($session, ['type' => 'question-opened'] + $common + [
            'answers' => $playerAnswers,
        ]);
    }

    private function revealCurrentQuestion(QuizLiveSession $session): void
    {
        $question = $session->getCurrentQuestion() ?? throw new LiveSessionStateException('No active question.');

        // Backfill non-answerers so the leaderboard/answer distribution reflects everyone, not
        // just responders - even before the timer strictly elapsed, since the teacher can reveal
        // early (no auto-timer-driven advance).
        foreach ($session->getParticipants() as $participant) {
            if (null === $participant->findAnswerFor($question)) {
                $noAnswer = new QuizLiveAnswer($participant, $question);
                $noAnswer->setIsCorrect(false);
                $participant->addAnswer($noAnswer);
                $this->entityManager->persist($noAnswer);
            }
        }

        $session->setStatus(LiveSessionStatus::Reveal);
        $this->entityManager->flush();

        $this->publishReveal($session, $question);
    }

    private function advanceFromReveal(QuizLiveSession $session): void
    {
        $totalQuestions = $session->getQuizInstance()->getQuestions()->count();
        $nextIndex = $session->getCurrentQuestionIndex() + 1;

        if ($nextIndex >= $totalQuestions) {
            $this->concludeSession($session);

            return;
        }

        $this->openQuestion($session, $nextIndex);
    }

    private function concludeSession(QuizLiveSession $session): void
    {
        $session->setStatus(LiveSessionStatus::Finished);
        $session->setFinishedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $leaderboard = $this->buildLeaderboard($session);
        $payload = [
            'type' => 'session-finished',
            'finalLeaderboard' => $leaderboard,
            'podium' => \array_slice($leaderboard, 0, 3),
        ];
        $this->publishToHost($session, $payload);
        $this->publishToPlayers($session, $payload);
    }

    // Identical payload broadcast to both host and players - a projected leaderboard is already
    // public in a Kahoot-style room, so sharing it with every player leaks nothing beyond what's
    // on the classroom screen. Only the pre-answer question *text* is ever withheld from players
    // (see openQuestion()).
    private function publishReveal(QuizLiveSession $session, QuizInstanceQuestion $question): void
    {
        $answers = $question->getAnswers()->toArray();

        $correctShapeIndex = null;
        foreach ($answers as $position => $answer) {
            if ($answer->isCorrect()) {
                $correctShapeIndex = $position;
                break;
            }
        }

        $answerCounts = array_fill(0, \count($answers), 0);
        foreach ($this->answerRepository->findForQuestion($question) as $liveAnswer) {
            $selected = $liveAnswer->getSelectedAnswer();
            if (null === $selected) {
                continue;
            }
            foreach ($answers as $position => $answer) {
                if ($answer === $selected) {
                    ++$answerCounts[$position];
                    break;
                }
            }
        }

        $totalQuestions = $session->getQuizInstance()->getQuestions()->count();

        $payload = [
            'type' => 'reveal',
            'correctShapeIndex' => $correctShapeIndex,
            'answerCounts' => $answerCounts,
            'leaderboard' => $this->buildLeaderboard($session),
            'isLastQuestion' => $session->getCurrentQuestionIndex() === $totalQuestions - 1,
        ];

        $this->publishToHost($session, $payload);
        $this->publishToPlayers($session, $payload);
    }

    /** @return list<array{participantId: int, displayName: string, score: int, rank: int}> */
    private function buildLeaderboard(QuizLiveSession $session): array
    {
        $ranked = $this->participantRepository->findRankedForSession($session);

        return array_values(array_map(
            static fn (QuizLiveParticipant $p, int $index): array => [
                'participantId' => $p->getId(),
                'displayName' => $p->getDisplayName(),
                'score' => $p->getScore(),
                'rank' => $index + 1,
            ],
            $ranked,
            array_keys($ranked),
        ));
    }

    // Kahoot-style speed bonus: correct + instant ~= 1000pts, correct right at the buzzer ~=
    // 500pts, wrong/unanswered = 0. Server timestamps only - never trust a client-reported elapsed
    // time.
    private function computePoints(\DateTimeImmutable $answeredAt, QuizLiveSession $session): int
    {
        $secondsPerQuestion = $session->getQuizInstance()->getSecondsPerQuestion() ?? 20;
        $phaseStartedAt = $session->getPhaseStartedAt();

        $elapsed = $answeredAt->getTimestamp() - $phaseStartedAt->getTimestamp();
        $remaining = max(0, $secondsPerQuestion - $elapsed);

        return (int) round(500 + 500 * $remaining / $secondsPerQuestion);
    }

    private function publishToHost(QuizLiveSession $session, array $payload): void
    {
        $this->mercureHub->publish(new Update($this->hostTopic($session), json_encode($payload, \JSON_THROW_ON_ERROR), true));
    }

    private function publishToPlayers(QuizLiveSession $session, array $payload): void
    {
        $this->mercureHub->publish(new Update($this->playersTopic($session), json_encode($payload, \JSON_THROW_ON_ERROR), true));
    }
}
