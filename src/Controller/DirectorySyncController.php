<?php

namespace App\Controller;

use App\Service\LdapUserSyncer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// No standalone index page any more - this action is reached from a page-specific "Sync with
// LDAP" button on Directory > Users (templates/directory/users.html.twig) instead, matching the
// same-page-triggers-its-own-sync pattern used by Settings > Groups and Directory > Services/
// Computers.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class DirectorySyncController extends AbstractController
{
    #[Route(path: '/directory/sync/run', name: 'app_directory_sync_run', methods: ['POST'])]
    public function run(Request $request, LdapUserSyncer $syncer): JsonResponse
    {
        if (!$this->isCsrfTokenValid('directory_sync', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->json(['createdCount' => $syncer->sync()]);
    }
}
