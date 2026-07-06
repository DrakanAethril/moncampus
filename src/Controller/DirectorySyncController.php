<?php

namespace App\Controller;

use App\Service\LdapUserSyncer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class DirectorySyncController extends AbstractController
{
    #[Route(path: '/directory/sync', name: 'app_directory_sync')]
    public function index(): Response
    {
        return $this->render('directory/sync.html.twig');
    }

    #[Route(path: '/directory/sync/run', name: 'app_directory_sync_run', methods: ['POST'])]
    public function run(Request $request, LdapUserSyncer $syncer): JsonResponse
    {
        if (!$this->isCsrfTokenValid('directory_sync', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->json(['createdCount' => $syncer->sync()]);
    }
}
