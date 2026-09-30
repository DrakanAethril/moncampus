<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoParcours;
use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Who a parcours may be shared with, and the one way its list of colleagues is rewritten.
 *
 * A colleague must be a teacher who runs e-CO: ROLE_TEACHER **and** ROLE_ECO, still active. Once
 * on the list they hold every right the creator holds (EcoParcours::isManagedBy()), sharing
 * further included - which is why anyone who may edit the parcours may also rewrite this list.
 *
 * A test account only ever sees test accounts, the rule StructureAccessChecker::matchesTestMode()
 * sets for the rest of the platform.
 */
final class EcoParcoursSharing
{
    /** Both roles, not either: e-CO is a manually granted role, teaching is what is shared. */
    public const array REQUIRED_ROLES = ['ROLE_TEACHER', 'ROLE_ECO'];

    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * The picker's answer - never the viewer, never the creator, who hold the parcours already.
     *
     * @return list<User>
     */
    public function candidates(User $viewer, ?EcoParcours $parcours, ?string $search, int $limit): array
    {
        $excluded = array_values(array_filter([$viewer->getId(), $parcours?->getTeacher()?->getId()]));

        $candidates = array_values(array_filter(
            $this->users->findActiveMatchingRoles(self::REQUIRED_ROLES, $excluded, $search),
            static fn (User $user): bool => !$viewer->isTestUser() || $user->isTestUser(),
        ));

        return \array_slice($candidates, 0, $limit);
    }

    public function mayShareWith(User $viewer, User $user): bool
    {
        return null === $user->getInactiveDate()
            && [] === array_diff(self::REQUIRED_ROLES, $user->getRoles())
            && (!$viewer->isTestUser() || $user->isTestUser());
    }

    /**
     * Rewrites the list from the ids submitted. A new name is kept only if the picker could have
     * offered it; a colleague already on the list stays even if they no longer would be - taking a
     * parcours away is a gesture someone makes, not a side effect of the directory.
     *
     * @param list<int> $userIds
     */
    public function apply(EcoParcours $parcours, array $userIds, User $viewer): void
    {
        $kept = [];
        foreach ([] !== $userIds ? $this->users->findByIds($userIds) : [] as $user) {
            if ($parcours->getSharedWith()->contains($user) || $this->mayShareWith($viewer, $user)) {
                $kept[] = $user;
            }
        }

        foreach ($parcours->getSharedWith()->toArray() as $user) {
            if (!\in_array($user, $kept, true)) {
                $parcours->unshareWith($user);
            }
        }

        foreach ($kept as $user) {
            $parcours->shareWith($user);
        }
    }
}
