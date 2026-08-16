<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlatformActivity;
use App\Entity\User;
use App\Enum\PlatformActivityType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The only write point of the platform log (App\Entity\PlatformActivity) - the counterpart of
 * App\Service\UfaActivityRecorder, same rules, and nothing from the UFA enters it.
 *
 * $request serves to read the IP and the User-Agent: the only two columns specific to this log, and
 * the ones that make a list of logins genuinely useful.
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
