<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Assignment;
use App\Entity\AssignmentSubmission;
use App\Entity\AssignmentSubmissionFile;
use App\Entity\AssignmentView;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentSubmissionStatus;
use App\Enum\Feature;
use App\Form\AssignmentSubmissionFileType;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionFileRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\AssignmentViewRepository;
use App\Repository\ProgramRepository;
use App\Security\Voter\AssignmentVoter;
use App\Service\AssignmentAudienceResolver;
use App\Service\AssignmentGradebookLinker;
use App\Service\FileUploadService;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// A student's own "my assignments" self-service view - route-level guards (not a class-level
// staff gate), same shape as ProgramInternshipEvaluationController. Whether a given Assignment is
// actually reachable is decided per-object by AssignmentVoter::SUBMIT (audience membership), not
// just the ROLE_STUDENT check, since not every student in a Program is in every Assignment's
// audience (see design/validated/assignment-submission-box.md).
#[RequiresFeature(Feature::StudentWork)]
class ProgramAssignmentSubmissionController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    private const string SUBMISSION_UPLOAD_PREFIX = 'assignment-submissions/';

    #[Route(path: '/programs/{id}/assignments', name: 'app_program_my_assignments')]
    #[IsGranted('ROLE_STUDENT')]
    public function myAssignments(int $id, ProgramRepository $repository, AssignmentRepository $assignmentRepository, AssignmentSubmissionRepository $submissionRepository, AssignmentAudienceResolver $audienceResolver): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $student = $this->currentUser();

        $rows = [];
        foreach ($assignmentRepository->findForProgram($program) as $assignment) {
            if (!$audienceResolver->isInAudience($assignment, $student)) {
                continue;
            }

            // One submission per expected production once the assignment spells them out - the
            // status reads on the first of them, which is when the student engaged.
            $submission = $submissionRepository->findForAssignmentAndStudent($assignment, $student)[0] ?? null;
            $status = match (true) {
                null === $submission => AssignmentSubmissionStatus::Missing,
                $assignment->isLate($submission->getSubmittedAt()) => AssignmentSubmissionStatus::Late,
                default => AssignmentSubmissionStatus::Submitted,
            };

            $rows[] = ['assignment' => $assignment, 'status' => $status];
        }

        return $this->render('program/my_assignments.html.twig', [
            'program' => $program,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/programs/{id}/assignments/{assignmentId}', name: 'app_program_my_assignment', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function show(int $id, int $assignmentId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, AssignmentRepository $assignmentRepository, AssignmentSubmissionRepository $submissionRepository, AssignmentViewRepository $viewRepository, FileUploadService $fileUploadService, UploadIntake $uploadIntake, AssignmentGradebookLinker $gradebookLinker): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $assignment = $this->findAssignmentForStudentOrNotFound($assignmentRepository, $program, $assignmentId);
        $student = $this->currentUser();

        // Opening an assignment's page is becoming aware of it: the trace is written here, and it is
        // what feeds the cahier de texte's « ouvert par ». An observed fact rather than a declaration
        // - the student does not choose to produce it.
        $view = $viewRepository->findOneFor($assignment, $student);
        $view ? $view->registerView() : $entityManager->persist($view = new AssignmentView($assignment, $student));
        $entityManager->flush();

        // This page keeps one box for the whole assignment, where "Travail à faire" gives each
        // expected production its own. It therefore reads every submission and writes to the first
        // production, so both screens keep seeing the same rows rather than two parallel sets.
        $submissions = $submissionRepository->findForAssignmentAndStudent($assignment, $student);
        $production = $assignment->getExpectedProductions()->first() ?: null;

        // Announce-only natures (à réviser/à préparer/à lire) have no submission box - the page
        // still shows the assignment details, but never builds or accepts the upload form.
        if (!$assignment->expectsSubmission()) {
            return $this->render('program/my_assignment.html.twig', [
                'program' => $program,
                'assignment' => $assignment,
                'submissions' => [],
                'form' => null,
            ]);
        }

        $form = $this->createForm(AssignmentSubmissionFileType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \App\Service\StagedUpload $file */
            $file = $form->get('file')->getData();

            $submission = $submissionRepository->findOneForAssignmentAndStudent($assignment, $student, $production)
                ?? new AssignmentSubmission($assignment, $student, $production);

            $extension = UploadIntake::extension($file);
            $key = $uploadIntake->store(
                $file,
                self::SUBMISSION_UPLOAD_PREFIX,
                sprintf('%d-%d-%s.%s', $assignment->getId(), $student->getId(), bin2hex(random_bytes(4)), $extension),
            );
            $submissionFile = new AssignmentSubmissionFile($submission, $key, UploadIntake::originalName($file));

            // « Une évaluation est créée automatiquement dans le carnet de notes à la réception des
            // rendus » (2a) - on reception, therefore here, and not on the assignment's publication.
            $gradebookLinker->ensureEvaluationExists($assignment);

            $entityManager->persist($submission);
            $entityManager->persist($submissionFile);
            $entityManager->flush();

            $this->addFlash('success', 'assignmentSubmissionUploadedFlashMessage');

            return $this->redirectToRoute('app_program_my_assignment', ['id' => $program->getId(), 'assignmentId' => $assignment->getId()]);
        }

        return $this->render('program/my_assignment.html.twig', [
            'program' => $program,
            'assignment' => $assignment,
            'submissions' => $submissions,
            'form' => $form,
        ]);
    }

    #[Route(path: '/programs/{id}/assignments/{assignmentId}/files/{fileId}/delete', name: 'app_program_my_assignment_files_delete', methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function deleteFile(int $id, int $assignmentId, int $fileId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, AssignmentRepository $assignmentRepository, AssignmentSubmissionFileRepository $fileRepository, FileUploadService $fileUploadService): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $assignment = $this->findAssignmentForStudentOrNotFound($assignmentRepository, $program, $assignmentId);
        $student = $this->currentUser();

        $file = $fileRepository->find($fileId) ?? throw $this->createNotFoundException();

        // A student may only ever delete their own file, on their own submission for this exact
        // Assignment - not just "is a file with this id" (that alone would let one student delete
        // another's upload by guessing/incrementing the id in the URL).
        if ($file->getSubmission()->getAssignment()->getId() !== $assignment->getId() || $file->getSubmission()->getStudent() !== $student) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('assignment_submission_file_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $fileUploadService->delete($file->getStorageKey());
        $entityManager->remove($file);
        $entityManager->flush();

        $this->addFlash('success', 'assignmentSubmissionFileRemovedFlashMessage');

        return $this->redirectToRoute('app_program_my_assignment', ['id' => $program->getId(), 'assignmentId' => $assignment->getId()]);
    }

    private function findProgramForStudentOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($this->currentUser())) {
            throw $this->createNotFoundException();
        }

        $this->assertProgramFeatureEnabled($program->isAssignmentManagementEnabled());

        return $program;
    }

    private function findAssignmentForStudentOrNotFound(AssignmentRepository $repository, Program $program, int $assignmentId): Assignment
    {
        $assignment = $repository->find($assignmentId) ?? throw $this->createNotFoundException();

        if ($assignment->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(AssignmentVoter::SUBMIT, $assignment);

        return $assignment;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
