<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptSelectedAnswer;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceAnswer;
use App\Entity\User;
use App\Enum\AttemptStatus;
use App\Enum\Feature;
use App\Enum\QuestionType;
use App\Enum\QuizMode;
use App\Repository\ProgramRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\QuizInstanceRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Service\PostValue;
use App\Service\QuizAttemptConcluder;
use App\Service\QuizAttemptGrader;
use App\Service\QuizAttemptNotAllowedException;
use App\Service\QuizAttemptSessionLock;
use App\Service\QuizAttemptStarter;
use App\Service\QuizDrawService;
use App\Service\QuizQuestionBudget;
use App\Service\QuizSupervisionNotice;
use App\Service\StudentQuizBoard;
use App\Util\NumericAnswerParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A student's own quiz-taking flow - screens 1d (class "Quiz" hub)/1e (passation)/1m (correction,
 * entraînement only). Route-level ROLE_STUDENT guards (not a class-level gate), same shape as
 * ProgramAssignmentSubmissionController: whether a given QuizInstance is actually reachable is
 * decided by Program membership, not a per-instance audience (a quiz's audience is always its
 * whole launch Program - see App\Entity\QuizInstance's class docblock).
 */
#[RequiresFeature(Feature::QuizTake)]
class ProgramQuizAttemptController extends AbstractController
{
    #[Route(path: '/programs/{id}/quiz/mine', name: 'app_program_quiz_mine')]
    #[IsGranted('ROLE_STUDENT')]
    public function myQuizzes(int $id, ProgramRepository $repository, StudentQuizBoard $quizBoard, QuizAttemptRepository $attemptRepository, QuizLiveSessionRepository $liveSessionRepository): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $student = $this->currentUser();
        $activeLiveSession = $liveSessionRepository->findActiveForProgram($program);

        // The access conditions a teacher set on a quiz, applied at last: an « Invisible » one is
        // already gone from this list, a « Grisé » one is still here and carries its reasons.
        $readable = $quizBoard->readableFor($program, $student);

        $evaluations = [];
        $trainings = [];
        foreach ($readable->instances as $instance) {
            $lastConcluded = $attemptRepository->findLastConcluded($instance, $student);
            $inProgress = $attemptRepository->findInProgress($instance, $student);

            $lockedBy = $readable->verdicts->isOpen($instance) ? [] : $readable->verdicts->reasonsFor($instance);

            if (QuizMode::Evaluation === $instance->getMode()) {
                $evaluations[] = ['instance' => $instance, 'attempt' => $lastConcluded, 'inProgress' => $inProgress, 'lockedBy' => $lockedBy];
            } else {
                $all = $attemptRepository->findForStudent($instance, $student);
                $concluded = array_values(array_filter($all, static fn (QuizAttempt $a): bool => $a->isConcluded()));
                $best = null;
                foreach ($concluded as $a) {
                    if (null === $best || ($a->getScorePercent() ?? -1) > ($best->getScorePercent() ?? -1)) {
                        $best = $a;
                    }
                }
                $trainings[] = ['instance' => $instance, 'attemptCount' => \count($concluded), 'best' => $best, 'last' => $lastConcluded, 'inProgress' => $inProgress, 'lockedBy' => $lockedBy];
            }
        }

        return $this->render('program/quiz_mine.html.twig', [
            'program' => $program,
            'evaluations' => $evaluations,
            'trainings' => $trainings,
            'activeLiveSession' => $activeLiveSession,
        ]);
    }

    // Resumes the in-progress attempt if there is one; otherwise starts a new one (unless
    // Évaluation already has a concluded attempt, since Phase 3 never grants retries - that's
    // App\Enum\AttemptOrigin::Relance, a later phase) and redirects to its first question.
    #[Route(path: '/programs/{id}/quiz/{instanceId}/take', name: 'app_program_quiz_take', requirements: ['instanceId' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function take(int $id, int $instanceId, Request $request, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, StudentQuizBoard $quizBoard, QuizAttemptStarter $attemptStarter, QuizAttemptSessionLock $sessionLock): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);

        // Asked again at the door: a greyed row names its quiz, so this address is one click away
        // from being typed by hand.
        if (!$quizBoard->isOpenFor($instance, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        // The entry contract of a supervised évaluation - what is recorded, who reads it, for how
        // long - shown before anything exists. « Rien n'est enregistré avant que vous ne cliquiez
        // sur Commencer » is only true if the attempt itself is not created yet, which is why this
        // stands in front of QuizAttemptStarter rather than after it.
        //
        // Only on the way in: an attempt already open is resumed straight away. The sentence above
        // would be false on a resumption, and a student whose tab crashed mid-exam has better
        // things to read than a contract they already accepted.
        if ($instance->isSupervised() && null === $attemptRepository->findInProgress($instance, $this->currentUser()) && null === $attemptRepository->findLastConcluded($instance, $this->currentUser())) {
            return $this->render('program/quiz_contract.html.twig', [
                'program' => $program,
                'quizInstance' => $instance,
            ]);
        }

        try {
            $started = $attemptStarter->startOrResume($instance, $this->currentUser());
        } catch (QuizAttemptNotAllowedException) {
            throw $this->createAccessDeniedException();
        }

        // Évaluation already handed in: there is nothing to take, only a result to look at.
        if ($started['concluded']) {
            return $this->redirectToRoute('app_program_quiz_result', ['id' => $program->getId(), 'instanceId' => $instance->getId(), 'attemptId' => $started['attempt']->getId()]);
        }

        // Coming back to a supervised attempt takes it over: this is the door a student whose tab
        // crashed comes back through, and it must give them the hand rather than ask them to prove
        // anything.
        if ($instance->isSupervised()) {
            $sessionLock->claim($started['attempt'], $request->getSession());
        }

        return $this->redirectToQuestion($program, $instance, $started['attempt'], 0);
    }

    /**
     * "Commencer" on the entry contract of a supervised évaluation. A POST of its own rather than a
     * link back to take(): accepting the contract is what creates the attempt, and that is not
     * something a URL pasted into a browser should do.
     */
    #[Route(path: '/programs/{id}/quiz/{instanceId}/start', name: 'app_program_quiz_start', requirements: ['instanceId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function start(int $id, int $instanceId, Request $request, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, StudentQuizBoard $quizBoard, QuizAttemptStarter $attemptStarter, QuizAttemptSessionLock $sessionLock): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);

        if (!$this->isCsrfTokenValid('quiz_supervised_start', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$quizBoard->isOpenFor($instance, $this->currentUser())) {
            throw $this->createAccessDeniedException();
        }

        try {
            $started = $attemptStarter->startOrResume($instance, $this->currentUser());
        } catch (QuizAttemptNotAllowedException) {
            throw $this->createAccessDeniedException();
        }

        if ($started['concluded']) {
            return $this->redirectToRoute('app_program_quiz_result', ['id' => $program->getId(), 'instanceId' => $instance->getId(), 'attemptId' => $started['attempt']->getId()]);
        }

        // This browser session becomes the owner - and whoever held it is turned away from here on.
        $sessionLock->claim($started['attempt'], $request->getSession());

        return $this->redirectToQuestion($program, $instance, $started['attempt'], 0);
    }

    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/question/{position}', name: 'app_program_quiz_question', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+', 'position' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function question(int $id, int $instanceId, int $attemptId, int $position, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizDrawService $drawService, QuizAttemptConcluder $concluder, QuizAttemptSessionLock $sessionLock, QuizSupervisionNotice $supervisionNotice): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        if ($this->concludeIfExpired($attempt, $entityManager, $concluder)) {
            return $this->redirectToOutcome($program, $instance, $attempt);
        }
        if ($attempt->isConcluded()) {
            return $this->redirectToOutcome($program, $instance, $attempt);
        }

        $attemptAnswers = $attempt->getAttemptAnswers()->toArray();
        if (!isset($attemptAnswers[$position])) {
            throw $this->createNotFoundException();
        }
        $attemptAnswer = $attemptAnswers[$position];
        $question = $attemptAnswer->getInstanceQuestion();

        // The tab that lost the hand stops here. Nothing punitive: it is told the attempt was
        // resumed elsewhere and offered the way to take it back - the point of the lock is that two
        // simultaneous openings are useless, not that anybody is shut out.
        if ($instance->isSupervised() && !$sessionLock->holds($attempt, $request->getSession())) {
            return $this->render('program/quiz_taken_over.html.twig', [
                'program' => $program,
                'quizInstance' => $instance,
            ]);
        }

        // The copy the teacher asked to be handed in past N exits. Asked here as well as at the
        // beacon: a beacon may be the last thing a tab ever sends, and the rule must not wait for
        // one more.
        if ($supervisionNotice->autoSubmitIfDue($attempt)) {
            return $this->redirectToOutcome($program, $instance, $attempt);
        }

        // The server's own half of the stopwatch: the first display is stamped, every display is
        // counted (App\Entity\QuizAttemptAnswer::markServed()). Reloading this page therefore
        // never hands out a fresh budget - it only raises display_count, which is itself a signal.
        $attemptAnswer->markServed(new \DateTimeImmutable());
        $entityManager->flush();

        // What the student is told, right now: how many times they have left, and for how long.
        // Facts, never an accusation - see App\Service\QuizSupervisionNotice.
        $countedAbsences = $instance->isSupervised() ? $supervisionNotice->countedAbsences($attempt) : [];

        $questionSeconds = $question->resolveSeconds($instance->getSecondsPerQuestion());

        return $this->render('program/quiz_question.html.twig', [
            'program' => $program,
            'quizInstance' => $instance,
            'attempt' => $attempt,
            'attemptAnswer' => $attemptAnswer,
            'question' => $question,
            'answers' => $drawService->orderAnswers($question, $attempt),
            'wordBank' => QuestionType::TexteATrous === $question->getType() ? $drawService->orderWordBank($question, $attempt) : [],
            'zoneChoices' => QuestionType::Legende === $question->getType() ? $drawService->orderZoneChoices($question, $attempt) : [],
            'matchingPairs' => QuestionType::Apparier === $question->getType() ? $drawService->orderMatchingPairs($question, $attempt) : [],
            // The values this student's statement is asked with. Recomputed here rather than read
            // back off the attempt answer: the question screen is what *poses* the question, and it
            // must show the same numbers whether or not it has been answered once already.
            'numericVariables' => $question->getType()->usesFormula() ? $drawService->drawNumericVariables($question, $attempt) : [],
            'matchingChoices' => QuestionType::Apparier === $question->getType() ? $drawService->orderMatchingChoices($question, $attempt) : [],
            // The hint only exists in entraînement: an évaluation shows no "Indice" button, and
            // the ids it would reveal are not even rendered into the page.
            'hintIds' => QuestionType::Zone === $question->getType() && QuizMode::Entrainement === $instance->getMode()
                ? $question->getZoneHintIds()
                : [],
            'position' => $position,
            'total' => \count($attemptAnswers),
            'questionSeconds' => $questionSeconds,
            // The key the page's beacons authenticate with - null on anything unsupervised, where
            // quiz_supervision_controller.js is not mounted at all.
            'supervisionKey' => $instance->isSupervised() ? $sessionLock->keyFor($attempt, $request->getSession()) : null,
            'supervisionAbsences' => $countedAbsences,
            'supervisionWarns' => $supervisionNotice->shouldWarn($attempt, $countedAbsences),
            // What is left of the budget from the *first* display, not the whole of it: the chip a
            // reloaded page shows must say the same thing the server would answer.
            'remainingSeconds' => QuizQuestionBudget::remainingSeconds($attemptAnswer->getServedAt(), $questionSeconds, new \DateTimeImmutable()),
        ]);
    }

    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/question/{position}/answer', name: 'app_program_quiz_answer', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+', 'position' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function answer(int $id, int $instanceId, int $attemptId, int $position, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizAttemptGrader $grader, QuizAttemptConcluder $concluder, QuizDrawService $drawService, QuizAttemptSessionLock $sessionLock): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        if (!$this->isCsrfTokenValid('quiz_attempt_answer', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($this->concludeIfExpired($attempt, $entityManager, $concluder) || $attempt->isConcluded()) {
            return $this->redirectToOutcome($program, $instance, $attempt);
        }

        // An answer from the tab that lost the hand is not recorded: it is the whole point of the
        // lock that the second opening becomes useless the moment the first one answers.
        if ($instance->isSupervised() && !$sessionLock->holds($attempt, $request->getSession())) {
            return $this->render('program/quiz_taken_over.html.twig', [
                'program' => $program,
                'quizInstance' => $instance,
            ]);
        }

        $attemptAnswers = $attempt->getAttemptAnswers()->toArray();
        if (!isset($attemptAnswers[$position])) {
            throw $this->createNotFoundException();
        }
        $attemptAnswer = $attemptAnswers[$position];
        $question = $attemptAnswer->getInstanceQuestion();

        // The per-question budget, applied where the global one already is. Nothing is recorded and
        // the student moves on: the question stays as it was, which for an unanswered one means
        // empty. Refusing without advancing would leave them stuck on a question they can no longer
        // answer.
        $now = new \DateTimeImmutable();
        if (QuizQuestionBudget::isLate($attemptAnswer->getServedAt(), $question->resolveSeconds($instance->getSecondsPerQuestion()), $now)) {
            $this->addFlash('warning', 'programQuizAnswerTooLateFlashMessage');

            return $this->afterQuestion($program, $instance, $attempt, $position, \count($attemptAnswers));
        }

        // Texte à trous submits one "blanks[n]" field per blank instead of answer ids - both modes
        // (word bank and free input) post the same shape, so grading never has to know which one
        // the student used. Trimmed to the question's real blank count: a client that posts extra
        // entries must not widen the stored array (App\Entity\QuizAttemptAnswer::$blankResponses).
        $blankResponses = [];
        if ($question->getType()->usesBlankAnswers()) {
            $submittedBlanks = PostValue::all($request, 'blanks');
            for ($i = 0, $blankCount = $question->getBlankCount(); $i < $blankCount; ++$i) {
                $raw = $submittedBlanks[$i] ?? null;
                $blankResponses[] = \is_scalar($raw) ? trim((string) $raw) : '';
            }
        }

        // Zone submits zones[] (clicked ids), Légende submits placements[zoneId] (choice key per
        // zone). Both are bounded to the question's own config on the way in - never trust the
        // client with ids the support doesn't have.
        $zoneResponses = [];
        if (QuestionType::Zone === $question->getType()) {
            $submitted = array_map(strval(...), array_filter(PostValue::all($request, 'zones'), is_scalar(...)));
            $zoneResponses = array_values(array_unique(array_intersect($submitted, $question->getZoneIds())));
        } elseif (QuestionType::Legende === $question->getType()) {
            $choiceKeys = array_column($question->getLegendeChoices(), 'key');
            $zoneIds = $question->getZoneIds();
            foreach (PostValue::all($request, 'placements') as $zoneId => $key) {
                if (\is_scalar($key) && \in_array((string) $zoneId, $zoneIds, true) && \in_array((string) $key, $choiceKeys, true)) {
                    $zoneResponses[(string) $zoneId] = (string) $key;
                }
            }
        }

        // Apparier submits pairs[pairId] = choice key, the same shape as a légende's placements one
        // type over, and bounded the same way to the question's own pairs and choices.
        $matchingResponses = [];
        if (QuestionType::Apparier === $question->getType()) {
            $choiceKeys = array_column($question->getMatchingChoices(), 'key');
            $pairIds = $question->getMatchingPairIds();
            foreach (PostValue::all($request, 'pairs') as $pairId => $key) {
                if (\is_scalar($key) && \in_array((string) $pairId, $pairIds, true) && \in_array((string) $key, $choiceKeys, true)) {
                    $matchingResponses[(string) $pairId] = (string) $key;
                }
            }
        }

        // Numérique / Calculée submit one free-text "numeric" field: what the student typed is kept
        // verbatim alongside the number read out of it, and a calculée also stores the values it
        // asked them about - see App\Entity\QuizAttemptAnswer::$numericResponse.
        $numericRaw = null;
        $numericParsed = ['value' => null, 'unit' => null];
        $numericVariables = [];
        if ($question->getType()->usesNumericConfig()) {
            $raw = $request->request->get('numeric');
            $numericRaw = \is_scalar($raw) ? trim((string) $raw) : '';
            $numericParsed = NumericAnswerParser::parse($numericRaw);
            $numericVariables = $question->getType()->usesFormula()
                ? $drawService->drawNumericVariables($question, $attempt)
                : [];
        }

        $submittedIds = array_map(intval(...), PostValue::all($request, 'answers'));
        $answersById = [];
        foreach ($question->getAnswers() as $instanceAnswer) {
            $answersById[$instanceAnswer->getId()] = $instanceAnswer;
        }

        foreach ($attemptAnswer->getSelectedAnswers()->toArray() as $existing) {
            $attemptAnswer->removeSelectedAnswer($existing);
        }
        $orderIndex = 0;
        foreach ($submittedIds as $answerId) {
            $instanceAnswer = $answersById[$answerId] ?? null;
            if (!$instanceAnswer instanceof QuizInstanceAnswer) {
                continue; // ignore any id not actually belonging to this question - never trust the client
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
        $attemptAnswer->setAnsweredAt($now);
        // Two instants the application wrote itself - never a duration the browser declared.
        $attemptAnswer->freezeElapsed($now);

        $entityManager->flush();

        return $this->afterQuestion($program, $instance, $attempt, $position, \count($attemptAnswers));
    }

    // Where a question hands over once it is done with, answered or refused: the next one, or the
    // hand-in.
    private function afterQuestion(Program $program, QuizInstance $instance, QuizAttempt $attempt, int $position, int $total): Response
    {
        $nextPosition = $position + 1;
        if ($nextPosition < $total) {
            return $this->redirectToQuestion($program, $instance, $attempt, $nextPosition);
        }

        return $this->redirectToRoute('app_program_quiz_finish', ['id' => $program->getId(), 'instanceId' => $instance->getId(), 'attemptId' => $attempt->getId()]);
    }

    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/finish', name: 'app_program_quiz_finish', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function finish(int $id, int $instanceId, int $attemptId, EntityManagerInterface $entityManager, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizAttemptConcluder $concluder): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        if (!$attempt->isConcluded()) {
            $concluder->conclude($attempt, AttemptStatus::Termine);
            $entityManager->flush();
        }

        return $this->redirectToOutcome($program, $instance, $attempt);
    }

    // 1m - the entraînement's own correction screen. Évaluation has one too since « vue de la
    // correction à la fin », but it is served inside the result screen rather than here: « Voir la
    // copie » is the one gesture, and it must not lead to a page carrying only a percentage.
    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/correction', name: 'app_program_quiz_correction', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function correction(int $id, int $instanceId, int $attemptId, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        if (QuizMode::Entrainement !== $instance->getMode()) {
            throw $this->createAccessDeniedException();
        }
        // Asked at the door as well as at the link, for the reason StudentQuizBoard gives about its
        // own: a screen whose address is one redirect away is one a student can type back in.
        if (!$instance->isCorrectionReadable()) {
            throw $this->createAccessDeniedException();
        }
        if (!$attempt->isConcluded()) {
            throw $this->createNotFoundException();
        }

        return $this->render('program/quiz_correction.html.twig', [
            'program' => $program,
            'quizInstance' => $instance,
            'attempt' => $attempt,
        ]);
    }

    // Évaluation's post-submission screen: the score only if $scoreVisibleImmediately, otherwise
    // just "copie remise" - see design/design_campus_manager/README.md.
    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/result', name: 'app_program_quiz_result', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function result(int $id, int $instanceId, int $attemptId, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizSupervisionNotice $supervisionNotice): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        if (!$attempt->isConcluded()) {
            throw $this->createNotFoundException();
        }

        return $this->render('program/quiz_result.html.twig', [
            'program' => $program,
            'quizInstance' => $instance,
            'attempt' => $attempt,
            // A copy handed in by the rule says so rather than appearing to have been handed in by
            // its author: the student was warned it would happen, and is owed the sentence.
            'autoSubmitted' => $supervisionNotice->wasAutoSubmitted($attempt),
        ]);
    }

    private function redirectToQuestion(Program $program, QuizInstance $instance, QuizAttempt $attempt, int $position): Response
    {
        return $this->redirectToRoute('app_program_quiz_question', [
            'id' => $program->getId(),
            'instanceId' => $instance->getId(),
            'attemptId' => $attempt->getId(),
            'position' => $position,
        ]);
    }

    private function redirectToOutcome(Program $program, QuizInstance $instance, QuizAttempt $attempt): Response
    {
        // The result screen is where a correction that is not to be read lands: it says the copy was
        // handed in, and carries the breakdown itself when the quiz does allow it.
        $route = QuizMode::Entrainement === $instance->getMode() && $instance->isCorrectionReadable()
            ? 'app_program_quiz_correction'
            : 'app_program_quiz_result';

        return $this->redirectToRoute($route, ['id' => $program->getId(), 'instanceId' => $instance->getId(), 'attemptId' => $attempt->getId()]);
    }

    // Returns true (and persists the conclusion) if $attempt just got lazily closed for running
    // past its time budget - see QuizAttempt::isPastTimeLimit()'s docblock.
    private function concludeIfExpired(QuizAttempt $attempt, EntityManagerInterface $entityManager, QuizAttemptConcluder $concluder): bool
    {
        if (!$attempt->isPastTimeLimit()) {
            return false;
        }

        $concluder->conclude($attempt, AttemptStatus::Interrompu);
        $entityManager->flush();

        return true;
    }

    private function findProgramForStudentOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $program;
    }

    private function findInstanceOrNotFound(QuizInstanceRepository $repository, Program $program, int $instanceId): QuizInstance
    {
        $instance = $repository->find($instanceId) ?? throw $this->createNotFoundException();

        if ($instance->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        // A deactivated quiz reads as gone for the student, on every route of this controller at
        // once - including the correction and the result of an attempt they had already handed in.
        // "Invisible et inaccessible" is the rule; only the teacher keeps looking at it.
        if (!$instance->isActive()) {
            throw $this->createNotFoundException();
        }

        return $instance;
    }

    private function findOwnAttemptOrNotFound(QuizAttemptRepository $repository, QuizInstance $instance, int $attemptId): QuizAttempt
    {
        $attempt = $repository->find($attemptId) ?? throw $this->createNotFoundException();

        if ($attempt->getQuizInstance()->getId() !== $instance->getId() || $attempt->getStudent() !== $this->currentUser()) {
            throw $this->createNotFoundException();
        }

        return $attempt;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
