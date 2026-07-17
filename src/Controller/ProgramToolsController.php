<?php

namespace App\Controller;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Security\StructureAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Classroom-facing tools reached via the per-program "Outils" nav flyout (between Emploi du temps
// and Syllabus, see templates/layout/app.html.twig) - teacher/staff-only unlike the rest of that
// dropdown, since these are meant to be run live in front of a class, not something a student
// should be able to reach (StructureAccessChecker::isProgramTeacher(), stricter than the plain
// isProgramVisible() every other program-scoped controller here uses).
class ProgramToolsController extends AbstractController
{
    #[Route(path: '/programs/{id}/tools/random-draw', name: 'app_program_tools_random_draw')]
    public function randomDraw(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramStudentOptionRepository $studentOptionRepository): Response
    {
        $program = $this->findForTeacherOrStaff($id, $repository, $accessChecker);
        $optionsByStudentId = $studentOptionRepository->findOptionsByStudentForProgram($program);

        $students = array_map(
            static fn (User $student): array => [
                'name' => $student->getDisplayName() ?? $student->getUsername(),
                'optionIds' => array_map(
                    static fn (Option $option): int => $option->getId(),
                    $optionsByStudentId[$student->getId()] ?? [],
                ),
            ],
            $this->sortedByName($program->getStudents()->toArray()),
        );

        return $this->render('program/tools_random_draw.html.twig', [
            'program' => $program,
            'students' => $students,
        ]);
    }

    private function findForTeacherOrStaff(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
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
