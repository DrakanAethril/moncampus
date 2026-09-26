<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Entity\User;
use App\Service\Equipment\EquipmentStockException;
use Symfony\Component\HttpFoundation\Request;

/**
 * What every screen of Gestion > Matériel shares.
 *
 * The access expression is the second of two locks, and the one that decides: the `equipment`
 * feature can only *remove* the area (App\Enum\Feature), so ticking it for teachers in the matrix
 * must still not open the inventory to them.
 */
trait EquipmentTrait
{
    private const string ACCESS_EXPRESSION = 'is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_SUPPORT-TECH")';

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function assertValidEquipmentToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** A refusal of the ledger becomes a flash message on the screen the gesture came from. */
    private function flashRefusal(EquipmentStockException $exception): void
    {
        $this->addFlash('danger', $exception->getMessage());
    }
}
