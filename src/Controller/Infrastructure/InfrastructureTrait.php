<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Repository\ProxmoxHostRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * The handful of things every screen of the Proxmox console needs.
 *
 * The area has *no entry in any menu* - that is a frozen decision, not an oversight - so the local
 * navigation bar carried by every template is the only way from one screen to the next. The rule
 * that follows from it is worth stating where the helpers live: every screen must be reachable by
 * clicking from /infrastructure. A screen that can only be opened by knowing its URL is a bug here,
 * where it would merely be awkward anywhere else in the application.
 */
trait InfrastructureTrait
{
    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function findHostOrNotFound(ProxmoxHostRepository $repository, int $id): ProxmoxHost
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    /**
     * The CSRF check for the small POST actions of this area, read from the header the Stimulus
     * controllers send - the same shape as SettingsTabTrait::assertValidDeactivateToken(). Reading
     * it from the header rather than the body is what lets these buttons sit inside the host form
     * without nesting a second <form> in it, which is the other half of the pair of bugs this
     * repository keeps rediscovering.
     */
    private function assertValidInfrastructureToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('infrastructure_action', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
