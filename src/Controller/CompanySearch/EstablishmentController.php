<?php

declare(strict_types=1);

namespace App\Controller\CompanySearch;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\EnterpriseRepository;
use App\Service\CompanySearch\CompanySearchService;
use App\Service\CompanySearch\MineOnFiche;
use App\Service\CompanySearch\ResultRows;
use App\Service\CompanySearch\SchoolLocation;
use App\Service\QueryValue;
use App\Service\Sirene\SireneUnavailableException;
use App\Service\Sirene\Siret;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The fiche of an establishment found in the register (design/validated/vivier-entreprises.md
 * §8.2): what it is, where it is, how to get there, and how to reach somebody - the register
 * itself gives no telephone, e-mail or website, and the fiche says so rather than leave the
 * student looking for them.
 */
#[RequiresFeature(Feature::CompanySearch)]
class EstablishmentController extends AbstractController
{
    #[Route(path: '/company-search/establishments/{siret}', name: 'app_company_search_establishment', requirements: ['siret' => '\d{14}'], methods: ['GET'])]
    public function show(string $siret, Request $request, CompanySearchService $search, ResultRows $rows, SchoolLocation $school, EnterpriseRepository $enterprises, MineOnFiche $mine): Response
    {
        $siret = Siret::normalize($siret);

        // An employer of ours: its fiche is the vivier's, which says everything this one does and
        // what the establishment knows of it besides (R8 - a confirmed SIRET only).
        $user = $this->getUser();
        $known = $user instanceof User ? ($enterprises->findConfirmedBySirets([$siret], $user)[$siret] ?? null) : null;
        if (null !== $known && null === $known->getInactiveDate()) {
            return $this->redirectToRoute('app_enterprise_pool_show', ['id' => $known->getId(), 'back' => $this->backUrl($request)]);
        }
        $unavailable = false;
        $company = null;

        try {
            $company = $search->company($siret);
        } catch (SireneUnavailableException) {
            $unavailable = true;
        }

        if (!$unavailable && null === $company) {
            throw $this->createNotFoundException();
        }

        $schoolPlace = $school->place();
        $origin = null !== $schoolPlace ? [$schoolPlace->latitude, $schoolPlace->longitude] : null;

        return $this->render('company_search/establishment.html.twig', [
            'siret' => $siret,
            'unavailable' => $unavailable,
            'company' => null !== $company ? $rows->company($company, $origin) : null,
            'schoolPlace' => $schoolPlace,
            'back' => $this->backUrl($request),
            'mine' => $mine->for($user instanceof User ? $user : null, $this->isGranted('ROLE_STUDENT'), $siret),
        ]);
    }

    /** The search the fiche was opened from - only ever a URL of this screen. */
    private function backUrl(Request $request): string
    {
        $back = QueryValue::string($request, 'back');

        return str_starts_with($back, '/company-search?') ? $back : $this->generateUrl('app_company_search');
    }
}
