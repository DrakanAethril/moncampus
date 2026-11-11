<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Attribute\RequiresFeature;
use App\Entity\SchoolYear;
use App\Enum\Feature;
use App\Service\Crm\CrmSearch;
use App\Service\Crm\CrmSettings;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The CRM's home: a search by name that opens a dossier, « Nouveau dossier », and the doors to the
 * overview, the settings and the history. The work year is chosen here and nowhere else, for the
 * session; the overview, a new dossier and the search follow it.
 */
#[RequiresFeature(Feature::Crm)]
class HomeController extends AbstractController
{
    use CrmControllerTrait;

    private const int MAX_HITS = 8;

    #[Route(path: '/crm', name: 'app_crm_home', methods: ['GET'])]
    public function home(Request $request, CrmSettings $settings): Response
    {
        return $this->render('crm/home.html.twig', [
            'workYear' => $this->workYear($request, $settings),
            'defaultYear' => $settings->defaultYear(),
            'openedYears' => $settings->openedYears(),
            'picking' => QueryValue::bool($request, 'pick'),
            'query' => QueryValue::trimmed($request, 'q'),
        ]);
    }

    /** The search's list, fetched as one types (crm_search_controller.js). */
    #[Route(path: '/crm/search', name: 'app_crm_search', methods: ['GET'])]
    public function search(Request $request, CrmSettings $settings, CrmSearch $search): Response
    {
        $query = QueryValue::trimmed($request, 'q');
        $workYear = $this->workYear($request, $settings);
        $hits = '' === $query ? [] : $search->find($query, $workYear);

        return $this->render('crm/_search_results.html.twig', [
            'query' => $query,
            'words' => CrmSearch::words($query),
            'hits' => \array_slice($hits, 0, self::MAX_HITS),
            'more' => max(0, \count($hits) - self::MAX_HITS),
            'workYear' => $workYear,
            'settings' => $settings,
        ]);
    }

    #[Route(path: '/crm/work-year/{id}', name: 'app_crm_work_year', methods: ['POST'])]
    public function workYearSet(Request $request, SchoolYear $year, CrmSettings $settings): Response
    {
        $this->assertCsrf($request);
        $this->openedYear($year, $settings);
        $request->getSession()->set(self::WORK_YEAR, $year->getId());

        return $this->redirectToRoute('app_crm_home');
    }
}
