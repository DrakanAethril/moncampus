<?php

namespace App\Controller;

use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Enum\AttemptOrigin;
use App\Repository\ProgramRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\QuizInstanceRepository;
use App\Security\StructureAccessChecker;
use App\Service\QuizDrawService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Program-scoped browsing of launched QuizInstances, and their results - see App\Entity\QuizInstance's
// class docblock and screens 1f (par étudiant)/1g (par question)/1p (tentatives d'un étudiant).
// Gated by StructureAccessChecker::isProgramTeacher() (same as Outils > Tirage au sort), not
// ROLE_ADMIN-only like ProgramSequenceInstanceController: a quiz's launching teacher needs to see
// their own instances/results here too, not just staff.
//
// "Sessions live" (screen 1o) is deliberately not built - out of scope along with the rest of the
// concours-à-plusieurs feature (App\Enum\QuizMode::Live is unexposed everywhere else too).
class ProgramQuizController extends AbstractController
{
    #[Route(path: '/programs/{id}/quiz', name: 'app_program_quiz')]
    public function list(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizInstanceRepository $instanceRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/quiz_instances.html.twig', [
            'program' => $program,
            'quizInstances' => $instanceRepository->findForProgram($program),
        ]);
    }

    // Screens 1f/1g - one route, two tabs (?tab=student|question), each with its own "Trier par".
    #[Route(path: '/programs/{id}/quiz/{instanceId}', name: 'app_program_quiz_show', requirements: ['instanceId' => '\d+'])]
    public function show(int $id, int $instanceId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);

        $tab = 'question' === $request->query->get('tab') ? 'question' : 'student';
        $concludedAttempts = $attemptRepository->findConcludedForInstance($instance);

        $studentRows = $this->buildStudentRows($program, $instance, $concludedAttempts, $attemptRepository);
        $questionRows = $this->buildQuestionRows($instance, $concludedAttempts);

        $sort = (string) $request->query->get('sort', 'student' === $tab ? 'name' : 'rate_asc');
        if ('student' === $tab) {
            $studentRows = $this->sortStudentRows($studentRows, $sort);
        } else {
            $questionRows = $this->sortQuestionRows($questionRows, $sort);
        }

        return $this->render('program/quiz_instance_show.html.twig', [
            'program' => $program,
            'quizInstance' => $instance,
            'tab' => $tab,
            'sort' => $sort,
            'kpis' => $this->buildKpis($program, $studentRows),
            'studentRows' => $studentRows,
            'questionRows' => $questionRows,
        ]);
    }

    // "Relancer" (1f) - grants a fresh attempt regardless of the instance's window/attempt-count
    // rules (App\Controller\ProgramQuizAttemptController::take() enforces those for a student's
    // own self-service start): a teacher-initiated retry always wins, same spirit as
    // App\Enum\AttemptOrigin::Relance existing specifically for this case. A no-op (with a flash)
    // if the student already has an attempt in progress - relaunching over an active attempt would
    // just orphan it.
    #[Route(path: '/programs/{id}/quiz/{instanceId}/relaunch/{studentId}', name: 'app_program_quiz_relaunch', requirements: ['instanceId' => '\d+', 'studentId' => '\d+'], methods: ['POST'])]
    public function relaunch(int $id, int $instanceId, int $studentId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository, QuizDrawService $drawService): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);

        if (!$this->isCsrfTokenValid('quiz_relaunch', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $student = null;
        foreach ($program->getStudents() as $candidate) {
            if ($candidate->getId() === $studentId) {
                $student = $candidate;

                break;
            }
        }
        if (null === $student) {
            throw $this->createNotFoundException();
        }

        if (null !== $attemptRepository->findInProgress($instance, $student)) {
            $this->addFlash('error', 'programQuizRelaunchInProgressFlashMessage');

            return $this->redirectToRoute('app_program_quiz_show', ['id' => $program->getId(), 'instanceId' => $instance->getId()]);
        }

        $priorCount = \count($attemptRepository->findForStudent($instance, $student));
        $attempt = new QuizAttempt($instance, $student);
        $attempt->setAttemptNumber($priorCount + 1);
        $attempt->setOrigin(AttemptOrigin::Relance);
        $attempt->setShuffleSeed(random_int(1, 2_147_483_647));

        foreach ($drawService->drawQuestions($attempt) as $position => $question) {
            $attemptAnswer = new QuizAttemptAnswer($attempt, $question);
            $attemptAnswer->setOrderIndex($position);
            $attempt->addAttemptAnswer($attemptAnswer);
        }

        $entityManager->persist($attempt);
        $entityManager->flush();

        $this->addFlash('success', 'programQuizRelaunchedFlashMessage');

        return $this->redirectToRoute('app_program_quiz_show', ['id' => $program->getId(), 'instanceId' => $instance->getId()]);
    }

    // 1p - a single student's full attempt history for this instance.
    #[Route(path: '/programs/{id}/quiz/{instanceId}/student/{studentId}', name: 'app_program_quiz_student_attempts', requirements: ['instanceId' => '\d+', 'studentId' => '\d+'])]
    public function studentAttempts(int $id, int $instanceId, int $studentId, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);

        $student = null;
        foreach ($program->getStudents() as $candidate) {
            if ($candidate->getId() === $studentId) {
                $student = $candidate;

                break;
            }
        }
        if (null === $student) {
            throw $this->createNotFoundException();
        }

        $attempts = $attemptRepository->findForStudent($instance, $student);
        $lastConcludedId = null;
        $hasInProgress = false;
        foreach (array_reverse($attempts) as $candidate) {
            if (!$candidate->isConcluded()) {
                $hasInProgress = true;
            } elseif (null === $lastConcludedId) {
                $lastConcludedId = $candidate->getId();
            }
        }

        return $this->render('program/quiz_student_attempts.html.twig', [
            'program' => $program,
            'quizInstance' => $instance,
            'student' => $student,
            'attempts' => array_reverse($attempts),
            'retainedAttemptId' => $lastConcludedId,
            'hasInProgress' => $hasInProgress,
        ]);
    }

    // "Voir la copie" (1f/1p) - a teacher's read of any single attempt's full breakdown, reusing
    // the same per-question markup as the student's own correction screen (screen 1m), but never
    // restricted to entraînement: teachers can review an évaluation copy, only students are
    // blocked from seeing évaluation correction (App\Controller\ProgramQuizAttemptController::correction()).
    #[Route(path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}', name: 'app_program_quiz_attempt_show', requirements: ['instanceId' => '\d+', 'attemptId' => '\d+'])]
    public function attemptShow(int $id, int $instanceId, int $attemptId, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizInstanceRepository $instanceRepository, QuizAttemptRepository $attemptRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);

        $attempt = $attemptRepository->find($attemptId) ?? throw $this->createNotFoundException();
        if ($attempt->getQuizInstance()->getId() !== $instance->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('program/quiz_attempt_show.html.twig', [
            'program' => $program,
            'quizInstance' => $instance,
            'attempt' => $attempt,
            'hasInProgress' => null !== $attemptRepository->findInProgress($instance, $attempt->getStudent()),
        ]);
    }

    /** @param list<QuizAttempt> $concludedAttempts */
    private function buildStudentRows(Program $program, QuizInstance $instance, array $concludedAttempts, QuizAttemptRepository $attemptRepository): array
    {
        $attemptsByStudent = [];
        foreach ($concludedAttempts as $attempt) {
            $attemptsByStudent[$attempt->getStudent()->getId()][] = $attempt;
        }

        $rows = [];
        foreach ($program->getStudents() as $student) {
            $attempts = $attemptsByStudent[$student->getId()] ?? [];
            $retained = [] !== $attempts ? $attempts[\count($attempts) - 1] : null;

            $rows[] = [
                'student' => $student,
                'attempts' => $attempts,
                'attemptCount' => \count($attempts),
                'retained' => $retained,
                'inProgress' => $attemptRepository->findInProgress($instance, $student),
                'hasRelance' => [] !== array_filter($attempts, static fn (QuizAttempt $a): bool => AttemptOrigin::Relance === $a->getOrigin()),
            ];
        }

        return $rows;
    }

    /** @param list<QuizAttempt> $concludedAttempts */
    private function buildQuestionRows(QuizInstance $instance, array $concludedAttempts): array
    {
        $correctByQuestion = [];
        $totalByQuestion = [];
        foreach ($concludedAttempts as $attempt) {
            foreach ($attempt->getAttemptAnswers() as $attemptAnswer) {
                if (!$attemptAnswer->isAnswered()) {
                    continue;
                }
                $questionId = $attemptAnswer->getInstanceQuestion()->getId();
                $totalByQuestion[$questionId] = ($totalByQuestion[$questionId] ?? 0) + 1;
                if (true === $attemptAnswer->getIsCorrect()) {
                    $correctByQuestion[$questionId] = ($correctByQuestion[$questionId] ?? 0) + 1;
                }
            }
        }

        $rows = [];
        foreach ($instance->getQuestions() as $number => $question) {
            $questionId = $question->getId();
            $total = $totalByQuestion[$questionId] ?? 0;
            $successRate = $total > 0 ? round((($correctByQuestion[$questionId] ?? 0) / $total) * 100, 1) : null;

            $rows[] = [
                'question' => $question,
                'number' => $number + 1,
                'answeredCount' => $total,
                'successRate' => $successRate,
                'isTrap' => null !== $successRate && $successRate < 30,
            ];
        }

        return $rows;
    }

    /** @param list<array{student: User, retained: ?QuizAttempt}> $studentRows */
    private function buildKpis(Program $program, array $studentRows): array
    {
        $totalStudents = \count($studentRows);
        $withRetained = array_values(array_filter($studentRows, static fn (array $row): bool => null !== $row['retained']));
        $participation = \count($withRetained);

        $scores20 = array_map(static fn (array $row): float => $row['retained']->getScoreOn20(), $withRetained);
        $scorePercents = array_map(static fn (array $row): float => $row['retained']->getScorePercent(), $withRetained);
        $durations = array_filter(array_map(
            static fn (array $row): ?int => $row['retained']->getSubmittedAt()?->getTimestamp() - $row['retained']->getStartedAt()->getTimestamp(),
            $withRetained,
        ));

        return [
            'participation' => \sprintf('%d/%d', $participation, $totalStudents),
            'average20' => [] !== $scores20 ? round(array_sum($scores20) / \count($scores20), 1) : null,
            'successRate' => [] !== $scorePercents ? round(array_sum($scorePercents) / \count($scorePercents)) : null,
            'averageSeconds' => [] !== $durations ? (int) round(array_sum($durations) / \count($durations)) : null,
        ];
    }

    private function sortStudentRows(array $rows, string $sort): array
    {
        usort($rows, static function (array $a, array $b) use ($sort): int {
            $nameOf = static fn (array $row): string => $row['student']->getDisplayName() ?? $row['student']->getUsername();

            return match ($sort) {
                'score_desc' => ($b['retained']?->getScorePercent() ?? -1) <=> ($a['retained']?->getScorePercent() ?? -1),
                'score_asc' => ($a['retained']?->getScorePercent() ?? 101) <=> ($b['retained']?->getScorePercent() ?? 101),
                'time' => ($a['retained']?->getSubmittedAt()?->getTimestamp() - $a['retained']?->getStartedAt()->getTimestamp() ?? \PHP_INT_MAX) <=> ($b['retained']?->getSubmittedAt()?->getTimestamp() - $b['retained']?->getStartedAt()->getTimestamp() ?? \PHP_INT_MAX),
                'status' => self::statusRank($a) <=> self::statusRank($b),
                default => $nameOf($a) <=> $nameOf($b),
            };
        });

        return $rows;
    }

    // "Statut (non commencés d'abord)" - see screen 1f's sort options.
    private static function statusRank(array $row): int
    {
        return match (true) {
            null !== $row['retained'] => 2,
            null !== $row['inProgress'] => 1,
            default => 0,
        };
    }

    private function sortQuestionRows(array $rows, string $sort): array
    {
        usort($rows, static fn (array $a, array $b): int => match ($sort) {
            'rate_desc' => ($b['successRate'] ?? -1) <=> ($a['successRate'] ?? -1),
            'number' => $a['number'] <=> $b['number'],
            'type' => $a['question']->getType()->value <=> $b['question']->getType()->value,
            'traps_first' => [$b['isTrap'], $a['successRate'] ?? 101] <=> [$a['isTrap'], $b['successRate'] ?? 101],
            default => ($a['successRate'] ?? 101) <=> ($b['successRate'] ?? 101),
        });

        return $rows;
    }

    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
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
}
