<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\JobboardBtsAccess;
use App\Enum\JobboardContract;
use App\Enum\JobboardRemote;
use App\Service\Jobboard\JobboardOfferFinder;
use App\Service\Jobboard\JobboardPerimeter;
use App\Service\Jobboard\OfferFilters;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Jobboard » - the offers the veille brought back, read and nothing else.
 *
 * Nothing here writes an offer, closes one or deletes one: the only hand that writes is the
 * collecting agent's, through App\Controller\Api\JobboardIngestController. This screen filters and
 * lists, and a row is a link to the advert's own site - there is no detail of an offer to serve,
 * because everything the platform knows about one is on its row.
 *
 * Two rules it carries rather than delegates to a template:
 *
 * - **the filière perimeter is a WHERE clause** (App\Service\Jobboard\JobboardOfferFinder), so an
 *   offer outside the reader's filières cannot be reached by a forged query string or a cursor;
 * - **the source is never shown to anybody** - it is an administrator's filter and nothing else.
 *   The three admin-only filters are cleared server-side for everybody else rather than merely not
 *   drawn.
 */
#[RequiresFeature(Feature::Jobboard)]
class JobboardController extends AbstractController
{
    #[Route(path: '/jobboard', name: 'app_jobboard', methods: ['GET'])]
    public function index(Request $request, JobboardOfferFinder $finder, JobboardPerimeter $perimeter): Response
    {
        $reader = $this->reader();
        $admin = $this->isGranted('ROLE_ADMIN');
        $filters = OfferFilters::fromRequest($request);
        $page = $finder->page($reader, $filters, QueryValue::trimmed($request, 'cursor'), $admin);

        $tracks = $perimeter->tracks($reader);

        return $this->render('jobboard/index.html.twig', [
            'page' => $page,
            'filters' => $filters,
            'isAdmin' => $admin,
            // The « Filières » filter appears as soon as there is a choice to make, whoever is
            // reading - a selector with one entry is not a choice, and an administrator has no more
            // right to one than a student in two formations.
            'tracks' => \count($tracks) > 1 ? $tracks : [],
            'contracts' => JobboardContract::cases(),
            'remotes' => JobboardRemote::cases(),
            'btsAccessValues' => JobboardBtsAccess::cases(),
            'categories' => $finder->distinctValues($reader, 'category'),
            'regions' => $finder->distinctValues($reader, 'region'),
            'countries' => $finder->distinctValues($reader, 'country'),
            'sources' => $admin ? $finder->sources($reader) : [],
        ]);
    }

    /**
     * « Afficher 40 offres de plus ». Answers the rows already rendered rather than the fields to
     * build them from: one template, one truth about what a row shows, and no second rendering path
     * to keep in step with the first.
     */
    #[Route(path: '/jobboard/offers', name: 'app_jobboard_offers', methods: ['GET'])]
    public function more(Request $request, JobboardOfferFinder $finder): JsonResponse
    {
        $admin = $this->isGranted('ROLE_ADMIN');
        $page = $finder->page(
            $this->reader(),
            OfferFilters::fromRequest($request),
            QueryValue::trimmed($request, 'cursor'),
            $admin,
        );

        return new JsonResponse([
            'html' => $this->renderView('jobboard/_rows.html.twig', ['page' => $page]),
            'cursor' => $page->nextCursor,
        ]);
    }

    private function reader(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
