<?php

namespace App\Controller;

use App\Entity\Program;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Placeholder pages reached via the Section > Année scolaire > Classe nav menu - to be
// filled in with the real student/teacher lists later. The "Paramétrage" entry lives in
// ProgramSettingsController instead, since it's grown into its own tabbed feature.
class ProgramController extends AbstractController
{
    #[Route(path: '/programs/{id}/students', name: 'app_program_students')]
    public function students(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/students.html.twig', ['program' => $program]);
    }

    #[Route(path: '/programs/{id}/teachers', name: 'app_program_teachers')]
    public function teachers(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/teachers.html.twig', ['program' => $program]);
    }

    // Students/teachers lists are visible under the same rule as the nav entry that links to
    // them: the program's cohort's own linked LDAP group role, or staff/admin.
    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isNodeVisible($program->getCohort())) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }
}
