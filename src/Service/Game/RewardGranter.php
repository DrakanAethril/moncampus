<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\RewardGrant;
use App\Entity\RewardItem;
use App\Entity\User;
use App\Enum\RewardScope;
use App\Repository\RewardGrantRepository;
use App\Repository\RewardItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Granting a reward - by hand, or on its own at a closure - and spending a consumable.
 *
 * **Nothing here writes a App\Entity\GameEntry, and that is the whole class in one sentence** (§5.5).
 * If a reward gave points, a teacher could lift a student above the others with one click and
 * outside their envelope, and the equity of §2 would leave through that door. The chain runs one
 * way: points make the index, the index makes the tiers, the tiers and a human hand make the
 * rewards. Nothing comes back up.
 *
 * The four tiers are ordinary catalogue entries with an $automaticThreshold, granted at closure by
 * grantAutomatic(). No separate mechanism, so the tiers and the hand-granted rewards cannot drift
 * into two different ideas of what a reward is.
 */
final class RewardGranter
{
    public function __construct(
        private readonly RewardItemRepository $items,
        private readonly RewardGrantRepository $grants,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Grant to one student, by a teacher's hand.
     *
     * $period may be null: a reward for a mock exam or an open day belongs to the establishment's
     * calendar, not to the game's, and there is no reason to refuse one because no evaluation period
     * covers that day. What it never does either way is move an index.
     */
    public function grantToStudent(RewardItem $item, User $student, Program $program, ?EvaluationPeriod $period, ?User $grantedBy, ?string $reason = null): RewardGrant
    {
        $grant = (new RewardGrant($item, $program, $period))
            ->setStudent($student)
            ->setGrantedBy($grantedBy)
            ->setReason($reason);

        $this->entityManager->persist($grant);
        $this->entityManager->flush();

        return $grant;
    }

    /**
     * Grant to a whole team, or to the whole class - one row per member, so a shelf is always
     * personal even when the decision was collective.
     *
     * @param list<User> $members
     *
     * @return list<RewardGrant>
     */
    public function grantToGroup(RewardItem $item, array $members, Program $program, ?EvaluationPeriod $period, ?User $grantedBy, ?int $groupRef = null, ?string $reason = null): array
    {
        $granted = [];
        foreach ($members as $member) {
            $grant = (new RewardGrant($item, $program, $period))
                ->setStudent($member)
                ->setGroupRef($groupRef)
                ->setGrantedBy($grantedBy)
                ->setReason($reason);

            $this->entityManager->persist($grant);
            $granted[] = $grant;
        }

        $this->entityManager->flush();

        return $granted;
    }

    /**
     * The tiers a closure grants on their own, given a frozen index.
     *
     * Idempotent: a period closed twice grants nothing twice. The threshold is read on the **index**
     * and never on a total - which is the whole of §2, and a threshold in points would quietly
     * re-introduce the ranking by availability the index exists to remove.
     *
     * @return list<RewardGrant>
     */
    public function grantAutomatic(User $student, Program $program, EvaluationPeriod $period, int $index): array
    {
        $granted = [];

        foreach ($this->items->automaticFor($program) as $item) {
            $threshold = $item->getAutomaticThreshold();

            if (null === $threshold || $index < $threshold) {
                continue;
            }

            if ($this->grants->alreadyHolds($item, $student, $period)) {
                continue;
            }

            $grant = (new RewardGrant($item, $program, $period))->setStudent($student);
            $this->entityManager->persist($grant);
            $granted[] = $grant;
        }

        return $granted;
    }

    /**
     * Spend a consumable.
     *
     * The student does it themselves and the teacher is only notified: a joker one can refuse is not
     * a reward, it is a request (§5.5). What it cannot be spent on - a graded assessment - is the
     * caller's business, not this method's; here it is simply marked, once.
     */
    public function spend(RewardGrant $grant, string $usedOn): bool
    {
        if ($grant->isUsed() || !$grant->getItem()->getNature()->isSpendable()) {
            return false;
        }

        $grant->use($usedOn);
        $this->entityManager->flush();

        return true;
    }

    /** Whether a scope needs a student picked, rather than a group. */
    public function needsStudent(RewardItem $item): bool
    {
        return RewardScope::Student === $item->getScope();
    }
}
