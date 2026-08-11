<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptSelectedAnswer;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceAnswer;
use App\Entity\User;
use App\Enum\AttemptStatus;
use App\Enum\QuestionType;
use App\Enum\QuizMode;
use App\Repository\ProgramRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\QuizInstanceRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Service\QuizAttemptConcluder;
use App\Service\QuizAttemptGrader;
use App\Service\QuizAttemptNotAllowedException;
use App\Service\QuizAttemptStarter;
use App\Service\QuizDrawService;
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
class ProgramQuizAttemptController extends AbstractController
{
    #[Route(path: '/programs/{id}/quiz/mine', name: 'app_program_quiz_mine')]
    #[IsGranted('ROLE_STUDENT')]
    public function myQuizzes(int $id, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizLiveSessionRepository $liveSessionRepository): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $student = $this->currentUser();
        $activeLiveSession = $liveSessionRepository->findActiveForProgram($program);

        $evaluations = [];
        $trainings = [];
        foreach ($instanceRepository->findForProgram($program) as $instance) {
            $lastConcluded = $attemptRepository->findLastConcluded($instance, $student);
            $inProgress = $attemptRepository->findInProgress($instance, $student);

            if (QuizMode::Evaluation === $instance->getMode()) {
                $evaluations[] = ['instance' => $instance, 'attempt' => $lastConcluded, 'inProgress' => $inProgress];
            } else {
                $all = $attemptRepository->findForStudent($instance, $student);
                $concluded = array_values(array_filter($all, static fn (QuizAttempt $a): bool => $a->isConcluded()));
                $best = null;
                foreach ($concluded as $a) {
                    if (null === $best || ($a->getScorePercent() ?? -1) > ($best->getScorePercent() ?? -1)) {
                        $best = $a;
                    }
                }
                $trainings[] = ['instance' => $instance, 'attemptCount' => \count($concluded), 'best' => $best, 'last' => $lastConcluded, 'inProgress' => $inProgress];
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
    public function take(int $id, int $instanceId, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptStarter $attemptStarter): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);

        try {
            $started = $attemptStarter->startOrResume($instance, $this->currentUser());
        } catch (QuizAttemptNotAllowedException) {
            throw $this->createAccessDeniedException();
        }

        // Évaluation already handed in: there is nothing to take, only a result to look at.
        if ($started['concluded']) {
            return $this->redirectToRoute('app_program_quiz_result', ['id' => $program->getId(), 'instanceId' => $instance->getId(), 'attemptId' => $started['attempt']->getId()]);
        }

        return $this->redirectToQuestion($program, $instance, $started['attempt'], 0);
    }

    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/question/{position}', name: 'app_program_quiz_question', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+', 'position' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function question(int $id, int $instanceId, int $attemptId, int $position, EntityManagerInterface $entityManager, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizDrawService $drawService, QuizAttemptConcluder $concluder): Response
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

        return $this->render('program/quiz_question.html.twig', [
            'program' => $program,
            'quizInstance' => $instance,
            'attempt' => $attempt,
            'attemptAnswer' => $attemptAnswer,
            'question' => $question,
            'answers' => $drawService->orderAnswers($question, $attempt),
            'wordBank' => QuestionType::TexteATrous === $question->getType() ? $drawService->orderWordBank($question, $attempt) : [],
            'zoneChoices' => QuestionType::Legende === $question->getType() ? $drawService->orderZoneChoices($question, $attempt) : [],
            // The hint only exists in entraînement: an évaluation shows no "Indice" button, and
            // the ids it would reveal are not even rendered into the page.
            'hintIds' => QuestionType::Zone === $question->getType() && QuizMode::Entrainement === $instance->getMode()
                ? $question->getZoneHintIds()
                : [],
            'position' => $position,
            'total' => \count($attemptAnswers),
        ]);
    }

    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/question/{position}/answer', name: 'app_program_quiz_answer', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+', 'position' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function answer(int $id, int $instanceId, int $attemptId, int $position, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizAttemptGrader $grader, QuizAttemptConcluder $concluder): Response
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

        $attemptAnswers = $attempt->getAttemptAnswers()->toArray();
        if (!isset($attemptAnswers[$position])) {
            throw $this->createNotFoundException();
        }
        $attemptAnswer = $attemptAnswers[$position];
        $question = $attemptAnswer->getInstanceQuestion();

        // Texte à trous submits one "blanks[n]" field per blank instead of answer ids - both modes
        // (word bank and free input) post the same shape, so grading never has to know which one
        // the student used. Trimmed to the question's real blank count: a client that posts extra
        // entries must not widen the stored array (App\Entity\QuizAttemptAnswer::$blankResponses).
        $blankResponses = [];
        if (QuestionType::TexteATrous === $question->getType()) {
            $submittedBlanks = $request->request->all('blanks');
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
            $submitted = array_map(strval(...), array_filter($request->request->all('zones'), is_scalar(...)));
            $zoneResponses = array_values(array_unique(array_intersect($submitted, $question->getZoneIds())));
        } elseif (QuestionType::Legende === $question->getType()) {
            $choiceKeys = array_column($question->getLegendeChoices(), 'key');
            $zoneIds = $question->getZoneIds();
            foreach ($request->request->all('placements') as $zoneId => $key) {
                if (\is_scalar($key) && \in_array((string) $zoneId, $zoneIds, true) && \in_array((string) $key, $choiceKeys, true)) {
                    $zoneResponses[(string) $zoneId] = (string) $key;
                }
            }
        }

        $submittedIds = array_map(intval(...), $request->request->all('answers'));
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
        $attemptAnswer->setIsCorrect($grader->isCorrect($question, $validSubmittedIds, $blankResponses, $zoneResponses));
        $attemptAnswer->setScore($grader->score($question, $validSubmittedIds, $blankResponses, $zoneResponses));
        $attemptAnswer->setAnsweredAt(new \DateTimeImmutable());

        $entityManager->flush();

        $nextPosition = $position + 1;
        if ($nextPosition < \count($attemptAnswers)) {
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

    // 1m - entraînement only (see design/design_campus_manager/README.md: "la correction est
    // disponible uniquement en mode entraînement").
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
    public function result(int $id, int $instanceId, int $attemptId, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository): Response
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
        $route = QuizMode::Entrainement === $instance->getMode() ? 'app_program_quiz_correction' : 'app_program_quiz_result';

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
