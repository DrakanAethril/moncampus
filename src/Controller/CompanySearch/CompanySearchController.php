<?php

declare(strict_types=1);

namespace App\Controller\CompanySearch;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\CompanyOrganisationType;
use App\Enum\EmployeeBand;
use App\Enum\Feature;
use App\Repository\CompanySearchCategoryRepository;
use App\Repository\JobApplicationRepository;
use App\Security\FeatureAccess;
use App\Security\Voter\EnterpriseVoter;
use App\Service\CompanySearch\CompanySearchCriteria;
use App\Service\CompanySearch\CompanySearchCriteriaFactory;
use App\Service\CompanySearch\CompanySearchService;
use App\Service\CompanySearch\CompanySearchThrottledException;
use App\Service\CompanySearch\NafNomenclature;
use App\Service\CompanySearch\ResultRows;
use App\Service\CompanySearch\SchoolLocation;
use App\Service\EnterprisePool\PoolAnnotations;
use App\Service\Ign\GeocodedPlace;
use App\Service\Ign\IgnGeoplateformeClient;
use App\Service\Ign\IgnUnavailableException;
use App\Service\QueryValue;
use App\Service\Sirene\SireneUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Trouver une entreprise » (design/validated/vivier-entreprises.md §8.1): the État's company
 * register, searched by what a student understands - a category, a place, a size.
 *
 * A GET form: the search is a result to show, not a change to record, so it is bookmarkable, shared
 * by its URL and found again with « Précédent ». The first visit searches nothing: it opens on the
 * school's département with no « quoi », and says what the screen is for.
 */
#[RequiresFeature(Feature::CompanySearch)]
class CompanySearchController extends AbstractController
{
    public function __construct(
        private readonly CompanySearchCriteriaFactory $criteriaFactory,
        private readonly CompanySearchService $search,
        private readonly CompanySearchCategoryRepository $categories,
        private readonly ResultRows $rows,
        private readonly SchoolLocation $school,
        private readonly PoolAnnotations $pool,
        private readonly JobApplicationRepository $applications,
        private readonly FeatureAccess $features,
    ) {
    }

    #[Route(path: '/company-search', name: 'app_company_search', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $criteria = $this->criteriaFactory->fromRequest($request);
        $searched = QueryValue::bool($request, 'searched');
        $page = null;
        $problem = null;

        if ($searched) {
            $problem = $criteria->problem();

            if (null === $problem) {
                try {
                    $page = $this->search->search($criteria, $this->currentUser());
                } catch (SireneUnavailableException) {
                    $problem = 'companySearchUnavailableError';
                } catch (CompanySearchThrottledException) {
                    $problem = 'companySearchThrottledError';
                }
            }
        }

        // Distances: from the commune searched around, else from the school.
        $origin = $criteria->origin();
        $schoolPlace = null;
        if (null === $origin) {
            $schoolPlace = $this->school->place();
            $origin = null !== $schoolPlace ? [$schoolPlace->latitude, $schoolPlace->longitude] : null;
        }

        $rows = [];
        $hidden = 0;
        $canKeep = $this->canKeep();
        if (null !== $page) {
            $sirets = [];
            foreach ($page->companies as $company) {
                foreach ($company->establishments as $establishment) {
                    $sirets[] = $establishment->siret;
                }
            }
            $mine = $canKeep ? $this->applications->findForStudentBySirets($this->currentUser(), $sirets) : [];
            $pool = $this->pool->forCompanies($page->companies, $this->currentUser(), $this->isGranted(EnterpriseVoter::VIEW_TEACHER_CONTACTS));
            foreach ($page->companies as $company) {
                $row = $this->rows->company($company, $origin);
                $row['mine'] = array_intersect_key($mine, array_flip(array_column($row['establishments'], 'siret')));
                if ($canKeep && $criteria->hideMine && [] !== $row['mine']) {
                    ++$hidden;
                    continue;
                }
                $row['pool'] = [
                    'establishments' => array_intersect_key($pool['establishments'], array_flip(array_column($row['establishments'], 'siret'))),
                    'elsewhere' => $pool['elsewhere'][$company->siren] ?? [],
                ];
                $rows[] = $row;
            }
        }

        return $this->render('company_search/index.html.twig', [
            'criteria' => $criteria,
            'searched' => $searched,
            'problem' => $problem,
            'page' => $page,
            'rows' => $rows,
            'hiddenMine' => $hidden,
            'canKeep' => $canKeep,
            'distanceFromSchool' => null === $criteria->origin() && null !== $origin,
            'groups' => $this->categories->findGroupedByTheme(),
            'bands' => EmployeeBand::cases(),
            'organisationTypes' => CompanyOrganisationType::cases(),
            'radii' => CompanySearchCriteria::RADII,
            'schoolPlace' => $schoolPlace ?? $this->school->place(),
        ]);
    }

    /** The advanced filter's completion: codes whose code or label holds what was typed. */
    #[Route(path: '/company-search/naf', name: 'app_company_search_naf', methods: ['GET'])]
    public function naf(Request $request, NafNomenclature $nomenclature): JsonResponse
    {
        return $this->json($nomenclature->search(QueryValue::trimmed($request, 'term'), 15));
    }

    /**
     * « Autour d'une commune »: communes only, from the Géoplateforme. What is sent is what the
     * person typed in that field - never an address of theirs (R5).
     */
    #[Route(path: '/company-search/communes', name: 'app_company_search_communes', methods: ['GET'])]
    public function communes(Request $request, IgnGeoplateformeClient $ign): JsonResponse
    {
        try {
            $places = $ign->places(QueryValue::trimmed($request, 'term'), 'municipality', 6);
        } catch (IgnUnavailableException) {
            return $this->json([], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(array_map(static fn (GeocodedPlace $place): array => [
            'label' => $place->label,
            'postalCode' => $place->postalCode,
            'latitude' => round($place->latitude, 5),
            'longitude' => round($place->longitude, 5),
        ], $places));
    }

    /** « Garder » is a student's, and lands in « Candidatures », which the Courrier pro carries. */
    private function canKeep(): bool
    {
        return $this->isGranted('ROLE_STUDENT') && $this->features->isEnabled(Feature::SchoolMail, $this->currentUser());
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
