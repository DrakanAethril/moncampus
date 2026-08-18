<?php

declare(strict_types=1);

namespace App\Service\Network;

use App\Entity\IpAllocation;
use App\Entity\IpRange;
use App\Entity\ProxmoxOperation;
use App\Enum\IpAllocationOrigin;
use App\Enum\IpAllocationStatus;
use App\Repository\IpAllocationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hands out addresses, and takes them back.
 *
 * **Two administrators launching a batch in the same second must never be given the same address**,
 * and this class defends that twice over.
 *
 * The mechanism is a row lock: every reservation opens a transaction and takes
 * `PESSIMISTIC_WRITE` on the *range* row before reading occupancy, so a second caller waits rather
 * than reading the same "next free one". Read-then-insert without it loses that race as often as it
 * is run, and the resulting address collisions take weeks to trace back to their cause.
 *
 * The guarantee is the unique index on `(range_id, live_key)`, which the database enforces whatever
 * the application believes. It is deliberately not the mechanism: relying on catching the violation
 * would mean recovering from a failed flush, and Doctrine closes the EntityManager on one - there
 * is no "carry on with the next address" from there. So the lock is what makes collisions not
 * happen, and the index is what makes it impossible for them to have happened.
 *
 * Occupancy is the union of three sources, which is the point the design insists on: the registry
 * knows what MonCampus handed out, the Proxmox scan knows what machines actually carry, and the
 * external entries know about the printer nobody must be offered. A registry that knows only its
 * own writes starts lying at the first machine created by hand.
 */
