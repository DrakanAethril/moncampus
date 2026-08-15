<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Repository\ProgramRepository;
use App\Service\GotenbergUnavailableException;
use App\Service\TsfFicheExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * The printed training referential (TSF): one fiche per competency, for one program.
 *
 * A controller of its own rather than another method on SettingsSkillGroupController, which already
 * owns the whole editing surface - this is the reading side, and it is the piece the establishment
 * hands out.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ReferentialExportController extends AbstractController
{
    use ProgramSettingsTabTrait;

    #[Route(path: '/programs/{id}/settings/skill-groups/export.pdf', name: 'app_program_referential_export_pdf', requirements: ['id' => '\d+'])]
    public function exportPdf(int $id, ProgramRepository $repository, TsfFicheExporter $exporter, SluggerInterface $slugger): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        try {
            $pdf = $exporter->export($program, $this->renderView(...), new \DateTimeImmutable('today'));
        } catch (GotenbergUnavailableException) {
            // Same shape as the progression export: a converter that is down is an operations
            // problem, not something to show the user a stack trace for.
            $this->addFlash('danger', 'referentialExportPdfFailedFlashMessage');

            return $this->redirectToRoute('app_program_settings_skill_groups', ['id' => $program->getId()]);
        }

        $name = sprintf('referentiel-%s.pdf', (string) $slugger->slug($program->getDisplayShortName())->lower());

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name),
        ]);
    }
}
