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
use App\Service\SelfAssessmentComparator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Travail de nature Autoévaluation : l'étudiant estime sa note, l'enseignant suit les estimations
 * reçues (design_handoff_carnet_de_notes, PROMPT_MODIFICATIONS §9 - écrans 5b, 5c et 5d ; la
 * création, 5a, se fait dans le modal du cahier de texte).
 *
 * L'estimation vit à part de la notation : rien de ce qui se passe ici n'écrit dans le carnet de
 * notes, et l'étudiant ne voit la note de l'enseignant qu'aux conditions posées par
 * App\Service\SelfAssessmentComparator::isComparisonReadable().
 */
class SelfAssessmentController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'self_assessment';

    /**
     * Écran étudiant : saisie de l'estimation (5b), ou comparaison avec la notation de l'enseignant
     * (5c) une fois l'estimation validée et la notation partagée puis publiée.
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
                // Une seule validation possible : c'est ce que l'écran promet avant la saisie.
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
     * Suivi enseignant (5d) : qui a rendu son autoévaluation, avec quelle justesse.
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
     * Le « détail → » du suivi (5d) : la comparaison question par question d'un élève donné, telle
     * que lui-même la voit (5c). Réservée aux enseignants de la formation - un étudiant qui
     * atteindrait cette adresse verrait les estimations d'un autre.
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
            // La même page vue par l'enseignant : elle nomme alors l'élève et revient au suivi.
            'viewedStudent' => $student,
        ]);
    }

    /**
     * Reprend la saisie du formulaire : une case par question du barème, ou l'estimation globale
     * quand l'évaluation n'en a pas. Le total est toujours écrit sur l'autoévaluation - somme des
     * questions le cas échéant - pour que la comparaison n'ait pas à le recalculer plus tard.
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

        $submitted = $request->request->all('questions');
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
