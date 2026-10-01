<?php

declare(strict_types=1);

namespace App\Controller\CompanySearch;

use App\Attribute\RequiresFeature;
use App\Entity\Option;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\HostingKind;
use App\Repository\CompanySearchCategoryRepository;
use App\Security\Voter\EnterpriseVoter;
use App\Service\EnterprisePool\PoolFilters;
use App\Service\EnterprisePool\PoolListing;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Vivier » - the employers the establishment knows, and what our students did there
 * (design/validated/vivier-entreprises.md §8.1). Teachers and the administration once
 * `enterprise_pool` is lit for them; the two filters on stage and alternance are the
 * administrator's alone (D7) and are dropped for anybody else, whatever the URL carries.
 */
#[RequiresFeature(Feature::EnterprisePool)]
class PoolController extends AbstractController
{
    #[Route(path: '/enterprises', name: 'app_enterprise_pool', methods: ['GET'])]
    public function index(Request $request, PoolListing $listing, CompanySearchCategoryRepository $categories, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::BROWSE_POOL);
        $user = $this->getUser();
        \assert($user instanceof User);
        $canFilterKinds = $this->isGranted(EnterpriseVoter::FILTER_HOSTINGS);

        $trackId = QueryValue::int($request, 'track');
        $optionId = QueryValue::int($request, 'option');
        $since = QueryValue::int($request, 'since');
        $departments = [];
        foreach (preg_split('/[\s,;]+/', QueryValue::string($request, 'deps')) ?: [] as $code) {
            $code = mb_strtoupper($code);
            if (1 === preg_match('/^(0[1-9]|[1-8]\d|9[0-5]|2A|2B|97[1-6])$/', $code)) {
                $departments[] = $code;
            }
        }

        $filters = new PoolFilters(
            name: mb_substr(QueryValue::trimmed($request, 'name'), 0, 100),
            departments: array_values(array_unique($departments)),
            categories: array_values(array_filter($categories->findByIds(QueryValue::intList($request, 'cat')), static fn ($category): bool => !$category->isSolo())),
            track: $trackId > 0 ? $entityManager->find(Track::class, $trackId) : null,
            option: $optionId > 0 ? $entityManager->find(Option::class, $optionId) : null,
            sinceYear: $since >= 1990 && $since <= 2100 ? $since : null,
            kinds: $canFilterKinds ? array_values(array_filter(array_map(
                static fn (mixed $value): ?HostingKind => \is_string($value) ? HostingKind::tryFrom($value) : null,
                QueryValue::all($request, 'kind'),
            ))) : [],
            withTeacherContact: QueryValue::bool($request, 'teacher'),
            pendingSiretOnly: QueryValue::bool($request, 'pending'),
            page: max(1, QueryValue::int($request, 'page', 1)),
        );
        $result = $listing->list($filters, $user);
        $currentYear = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;

        return $this->render('company_search/pool.html.twig', [
            'filters' => $filters,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'pages' => $result['pages'],
            'canFilterKinds' => $canFilterKinds,
            'groups' => $categories->findGroupedByTheme(),
            'tracks' => $entityManager->getRepository(Track::class)->findBy([], ['name' => 'ASC']),
            'options' => $entityManager->getRepository(Option::class)->findBy([], ['name' => 'ASC']),
            'years' => range($currentYear, 2010),
            'kinds' => HostingKind::cases(),
            'back' => $request->getRequestUri(),
        ]);
    }
}
