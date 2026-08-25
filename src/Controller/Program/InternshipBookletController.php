<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\InternshipTutorLink;
use App\Enum\Feature;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipBookletBuilder;
use App\Service\InternshipBookletPdfExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Le Livret de l'alternant vu depuis la formation : aperçu, document brut de la liseuse et exports
 * PDF d'un tuteur (complet, ou réduit à une période d'évaluation).
 *
 * Split out of the former ProgramInternshipController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class InternshipBookletController extends AbstractController
{
    use ProgramInternshipTrait;

    // Reader for the booklet - the same TOC-plus-iframe shell every other role gets
    // (Ufa\BookletController::livret() and the tutor/alternant equivalents), and the only place
    // the PDF export actions live.
    #[Route(path: '/ufa/programs/{id}/tutors/{tutorLinkId}/booklet', name: 'app_ufa_formation_tutors_booklet')]
    public function tutorLinkBooklet(int $id, int $tutorLinkId, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/internship/booklet.html.twig', [
            'program' => $program,
            'tutorLink' => $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId),
            'periods' => $evaluationPeriodRepository->findAllActiveForProgram($program),
        ]);
    }

    // Unwrapped document behind the reader's <iframe src="...">.
    #[Route(path: '/ufa/programs/{id}/tutors/{tutorLinkId}/booklet/frame', name: 'app_ufa_formation_tutors_booklet_frame')]
    public function tutorLinkBookletFrame(int $id, int $tutorLinkId, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletBuilder $bookletBuilder): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($tutorLink));
    }

    #[Route(path: '/ufa/programs/{id}/tutors/{tutorLinkId}/booklet/pdf', name: 'app_ufa_formation_tutors_booklet_pdf')]
    public function tutorLinkBookletPdf(int $id, int $tutorLinkId, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletPdfExporter $exporter): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);

        // Back to the reader on failure - that is where this button lives, and unlike the bare
        // document it used to sit next to, it has a flash-message region to show the error in.
        return $this->exportBookletPdf($tutorLink, 'app_ufa_formation_tutors_booklet', ['id' => $program->getId(), 'tutorLinkId' => $tutorLink->getId()], $exporter);
    }

    // Export partiel: the booklet cut down to a single evaluation period - same staff-only action as
    // Ufa\BookletController::livretPdfPeriod(), reached from this screen's own reader.
    #[Route(path: '/ufa/programs/{id}/tutors/{tutorLinkId}/booklet/pdf/period/{periodId}', name: 'app_ufa_formation_tutors_booklet_pdf_period', requirements: ['periodId' => '\d+'])]
    public function tutorLinkBookletPdfPeriod(int $id, int $tutorLinkId, int $periodId, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipBookletPdfExporter $exporter, SluggerInterface $slugger): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);
        $period = $evaluationPeriodRepository->find($periodId) ?? throw $this->createNotFoundException();

        // A period belonging to another Program would render an empty extract rather than fail.
        if ($period->getProgram()?->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $pdf = $exporter->exportPeriod($tutorLink, $period, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            return $this->redirectToRoute('app_ufa_formation_tutors_booklet', ['id' => $program->getId(), 'tutorLinkId' => $tutorLink->getId()]);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('livret-alternant-%s-%s.pdf', $tutorLink->getStudent()->getUsername(), (string) $slugger->slug($period->getName())->lower())),
        ]);
    }

    /** @param array<string, mixed> $backRouteParams */
    private function exportBookletPdf(InternshipTutorLink $tutorLink, string $backRoute, array $backRouteParams, InternshipBookletPdfExporter $exporter): Response
    {
        try {
            $pdf = $exporter->export($tutorLink, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            return $this->redirectToRoute($backRoute, $backRouteParams);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('livret-alternant-%s.pdf', $tutorLink->getStudent()->getUsername())),
        ]);
    }
}
