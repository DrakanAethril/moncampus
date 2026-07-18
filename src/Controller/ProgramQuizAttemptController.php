<?php

namespace App\Controller;

use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Entity\QuizAttemptSelectedAnswer;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceAnswer;
use App\Entity\User;
use App\Enum\AttemptStatus;
use App\Enum\QuizMode;
use App\Repository\ProgramRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\QuizInstanceRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Service\QuizAttemptGrader;
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
    public function take(int $id, int $instanceId, EntityManagerInterface $entityManager, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizDrawService $drawService): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $student = $this->currentUser();

        $inProgress = $attemptRepository->findInProgress($instance, $student);
        if (null !== $inProgress) {
            return $this->redirectToQuestion($program, $instance, $inProgress, 0);
        }

        if (QuizMode::Evaluation === $instance->getMode()) {
            $lastConcluded = $attemptRepository->findLastConcluded($instance, $student);
            if (null !== $lastConcluded) {
                return $this->redirectToRoute('app_program_quiz_result', ['id' => $program->getId(), 'instanceId' => $instance->getId(), 'attemptId' => $lastConcluded->getId()]);
            }
        }

        if (!$instance->isOpenNow()) {
            throw $this->createAccessDeniedException();
        }

        $priorCount = \count($attemptRepository->findForStudent($instance, $student));
        $attempt = new QuizAttempt($instance, $student);
        $attempt->setAttemptNumber($priorCount + 1);
        // Capped at a signed 32-bit INT (the column's SQL type) - plenty of entropy for a
        // non-cryptographic deterministic-shuffle seed (see QuizDrawService).
        $attempt->setShuffleSeed(random_int(1, 2_147_483_647));

        foreach ($drawService->drawQuestions($attempt) as $position => $question) {
            $attemptAnswer = new QuizAttemptAnswer($attempt, $question);
            $attemptAnswer->setOrderIndex($position);
            $attempt->addAttemptAnswer($attemptAnswer);
        }

        $entityManager->persist($attempt);
        $entityManager->flush();

        return $this->redirectToQuestion($program, $instance, $attempt, 0);
    }

    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/question/{position}', name: 'app_program_quiz_question', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+', 'position' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function question(int $id, int $instanceId, int $attemptId, int $position, EntityManagerInterface $entityManager, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizDrawService $drawService): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        if ($this->concludeIfExpired($attempt, $entityManager)) {
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
            'position' => $position,
            'total' => \count($attemptAnswers),
        ]);
    }

    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/question/{position}/answer', name: 'app_program_quiz_answer', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+', 'position' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function answer(int $id, int $instanceId, int $attemptId, int $position, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizAttemptGrader $grader): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        if (!$this->isCsrfTokenValid('quiz_attempt_answer', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($this->concludeIfExpired($attempt, $entityManager) || $attempt->isConcluded()) {
            return $this->redirectToOutcome($program, $instance, $attempt);
        }

        $attemptAnswers = $attempt->getAttemptAnswers()->toArray();
        if (!isset($attemptAnswers[$position])) {
            throw $this->createNotFoundException();
        }
        $attemptAnswer = $attemptAnswers[$position];
        $question = $attemptAnswer->getInstanceQuestion();

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
        $attemptAnswer->setIsCorrect($grader->isCorrect($question, $validSubmittedIds));
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
    public function finish(int $id, int $instanceId, int $attemptId, EntityManagerInterface $entityManager, ProgramRepository $repository, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findOwnAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        if (!$attempt->isConcluded()) {
            $this->concludeAttempt($attempt, AttemptStatus::Termine);
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
    private function concludeIfExpired(QuizAttempt $attempt, EntityManagerInterface $entityManager): bool
    {
        if (!$attempt->isPastTimeLimit()) {
            return false;
        }

        $this->concludeAttempt($attempt, AttemptStatus::Interrompu);
        $entityManager->flush();

        return true;
    }

    private function concludeAttempt(QuizAttempt $attempt, AttemptStatus $status): void
    {
        $answered = array_values(array_filter($attempt->getAttemptAnswers()->toArray(), static fn (QuizAttemptAnswer $a): bool => $a->isAnswered()));
        $correct = \count(array_filter($answered, static fn (QuizAttemptAnswer $a): bool => true === $a->getIsCorrect()));

        $attempt->setStatus($status);
        $attempt->setSubmittedAt(new \DateTimeImmutable());
        $attempt->setScore($correct, $attempt->getAttemptAnswers()->count());
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
