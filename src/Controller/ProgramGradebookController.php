<?php

namespace App\Controller;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use App\Entity\Grade;
use App\Entity\GradeRubricAnswer;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\GradeStatus;
use App\Form\EvaluationFormType;
use App\Repository\EvaluationRepository;
use App\Repository\GradeRepository;
use App\Repository\ProgramRepository;
use App\Repository\TopicRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\EvaluationVoter;
use App\Service\EvaluationAverageCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Carnet de notes (design/design_handoff_projet/PROMPT_CLAUDE_CODE_carnet_de_notes.md, Part B/C).
 * One entry route branches by role (grid() below) - a teacher/staff sees the full editable grid
 * for a Topic they own, an enrolled student sees their own read-only carnet
 * (App\Security\Voter\EvaluationVoter gates evaluation-level access beyond that).
 *
 * Gated behind Program::$timetableManagementEnabled - Evaluation is anchored to Topic, which
 * already lives under that same feature area (see App\Controller\ProgramTimetableSettingsController's
 * topicsTab()); this doesn't introduce a new dedicated feature flag for grading.
 */
class ProgramGradebookController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    private const string SAVE_GRADE_CSRF_TOKEN_ID = 'gradebook_save';

    #[Route(path: '/programs/{id}/carnet-de-notes', name: 'app_program_gradebook')]
    public function grid(
        int $id,
        Request $request,
        ProgramRepository $programRepository,
        TopicRepository $topicRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        StructureAccessChecker $accessChecker,
        EvaluationAverageCalculator $calculator,
    ): Response {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $user = $this->currentUser();

        if (!$accessChecker->isStaff() && !$program->getTeachers()->contains($user) && $program->getStudents()->contains($user)) {
            return $this->studentView($program, $request, $topicRepository, $evaluationRepository, $gradeRepository, $calculator);
        }

        $topics = $accessChecker->isStaff()
            ? $topicRepository->findAllActiveForProgram($program)
            : array_values(array_filter($topicRepository->findAllActiveForProgram($program), static fn (Topic $topic): bool => $topic->getTeacher() === $user));

        if ([] === $topics) {
            return $this->render('program/gradebook_empty.html.twig', ['program' => $program]);
        }

        $requestedTopicId = $request->query->getInt('topic', 0);
        $topic = current(array_filter($topics, static fn (Topic $t): bool => $t->getId() === $requestedTopicId)) ?: $topics[0];

        $evaluations = $evaluationRepository->findActiveForTopicOrderedByDate($topic);
        $roster = $this->sortedByName($program->getStudents()->toArray());
        $gradesByEvaluation = [];
        foreach ($evaluations as $evaluation) {
            $gradesByEvaluation[$evaluation->getId()] = $gradeRepository->findForEvaluation($evaluation);
        }

        $now = new \DateTimeImmutable();

        return $this->render('program/gradebook_grid.html.twig', [
            'program' => $program,
            'topic' => $topic,
            'topicsJson' => array_map(static fn (Topic $t): array => ['id' => $t->getId(), 'name' => $t->getName()], $topics),
            'periodsJson' => $this->periodsJson($program),
            'evaluationsJson' => array_map(
                fn (Evaluation $e): array => $this->evaluationJson($e, $gradesByEvaluation[$e->getId()], $calculator, $now),
                $evaluations,
            ),
            'rosterJson' => array_map(static fn (User $s): array => ['id' => $s->getId(), 'name' => $s->getDisplayName() ?? $s->getUsername()], $roster),
            'gradesJson' => $this->gradesJson($evaluations, $gradesByEvaluation, $calculator),
        ]);
    }

    private function studentView(
        Program $program,
        Request $request,
        TopicRepository $topicRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        EvaluationAverageCalculator $calculator,
    ): Response {
        $student = $this->currentUser();
        $now = new \DateTimeImmutable();
        $topics = $topicRepository->findAllActiveForProgram($program);

        $subjects = [];
        foreach ($topics as $topic) {
            $evaluations = array_values(array_filter(
                $evaluationRepository->findActiveForTopicOrderedByDate($topic),
                static fn (Evaluation $e): bool => $e->isVisibleAt($now),
            ));
            if ([] === $evaluations) {
                continue;
            }

            $grades = $gradeRepository->findForEvaluationsAndStudent($evaluations, $student);
            $gradeByEvaluationId = [];
            foreach ($grades as $grade) {
                $gradeByEvaluationId[$grade->getEvaluation()->getId()] = $grade;
            }

            $rows = [];
            foreach ($evaluations as $evaluation) {
                $grade = $gradeByEvaluationId[$evaluation->getId()] ?? null;
                $rows[] = [
                    'evaluation' => $evaluation,
                    'grade' => $grade,
                    'normalized' => $grade ? $calculator->normalize($grade) : null,
                ];
            }

            $countedGrades = array_values(array_filter($grades, static fn (Grade $g): bool => $g->getStatus()->countsTowardAverage()));
            $subjects[] = [
                'topic' => $topic,
                'rows' => $rows,
                'average' => $calculator->studentAverage($countedGrades),
            ];
        }

        $allGrades = [] === $subjects ? [] : array_merge(...array_map(static fn (array $s): array => array_column($s['rows'], 'grade'), $subjects));
        $allGrades = array_values(array_filter($allGrades, static fn (?Grade $g): bool => null !== $g && $g->getStatus()->countsTowardAverage()));

        return $this->render('program/gradebook_student.html.twig', [
            'program' => $program,
            'subjects' => $subjects,
            'overallAverage' => $calculator->studentAverage($allGrades),
            'calculator' => $calculator,
        ]);
    }

    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/grades/{studentId}', name: 'app_program_gradebook_save_grade', methods: ['POST'])]
    public function saveGrade(
        int $id,
        int $evaluationId,
        int $studentId,
        Request $request,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        EntityManagerInterface $entityManager,
        StructureAccessChecker $accessChecker,
        EvaluationAverageCalculator $calculator,
    ): JsonResponse {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);
        $this->assertCsrf($request);

        $student = $program->getStudents()->filter(static fn (User $s): bool => $s->getId() === $studentId)->first();
        if (false === $student) {
            throw $this->createNotFoundException();
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        [$status, $value] = $this->interpret((string) ($payload['raw'] ?? ''), $evaluation->getScale());

        $grade = $gradeRepository->findOneForEvaluationAndStudent($evaluation, $student);
        if (null === $status) {
            if (null !== $grade) {
                $entityManager->remove($grade);
                $entityManager->flush();
            }

            return $this->json(['cleared' => true, ...$this->recomputeAverages($evaluation, $gradeRepository, $calculator)]);
        }

        if (null === $grade) {
            $grade = new Grade($evaluation, $student);
            $entityManager->persist($grade);
        }

        $grade->setStatus($status)->setValue($value)->setGradedBy($this->currentUser())->setGradedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json([
            'status' => $grade->getStatus()->value,
            'value' => $grade->getValue(),
            'normalizedValue' => $calculator->normalize($grade),
            'colorClass' => $calculator->gradeColorClass($calculator->normalize($grade)),
            ...$this->recomputeAverages($evaluation, $gradeRepository, $calculator),
        ]);
    }

    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/new', name: 'app_program_gradebook_evaluation_new')]
    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/edit', name: 'app_program_gradebook_evaluation_edit')]
    public function evaluationForm(
        int $id,
        Request $request,
        ProgramRepository $programRepository,
        TopicRepository $topicRepository,
        EvaluationRepository $evaluationRepository,
        EntityManagerInterface $entityManager,
        StructureAccessChecker $accessChecker,
        ?int $evaluationId = null,
    ): Response {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $isEdit = null !== $evaluationId;

        if ($isEdit) {
            $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
            $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);
            $topic = $evaluation->getTopic();
        } else {
            $topicId = $request->query->getInt('topic', 0);
            $topic = $this->findTopicOrNotFound($topicRepository, $program, $topicId);
            $evaluation = new Evaluation($topic, '', new \DateTimeImmutable());
            $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);
        }

        $form = $this->createForm(EvaluationFormType::class, $evaluation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$form->get('hasScheduledVisibility')->getData()) {
                $evaluation->setVisibleAt(null);
            }

            if ($isEdit) {
                $evaluation->setLastUpdatedBy($this->currentUser());
                $evaluation->setLastUpdatedDate(new \DateTimeImmutable());
            } else {
                $evaluation->setCreatedBy($this->currentUser());
            }

            $entityManager->persist($evaluation);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'evaluationUpdatedFlashMessage' : 'evaluationCreatedFlashMessage');

            if ($form->get('hasRubric')->getData()) {
                return $this->redirectToRoute('app_program_gradebook_evaluation_rubric', ['id' => $program->getId(), 'evaluationId' => $evaluation->getId()]);
            }

            return $this->redirectToRoute('app_program_gradebook', ['id' => $program->getId(), 'topic' => $topic->getId()]);
        }

        return $this->render('program/gradebook_evaluation_form.html.twig', [
            'program' => $program,
            'topic' => $topic,
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/deactivate', name: 'app_program_gradebook_evaluation_deactivate', methods: ['POST'])]
    public function deactivateEvaluation(
        int $id,
        int $evaluationId,
        Request $request,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        EntityManagerInterface $entityManager,
        StructureAccessChecker $accessChecker,
    ): JsonResponse {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);
        $this->assertCsrf($request);

        $evaluation->setInactiveDate(new \DateTimeImmutable());
        $evaluation->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/bareme', name: 'app_program_gradebook_evaluation_rubric')]
    public function rubricForm(
        int $id,
        int $evaluationId,
        Request $request,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        EntityManagerInterface $entityManager,
        StructureAccessChecker $accessChecker,
    ): Response {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);

        if ($request->isMethod('POST')) {
            $this->assertFormCsrf($request);
            $this->applyRubricSubmission($evaluation, $entityManager, $request->request->all('sections'));
            $entityManager->flush();

            $this->addFlash('success', 'evaluationRubricSavedFlashMessage');

            return $this->redirectToRoute('app_program_gradebook', ['id' => $program->getId(), 'topic' => $evaluation->getTopic()->getId()]);
        }

        $sectionsJson = [];
        foreach ($evaluation->getRubricSections() as $section) {
            $questions = [];
            foreach ($section->getQuestions() as $question) {
                $questions[] = ['label' => $question->getLabel(), 'maxPoints' => $question->getMaxPoints()];
            }
            $sectionsJson[] = ['name' => $section->getName(), 'questions' => $questions];
        }

        return $this->render('program/gradebook_evaluation_rubric.html.twig', [
            'program' => $program,
            'evaluation' => $evaluation,
            'sectionsJson' => $sectionsJson,
        ]);
    }

    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/detail', name: 'app_program_gradebook_evaluation_detail')]
    public function rubricGrid(
        int $id,
        int $evaluationId,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        StructureAccessChecker $accessChecker,
    ): Response {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);

        $roster = $this->sortedByName($program->getStudents()->toArray());
        $grades = $gradeRepository->findForEvaluation($evaluation);
        $gradeByStudentId = [];
        foreach ($grades as $grade) {
            $gradeByStudentId[$grade->getStudent()->getId()] = $grade;
        }

        $sections = [];
        foreach ($evaluation->getRubricSections() as $section) {
            $questions = [];
            foreach ($section->getQuestions() as $question) {
                $questions[] = ['id' => $question->getId(), 'label' => $question->getLabel(), 'maxPoints' => $question->getMaxPoints()];
            }
            $sections[] = ['name' => $section->getName(), 'questions' => $questions];
        }

        $answersJson = [];
        foreach ($roster as $student) {
            $grade = $gradeByStudentId[$student->getId()] ?? null;
            $row = [];
            if (null !== $grade) {
                foreach ($grade->getRubricAnswers() as $answer) {
                    $row[$answer->getQuestion()->getId()] = $answer->isNotTested() ? 'nt' : $answer->getPointsAwarded();
                }
            }
            $answersJson[$student->getId()] = $row;
        }

        $totalsJson = [];
        foreach ($roster as $student) {
            $totalsJson[$student->getId()] = ($gradeByStudentId[$student->getId()] ?? null)?->getValue();
        }

        return $this->render('program/gradebook_evaluation_detail.html.twig', [
            'program' => $program,
            'evaluation' => $evaluation,
            'sectionsJson' => $sections,
            'rosterJson' => array_map(static fn (User $s): array => ['id' => $s->getId(), 'name' => $s->getDisplayName() ?? $s->getUsername()], $roster),
            'answersJson' => $answersJson,
            'totalsJson' => $totalsJson,
        ]);
    }

    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/detail/grades/{studentId}/questions/{questionId}', name: 'app_program_gradebook_save_rubric_answer', methods: ['POST'])]
    public function saveRubricAnswer(
        int $id,
        int $evaluationId,
        int $studentId,
        int $questionId,
        Request $request,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        EntityManagerInterface $entityManager,
        StructureAccessChecker $accessChecker,
        EvaluationAverageCalculator $calculator,
    ): JsonResponse {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);
        $this->assertCsrf($request);

        $student = $program->getStudents()->filter(static fn (User $s): bool => $s->getId() === $studentId)->first();
        if (false === $student) {
            throw $this->createNotFoundException();
        }

        $question = null;
        foreach ($evaluation->getRubricSections() as $section) {
            foreach ($section->getQuestions() as $candidate) {
                if ($candidate->getId() === $questionId) {
                    $question = $candidate;
                }
            }
        }
        if (null === $question) {
            throw $this->createNotFoundException();
        }

        $grade = $gradeRepository->findOneForEvaluationAndStudent($evaluation, $student);
        if (null === $grade) {
            $grade = new Grade($evaluation, $student);
            $grade->setStatus(GradeStatus::Normal);
            $entityManager->persist($grade);
        }

        $answer = null;
        foreach ($grade->getRubricAnswers() as $candidate) {
            if ($candidate->getQuestion() === $question) {
                $answer = $candidate;
            }
        }
        if (null === $answer) {
            $answer = new GradeRubricAnswer($grade, $question);
            $grade->addRubricAnswer($answer);
            $entityManager->persist($answer);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $raw = trim((string) ($payload['raw'] ?? ''));

        if ('' === $raw) {
            $answer->setPointsAwarded(null)->setNotTested(false);
        } elseif ('nt' === strtolower($raw)) {
            $answer->setPointsAwarded(null)->setNotTested(true);
        } else {
            $points = $this->clampNumber($raw, $question->getMaxPoints());
            if (null === $points) {
                return $this->json(['error' => 'invalid'], 422);
            }
            $answer->setPointsAwarded($points)->setNotTested(false);
        }

        $grade->setValue($calculator->computeRubricTotal($grade));
        $grade->setGradedBy($this->currentUser())->setGradedAt(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json([
            'total' => $grade->getValue(),
            'normalizedValue' => $calculator->normalize($grade),
            'colorClass' => $calculator->gradeColorClass($calculator->normalize($grade)),
        ]);
    }

    /** @param list<Evaluation> $evaluations @param array<int, list<Grade>> $gradesByEvaluation */
    private function gradesJson(array $evaluations, array $gradesByEvaluation, EvaluationAverageCalculator $calculator): array
    {
        $byEvaluation = [];
        foreach ($evaluations as $evaluation) {
            $row = [];
            foreach ($gradesByEvaluation[$evaluation->getId()] as $grade) {
                $normalized = $calculator->normalize($grade);
                $row[$grade->getStudent()->getId()] = [
                    'status' => $grade->getStatus()->value,
                    'value' => $grade->getValue(),
                    'normalizedValue' => $normalized,
                    'colorClass' => $calculator->gradeColorClass($normalized),
                    'hasAudio' => null !== $grade->getAudioComment(),
                ];
            }
            $byEvaluation[$evaluation->getId()] = $row;
        }

        return $byEvaluation;
    }

    private function evaluationJson(Evaluation $evaluation, array $grades, EvaluationAverageCalculator $calculator, \DateTimeImmutable $now): array
    {
        return [
            'id' => $evaluation->getId(),
            'name' => $evaluation->getName(),
            'type' => $evaluation->getType()->value,
            'modality' => $evaluation->getModality()->value,
            'status' => $evaluation->getStatus()->value,
            'date' => $evaluation->getDate()?->format('Y-m-d'),
            'scale' => $evaluation->getScale(),
            'coefficient' => $evaluation->getCoefficient(),
            'countsOutOf20' => $evaluation->countsOutOf20(),
            'hasRubric' => $evaluation->hasRubric(),
            'isHidden' => !$evaluation->isVisibleAt($now),
            'classAverage' => $calculator->evaluationAverage($grades),
        ];
    }

    /** @return array{studentAverage: ?float, evaluationAverage: ?float} */
    private function recomputeAverages(Evaluation $evaluation, GradeRepository $gradeRepository, EvaluationAverageCalculator $calculator): array
    {
        $grades = $gradeRepository->findForEvaluation($evaluation);

        return ['evaluationAverage' => $calculator->evaluationAverage($grades)];
    }

    /** @return array{0: ?GradeStatus, 1: ?float} */
    private function interpret(string $raw, float $scale): array
    {
        $trimmed = trim($raw);
        $lower = mb_strtolower($trimmed);

        if ('' === $trimmed) {
            return [null, null];
        }

        if ('abs' === $lower || 'a' === $lower) {
            return [GradeStatus::Absent, null];
        }

        if (\in_array($lower, ['ne', 'né', 'n.é.'], true)) {
            return [GradeStatus::NotEvaluated, null];
        }

        if ('nt' === $lower) {
            return [GradeStatus::NotTested, null];
        }

        if (1 === preg_match('/^\((.+)\)$/', $trimmed, $matches)) {
            $value = $this->clampNumber($matches[1], $scale);

            return null === $value ? [null, null] : [GradeStatus::Excluded, $value];
        }

        $value = $this->clampNumber($trimmed, $scale);

        return null === $value ? [null, null] : [GradeStatus::Normal, $value];
    }

    private function clampNumber(string $raw, float $max): ?float
    {
        $normalized = str_replace(',', '.', trim($raw));
        if (!is_numeric($normalized)) {
            return null;
        }

        return round(max(0.0, min($max, (float) $normalized)), 2);
    }

    private function periodsJson(Program $program): array
    {
        $group = $program->getEvaluationPeriodGroup();
        if (null === $group) {
            return [];
        }

        $periods = [];
        foreach ($group->getPeriods() as $period) {
            $periods[] = [
                'id' => (string) $period->getId(),
                'name' => $period->getName(),
                'startDate' => $period->getStartDate()?->format('Y-m-d'),
                'endDate' => $period->getEndDate()?->format('Y-m-d'),
            ];
        }

        return $periods;
    }

    /** @param array<int, mixed> $sectionsPayload */
    private function applyRubricSubmission(Evaluation $evaluation, EntityManagerInterface $entityManager, array $sectionsPayload): void
    {
        foreach ($evaluation->getRubricSections() as $existingSection) {
            $evaluation->removeRubricSection($existingSection);
            $entityManager->remove($existingSection);
        }

        $sectionPosition = 0;
        foreach ($sectionsPayload as $sectionData) {
            $sectionName = trim((string) ($sectionData['name'] ?? ''));
            $questionsData = $sectionData['questions'] ?? [];
            if ('' === $sectionName || !\is_array($questionsData)) {
                continue;
            }

            $section = new EvaluationRubricSection($sectionName, $sectionPosition++);

            $questionPosition = 0;
            foreach ($questionsData as $questionData) {
                $label = trim((string) ($questionData['label'] ?? ''));
                $maxPoints = is_numeric($questionData['maxPoints'] ?? null) ? (float) $questionData['maxPoints'] : 0.0;
                if ('' === $label || $maxPoints <= 0) {
                    continue;
                }

                $section->addQuestion(new EvaluationRubricQuestion($label, $maxPoints, $questionPosition++));
            }

            if (!$section->getQuestions()->isEmpty()) {
                $evaluation->addRubricSection($section);
                $entityManager->persist($section);
            }
        }
    }

    private function findVisibleProgram(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        if (!$accessChecker->isProgramVisible($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    private function findTopicOrNotFound(TopicRepository $repository, Program $program, int $topicId): Topic
    {
        $topic = $repository->find($topicId) ?? throw $this->createNotFoundException();
        if ($topic->getProgram() !== $program) {
            throw $this->createNotFoundException();
        }

        return $topic;
    }

    private function findEvaluationOrNotFound(EvaluationRepository $repository, Program $program, int $evaluationId): Evaluation
    {
        $evaluation = $repository->find($evaluationId) ?? throw $this->createNotFoundException();
        if ($evaluation->getTopic()?->getProgram() !== $program) {
            throw $this->createNotFoundException();
        }

        return $evaluation;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::SAVE_GRADE_CSRF_TOKEN_ID, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    // Unlike saveGrade()/saveRubricAnswer() (fetch calls, token in the X-CSRF-Token header), the
    // rubric editor is a classic full-page form POST - same token ID, submitted as the usual
    // hidden _token field instead.
    private function assertFormCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::SAVE_GRADE_CSRF_TOKEN_ID, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @param list<User> $users
     *
     * @return list<User>
     */
    private function sortedByName(array $users): array
    {
        usort($users, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        return $users;
    }
}
