<?php

declare(strict_types=1);

namespace App\Controller\Documentation;

use App\Entity\User;
use App\Repository\DocumentationArticleRepository;
use App\Service\DocumentationPerimeter;
use App\Service\DocumentationReadCounter;
use App\Service\DocumentationStats;
use App\Service\PostValue;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The reading figures of the documentation base (handoff 2f/2g/2h) - staff, staff-lead and admin
 * only, like every screen that reads the base whole.
 *
 * The two lists are one method for the same reason the home page and a page de garde are: 2h is 2g
 * with "jamais lues" turned on and a last column that shows the publication date instead of a
 * count.
 */
#[IsGranted(new Expression('is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_ADMIN")'))]
#[Route(path: '/documentation/manage')]
class StatsController extends AbstractController
{
    private const int MOST_READ_LIMIT = 6;
    private const int PAGE_SIZE = 10;

    public function __construct(
        private readonly DocumentationStats $stats,
        private readonly DocumentationPerimeter $perimeter,
        private readonly DocumentationArticleRepository $articles,
    ) {
    }

    #[Route(path: '/dashboard', name: 'app_documentation_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $sinceReset = 'always' !== QueryValue::trimmed($request, 'range');

        return $this->render('documentation/manage/dashboard.html.twig', [
            'overview' => $this->stats->overview(),
            'mostRead' => $this->stats->mostRead($sinceReset, self::MOST_READ_LIMIT),
            'perimeterReads' => $this->stats->readsByPerimeter($sinceReset),
            'sinceReset' => $sinceReset,
        ]);
    }

    #[Route(path: '/reads', name: 'app_documentation_reads', methods: ['GET'])]
    #[Route(path: '/never-read', name: 'app_documentation_never_read', methods: ['GET'])]
    public function reads(Request $request): Response
    {
        $neverReadOnly = 'app_documentation_never_read' === $request->attributes->get('_route');
        $sinceReset = 'always' !== QueryValue::trimmed($request, 'range');
        $page = max(1, QueryValue::int($request, 'page', 1));
        $scopeId = QueryValue::nullableInt($request, 'scope');
        $scope = null === $scopeId ? null : $this->perimeter->find($scopeId);
        $scopeIds = null === $scope ? null : $this->perimeter->branchIds($scopeId ?? 0);

        $total = $this->articles->countPage($scopeIds, $neverReadOnly);
        $offset = ($page - 1) * self::PAGE_SIZE;

        return $this->render('documentation/manage/reads.html.twig', [
            'articles' => $this->articles->findPage($offset, self::PAGE_SIZE, $scopeIds, sinceReset: $sinceReset, neverReadOnly: $neverReadOnly),
            'neverReadOnly' => $neverReadOnly,
            'sinceReset' => $sinceReset,
            'scope' => $scope,
            'perimeterTree' => $this->perimeter->tree(),
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'total' => $total,
            'offset' => $offset,
        ]);
    }

    #[Route(path: '/reset-counters', name: 'app_documentation_reset_counters', methods: ['POST'])]
    public function resetCounters(Request $request, DocumentationReadCounter $readCounter): Response
    {
        if (!$this->isCsrfTokenValid('documentation_reset_counters', PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->getUser();
        $readCounter->reset($user instanceof User ? $user : null);

        $this->addFlash('success', 'documentationCountersResetFlashMessage');

        return $this->redirectToRoute('app_documentation_dashboard');
    }
}
