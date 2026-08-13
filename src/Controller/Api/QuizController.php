<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptSelectedAnswer;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Entity\User;
use App\Enum\AttemptStatus;
use App\Enum\QuestionType;
use App\Enum\QuizMode;
use App\Repository\ProgramRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\QuizInstanceRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Service\FileUploadService;
use App\Service\JsonRequestPayload;
use App\Service\QuizAttemptConcluder;
use App\Service\QuizAttemptGrader;
use App\Service\QuizAttemptNotAllowedException;
use App\Service\QuizAttemptStarter;
use App\Service\QuizDrawService;
use App\Service\QuizQuestionPayload;
use App\Service\StudentQuizBoard;
use App\Util\NumericAnswerParser;
use App\Util\NumericVariableParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mobile counterpart to App\Controller\ProgramQuizAttemptController - the student's own quiz
 * section (screen 1k) and individual passation, évaluation and entraînement alike, including the
 * texte à trous type the live concours skips.
 *
 * The live multiplayer side lives in its own controller (Api\QuizLiveController): it is a
 * synchronized state machine over Mercure, whereas everything here is an ordinary request/response
 * flow the student drives at their own pace.
 *
 * Deliberately mirrors the web flow rather than inventing a mobile-only one: the same
 * QuizAttemptStarter, the same QuizDrawService ordering and the same QuizAttemptGrader verdicts, so
 * a student who starts a quiz on the phone and finishes it in a browser (or the reverse) picks up
 * exactly where they left off - answers are persisted per question, not per attempt.
 *
 * Class-level ROLE_STUDENT gate, same as Api\QuizLiveController (security.yaml's `^/api` rule only
 * requires authentication).
 */
#[IsGranted('ROLE_STUDENT')]
class QuizController extends AbstractController
{
    public function __construct(
        private readonly FileUploadService $fileUploadService,
        private readonly QuizAttemptConcluder $concluder,
        private readonly QuizQuestionPayload $questionPayloadBuilder,
    ) {
    }

    /**
     * Screen 1k: the concours banner, the évaluations, and the free-practice quizzes, for the
     * student's own class. One call - the mobile hub is a single scroll, not three tabs.
     */
    #[Route(path: '/api/quiz/mine', name: 'api_quiz_mine', methods: ['GET'])]
    public function mine(
        ProgramRepository $programRepository,
        StudentQuizBoard $quizBoard,
        QuizAttemptRepository $attemptRepository,
        QuizLiveSessionRepository $liveSessionRepository,
    ): JsonResponse {
        $student = $this->currentUser();
        $program = $programRepository->findActiveForStudent($student);

        if (null === $program) {
            return $this->json(['program' => null, 'liveSession' => null, 'evaluations' => [], 'practice' => []]);
        }

        $liveSession = $liveSessionRepository->findActiveForProgram($program);
        $evaluations = [];
        $practice = [];

        // The same gate the web hub asks, from the same object - the app must not be the way round
        // a condition (App\Service\StudentQuizBoard).
        $readable = $quizBoard->readableFor($program, $student);

        foreach ($readable->instances as $instance) {
            $inProgress = $attemptRepository->findInProgress($instance, $student);
            $lastConcluded = $attemptRepository->findLastConcluded($instance, $student);
            // Sent rather than hidden: a locked quiz stays on screen with the way out written on
            // it, which is what an empty list cannot say. An older build ignores the two keys and
            // simply shows the row - the start call is refused on the server either way.
            $lockedBy = $readable->verdicts->isOpen($instance) ? [] : $readable->verdicts->reasonsFor($instance);

            if (QuizMode::Evaluation === $instance->getMode()) {
                $evaluations[] = [
                    'instanceId' => $instance->getId(),
                    'name' => $instance->getName(),
                    'questionCount' => $instance->getQuestionCount(),
                    'secondsPerQuestion' => $instance->getSecondsPerQuestion(),
                    'globalTimeMinutes' => $instance->getGlobalTimeMinutes(),
                    'closesAt' => $instance->getClosesAt()?->format(\DateTimeInterface::ATOM),
                    'openNow' => $instance->isOpenNow(),
                    'inProgress' => null !== $inProgress,
                    // Only surfaced once the teacher allows it - "copie remise" otherwise, exactly
                    // like the web result screen.
                    'done' => null !== $lastConcluded,
                    'scorePercent' => $instance->isScoreVisibleImmediately() ? $lastConcluded?->getScorePercent() : null,
                    'locked' => [] !== $lockedBy,
                    'lockedReasons' => $lockedBy,
                ];

                continue;
            }

            $concluded = array_values(array_filter(
                $attemptRepository->findForStudent($instance, $student),
                static fn (QuizAttempt $attempt): bool => $attempt->isConcluded(),
            ));
            $best = null;
            foreach ($concluded as $attempt) {
                if (null === $best || ($attempt->getScorePercent() ?? -1) > ($best->getScorePercent() ?? -1)) {
                    $best = $attempt;
                }
            }

            $practice[] = [
                'instanceId' => $instance->getId(),
                'name' => $instance->getName(),
                'questionCount' => $instance->getQuestionCount(),
                'secondsPerQuestion' => $instance->getSecondsPerQuestion(),
                'openNow' => $instance->isOpenNow(),
                'inProgress' => null !== $inProgress,
                'attemptCount' => \count($concluded),
                'bestScorePercent' => $best?->getScorePercent(),
                'lastScorePercent' => $lastConcluded?->getScorePercent(),
                'locked' => [] !== $lockedBy,
                'lockedReasons' => $lockedBy,
            ];
        }

        return $this->json([
            'program' => ['id' => $program->getId(), 'name' => $program->getDisplayShortName()],
            'liveSession' => null === $liveSession ? null : [
                'sessionId' => $liveSession->getId(),
                'name' => $liveSession->getQuizInstance()->getName(),
                'programId' => $program->getId(),
                'hostName' => $liveSession->getHost()->getDisplayName() ?? $liveSession->getHost()->getUsername(),
                'participantCount' => \count($liveSession->getParticipants()),
            ],
            'evaluations' => $evaluations,
            'practice' => $practice,
        ]);
    }

