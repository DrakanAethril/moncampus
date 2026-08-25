<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipTutorLinkRepository;
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
 * Le Livret de l'alternant : aperçu, document brut de la liseuse et exports PDF (complet, ou
 * réduit à une période d'évaluation).
 *
 * Split out of the former UfaAlternanceController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_TEACHER")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class BookletController extends AbstractController
{
    use UfaAlternanceTrait;

    // Livret reader (26d): left TOC card (static, matching the booklet's own section anchors) +
    // an iframe pointing at livretFrame() below - deliberately not real pagination/thumbnails/
    // zoom, see the feature's plan doc, architecture call 6, for why: this document's real
    // deliverable is the Gotenberg PDF export (already solved), a from-scratch paginated reader
    // would be disproportionate effort for a secondary in-browser view of the same content.
    #[Route(path: '/ufa/alternances/{id}/booklet', name: 'app_ufa_alternance_livret', requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function livret(int $id, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();

        return $this->render('ufa/alternance/livret.html.twig', [
            'tutorLink' => $tutorLink,
            'periods' => $periodRepository->findAllActiveForProgram($tutorLink->getProgram()),
        ]);
    }

    // Standalone, unwrapped booklet render for the reader's <iframe src="..."> - same template as
    // the PDF export and the tutor/student's own "view" routes, just with assetBaseUrl left null
    // so asset() resolves relative to the browser (the Gotenberg-bound render below overrides it
    // to 'http://php' since that container has no browser origin - see
    // InternshipBookletPdfExporter's own docblock).
    #[Route(path: '/ufa/alternances/{id}/booklet/frame', name: 'app_ufa_alternance_livret_frame', requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function livretFrame(int $id, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletBuilder $bookletBuilder): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($tutorLink));
    }

    #[Route(path: '/ufa/alternances/{id}/booklet/pdf', name: 'app_ufa_alternance_livret_pdf', requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function livretPdf(int $id, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletPdfExporter $exporter): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();

        try {
            $pdf = $exporter->export($tutorLink, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_livret', ['id' => $tutorLink->getId()]);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('livret-alternant-%s.pdf', $tutorLink->getStudent()?->getUsername())),
        ]);
    }

    // Export partiel: the booklet cut down to a single evaluation period, for the follow-up visit
    // that only concerns that one. Staff-only, unlike the full export the tutor and the alternant
    // may run on their own screens - see InternshipBookletPdfExporter::exportPeriod().
    #[Route(path: '/ufa/alternances/{id}/booklet/pdf/period/{periodId}', name: 'app_ufa_alternance_livret_pdf_period', requirements: ['id' => '\d+', 'periodId' => '\d+'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function livretPdfPeriod(int $id, int $periodId, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository, InternshipBookletPdfExporter $exporter, SluggerInterface $slugger): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();

        // A period belonging to another Program would render an empty extract rather than fail.
        if ($period->getProgram()?->getId() !== $tutorLink->getProgram()?->getId()) {
            throw $this->createNotFoundException();
        }

        try {
            $pdf = $exporter->exportPeriod($tutorLink, $period, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_livret', ['id' => $tutorLink->getId()]);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('livret-alternant-%s-%s.pdf', $tutorLink->getStudent()?->getUsername(), (string) $slugger->slug($period->getName())->lower())),
        ]);
    }
}