class IpAllocator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IpAllocationRepository $repository,
        private readonly IpRangeCalculator $calculator,
    ) {
    }

    /**
     * Reserves the first address nobody holds.
     *
     * @param list<string> $alsoTaken addresses known from outside the registry - what the Proxmox
     *                                scan found. Passing them is what keeps the console from
     *                                offering an address a hand-made machine already uses.
     *
     * @throws RangeExhaustedException when the window has nothing left
     */
    public function reserveNext(IpRange $range, array $alsoTaken = [], ?string $hostname = null): IpAllocation
    {
        return $this->entityManager->wrapInTransaction(function () use ($range, $alsoTaken, $hostname): IpAllocation {
            $this->lock($range);

            $taken = [...$this->repository->findLiveAddresses($range), ...$alsoTaken];
            $candidate = $this->calculator->nextFree($range->getFirstUsable(), $range->getLastUsable(), $taken);

            if (null === $candidate) {
                throw new RangeExhaustedException(\sprintf('Range "%s" has no free address left.', $range->getLabel()));
            }

            return $this->persistReservation($range, $candidate, $hostname);
        });
    }

    /**
     * Reserves one particular address - what the wizard does when somebody types one instead of
     * accepting the offer.
     *
     * @throws AddressUnavailableException when it is outside the window or already held
     */
    public function reserve(IpRange $range, string $ip, ?string $hostname = null): IpAllocation
    {
        if (!$this->isInsideWindow($range, $ip)) {
            throw new AddressUnavailableException(\sprintf('%s is outside the assignable window of "%s".', $ip, $range->getLabel()));
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($range, $ip, $hostname): IpAllocation {
                $this->lock($range);

                if (null !== $this->repository->findLiveByAddress($range, $ip)) {
                    throw new AddressUnavailableException(\sprintf('%s is already taken.', $ip));
                }

                return $this->persistReservation($range, $ip, $hostname);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // The index caught what the lock should have. Reported rather than retried: this path
            // was asked for one particular address, and there is no other answer to give.
            throw new AddressUnavailableException(\sprintf('%s is already taken.', $ip), previous: $exception);
        }
    }

    /** The creation call was accepted: the address now belongs to a machine that exists. */
    public function assign(IpAllocation $allocation, ?int $vmid, ?string $node, ?ProxmoxOperation $operation = null): void
    {
        $allocation->setStatus(IpAllocationStatus::Assigned);
        $allocation->setVmid($vmid);
        $allocation->setNode($node);
        $allocation->setOperation($operation);

        $this->entityManager->flush();
    }

    /** A machine answered at it. The only state that is evidence rather than intention. */
    public function confirm(IpAllocation $allocation, ?string $macAddress = null): void
    {
        $allocation->setStatus(IpAllocationStatus::Confirmed);

        if (null !== $macAddress) {
            $allocation->setMacAddress($macAddress);
        }

        $this->entityManager->flush();
    }

    /**
     * Back on offer.
     *
     * Called the moment a creation fails, and not later: without that, a range empties itself one
     * failed attempt at a time and nobody notices until it holds nothing but addresses no machine
     * carries.
     */
    public function release(IpAllocation $allocation): void
    {
        $allocation->setStatus(IpAllocationStatus::Released);
        $this->entityManager->flush();
    }

    /**
     * Adopts an address the scan found on a machine the registry knew nothing about - the
     * « Découverte » line of the gaps card. Writes nothing to Proxmox: it only stops the registry
     * claiming that address is free.
     */
    public function adopt(IpRange $range, string $ip, ?int $vmid, ?string $node, ?string $hostname, ?string $macAddress): ?IpAllocation
    {
        if (null !== $this->repository->findLiveByAddress($range, $ip)) {
            return null;
        }

        $allocation = new IpAllocation($range, $ip, IpAllocationOrigin::Discovered);
        $allocation->setVmid($vmid);
        $allocation->setNode($node);
        $allocation->setHostname($hostname);
        $allocation->setMacAddress($macAddress);
        // Confirmed rather than assigned: a machine is demonstrably carrying it right now, which is
        // a stronger fact than anything MonCampus could claim about an address it handed out.
        $allocation->setStatus(IpAllocationStatus::Confirmed);

        $this->entityManager->persist($allocation);
        $this->entityManager->flush();

        return $allocation;
    }

    /**
     * Declares an address for something that is not a Proxmox guest at all - a printer, a switch.
     * These must never be offered, and the scan must never call them orphaned.
     */
    public function declareExternal(IpRange $range, string $ip, string $note): IpAllocation
    {
        $allocation = new IpAllocation($range, $ip, IpAllocationOrigin::External);
        $allocation->setHostname($note);
        $allocation->setNote($note);
        $allocation->setStatus(IpAllocationStatus::Confirmed);

        $this->entityManager->persist($allocation);
        $this->entityManager->flush();

        return $allocation;
    }

    /**
     * Frees reservations nothing came of. Run by `app:proxmox:scan-addresses`; without it an
     * abandoned wizard holds an address for ever.
     */
    public function releaseStaleReservations(int $afterSeconds = 1800): int
    {
        $stale = $this->repository->findStaleReservations(new \DateTimeImmutable(\sprintf('-%d seconds', $afterSeconds)));

        foreach ($stale as $allocation) {
            $allocation->setStatus(IpAllocationStatus::Released);
        }

        if ([] !== $stale) {
            $this->entityManager->flush();
        }

        return \count($stale);
    }

    public function isInsideWindow(IpRange $range, string $ip): bool
    {
        $value = $this->calculator->toLong($ip);
        $first = $this->calculator->toLong($range->getFirstUsable());
        $last = $this->calculator->toLong($range->getLastUsable());

        return null !== $value && null !== $first && null !== $last && $value >= $first && $value <= $last;
    }

    /**
     * Serialises the readers of one range. Taken on the range row rather than on the allocations,
     * because the thing being protected is the *decision* - "which address is free" - and that
     * decision reads rows that do not exist yet.
     */
    private function lock(IpRange $range): void
    {
        $this->entityManager->lock($range, LockMode::PESSIMISTIC_WRITE);
    }

    private function persistReservation(IpRange $range, string $ip, ?string $hostname): IpAllocation
    {
        $allocation = new IpAllocation($range, $ip);
        $allocation->setHostname($hostname);

        $this->entityManager->persist($allocation);
        $this->entityManager->flush();

        return $allocation;
    }
}
