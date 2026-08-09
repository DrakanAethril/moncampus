<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Form\InternshipStudentEvaluationType;
use App\Form\InternshipTutorEvaluationType;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SkillLevelRepository;
use App\Service\InternshipTutorEvaluationBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les écrans de suivi des évaluations : où en sont tuteurs et alternants, et la saisie de chacune.
 *
 * Split out of the former ProgramInternshipController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class InternshipEvaluationStatusController extends AbstractController
{
    use ProgramInternshipTrait;

    // One row per (active InternshipTutorLink x active InternshipEvaluationPeriod) for the
    // program - a fuller, always-visible picture than the "Rappels" screen above, which only
    // ever shows one selected period's pending list. Sorted late-first so the most urgent rows
    // surface immediately; clicking any row (submitted or not) opens tutorEvaluation() below to
    // view/edit it on the tutor's behalf.
    #[Route(path: '/programs/{id}/internship/tutors/evaluations', name: 'app_program_internship_tutors_evaluations')]
    public function tutorEvaluationsStatus(int $id, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $evaluationPeriods = $evaluationPeriodRepository->findAllActiveForProgram($program);

        $rows = [];
        foreach ($tutorLinkRepository->findAllActiveForProgram($program) as $tutorLink) {
            $evaluationsByPeriodId = [];
            foreach ($tutorEvaluationRepository->findAllForTutorLink($tutorLink) as $evaluation) {
                $evaluationsByPeriodId[$evaluation->getEvaluationPeriod()->getId()] = $evaluation;
            }

            foreach ($evaluationPeriods as $evaluationPeriod) {
                $evaluation = $evaluationsByPeriodId[$evaluationPeriod->getId()] ?? null;

                $rows[] = [
                    'tutorLink' => $tutorLink,
                    'evaluationPeriod' => $evaluationPeriod,
                    'evaluation' => $evaluation,
                    'status' => match (true) {
                        null !== $evaluation => 'submitted',
                        $evaluationPeriod->isPast() => 'late',
                        default => 'pending',
                    },
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => self::evaluationStatusSortWeight($a['status']) <=> self::evaluationStatusSortWeight($b['status']));

        return $this->render('program/internship_tutor_evaluations_status.html.twig', [
            'program' => $program,
            'rows' => $rows,
        ]);
    }

    private static function evaluationStatusSortWeight(string $status): int
    {
        return match ($status) {
            'late' => 0,
            'pending' => 1,
            'submitted' => 2,
            // Unknown statuses sort last rather than throwing an UnhandledMatchError, which would
            // take the whole screen down over a single unexpected row.
            default => 3,
        };
    }

    // Staff view/edit of an InternshipTutorEvaluation on the tutor's own behalf - same
    // InternshipTutorEvaluationBuilder find-or-create + pre-population logic and the same
    // InternshipTutorEvaluationType form as the tutor's own InternshipTutorEvaluationController::
    // evaluate(), just reached from the staff status screen above instead of ROLE_TUTOR's own
    // area, and stamping $lastEditedBy with the staff member instead of the tutor.
    #[Route(path: '/programs/{id}/internship/tutors/{tutorLinkId}/evaluations/{evaluationPeriodId}', name: 'app_program_internship_tutors_evaluation', requirements: ['evaluationPeriodId' => '\d+'])]
    public function tutorEvaluation(int $id, int $tutorLinkId, int $evaluationPeriodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTutorEvaluationBuilder $evaluationBuilder, SkillLevelRepository $skillLevelRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);
        $evaluationPeriod = $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId);

        ['evaluation' => $evaluation, 'isEdit' => $isEdit, 'skillGroups' => $skillGroups] = $evaluationBuilder->findOrPrepare($tutorLink, $evaluationPeriod);

        $skillLevels = $skillLevelRepository->findAllActiveForProgramOrGlobal($program);
        $form = $this->createForm(InternshipTutorEvaluationType::class, $evaluation, ['skillLevelChoices' => $skillLevels]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setValidationDate(new \DateTimeImmutable());
            $entity->setLastEditedBy($this->currentUser());
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'internshipTutorEvaluationSavedFlashMessage');

            return $this->redirectToRoute('app_program_internship_tutors_evaluations', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_tutor_evaluation.html.twig', [
            'form' => $form,
            'program' => $program,
            'tutorLink' => $tutorLink,
            'period' => $evaluationPeriod,
            'skillGroups' => $skillGroups,
        ]);
    }

    // One row per (Program student x active InternshipEvaluationPeriod) - same shape as
    // tutorEvaluationsStatus() above, for student self-evaluations instead of tutor ones.
    #[Route(path: '/programs/{id}/internship/students/evaluations', name: 'app_program_internship_students_evaluations')]
    public function studentEvaluationsStatus(int $id, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $evaluationPeriods = $evaluationPeriodRepository->findAllActiveForProgram($program);

        $rows = [];
        foreach ($program->getStudents() as $student) {
            $evaluationsByPeriodId = [];
            foreach ($studentEvaluationRepository->findAllForStudentAndProgram($student, $program) as $evaluation) {
                $evaluationsByPeriodId[$evaluation->getEvaluationPeriod()->getId()] = $evaluation;
            }

            foreach ($evaluationPeriods as $evaluationPeriod) {
                $evaluation = $evaluationsByPeriodId[$evaluationPeriod->getId()] ?? null;

                $rows[] = [
                    'student' => $student,
                    'evaluationPeriod' => $evaluationPeriod,
                    'evaluation' => $evaluation,
                    'status' => match (true) {
                        null !== $evaluation => 'submitted',
                        $evaluationPeriod->isPast() => 'late',
                        default => 'pending',
                    },
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => self::evaluationStatusSortWeight($a['status']) <=> self::evaluationStatusSortWeight($b['status']));

        return $this->render('program/internship_student_evaluations_status.html.twig', [
            'program' => $program,
            'rows' => $rows,
        ]);
    }

    // Staff view/edit of an InternshipStudentEvaluation on the student's own behalf - same form
    // as the student's own ProgramInternshipEvaluationController::myEvaluation(), just reached
    // from the staff status screen above and stamping $lastEditedBy with the staff member.
    #[Route(path: '/programs/{id}/internship/students/{studentId}/evaluations/{evaluationPeriodId}', name: 'app_program_internship_students_evaluation', requirements: ['evaluationPeriodId' => '\d+'])]
    public function studentEvaluation(int $id, int $studentId, int $evaluationPeriodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $student = $this->findProgramStudentOrNotFound($program, $studentId);
        $evaluationPeriod = $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId);

        $evaluation = $studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $evaluationPeriod);
        $isEdit = null !== $evaluation;

        if (!$isEdit) {
            $evaluation = new InternshipStudentEvaluation($student, $program, $evaluationPeriod);
        }

        $form = $this->createForm(InternshipStudentEvaluationType::class, $evaluation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setValidationDate(new \DateTimeImmutable());
            $entity->setLastEditedBy($this->currentUser());
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'internshipStudentEvaluationSavedFlashMessage');

            return $this->redirectToRoute('app_program_internship_students_evaluations', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_student_evaluation.html.twig', [
            'form' => $form,
            'program' => $program,
            'student' => $student,
            'period' => $evaluationPeriod,
        ]);
    }

    private function findProgramStudentOrNotFound(Program $program, int $studentId): User
    {
        return $this->resolveProgramStudent($program, $studentId) ?? throw $this->createNotFoundException();
    }
}
