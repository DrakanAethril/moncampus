<?php

namespace App\Controller;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\ProgramTeacherOptionRepository;
use App\Security\StructureAccessChecker;
use App\Service\LessonSessionEventFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Reached via the Section > Année scolaire > Classe nav menu. The "Paramétrage" entry lives in
// ProgramSettingsController instead, since it's grown into its own tabbed feature.
class ProgramController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/students', name: 'app_program_students')]
    public function students(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramStudentOptionRepository $studentOptionRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/students.html.twig', [
            'program' => $program,
            'students' => $this->sortedByName($program->getStudents()->toArray()),
            'optionsByStudentId' => $studentOptionRepository->findOptionsByStudentForProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/teachers', name: 'app_program_teachers')]
    public function teachers(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramTeacherOptionRepository $teacherOptionRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/teachers.html.twig', [
            'program' => $program,
            'teachers' => $this->sortedByName($program->getTeachers()->toArray()),
            'optionsByTeacherId' => $teacherOptionRepository->findOptionsByTeacherForProgram($program),
            // Students see "S. Tharaud" instead of "Sébastien Tharaud" - teachers/staff viewing
            // this same page (e.g. a teacher checking their own team) still see the full name.
            'politeNames' => $this->isGranted('ROLE_STUDENT'),
        ]);
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

    #[Route(path: '/programs/{id}/timetable', name: 'app_program_timetable')]
    public function timetable(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        return $this->render('program/timetable.html.twig', ['program' => $program]);
    }

    #[Route(path: '/programs/{id}/timetable/feed', name: 'app_program_timetable_feed')]
    public function timetableFeed(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, LessonSessionRepository $lessonSessionRepository, LessonSessionEventFormatter $eventFormatter): JsonResponse
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());
        $sessions = $lessonSessionRepository->findForProgram($program);

        return $this->json(array_map(
            static fn (LessonSession $session): array => $eventFormatter->format($session, editable: false),
            $sessions,
        ));
    }

    // Students/teachers/timetable pages are reachable under the same rule as the nav entries
    // that link to them: staff/admin see every Program, a student or teacher only one they're
    // actually enrolled in/teaching - see StructureAccessChecker::isProgramVisible().
    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramVisible($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }
}
