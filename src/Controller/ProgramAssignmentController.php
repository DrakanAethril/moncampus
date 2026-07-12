<?php

namespace App\Controller;

use App\Entity\Assignment;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentSubmissionStatus;
use App\Form\AssignmentType;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Service\AssignmentAudienceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Staff-facing Assignment management - sibling of ProgramTimetableSettingsController/
// ProgramInternshipController under the "Paramétrage" dropend (see templates/layout/app.html.twig
// and design/validated/assignment-submission-box.md). The student-facing "my assignments"
// self-service side lives in ProgramAssignmentSubmissionController instead.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgramAssignmentController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/settings/assignments', name: 'app_program_assignments')]
    public function list(int $id, ProgramRepository $repository, AssignmentRepository $assignmentRepository, AssignmentSubmissionRepository $submissionRepository, AssignmentAudienceResolver $audienceResolver): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $assignments = $assignmentRepository->findForProgram($program);

        $rows = array_map(function (Assignment $assignment) use ($submissionRepository, $audienceResolver): array {
            $audience = $audienceResolver->resolveAudience($assignment);
            $submissionsByStudentId = $submissionRepository->findAllByStudentIdForAssignment($assignment);

            return [
                'assignment' => $assignment,
                'audienceCount' => \count($audience),
                'submittedCount' => \count(array_intersect_key($submissionsByStudentId, array_flip(array_map(static fn (User $u): int => $u->getId(), $audience)))),
            ];
        }, $assignments);

        return $this->render('program/assignments.html.twig', [
            'program' => $program,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/assignments/new', name: 'app_program_assignments_new')]
    #[Route(path: '/programs/{id}/settings/assignments/{assignmentId}/edit', name: 'app_program_assignments_edit')]
    public function form(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, AssignmentRepository $assignmentRepository, UserRepository $userRepository, ?int $assignmentId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $assignment = null !== $assignmentId ? $this->findAssignmentOrNotFound($assignmentRepository, $program, $assignmentId) : null;
        $isEdit = null !== $assignment;

        if (!$isEdit) {
            $assignment = new Assignment($program);
        }

        $form = $this->createForm(AssignmentType::class, $assignment, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();

            // Only the field matching the submitted audienceType is meaningful - clear the other
            // two so a stale value from a previous edit never lingers (see AssignmentType's
            // "shown at once, no JS toggling in the form definition" comment).
            if (AssignmentAudienceType::Option !== $entity->getAudienceType()) {
                foreach ($entity->getOptions()->toArray() as $option) {
                    $entity->removeOption($option);
                }
            }

            foreach ($entity->getManualRecipients()->toArray() as $recipient) {
                $entity->removeManualRecipient($recipient);
            }
            if (AssignmentAudienceType::Manual === $entity->getAudienceType()) {
                $submittedIds = array_map('intval', $request->request->all('manual_recipients'));
                foreach ($userRepository->findByIdsForProgram($program, $submittedIds) as $student) {
                    $entity->addManualRecipient($student);
                }
            }

            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'assignmentUpdatedFlashMessage' : 'assignmentCreatedFlashMessage');

            return $this->redirectToRoute('app_program_assignments', ['id' => $program->getId()]);
        }

        return $this->render('program/assignment_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    // Backs the select2 ajax widget for manualRecipients (see AssignmentType's class docblock) -
    // returns just the matching page of students, never the whole roster.
    #[Route(path: '/programs/{id}/settings/assignments/students-search', name: 'app_program_assignments_students_search')]
    public function studentsSearch(int $id, Request $request, ProgramRepository $repository, UserRepository $userRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $limit = 20;

        $students = $userRepository->searchStudentsForProgram($program, $request->query->get('q'), $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], $students),
            'pagination' => ['more' => \count($students) === $limit],
        ]);
    }

    #[Route(path: '/programs/{id}/settings/assignments/{assignmentId}', name: 'app_program_assignments_show')]
    public function show(int $id, int $assignmentId, ProgramRepository $repository, AssignmentRepository $assignmentRepository, AssignmentSubmissionRepository $submissionRepository, AssignmentAudienceResolver $audienceResolver): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $assignment = $this->findAssignmentOrNotFound($assignmentRepository, $program, $assignmentId);

        $audience = $this->sortedByName($audienceResolver->resolveAudience($assignment));
        $submissionsByStudentId = $submissionRepository->findAllByStudentIdForAssignment($assignment);

        $rows = array_map(static function (User $student) use ($assignment, $submissionsByStudentId): array {
            $submission = $submissionsByStudentId[$student->getId()] ?? null;
            $status = match (true) {
                null === $submission => AssignmentSubmissionStatus::Missing,
                $assignment->isLate($submission->getSubmittedAt()) => AssignmentSubmissionStatus::Late,
                default => AssignmentSubmissionStatus::Submitted,
            };

            return ['student' => $student, 'submission' => $submission, 'status' => $status];
        }, $audience);

        return $this->render('program/assignment_show.html.twig', [
            'program' => $program,
            'assignment' => $assignment,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/assignments/{assignmentId}/remove', name: 'app_program_assignments_remove', methods: ['POST'])]
    public function remove(int $id, int $assignmentId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, AssignmentRepository $assignmentRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $assignment = $this->findAssignmentOrNotFound($assignmentRepository, $program, $assignmentId);

        if (!$this->isCsrfTokenValid('program_assignments_remove', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($assignment);
        $entityManager->flush();

        $this->addFlash('success', 'assignmentRemovedFlashMessage');

        return $this->redirectToRoute('app_program_assignments', ['id' => $program->getId()]);
    }

    /** @param list<User> $users @return list<User> */
    private function sortedByName(array $users): array
    {
        usort($users, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        return $users;
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->assertProgramFeatureEnabled($program->isAssignmentManagementEnabled());

        return $program;
    }

    private function findAssignmentOrNotFound(AssignmentRepository $repository, Program $program, int $assignmentId): Assignment
    {
        $assignment = $repository->find($assignmentId) ?? throw $this->createNotFoundException();

        if ($assignment->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $assignment;
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