    /** "Commencer" / "S'entraîner" - resumes an open attempt or draws a new one. */
    #[Route(path: '/api/quiz/{instanceId}/start', name: 'api_quiz_start', requirements: ['instanceId' => '\d+'], methods: ['POST'])]
    public function start(int $instanceId, ProgramRepository $programRepository, QuizInstanceRepository $instanceRepository, StudentQuizBoard $quizBoard, QuizAttemptStarter $attemptStarter): JsonResponse
    {
        $student = $this->currentUser();
        $instance = $this->findInstanceOrNotFound($instanceRepository, $programRepository, $instanceId);

        // The app holds instance ids from its last refresh; a condition that has closed since must
        // not be startable from a stale list.
        if (!$quizBoard->isOpenFor($instance, $student)) {
            return $this->json(['error' => 'quiz_locked'], Response::HTTP_CONFLICT);
        }

        try {
            $started = $attemptStarter->startOrResume($instance, $student);
        } catch (QuizAttemptNotAllowedException) {
            return $this->json(['error' => 'quiz_closed'], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'attemptId' => $started['attempt']->getId(),
            // The app sends the student to the result screen instead of question 1 - an évaluation
            // is one attempt only.
            'concluded' => $started['concluded'],
        ]);
    }

    /**
     * One question of an attempt, at the student's own presentation position, with its answers
     * already in this attempt's order (never the stored order - that would leak "ordre" solutions).
     */
    #[Route(path: '/api/quiz/attempt/{attemptId}/question/{position}', name: 'api_quiz_question', requirements: ['attemptId' => '\d+', 'position' => '\d+'], methods: ['GET'])]
    public function question(int $attemptId, int $position, EntityManagerInterface $entityManager, ProgramRepository $programRepository, QuizAttemptRepository $attemptRepository, QuizDrawService $drawService): JsonResponse
    {
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $programRepository, $attemptId);

        if ($this->concludeIfExpired($attempt, $entityManager) || $attempt->isConcluded()) {
            return $this->json(['concluded' => true, 'attemptId' => $attempt->getId()]);
        }

        $attemptAnswers = $attempt->getAttemptAnswers()->toArray();
        $attemptAnswer = $attemptAnswers[$position] ?? throw $this->createNotFoundException();
        $question = $attemptAnswer->getInstanceQuestion();
        $instance = $attempt->getQuizInstance();

