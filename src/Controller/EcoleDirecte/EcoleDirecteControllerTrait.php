<?php

declare(strict_types=1);

namespace App\Controller\EcoleDirecte;

use App\EcoleDirecte\EcoleDirecteException;
use App\EcoleDirecte\EcoleDirecteSessionExpiredException;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the two École Directe controllers share: one CSRF id, one door, and one shape of refusal the
 * page knows how to show - `expired` telling it to sign in again. The using class holds the
 * translator.
 *
 * The door is **ROLE_ADMIN, for now**: reading and writing a teacher's École Directe from the
 * platform is being tried by administrators on their own accounts before any teacher is offered it.
 * Widening it later is this one method plus the menu entry and RoleAccessSmokeTest.
 */
trait EcoleDirecteControllerTrait
{
    private const string CSRF_ID = 'ecole_directe';

    private function failure(EcoleDirecteException $exception): JsonResponse
    {
        $message = $this->translator->trans($exception->getMessage());
        if ('' !== $exception->apiMessage) {
            $message .= ' '.$this->translator->trans('ecoleDirecteApiSaidLabel', ['%message%' => $exception->apiMessage]);
        }

        return $this->json([
            'ok' => false,
            'message' => $message,
            'expired' => $exception instanceof EcoleDirecteSessionExpiredException,
        ]);
    }

    private function refusal(string $messageKey): JsonResponse
    {
        return $this->json(['ok' => false, 'message' => $this->translator->trans($messageKey), 'expired' => false]);
    }

    private function guard(Request $request): User
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->administrator();
    }

    private function administrator(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
