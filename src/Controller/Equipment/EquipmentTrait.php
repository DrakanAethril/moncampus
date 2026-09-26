<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Entity\User;
use App\Form\EquipmentIncidentType;
use App\Form\EquipmentResolutionType;
use App\Service\Equipment\EquipmentStockException;
use Symfony\Component\Form\FormInterface;
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

    /** « Déclarer un problème », posted to $action - a piece's fiche, or a quantity type's. */
    private function incidentForm(string $action, bool $forQuantity): FormInterface
    {
        return $this->createForm(EquipmentIncidentType::class, null, ['action' => $action, 'for_quantity' => $forQuantity]);
    }

    private function resolutionForm(string $action): FormInterface
    {
        return $this->createForm(EquipmentResolutionType::class, null, ['action' => $action]);
    }

    /**
     * The moment an incident is filed under. Today means now, so the journal keeps its order; an
     * earlier day is that day - it is the date the school year of the report is read on.
     */
    private function occurredAt(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return $day->format('Y-m-d') === $now->format('Y-m-d') ? $now : $day->setTime(12, 0);
    }

    /** A refusal of the ledger becomes a flash message on the screen the gesture came from. */
    private function flashRefusal(EquipmentStockException $exception): void
    {
        $this->addFlash('danger', $exception->getMessage());
    }
}
