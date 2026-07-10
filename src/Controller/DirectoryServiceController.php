<?php

namespace App\Controller;

use App\Entity\LdapService;
use App\Entity\User;
use App\Repository\LdapServiceRepository;
use App\Service\LdapServiceSyncer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Admin-only, same reasoning as SettingsGroupsController - and stricter than the rest of
// Directory (staff/staff-lead can manage Groups/Users), since service accounts/computers aren't
// something day-to-day staff need visibility into. Read-only: there's no create/edit/deactivate
// action at all, only "sync now" (LdapServiceSyncer) - see App\Entity\LdapService.
#[IsGranted('ROLE_ADMIN')]
class DirectoryServiceController extends AbstractController
{
    #[Route(path: '/directory/services', name: 'app_directory_services')]
    public function index(): Response
    {
        return $this->render('directory/services.html.twig');
    }

    #[Route(path: '/directory/services/sync', name: 'app_directory_services_sync', methods: ['POST'])]
    public function sync(Request $request, LdapServiceSyncer $syncer): JsonResponse
    {
        if (!$this->isCsrfTokenValid('directory_services_sync', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->json(['createdCount' => $syncer->sync($this->currentUser())]);
    }

    #[Route(path: '/directory/services/data', name: 'app_directory_services_data')]
    public function data(Request $request, LdapServiceRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $total = $repository->countAll();
        $filteredTotal = '' !== $search ? $repository->countAll($search) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (LdapService $service): array => [
                    'name' => $service->getName(),
                    'description' => $service->getDescription() ?? '—',
                    'creationDate' => $service->getCreationDate()->format('d/m/Y H:i'),
                    'createdByName' => $this->userLabel($service->getCreatedBy()),
                ],
                $rows,
            ),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function userLabel(?User $user): string
    {
        if (null === $user) {
            return '—';
        }

        return $user->getDisplayName() ?? $user->getUsername();
    }

    /** @return array{0: int, 1: int, 2: int, 3: string} */
    private function readDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));

        return [$draw, $start, $length, $search];
    }
}
