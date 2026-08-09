<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlatformActivity;
use App\Entity\User;
use App\Enum\PlatformActivityType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le seul point d'écriture du journal plateforme (App\Entity\PlatformActivity) - pendant de
 * App\Service\UfaActivityRecorder, mêmes règles, et rien de l'UFA n'y entre.
 *
 * $request sert à relever IP et User-Agent : les deux seules colonnes propres à ce journal, et
 * celles qui rendent une liste de connexions réellement utile.
 */
class PlatformActivityRecorder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, string> $extraPayload */
    public function record(PlatformActivityType $type, ?User $actor, ?Request $request = null, array $extraPayload = []): void
    {
        $activity = new PlatformActivity($type, $actor, [
            'user' => $actor?->getDisplayName() ?? $actor?->getUsername() ?? '',
            ...$extraPayload,
        ]);

        $activity->setIpAddress($request?->getClientIp());
        $activity->setUserAgent($request?->headers->get('User-Agent'));

        $this->entityManager->persist($activity);
        $this->entityManager->flush();
    }
}
