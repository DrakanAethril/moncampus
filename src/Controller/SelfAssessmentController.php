<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Assignment;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\SelfAssessment;
use App\Entity\SelfAssessmentAnswer;
use App\Entity\User;
use App\Repository\AssignmentRepository;
use App\Repository\GradeRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\SelfAssessmentRepository;
use App\Security\StructureAccessChecker;
use App\Service\AssignmentAudienceResolver;
use App\Service\PostValue;
use App\Service\SelfAssessmentComparator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Assignment of the Autoévaluation nature: the student estimates their grade, the teacher follows
 * the estimates received (design_handoff_carnet_de_notes, PROMPT_MODIFICATIONS §9 - screens 5b, 5c
 * and 5d; creation, 5a, happens in the cahier de texte modal).
 *
 * The estimate lives apart from the grading: nothing that happens here writes into the carnet de
 * notes, and the student only sees the teacher's grade under the conditions laid down by
 * App\Service\SelfAssessmentComparator::isComparisonReadable().
 */
class SelfAssessmentController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'self_assessment';

    /**
     * Student screen: entering the estimate (5b), or comparing it with the teacher's grading (5c)
     * once the estimate is submitted and the grading shared then published.
     */
    #[Route(path: '/student-work/{assignmentId}/self-assessment', name: 'app_student_self_assessment', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function student(
        int $assignmentId,
        Request $request,
        AssignmentRepository $assignmentRepository,
        SelfAssessmentRepository $selfAssessmentRepository,
        GradeRepository $gradeRepository,
        AssignmentAudienceResolver $audienceResolver,
        SelfAssessmentComparator $comparator,
        EntityManagerInterface $entityManager,
    ): Response {
        $student = $this->currentUser();
        $assignment = $this->findSelfAssessmentWorkOrNotFound($assignmentRepository, $assignmentId);
        $now = new \DateTimeImmutable();

        if (!$assignment->isVisibleFor($now) || !$audienceResolver->isInAudience($assignment, $student)) {
            throw $this->createAccessDeniedException();
        }

        $selfAssessment = $selfAssessmentRepository->findOneForStudent($assignment, $student);
        $questions = $this->questionsOf($assignment);

        if ($request->isMethod('POST')) {
            if (true === $selfAssessment?->isValidated()) {
                // Only one submission possible: that is what the screen promises before entry.
                throw $this->createAccessDeniedException();
            }

            $this->assertCsrf($request);
            $selfAssessment ??= new SelfAssessment($assignment, $student);
            $entityManager->persist($selfAssessment);

            $this->applySubmission($selfAssessment, $questions, $request, $entityManager);

            $validating = $request->request->has('validate');
            if ($validating && !$this->isComplete($selfAssessment, $questions)) {
                $this->addFlash('danger', 'selfAssessmentIncompleteFlashMessage');
            } elseif ($validating) {
                $selfAssessment->validate();
                $this->addFlash('success', 'selfAssessmentValidatedFlashMessage');
            } else {
                $this->addFlash('success', 'selfAssessmentDraftSavedFlashMessage');
            }

            $entityManager->flush();

            return $this->redirectToRoute('app_student_self_assessment', ['assignmentId' => $assignment->getId()]);
        }

        $grade = null === $assignment->getEvaluation()
            ? null
            : $gradeRepository->findOneForEvaluationAndStudent($assignment->getEvaluation(), $student);

        if ($comparator->isComparisonReadable($assignment, $selfAssessment, $grade, $now)) {
            return $this->render('student/self_assessment_comparison.html.twig', [
                'assignment' => $assignment,
                'selfAssessment' => $selfAssessment,
                'grade' => $grade,
                'rows' => $comparator->questionRows($assignment, $selfAssessment, $grade),
                'gap' => $comparator->gap($selfAssessment->getEstimatedValue(), $grade->getValue()),
                'comparator' => $comparator,
            ]);
        }

        return $this->render('student/self_assessment_form.html.twig', [
            'assignment' => $assignment,
            'selfAssessment' => $selfAssessment,
            'questions' => $questions,
            'sections' => $assignment->getEvaluation()?->getRubricSections() ?? [],
            'answered' => $this->answeredCount($selfAssessment, $questions),
        ]);
    }

    /**
     * Teacher tracking (5d): who handed in their self-assessment, and how accurately.
     */
    #[Route(path: '/programs/{id}/lesson-log/assignments/{assignmentId}/self-assessments', name: 'app_program_self_assessments')]
    #[IsGranted('ROLE_USER')]
    public function tracking(
        int $id,
        int $assignmentId,
        ProgramRepository $programRepository,
        AssignmentRepository $assignmentRepository,
        SelfAssessmentRepository $selfAssessmentRepository,
        GradeRepository $gradeRepository,
        ProgramStudentOptionRepository $studentOptionRepository,
        AssignmentAudienceResolver $audienceResolver,
        StructureAccessChecker $accessChecker,
        SelfAssessmentComparator $comparator,
    ): Response {
        $program = $programRepository->find($id) ?? throw $this->createNotFoundException();
        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        $assignment = $this->findSelfAssessmentWorkOrNotFound($assignmentRepository, $assignmentId);
        if ($assignment->getProgram() !== $program) {
            throw $this->createNotFoundException();
        }

        $selfAssessmentsByStudentId = $selfAssessmentRepository->findByStudentIdForAssignment($assignment);
        $optionsByStudentId = $studentOptionRepository->findOptionsByStudentForProgram($program);
        $gradesByStudentId = [];
        foreach (null === $assignment->getEvaluation() ? [] : $gradeRepository->findForEvaluation($assignment->getEvaluation()) as $grade) {
            $gradesByStudentId[$grade->getStudent()->getId()] = $grade;
        }

        $recipients = $audienceResolver->resolveAudience($assignment);
        usort($recipients, static fn (User $a, User $b): int => mb_strtolower(($a->getLastname() ?? '').' '.($a->getFirstname() ?? ''))
            <=> mb_strtolower(($b->getLastname() ?? '').' '.($b->getFirstname() ?? '')));

        $rows = [];
        foreach ($recipients as $recipient) {
            $selfAssessment = $selfAssessmentsByStudentId[$recipient->getId()] ?? null;
            $grade = $gradesByStudentId[$recipient->getId()] ?? null;
            $option = ($optionsByStudentId[$recipient->getId()] ?? [])[0] ?? null;

            $rows[] = [
                'student' => $recipient,
                'option' => $option,
                'selfAssessment' => $selfAssessment,
                'estimated' => true === $selfAssessment?->isValidated() ? $selfAssessment->getEstimatedValue() : null,
                'graded' => $grade?->getValue(),
                'gap' => true === $selfAssessment?->isValidated()
                    ? $comparator->gap($selfAssessment->getEstimatedValue(), $grade?->getValue())
                    : null,
            ];
        }

        return $this->render('program/self_assessment_tracking.html.twig', [
            'program' => $program,
            'assignment' => $assignment,
            'rows' => $rows,
            'summary' => $comparator->summary($rows),
            'questionCount' => \count($this->questionsOf($assignment)),
            'comparator' => $comparator,
        ]);
    }

    /**
     * The « détail → » of the tracking screen (5d): the question-by-question comparison for a given
     * student, as they see it themselves (5c). Reserved for the program's teachers - a student
     * reaching this address would see somebody else's estimates.
     */
    #[Route(path: '/programs/{id}/lesson-log/assignments/{assignmentId}/self-assessments/{studentId}', name: 'app_program_self_assessment_detail')]
    #[IsGranted('ROLE_USER')]
    public function trackingDetail(
        int $id,
        int $assignmentId,
        int $studentId,
        ProgramRepository $programRepository,
        AssignmentRepository $assignmentRepository,
        SelfAssessmentRepository $selfAssessmentRepository,
        GradeRepository $gradeRepository,
        StructureAccessChecker $accessChecker,
        SelfAssessmentComparator $comparator,
    ): Response {
        $program = $programRepository->find($id) ?? throw $this->createNotFoundException();
        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        $assignment = $this->findSelfAssessmentWorkOrNotFound($assignmentRepository, $assignmentId);
        if ($assignment->getProgram() !== $program) {
            throw $this->createNotFoundException();
        }

        $student = $program->getStudents()->filter(static fn (User $s): bool => $s->getId() === $studentId)->first()
            ?: throw $this->createNotFoundException();

        $selfAssessment = $selfAssessmentRepository->findOneForStudent($assignment, $student);
        if (null === $selfAssessment || !$selfAssessment->isValidated()) {
            throw $this->createNotFoundException();
        }

        $grade = $gradeRepository->findOneForEvaluationAndStudent($assignment->getEvaluation(), $student);

        return $this->render('student/self_assessment_comparison.html.twig', [
            'assignment' => $assignment,
            'selfAssessment' => $selfAssessment,
            'grade' => $grade,
            'rows' => $comparator->questionRows($assignment, $selfAssessment, $grade),
            'gap' => $comparator->gap($selfAssessment->getEstimatedValue(), $grade?->getValue()),
            'comparator' => $comparator,
            // The same page seen by the teacher: it then names the student and leads back to the tracking screen.
            'viewedStudent' => $student,
        ]);
    }

    /**
     * Reads the form input back: one box per rubric question, or the overall estimate when the
     * evaluation has no rubric. The total is always written on the self-assessment - the sum of the
     * questions where applicable - so the comparison does not have to recompute it later.
     *
     * @param list<EvaluationRubricQuestion> $questions
     */
    private function applySubmission(SelfAssessment $selfAssessment, array $questions, Request $request, EntityManagerInterface $entityManager): void
    {
        $selfAssessment->touch();

        if ([] === $questions) {
            $selfAssessment->setEstimatedValue($this->clamp(
                $request->request->get('estimation'),
                $selfAssessment->getAssignment()?->getEvaluation()?->getScale() ?? 20.0,
            ));

            return;
        }

        $submitted = PostValue::all($request, 'questions');
        $total = 0.0;
        $any = false;

        foreach ($questions as $question) {
            $answer = $selfAssessment->answerFor($question);
            if (null === $answer) {
                $answer = new SelfAssessmentAnswer($selfAssessment, $question);
                $selfAssessment->addAnswer($answer);
                $entityManager->persist($answer);
            }

            $points = $this->clamp($submitted[$question->getId()] ?? null, $question->getMaxPoints());
            $answer->setEstimatedPoints($points);

            if (null !== $points) {
                $any = true;
                $total += $points;
            }
        }

        $selfAssessment->setEstimatedValue($any ? round($total, 2) : null);
    }

    private function clamp(mixed $raw, float $max): ?float
    {
        $normalized = \is_scalar($raw) ? str_replace(',', '.', trim((string) $raw)) : '';
        if ('' === $normalized || !is_numeric($normalized)) {
            return null;
        }

        return round(max(0.0, min($max, (float) $normalized)), 2);
    }

    /** @param list<EvaluationRubricQuestion> $questions */
    private function isComplete(SelfAssessment $selfAssessment, array $questions): bool
    {
        if ([] === $questions) {
            return null !== $selfAssessment->getEstimatedValue();
        }

        return $this->answeredCount($selfAssessment, $questions) === \count($questions);
    }

    /** @param list<EvaluationRubricQuestion> $questions */
    private function answeredCount(?SelfAssessment $selfAssessment, array $questions): int
    {
        if (null === $selfAssessment) {
            return 0;
        }

        return \count(array_filter(
            $questions,
            static fn (EvaluationRubricQuestion $question): bool => null !== $selfAssessment->answerFor($question)?->getEstimatedPoints(),
        ));
    }

    /** @return list<EvaluationRubricQuestion> */
    private function questionsOf(Assignment $assignment): array
    {
        $questions = [];
        foreach ($assignment->getEvaluation()?->getRubricSections() ?? [] as $section) {
            foreach ($section->getQuestions() as $question) {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    private function findSelfAssessmentWorkOrNotFound(AssignmentRepository $repository, int $assignmentId): Assignment
    {
        $assignment = $repository->find($assignmentId) ?? throw $this->createNotFoundException();
        if (!$assignment->getNature()->expectsSelfAssessment() || null === $assignment->getEvaluation()) {
            throw $this->createNotFoundException();
        }

        return $assignment;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
