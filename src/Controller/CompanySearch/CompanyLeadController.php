<?php

declare(strict_types=1);

namespace App\Controller\CompanySearch;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\JobApplicationRepository;
use App\Security\FeatureAccess;
use App\Service\CompanySearch\CompanyLeads;
use App\Service\CompanySearch\CompanySearchService;
use App\Service\Sirene\SireneUnavailableException;
use App\Service\Sirene\Siret;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Garder » and « Rattacher » on « Trouver une entreprise » (design/validated/vivier-entreprises.md
 * §6): a student's own gestures, which open or complete one of their démarches.
 *
 * A démarche lives in « Candidatures », which the Courrier pro carries (MyJobApplicationController
 * says why): with the mailbox switched off there would be nowhere to find what was kept, so both
 * gestures need `school_mail` as well.
 */
#[RequiresFeature(Feature::CompanySearch)]
#[IsGranted('ROLE_STUDENT')]
class CompanyLeadController extends AbstractController
{
    public function __construct(
        private readonly CompanyLeads $leads,
        private readonly FeatureAccess $features,
    ) {
    }

    #[Route(path: '/company-search/keep', name: 'app_company_search_keep', methods: ['POST'])]
    public function keep(Request $request, CompanySearchService $search): Response
    {
        $student = $this->guard($request, 'company_search_keep');
        $siret = Siret::normalize((string) $request->request->get('siret', ''));

        try {
            $company = Siret::isValid($siret) ? $search->company($siret) : null;
        } catch (SireneUnavailableException) {
            $this->addFlash('warning', 'companySearchUnavailableError');

            return $this->back($request);
        }
        if (null === $company) {
            throw $this->createNotFoundException();
        }

        $result = $this->leads->keep($student, $company);
        $this->addFlash(null !== $result['error'] ? 'warning' : 'success', $result['error'] ?? 'companyLeadKeptFlash');

        return $this->back($request);
    }

    #[Route(path: '/company-search/attach', name: 'app_company_search_attach', methods: ['POST'])]
    public function attach(Request $request, JobApplicationRepository $applications): Response
    {
        $student = $this->guard($request, 'company_search_attach');
        $siret = Siret::normalize((string) $request->request->get('siret', ''));
        $application = $applications->find((int) $request->request->get('application', 0));

        if (null === $application || !Siret::isValid($siret) || !$this->leads->attach($student, $application, $siret)) {
            throw $this->createNotFoundException();
        }
        $this->addFlash('success', 'companyLeadAttachedFlash');

        return $this->back($request);
    }

    private function guard(Request $request, string $tokenId): User
    {
        $student = $this->getUser();
        if (!$student instanceof User || !$this->features->isEnabled(Feature::SchoolMail, $student)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return $student;
    }

    /** Back where the gesture was made - a search or a fiche, never anywhere else. */
    private function back(Request $request): Response
    {
        $back = (string) $request->request->get('back', '');

        return $this->redirect(str_starts_with($back, '/company-search') || str_starts_with($back, '/enterprises/')
            ? $back
            : $this->generateUrl('app_company_search'));
    }
}
