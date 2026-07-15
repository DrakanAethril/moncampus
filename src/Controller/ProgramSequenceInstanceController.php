<?php

namespace App\Controller;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Form\ScheduleSeanceType;
use App\Repository\ProgramRepository;
use App\Repository\SeanceInstanceRepository;
use App\Repository\SequenceInstanceRepository;
use App\Security\StructureAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Program-scoped browsing of instantiated séquences/séances (SequenceInstance/SeanceInstance) and
// the "schedule" step that turns an unscheduled SeanceInstance into a real LessonSession - see
// design/validated/teaching-sequence-library.md. Restricted to ROLE_ADMIN only (unlike the
// Bibliothèque/library side in SequenceLibraryController, which stays open to teachers/staff too)
// - the program visibility/staff-or-creator checks below predate this and are now always true for
// an admin, but are left in place since they're still correct, just no longer reachable by anyone
// else.
#[IsGranted('ROLE_ADMIN')]
class ProgramSequenceInstanceController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/sequences', name: 'app_program_sequences')]
    public function list(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, SequenceInstanceRepository $sequenceInstanceRepository, SeanceInstanceRepository $seanceInstanceRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/sequences.html.twig', [
            'program' => $program,
            'sequenceInstances' => $sequenceInstanceRepository->findForProgram($program),
            'standaloneSeanceInstances' => $seanceInstanceRepository->findStandaloneForProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/sequences/{sequenceInstanceId}', name: 'app_program_sequences_show', requirements: ['sequenceInstanceId' => '\d+'])]
    public function show(int $id, int $sequenceInstanceId, ProgramRepository $repository, StructureAccessChecker $accessChecker, SequenceInstanceRepository $sequenceInstanceRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $sequenceInstance = $sequenceInstanceRepository->find($sequenceInstanceId) ?? throw $this->createNotFoundException();

        if ($sequenceInstance->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('program/sequence_instance_show.html.twig', [
            'program' => $program,
            'sequenceInstance' => $sequenceInstance,
        ]);
    }

    #[Route(path: '/programs/{id}/sequences/seances/{seanceInstanceId}/schedule', name: 'app_program_sequence_seance_schedule')]
    public function schedule(int $id, int $seanceInstanceId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, StructureAccessChecker $accessChecker, SeanceInstanceRepository $seanceInstanceRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $seanceInstance = $seanceInstanceRepository->find($seanceInstanceId) ?? throw $this->createNotFoundException();

        if ($seanceInstance->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$accessChecker->isStaff() && $seanceInstance->getCreatedBy() !== $this->currentUser()) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(ScheduleSeanceType::class, null, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $day = $form->get('day')->getData();
            $startHour = $form->get('startHour')->getData();
            $endHour = $form->get('endHour')->getData();

            $lessonSession = new LessonSession($program);
            $lessonSession->setDay($day);
            $lessonSession->setStartHour($startHour);
            $lessonSession->setEndHour($endHour);
            $lessonSession->setTitle($seanceInstance->getTitre());
            $lessonSession->setLength($seanceInstance->getDuree() ?? '0');
            $lessonSession->setTeacher($this->resolveProgramTeacher($program, $request->request->get('teacher')));
            $lessonSession->setClassRoom($form->get('classRoom')->getData());

            $seanceInstance->setLessonSession($lessonSession);

            $entityManager->persist($lessonSession);
            $entityManager->flush();

            $this->addFlash('success', 'seanceScheduledFlashMessage');

            return $this->redirectToRoute(
                null !== $seanceInstance->getSequenceInstance() ? 'app_program_sequences_show' : 'app_program_sequences',
                null !== $seanceInstance->getSequenceInstance()
                    ? ['id' => $program->getId(), 'sequenceInstanceId' => $seanceInstance->getSequenceInstance()->getId()]
                    : ['id' => $program->getId()],
            );
        }

        return $this->render('program/schedule_seance.html.twig', [
            'program' => $program,
            'seanceInstance' => $seanceInstance,
            'form' => $form,
        ]);
    }

    // Backs the teacher ajax tom-select field in schedule_seance.html.twig - only the program's
    // own teachers are eligible, same convention as
    // ProgramTimetableSettingsController::teachersSearch().
    #[Route(path: '/programs/{id}/sequences/teachers-search', name: 'app_program_sequence_teachers_search')]
    public function teachersSearch(int $id, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker): JsonResponse
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $limit = 20;
        $query = mb_strtolower((string) $request->query->get('q', ''));

        $candidates = array_values(array_filter(
            $program->getTeachers()->toArray(),
            static fn (User $user): bool => '' === $query || str_contains(mb_strtolower($user->getDisplayName() ?? $user->getUsername()), $query),
        ));

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], \array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => \count($candidates) > $limit],
        ]);
    }

    // Students/teachers see every séquence for a Program they're visible in (same rule as the
    // timetable/lesson-log read side) - staff always, others per StructureAccessChecker. Also
    // requires timetableManagementEnabled, same as LessonLogController - a SeanceInstance only
    // ever becomes useful by eventually backing a real LessonSession via schedule(), so a Program
    // with the timetable feature off is a dead end for this whole area, not just a display quirk.
    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramVisible($program)) {
            throw $this->createAccessDeniedException();
        }

        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        return $program;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    // Re-resolves and re-checks the submitted teacher id server-side rather than trusting it -
    // same reasoning as LaptopController::resolveActiveBorrower(). Optional field: a non-numeric
    // or blank id (nothing picked) simply clears it.
    private function resolveProgramTeacher(Program $program, mixed $teacherId): ?User
    {
        if (!is_numeric($teacherId)) {
            return null;
        }

        foreach ($program->getTeachers() as $teacher) {
            if ($teacher->getId() === (int) $teacherId) {
                return $teacher;
            }
        }

        return null;
    }
}
