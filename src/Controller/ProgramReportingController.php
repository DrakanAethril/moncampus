<?php

namespace App\Controller;

use App\Repository\ProgramRepository;
use App\Service\ProgramFinancialCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The per-program "Reporting" page reached via the Section > Année scolaire > Classe nav menu -
// staff/admin only, unlike the students/teachers/timetable pages, since financial data isn't
// meant to be visible to the class's own teachers/students.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgramReportingController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/reporting', name: 'app_program_reporting')]
    public function financial(int $id, ProgramRepository $repository, ProgramFinancialCalculator $calculator): Response
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());

        return $this->render('program/reporting_financial.html.twig', [
            'program' => $program,
            'financialTotals' => $calculator->computeTotals($program),
        ]);
    }
}
