<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Attribute\RequiresFeature;
use App\Entity\AssignmentSubmission;
use App\Entity\AssignmentSubmissionFile;
use App\Enum\Feature;
use App\Enum\PortfolioEvidenceKind;
use App\Repository\PortfolioEvidenceRepository;
use App\Security\Voter\PortfolioVoter;
use App\Service\FileUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Opens one piece of evidence - for its student, the validateurs of their option, and the
 * administration in reading. The storage URL is signed on the way out, never printed in a page
 * somebody else could keep.
 */
#[RequiresFeature(Feature::Portfolio)]
class EvidenceController extends AbstractController
{
    #[Route(path: '/portfolio/evidence/{id}', name: 'app_portfolio_evidence_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function open(int $id, PortfolioEvidenceRepository $evidences, FileUploadService $files): Response
    {
        $evidence = $evidences->find($id) ?? throw $this->createNotFoundException();
        $portfolio = $evidence->getOwningPortfolio() ?? throw $this->createNotFoundException();

        if (!$this->isGranted(PortfolioVoter::VIEW, $portfolio)) {
            throw $this->createNotFoundException();
        }

        return match ($evidence->getKind()) {
            PortfolioEvidenceKind::Link => $this->redirect((string) $evidence->getUrl()),
            PortfolioEvidenceKind::File => $this->redirect($files->downloadUrl((string) $evidence->getFileKey(), (string) ($evidence->getOriginalName() ?? $evidence->getLabel()))),
            PortfolioEvidenceKind::Submission => $this->redirectToSubmission($evidence->getSubmission(), $files),
        };
    }

    private function redirectToSubmission(?AssignmentSubmission $submission, FileUploadService $files): Response
    {
        $file = $submission?->getFiles()->first();

        if (!$file instanceof AssignmentSubmissionFile || null === $file->getStorageKey()) {
            throw $this->createNotFoundException();
        }

        return $this->redirect($files->downloadUrl($file->getStorageKey(), (string) $file->getOriginalFilename()));
    }
}