        return $this->json([
            'concluded' => false,
            'attemptId' => $attempt->getId(),
            'quizName' => $instance->getName(),
            'mode' => $instance->getMode()->value,
            'position' => $position,
            'total' => \count($attemptAnswers),
            // Kept for older builds of the app, which read it as THE per-question time; new builds
            // read secondsForQuestion, which is the one that accounts for the question's own mode
            // (null = no limit at all, so no countdown for this question).
            'secondsPerQuestion' => $instance->getSecondsPerQuestion(),
            'secondsForQuestion' => $question->resolveSeconds($instance->getSecondsPerQuestion()),
            'deadline' => $attempt->getTimeLimitAt()?->format(\DateTimeInterface::ATOM),
            'question' => $this->questionPayload($question, $attempt, $drawService),
        ]);
    }

    /**
     * Saves one question's answer and says where to go next. Same contract as the web form: the
     * answer is recorded when the student moves on, and re-posting an already-answered question
     * simply overwrites it (they can only ever be on one question at a time).
     */
    #[Route(path: '/api/quiz/attempt/{attemptId}/question/{position}/answer', name: 'api_quiz_answer', requirements: ['attemptId' => '\d+', 'position' => '\d+'], methods: ['POST'])]
    public function answer(int $attemptId, int $position, Request $request, EntityManagerInterface $entityManager, ProgramRepository $programRepository, QuizAttemptRepository $attemptRepository, QuizAttemptGrader $grader, QuizDrawService $drawService): JsonResponse
    {
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $programRepository, $attemptId);

        if ($this->concludeIfExpired($attempt, $entityManager) || $attempt->isConcluded()) {
            return $this->json(['concluded' => true, 'attemptId' => $attempt->getId()]);
        }

        $attemptAnswers = $attempt->getAttemptAnswers()->toArray();
        $attemptAnswer = $attemptAnswers[$position] ?? throw $this->createNotFoundException();
        $question = $attemptAnswer->getInstanceQuestion();

        $payload = JsonRequestPayload::fromRequest($request);

        $blankResponses = [];
        if ($question->getType()->usesBlankAnswers()) {
            $submittedBlanks = $payload->strings('blanks');
            for ($i = 0, $blankCount = $question->getBlankCount(); $i < $blankCount; ++$i) {
                $blankResponses[] = trim($submittedBlanks[$i] ?? '');
            }
        }

        // Zone posts {"zones": ["z4"]}, Légende posts {"placements": [{"zone": "s", "choice": "s"}]}
        // - both bounded to the question's own config, same as the web flow.
        $zoneResponses = [];
        if (QuestionType::Zone === $question->getType()) {
            $zoneResponses = array_values(array_unique(array_intersect($payload->strings('zones'), $question->getZoneIds())));
        } elseif (QuestionType::Legende === $question->getType()) {
            $choiceKeys = array_column($question->getLegendeChoices(), 'key');
            $zoneIds = $question->getZoneIds();
            foreach ($payload->objects('placements') as $placement) {
                $zoneId = $placement->string('zone');
                $choice = $placement->string('choice');
                if (\in_array($zoneId, $zoneIds, true) && \in_array($choice, $choiceKeys, true)) {
                    $zoneResponses[$zoneId] = $choice;
                }
            }
        }

        // Apparier posts {"pairs": [{"pair": "p1", "choice": "p1"}]} - same shape as the légende
        // placements just above, and bounded the same way.
        $matchingResponses = [];
        if (QuestionType::Apparier === $question->getType()) {
            $choiceKeys = array_column($question->getMatchingChoices(), 'key');
            $pairIds = $question->getMatchingPairIds();
            foreach ($payload->objects('pairs') as $association) {
                $pairId = $association->string('pair');
                $choice = $association->string('choice');
                if (\in_array($pairId, $pairIds, true) && \in_array($choice, $choiceKeys, true)) {
                    $matchingResponses[$pairId] = $choice;
                }
            }
        }

        // Numérique / Calculée post {"numeric": "240 km"} - one free-text field, read exactly like
        // the web's, so a French comma or a trailing unit costs nothing on either client.
        $numericRaw = null;
        $numericParsed = ['value' => null, 'unit' => null];
        $numericVariables = [];
        if ($question->getType()->usesNumericConfig()) {
            $numericRaw = $payload->string('numeric');
            $numericParsed = NumericAnswerParser::parse($numericRaw);
            $numericVariables = $question->getType()->usesFormula()
                ? $drawService->drawNumericVariables($question, $attempt)
                : [];
        }

        $answersById = [];
        foreach ($question->getAnswers() as $instanceAnswer) {
            $answersById[$instanceAnswer->getId()] = $instanceAnswer;
        }

        foreach ($attemptAnswer->getSelectedAnswers()->toArray() as $existing) {
            $attemptAnswer->removeSelectedAnswer($existing);
        }

        $submittedIds = $payload->ids('answers');
        $orderIndex = 0;
        foreach ($submittedIds as $answerId) {
            $instanceAnswer = $answersById[$answerId] ?? null;
            if (!$instanceAnswer instanceof QuizInstanceAnswer) {
                continue; // never trust the client with ids that aren't this question's
            }
            $selected = new QuizAttemptSelectedAnswer($attemptAnswer, $instanceAnswer);
            $selected->setOrderIndex($orderIndex++);
            $attemptAnswer->addSelectedAnswer($selected);
        }

        $validSubmittedIds = array_values(array_filter($submittedIds, static fn (int $answerId): bool => isset($answersById[$answerId])));
        $attemptAnswer->setBlankResponses([] !== $blankResponses ? $blankResponses : null);
        $attemptAnswer->setZoneResponses($question->getType()->usesZoneConfig() ? $zoneResponses : null);
        $attemptAnswer->setMatchingResponses($question->getType()->usesMatchingConfig() ? $matchingResponses : null);
        $attemptAnswer->setNumericResponse($numericRaw, $numericParsed['value'], $numericParsed['unit'], $numericVariables);
        $attemptAnswer->setIsCorrect($grader->isCorrect($question, $validSubmittedIds, $blankResponses, $zoneResponses, $matchingResponses, $numericParsed['value'], $numericParsed['unit'], $numericVariables));
        $attemptAnswer->setScore($grader->score($question, $validSubmittedIds, $blankResponses, $zoneResponses, $matchingResponses, $numericParsed['value'], $numericParsed['unit'], $numericVariables));
        $attemptAnswer->setAnsweredAt(new \DateTimeImmutable());

        $isLast = $position + 1 >= \count($attemptAnswers);
        if ($isLast) {
            $this->concluder->conclude($attempt, AttemptStatus::Termine);
        }

        $entityManager->flush();

        return $this->json(['concluded' => $isLast, 'nextPosition' => $isLast ? null : $position + 1, 'attemptId' => $attempt->getId()]);
    }

    /**
     * The end screen: the score if the teacher publishes it, plus the full per-question correction
     * in entraînement (the only mode that shows it - see ProgramQuizAttemptController::correction()).
     */
    #[Route(path: '/api/quiz/attempt/{attemptId}/result', name: 'api_quiz_result', requirements: ['attemptId' => '\d+'], methods: ['GET'])]
    public function result(int $attemptId, ProgramRepository $programRepository, QuizAttemptRepository $attemptRepository, QuizAttemptGrader $grader): JsonResponse
    {
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $programRepository, $attemptId);

        if (!$attempt->isConcluded()) {
            throw $this->createNotFoundException();
        }

        $instance = $attempt->getQuizInstance();
        $isPractice = QuizMode::Entrainement === $instance->getMode();
        $scoreVisible = $isPractice || $instance->isScoreVisibleImmediately();

        $correction = [];
        if ($isPractice) {
            foreach ($attempt->getAttemptAnswers() as $attemptAnswer) {
                $question = $attemptAnswer->getInstanceQuestion();
                $selectedIds = array_map(
                    static fn (QuizAttemptSelectedAnswer $selected): int => $selected->getInstanceAnswer()->getId(),
                    $attemptAnswer->getSelectedAnswers()->toArray(),
                );

                $isZones = $question->getType()->usesZoneConfig();
                // The feedback of every wrongly clicked zone - correction time is when the
                // config's per-zone texts are finally allowed out.
                $zoneFeedback = [];
                if (QuestionType::Zone === $question->getType()) {
                    foreach ($attemptAnswer->getZoneResponses() as $zoneId) {
                        $text = $question->getZoneFeedbackFor($zoneId);
                        if (null !== $text) {
                            $zoneFeedback[$zoneId] = $text;
                        }
                    }
                }

                // Same idea one type over: the feedback of every pair the student got wrong.
                $isMatching = QuestionType::Apparier === $question->getType();
                $isNumericQuestion = $question->getType()->usesNumericConfig();
                $matchingFeedback = [];
                if ($isMatching) {
                    foreach ($grader->matchingResults($question, $attemptAnswer->getMatchingResponses()) as $pairId => $isRight) {
                        $text = $isRight ? null : $question->getMatchingFeedbackFor($pairId);
                        if (null !== $text) {
                            $matchingFeedback[$pairId] = $text;
                        }
                    }
                }

                $correction[] = [
                    'label' => $question->getLabel(),
                    'type' => $question->getType()->value,
                    'isCorrect' => $attemptAnswer->getIsCorrect(),
                    'explanation' => $question->getExplanation(),
                    'blankResponses' => $question->getType()->usesBlankAnswers() ? $attemptAnswer->getBlankResponses() : null,
                    'blankResults' => $grader->blankResults($question, $attemptAnswer->getBlankResponses()),
                    'blankExpected' => $question->getType()->usesBlankAnswers() ? $question->getBlankAnswers() : null,
                    'zoneKind' => $isZones ? $question->getZoneKind()->value : null,
                    'zoneLines' => $isZones ? $question->getZoneLines() : null,
                    'imageZones' => $isZones ? $question->getImageZones() : null,
                    'imageUrl' => $isZones && null !== $question->getImageStorageKey() ? $this->fileUploadService->url($question->getImageStorageKey()) : null,
                    'zoneResponses' => $isZones ? $attemptAnswer->getZoneResponses() : null,
                    'zoneCorrectIds' => QuestionType::Zone === $question->getType() ? $question->getZoneCorrectIds() : null,
                    'zoneResults' => $grader->zoneResults($question, $attemptAnswer->getZoneResponses()),
                    'zoneLabels' => QuestionType::Legende === $question->getType() ? $question->getZoneLabelTexts() : null,
                    'zoneChoices' => QuestionType::Legende === $question->getType() ? $question->getLegendeChoices() : null,
                    'zoneFeedback' => $zoneFeedback,
                    // Apparier: the pairs *with* their right-hand side, which is the correction -
                    // during the attempt the app only ever received the left column and a shuffled
                    // pool of choices.
                    'matchingHeaders' => $isMatching ? $question->getMatchingHeaders() : null,
                    'matchingLeftKind' => $isMatching ? $question->getMatchingLeftKind()->value : null,
                    'matchingRightKind' => $isMatching ? $question->getMatchingRightKind()->value : null,
                    'matchingPairs' => $isMatching ? array_map($this->questionPayloadBuilder->matchingPair(...), $question->getMatchingPairs()) : null,
                    'matchingChoices' => $isMatching ? array_map($this->questionPayloadBuilder->matchingChoice(...), $question->getMatchingChoices()) : null,
                    'matchingResponses' => $isMatching ? $attemptAnswer->getMatchingResponses() : null,
                    'matchingResults' => $grader->matchingResults($question, $attemptAnswer->getMatchingResponses()),
                    'matchingFeedback' => $matchingFeedback,
                    // Numérique / Calculée at correction time: the statement as this student read
                    // it, what they typed, and what was expected - the three things a correction
                    // has to line up.
                    'numericStatement' => $isNumericQuestion ? NumericVariableParser::render((string) $question->getLabel(), $this->questionPayloadBuilder->formattedVariables($question, $attemptAnswer->getNumericVariables())) : null,
                    'numericRaw' => $isNumericQuestion ? $attemptAnswer->getNumericRaw() : null,
                    'numericExpected' => $isNumericQuestion ? $grader->expectedNumericValue($question, $attemptAnswer->getNumericVariables()) : null,
                    'numericMargin' => $isNumericQuestion ? $grader->numericMargin($question, $attemptAnswer->getNumericVariables()) : null,
                    'numericUnit' => $isNumericQuestion ? $question->getNumericUnit() : null,
                    'numericDecimals' => $isNumericQuestion ? $question->getNumericDecimals() : null,
                    'answers' => array_values(array_map(
                        static fn (QuizInstanceAnswer $answer): array => [
                            'label' => $answer->getLabel(),
                            'correct' => $answer->isCorrect(),
                            'selected' => \in_array($answer->getId(), $selectedIds, true),
                        ],
                        $question->getAnswers()->toArray(),
                    )),
                ];
            }
        }

        return $this->json([
            'quizName' => $instance->getName(),
            'mode' => $instance->getMode()->value,
            'status' => $attempt->getStatus()?->value,
            'scoreVisible' => $scoreVisible,
            'score' => $scoreVisible ? $attempt->getCorrectCountLabel() : null,
            'questionTotal' => $attempt->getQuestionTotal(),
            'scorePercent' => $scoreVisible ? $attempt->getScorePercent() : null,
            'scoreOn20' => $scoreVisible && 'note20' === $instance->getScoring()->value ? $attempt->getScoreOn20() : null,
            'correction' => $correction,
        ]);
    }

    /**
     * The shared description of one question (App\Service\QuizQuestionPayload), handed the ordering
     * this attempt drew - seeded per attempt, so a student who reloads sees the same question.
     *
     * @return array<string, mixed>
     */
    private function questionPayload(QuizInstanceQuestion $question, QuizAttempt $attempt, QuizDrawService $drawService): array
    {
        return $this->questionPayloadBuilder->build(
            $question,
            $drawService->orderAnswers($question, $attempt),
            QuestionType::TexteATrous === $question->getType() ? $drawService->orderWordBank($question, $attempt) : [],
            QuestionType::Legende === $question->getType() ? $drawService->orderZoneChoices($question, $attempt) : [],
            QuestionType::Apparier === $question->getType() ? $drawService->orderMatchingPairs($question, $attempt) : [],
            QuestionType::Apparier === $question->getType() ? $drawService->orderMatchingChoices($question, $attempt) : [],
            $question->getType()->usesFormula() ? $drawService->drawNumericVariables($question, $attempt) : [],
            withHints: QuizMode::Entrainement === $attempt->getQuizInstance()->getMode(),
        );
    }

    // Same lazy close-out as the web flow - see QuizAttempt::isPastTimeLimit().
    private function concludeIfExpired(QuizAttempt $attempt, EntityManagerInterface $entityManager): bool
    {
        if ($attempt->isConcluded() || !$attempt->isPastTimeLimit()) {
            return false;
        }

        $this->concluder->conclude($attempt, AttemptStatus::Interrompu);
        $entityManager->flush();

        return true;
    }

    private function findInstanceOrNotFound(QuizInstanceRepository $instanceRepository, ProgramRepository $programRepository, int $instanceId): QuizInstance
    {
        $instance = $instanceRepository->find($instanceId) ?? throw $this->createNotFoundException();
        $this->denyUnlessStudentOf($instance->getProgram(), $programRepository);

        // Same rule as the web passation (App\Controller\ProgramQuizAttemptController): a quiz the
        // teacher deactivated is gone for the student, whatever id the app still has cached.
        if (!$instance->isActive()) {
            throw $this->createNotFoundException();
        }

        return $instance;
    }

    private function findOwnAttemptOrNotFound(QuizAttemptRepository $attemptRepository, ProgramRepository $programRepository, int $attemptId): QuizAttempt
    {
        $attempt = $attemptRepository->find($attemptId) ?? throw $this->createNotFoundException();

        // Ownership first: another student's attempt id must read as "not found", never as a 403
        // that would confirm it exists.
        if ($attempt->getStudent() !== $this->currentUser()) {
            throw $this->createNotFoundException();
        }
        $this->denyUnlessStudentOf($attempt->getQuizInstance()->getProgram(), $programRepository);

        // The app reaches question/answer/result by attempt id alone, never through the instance
        // route above - so the deactivation check has to be repeated here, or an app still holding
        // an attempt id would keep playing a quiz its class can no longer see.
        if (!$attempt->getQuizInstance()->isActive()) {
            throw $this->createNotFoundException();
        }

        return $attempt;
    }

    private function denyUnlessStudentOf(Program $program, ProgramRepository $programRepository): void
    {
        if (!$program->getStudents()->contains($this->currentUser())) {
            throw $this->createNotFoundException();
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
