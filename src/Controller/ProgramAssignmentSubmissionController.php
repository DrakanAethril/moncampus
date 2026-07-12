<?php

namespace App\Controller;

use App\Entity\Assignment;
use App\Entity\AssignmentSubmission;
use App\Entity\AssignmentSubmissionFile;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentSubmissionStatus;
use App\Form\AssignmentSubmissionFileType;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionFileRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\ProgramRepository;
use App\Security\Voter\AssignmentVoter;
use App\Service\AssignmentAudienceResolver;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// A student's own "my assignments" self-service view - route-level guards (not a class-level
// staff gate), same shape as ProgramInternshipEvaluationController. Whether a given Assignment is
// actually reachable is decided per-object by AssignmentVoter::SUBMIT (audience membership), not
// just the ROLE_STUDENT check, since not every student in a Program is in every Assignment's
// audience (see design/validated/assignment-submission-box.md).
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

            $submission = $submissionRepository->findOneForAssignmentAndStudent($assignment, $student);
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
    public function show(int $id, int $assignmentId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, AssignmentRepository $assignmentRepository, AssignmentSubmissionRepository $submissionRepository, FileUploadService $fileUploadService): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $assignment = $this->findAssignmentForStudentOrNotFound($assignmentRepository, $program, $assignmentId);
        $student = $this->currentUser();

        $submission = $submissionRepository->findOneForAssignmentAndStudent($assignment, $student);

        $form = $this->createForm(AssignmentSubmissionFileType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();

            if (null === $submission) {
                $submission = new AssignmentSubmission($assignment, $student);
            }

            $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
            $key = $fileUploadService->upload(
                self::SUBMISSION_UPLOAD_PREFIX,
                sprintf('%d-%d-%s.%s', $assignment->getId(), $student->getId(), bin2hex(random_bytes(4)), $extension),
                $file,
            );
            $submissionFile = new AssignmentSubmissionFile($submission, $key, $file->getClientOriginalName());

            $entityManager->persist($submission);
            $entityManager->persist($submissionFile);
            $entityManager->flush();

            $this->addFlash('success', 'assignmentSubmissionUploadedFlashMessage');

            return $this->redirectToRoute('app_program_my_assignment', ['id' => $program->getId(), 'assignmentId' => $assignment->getId()]);
        }

        return $this->render('program/my_assignment.html.twig', [
            'program' => $program,
            'assignment' => $assignment,
            'submission' => $submission,
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
