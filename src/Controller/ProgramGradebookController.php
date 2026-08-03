<?php

namespace App\Controller;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use App\Entity\Grade;
use App\Entity\GradeAudioComment;
use App\Entity\GradeRubricAnswer;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\GradeStatus;
use App\Form\EvaluationFormType;
use App\Repository\EvaluationRepository;
use App\Repository\GradeRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\TopicRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\EvaluationVoter;
use App\Service\EvaluationAverageCalculator;
use App\Service\GradeAudioCommentUploadService;
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
        ProgramStudentOptionRepository $studentOptionRepository,
        StructureAccessChecker $accessChecker,
        EvaluationAverageCalculator $calculator,
    ): Response {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $user = $this->currentUser();

        if (!$accessChecker->isStaff() && !$program->getTeachers()->contains($user) && $program->getStudents()->contains($user)) {
            return $this->studentView($program, $request, $topicRepository, $evaluationRepository, $gradeRepository, $calculator);
        }

        // A referent teacher of the class reads the whole class's carnet, every Topic included -
        // but only the matières they teach themselves stay editable (see $canEdit below, and the
        // EvaluationVoter::MANAGE checks every write route keeps). A plain teacher still only ever
        // sees their own matières.
        $topics = $accessChecker->isStaff() || $accessChecker->isProgramReferentTeacher($program)
            ? $topicRepository->findAllActiveForProgram($program)
            : array_values(array_filter($topicRepository->findAllActiveForProgram($program), static fn (Topic $topic): bool => $topic->getTeacher() === $user));

        if ([] === $topics) {
            return $this->render('program/gradebook_empty.html.twig', ['program' => $program]);
        }

        $requestedTopicId = $request->query->getInt('topic', 0);
        $topic = current(array_filter($topics, static fn (Topic $t): bool => $t->getId() === $requestedTopicId)) ?: $topics[0];
        $canEdit = $this->canEditTopic($topic, $accessChecker);

        $evaluations = $evaluationRepository->findActiveForTopicOrderedByDate($topic);
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
            'rosterJson' => $this->rosterJson($program, $studentOptionRepository),
            'gradesJson' => $this->gradesJson($evaluations, $gradesByEvaluation, $calculator),
            'canEdit' => $canEdit,
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

        $periods = $program->getEvaluationPeriodGroup()?->getPeriods()->toArray() ?? [];
        $selectedPeriodId = $request->query->getInt('period', 0);
        $selectedPeriod = null;
        foreach ($periods as $candidate) {
            if ($candidate->getId() === $selectedPeriodId) {
                $selectedPeriod = $candidate;
            }
        }

        $subjects = [];
        foreach ($topics as $topic) {
            $evaluations = array_values(array_filter(
                $evaluationRepository->findActiveForTopicOrderedByDate($topic),
                static fn (Evaluation $e): bool => $e->isVisibleAt($now)
                    && (null === $selectedPeriod || (null !== $e->getDate() && $selectedPeriod->contains($e->getDate()))),
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

        // "Dernières notes" : les 4 évaluations notées les plus récentes, toutes matières
        // confondues (design écran 5, grille de 3 colonnes fixes).
        $recent = [];
        foreach ($subjects as $subject) {
            foreach ($subject['rows'] as $row) {
                if (null !== $row['grade'] && null !== $row['grade']->getValue()) {
                    $recent[] = ['topic' => $subject['topic'], ...$row];
                }
            }
        }
        usort($recent, static fn (array $a, array $b): int => ($b['evaluation']->getDate()?->getTimestamp() ?? 0) <=> ($a['evaluation']->getDate()?->getTimestamp() ?? 0));

        return $this->render('program/gradebook_student.html.twig', [
            'program' => $program,
            'subjects' => $subjects,
            'recent' => \array_slice($recent, 0, 4),
            'gradedCount' => \count($allGrades),
            'overallAverage' => $calculator->studentAverage($allGrades),
            'calculator' => $calculator,
            'periods' => $periods,
            'selectedPeriod' => $selectedPeriod,
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

        $student = $this->findStudentOrNotFound($program, $studentId);

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
            // Visibilité programmée à J+1 par défaut : le temps de finir la saisie avant que la
            // classe ne voie ses notes (handoff itération 2, point 6). L'enseignant peut toujours
            // repasser en visibilité immédiate ou déplacer l'échéance.
            $evaluation->setVisibleAt(new \DateTimeImmutable('+24 hours'));
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

            // Le barème passe devant : sans ses sections, l'écran de saisie n'aurait aucune
            // colonne à proposer (design écran 2, « étape supplémentaire après la création »).
            if ($form->get('hasRubric')->getData()) {
                return $this->redirectToRoute('app_program_gradebook_evaluation_rubric', ['id' => $program->getId(), 'evaluationId' => $evaluation->getId()]);
            }

            if ($request->request->has('saveAndEnter')) {
                return $this->redirectToRoute('app_program_gradebook_evaluation_entry', ['id' => $program->getId(), 'evaluationId' => $evaluation->getId()]);
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

    /**
     * Saisie rapide d'une évaluation (handoff écran 4) : une ligne par élève, en deux modes selon
     * que l'évaluation porte un barème détaillé ou non - une note globale par élève, ou une case
     * par question avec total automatique. Le commentaire audio de chaque élève se pose ici (la
     * grille ne fait qu'en signaler la présence et renvoyer vers cet écran).
     */
    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/saisie', name: 'app_program_gradebook_evaluation_entry')]
    public function entry(
        int $id,
        int $evaluationId,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        ProgramStudentOptionRepository $studentOptionRepository,
        StructureAccessChecker $accessChecker,
        EvaluationAverageCalculator $calculator,
    ): Response {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);

        // Not EvaluationVoter::MANAGE, unlike every write route here: a referent teacher reaches
        // this screen read-only for a colleague's matière (the grid opens onto it), while
        // saveGrade()/saveRubricAnswer() stay MANAGE-only. Deliberately not EvaluationVoter::VIEW
        // either - that attribute also lets an enrolled student through, and this screen shows the
        // whole class's grades.
        $canEdit = $this->canEditTopic($evaluation->getTopic(), $accessChecker);
        if (!$canEdit && !$accessChecker->isProgramReferentTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

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

        $roster = $this->rosterJson($program, $studentOptionRepository);
        $rowsJson = [];
        foreach ($roster as $student) {
            $grade = $gradeByStudentId[$student['id']] ?? null;
            $answers = [];
            foreach ($grade?->getRubricAnswers() ?? [] as $answer) {
                $answers[$answer->getQuestion()->getId()] = $answer->isNotTested() ? 'nt' : $answer->getPointsAwarded();
            }

            $normalized = null !== $grade ? $calculator->normalize($grade) : null;
            $rowsJson[] = [
                ...$student,
                'status' => $grade?->getStatus()->value,
                'value' => $grade?->getValue(),
                'colorClass' => $calculator->gradeColorClass($normalized),
                'normalizedValue' => $normalized,
                'answers' => $answers,
                'hasAudio' => null !== $grade?->getAudioComment(),
                'audioListenPercent' => $grade?->getAudioComment()?->getMaxListenedPercent(),
            ];
        }

        return $this->render('program/gradebook_evaluation_entry.html.twig', [
            'program' => $program,
            'evaluation' => $evaluation,
            'sectionsJson' => $sections,
            'rowsJson' => $rowsJson,
            'canEdit' => $canEdit,
        ]);
    }

    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/saisie/grades/{studentId}/questions/{questionId}', name: 'app_program_gradebook_save_rubric_answer', methods: ['POST'])]
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

        $student = $this->findStudentOrNotFound($program, $studentId);

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
            $normalized = str_replace(',', '.', $raw);
            if (!is_numeric($normalized)) {
                return $this->json(['error' => 'invalid'], 422);
            }

            // Unlike the simple grid's interpret()/clampNumber() (which clamps a stray
            // over-scale value down), a barème question REJECTS a value above its own max
            // points outright (design's qSet(): "if (n > pts) return;") rather than silently
            // rewriting what the teacher typed - acceptance criterion 5.
            $points = round((float) $normalized, 2);
            if ($points < 0 || $points > $question->getMaxPoints()) {
                return $this->json(['error' => 'exceeds_max_points'], 422);
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

    // Step 1 of the recorded-Blob-direct-to-S3 flow (design Part C) - just hands back a presigned
    // PUT URL, nothing is persisted here yet (see confirmAudioComment()).
    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/audio/{studentId}/request-upload', name: 'app_program_gradebook_audio_request_upload', methods: ['POST'])]
    public function requestAudioUpload(
        int $id,
        int $evaluationId,
        int $studentId,
        Request $request,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        StructureAccessChecker $accessChecker,
        GradeAudioCommentUploadService $uploadService,
    ): JsonResponse {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);
        $this->assertCsrf($request);

        $student = $this->findStudentOrNotFound($program, $studentId);
        $key = $uploadService->keyFor($evaluation, $student);

        return $this->json(['key' => $key, 'uploadUrl' => $uploadService->createUploadUrl($key)]);
    }

    // Step 2 - called once the browser's direct PUT to S3 (using the URL from
    // requestAudioUpload()) has actually succeeded, so the DB row is only ever created for a
    // recording that's really sitting in the bucket.
    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/audio/{studentId}/confirm', name: 'app_program_gradebook_audio_confirm', methods: ['POST'])]
    public function confirmAudioComment(
        int $id,
        int $evaluationId,
        int $studentId,
        Request $request,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        EntityManagerInterface $entityManager,
        StructureAccessChecker $accessChecker,
        GradeAudioCommentUploadService $uploadService,
    ): JsonResponse {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);
        $this->assertCsrf($request);

        $student = $this->findStudentOrNotFound($program, $studentId);
        $payload = json_decode($request->getContent(), true) ?? [];
        $fileSize = max(0, (int) ($payload['fileSize'] ?? 0));

        $grade = $gradeRepository->findOneForEvaluationAndStudent($evaluation, $student);
        if (null === $grade) {
            $grade = new Grade($evaluation, $student);
            $grade->setStatus(GradeStatus::NotEvaluated);
            $entityManager->persist($grade);
        }

        $existing = $grade->getAudioComment();
        if (null !== $existing) {
            $uploadService->delete($existing->getS3Key());
            $entityManager->remove($existing);
        }

        $key = $uploadService->keyFor($evaluation, $student);
        $audioComment = new GradeAudioComment($grade, $key, $fileSize, $this->currentUser());
        $entityManager->persist($audioComment);
        $entityManager->flush();

        return $this->json(['success' => true, 'playbackUrl' => $uploadService->playbackUrl($key)]);
    }

    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/audio/{studentId}/delete', name: 'app_program_gradebook_audio_delete', methods: ['POST'])]
    public function deleteAudioComment(
        int $id,
        int $evaluationId,
        int $studentId,
        Request $request,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        EntityManagerInterface $entityManager,
        StructureAccessChecker $accessChecker,
        GradeAudioCommentUploadService $uploadService,
    ): JsonResponse {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::MANAGE, $evaluation);
        $this->assertCsrf($request);

        $student = $this->findStudentOrNotFound($program, $studentId);
        $grade = $gradeRepository->findOneForEvaluationAndStudent($evaluation, $student);
        $audioComment = $grade?->getAudioComment();

        if (null !== $audioComment) {
            $uploadService->delete($audioComment->getS3Key());
            $entityManager->remove($audioComment);
            $entityManager->flush();
        }

        return $this->json(['success' => true]);
    }

    // Reached by both the teacher (grid playback) and the owning student (their own carnet) -
    // EvaluationVoter::VIEW covers both, but the student branch additionally must be *this*
    // audio's own student, never another student's (see currentUser() comparison below).
    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/audio/{studentId}/playback-url', name: 'app_program_gradebook_audio_playback_url')]
    public function audioPlaybackUrl(
        int $id,
        int $evaluationId,
        int $studentId,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        StructureAccessChecker $accessChecker,
        GradeAudioCommentUploadService $uploadService,
    ): JsonResponse {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::VIEW, $evaluation);

        $student = $this->findStudentOrNotFound($program, $studentId);
        if (!$accessChecker->isStaff() && $evaluation->getTopic()?->getTeacher() !== $this->currentUser() && $student !== $this->currentUser()) {
            throw $this->createAccessDeniedException();
        }

        $grade = $gradeRepository->findOneForEvaluationAndStudent($evaluation, $student);
        $audioComment = $grade?->getAudioComment();
        if (null === $audioComment) {
            throw $this->createNotFoundException();
        }

        return $this->json(['url' => $uploadService->playbackUrl($audioComment->getS3Key())]);
    }

    // Student-only (a teacher listening back never moves their own ratchet) - throttled to ~5s
    // client-side (grade_audio_comment_controller.js), percent only ever increases (see
    // GradeAudioComment::registerListenProgress()).
    #[Route(path: '/programs/{id}/carnet-de-notes/evaluations/{evaluationId}/audio/{studentId}/listen-progress', name: 'app_program_gradebook_audio_listen_progress', methods: ['POST'])]
    public function registerAudioListenProgress(
        int $id,
        int $evaluationId,
        int $studentId,
        Request $request,
        ProgramRepository $programRepository,
        EvaluationRepository $evaluationRepository,
        GradeRepository $gradeRepository,
        EntityManagerInterface $entityManager,
        StructureAccessChecker $accessChecker,
    ): JsonResponse {
        $program = $this->findVisibleProgram($id, $programRepository, $accessChecker);
        $evaluation = $this->findEvaluationOrNotFound($evaluationRepository, $program, $evaluationId);
        $this->denyAccessUnlessGranted(EvaluationVoter::VIEW, $evaluation);

        $student = $this->findStudentOrNotFound($program, $studentId);
        if ($student !== $this->currentUser()) {
            throw $this->createAccessDeniedException();
        }

        $this->assertCsrf($request);

        $grade = $gradeRepository->findOneForEvaluationAndStudent($evaluation, $student);
        $audioComment = $grade?->getAudioComment();
        if (null === $audioComment) {
            throw $this->createNotFoundException();
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $audioComment->registerListenProgress((int) ($payload['percent'] ?? 0));
        $entityManager->flush();

        return $this->json(['success' => true]);
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
                    'audioListenPercent' => $grade->getAudioComment()?->getMaxListenedPercent(),
                ];
            }
            $byEvaluation[$evaluation->getId()] = $row;
        }

        return $byEvaluation;
    }

    /**
     * La liste des élèves telle que les écrans la peignent : nom, puis étiquette d'option
     * (SLAM/SISR...) à droite du nom. Les créas donnent la forme de l'étiquette mais lui inventent
     * deux couleurs fixes ; l'application, elle, stocke déjà une couleur par Option et l'affiche
     * partout ailleurs de la même façon (nom court sur fond coloré, texte blanc - voir
     * program/_user_card.html.twig), donc c'est cette couleur-là qui est retenue.
     *
     * @return list<array{id: int, name: string, option: ?string, optionColor: ?string}>
     */
    private function rosterJson(Program $program, ProgramStudentOptionRepository $studentOptionRepository): array
    {
        $optionsByStudentId = $studentOptionRepository->findOptionsByStudentForProgram($program);

        return array_map(
            static function (User $student) use ($optionsByStudentId): array {
                $option = ($optionsByStudentId[$student->getId()] ?? [])[0] ?? null;

                return [
                    'id' => $student->getId(),
                    'name' => $student->getDisplayName() ?? $student->getUsername(),
                    'option' => $option?->getShortName(),
                    'optionColor' => $option?->getColor(),
                ];
            },
            $this->sortedByName($program->getStudents()->toArray()),
        );
    }

    private function evaluationJson(Evaluation $evaluation, array $grades, EvaluationAverageCalculator $calculator, \DateTimeImmutable $now): array
    {
        return [
            'id' => $evaluation->getId(),
            'name' => $evaluation->getName(),
            'type' => $evaluation->getType()->value,
            // Optional D/F/S pastille on the column header - null for any evaluation that isn't
            // tied to a progression (see App\Enum\EvaluationNature).
            'nature' => $evaluation->getNature()?->value,
            'natureInitial' => $evaluation->getNature()?->initial(),
            'modality' => $evaluation->getModality()->value,
            'status' => $evaluation->getStatus()->value,
            'date' => $evaluation->getDate()?->format('Y-m-d'),
            // Jour/mois seuls : la colonne fait 132px, l'année n'y tient pas et la grille couvre
            // de toute façon une seule période scolaire.
            'dateLabel' => $evaluation->getDate()?->format('d/m') ?? '—',
            'scale' => $evaluation->getScale(),
            'coefficient' => $evaluation->getCoefficient(),
            'countsOutOf20' => $evaluation->countsOutOf20(),
            'hasRubric' => $evaluation->hasRubric(),
            'isHidden' => !$evaluation->isVisibleAt($now),
            'visibleAtLabel' => $evaluation->getVisibleAt()?->format('d/m H:i'),
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

    /**
     * Same rule as EvaluationVoter::MANAGE, asked about a Topic instead of one Evaluation - used to
     * render the grid read-only for a referent teacher looking at a colleague's matière (the voter
     * still guards every write route on its own).
     */
    private function canEditTopic(?Topic $topic, StructureAccessChecker $accessChecker): bool
    {
        return $accessChecker->isStaff() || (null !== $topic && $topic->getTeacher() === $this->currentUser());
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

    private function findStudentOrNotFound(Program $program, int $studentId): User
    {
        $student = $program->getStudents()->filter(static fn (User $s): bool => $s->getId() === $studentId)->first();

        return false === $student ? throw $this->createNotFoundException() : $student;
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
     * Ordre par défaut de tous les écrans qui listent des élèves : nom de famille croissant, puis
     * prénom (handoff itération 2, point 7). Les tris manuels de la grille (par moyenne, par note)
     * se posent par-dessus côté client et retombent sur cet ordre quand on les annule.
     *
     * Le nom affiché (« Prénom Nom ») trierait par prénom, d'où le tri sur les champs séparés ;
     * il ne sert que de repli pour un compte sans nom renseigné.
     *
     * @param list<User> $users
     *
     * @return list<User>
     */
    private function sortedByName(array $users): array
    {
        $key = static fn (User $user): string => mb_strtolower(trim(
            ($user->getLastname() ?? '').' '.($user->getFirstname() ?? '')
        )) ?: mb_strtolower($user->getDisplayName() ?? $user->getUsername());

        usort($users, static fn (User $a, User $b): int => $key($a) <=> $key($b));

        return $users;
    }
}
