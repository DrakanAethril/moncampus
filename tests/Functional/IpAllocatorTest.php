<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\IpAllocation;
use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Enum\IpAllocationStatus;
use App\Repository\IpAllocationRepository;
use App\Service\Network\AddressUnavailableException;
use App\Service\Network\IpAllocator;
use App\Service\Network\RangeExhaustedException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The allocator against a real database, because the guarantee being tested **is** a database
 * guarantee.
 *
 * MySQL 8 has no partial unique index, so a live allocation's uniqueness rides on a `live_key`
 * column holding the address while the row lives and NULL once it is released - two NULLs never
 * collide in a unique index. The design flags that technique as "to be confirmed by an integration
 * test" and this is that test: nothing about it can be proved with a mock, because a mock is
 * exactly the thing that would agree with whatever the code believes.
 *
 * A KernelTestCase rather than a WebTestCase - there is no HTTP here - with the same
 * one-transaction-per-test rollback as FunctionalTestCase, so the `_test` schema is left as it was
 * found.
 */
class IpAllocatorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private IpAllocator $allocator;
    private IpAllocationRepository $allocations;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->allocator = $container->get(IpAllocator::class);
        $this->allocations = $container->get(IpAllocationRepository::class);

        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /** A three-address window, so "full" is reachable in three calls rather than two hundred. */
    private function range(string $first = '10.30.20.50', string $last = '10.30.20.52'): IpRange
    {
        $user = new User('alloc.admin');
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $this->entityManager->persist($user);

        $host = new ProxmoxHost('Hôte de test', 'pve.example.lan', 'svc');
        $host->setCreatedBy($user);
        $host->setSecretCipher('v1.x.y');
        $this->entityManager->persist($host);

        $range = new IpRange('Réseau de test', $host, '10.30.20.0/24', '10.30.20.1', $first, $last);
        $range->setCreatedBy($user);
        $this->entityManager->persist($range);

        $this->entityManager->flush();

        return $range;
    }

    public function testTheFirstReservationTakesTheFirstAddressOfTheWindow(): void
    {
        $allocation = $this->allocator->reserveNext($this->range());

        self::assertSame('10.30.20.50', $allocation->getIp());
        self::assertSame(IpAllocationStatus::Reserved, $allocation->getStatus());
        self::assertSame('10.30.20.50', $allocation->getLiveKey(), 'a live allocation holds its slot');
    }

    public function testSuccessiveReservationsNeverRepeatAnAddress(): void
    {
        $range = $this->range();

        $addresses = [
            $this->allocator->reserveNext($range)->getIp(),
            $this->allocator->reserveNext($range)->getIp(),
            $this->allocator->reserveNext($range)->getIp(),
        ];

        self::assertSame(['10.30.20.50', '10.30.20.51', '10.30.20.52'], $addresses);
        self::assertSame($addresses, array_unique($addresses));
    }

    public function testAFullRangeRefusesRatherThanRepeating(): void
    {
        $range = $this->range();

        for ($i = 0; $i < 3; ++$i) {
            $this->allocator->reserveNext($range);
        }

        $this->expectException(RangeExhaustedException::class);
        $this->allocator->reserveNext($range);
    }

    public function testAddressesKnownFromProxmoxAreSkipped(): void
    {
        // The registry knowing only its own writes is what makes it lie the moment somebody creates
        // a machine by hand in Proxmox.
        $allocation = $this->allocator->reserveNext($this->range(), ['10.30.20.50', '10.30.20.51']);

        self::assertSame('10.30.20.52', $allocation->getIp());
    }

    public function testTheDatabaseRefusesASecondLiveRowForTheSameAddress(): void
    {
        // Straight at the constraint, bypassing the allocator entirely: this is the guarantee, and
        // it has to hold whatever the application believes.
        $range = $this->range();
        $this->allocator->reserveNext($range);

        $duplicate = new IpAllocation($range, '10.30.20.50');
        $this->entityManager->persist($duplicate);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testReleasingAnAddressFreesItsSlotForGood(): void
    {
        // The whole point of the live_key technique: two NULLs do not collide, so a released row
        // stays as history without occupying anything.
        $range = $this->range();
        $first = $this->allocator->reserveNext($range);

        $this->allocator->release($first);
        self::assertNull($first->getLiveKey());
        self::assertSame(IpAllocationStatus::Released, $first->getStatus());

        $again = $this->allocator->reserveNext($range);
        self::assertSame('10.30.20.50', $again->getIp(), 'a released address goes back on offer');

        $andAgain = $this->allocator->reserveNext($range);
        $this->allocator->release($andAgain);
        $third = $this->allocator->reserveNext($range);
        self::assertSame('10.30.20.51', $third->getIp(), 'two released rows for two addresses coexist');
    }

    public function testAReleasedRowIsKeptRatherThanDeleted(): void
    {
        $range = $this->range();
        $allocation = $this->allocator->reserveNext($range);
        $id = $allocation->getId();

        $this->allocator->release($allocation);

        self::assertNotNull($this->entityManager->find(IpAllocation::class, $id), 'the registry keeps who held what');
    }

    public function testAFailedCreationReleasesImmediately(): void
    {
        // Without this the range empties itself one failed attempt at a time, and nobody notices
        // until it is full of addresses no machine holds.
        $range = $this->range();
        $allocation = $this->allocator->reserveNext($range);

        $this->allocator->release($allocation);

        self::assertCount(0, $this->allocations->findLive($range));
        self::assertSame('10.30.20.50', $this->allocator->reserveNext($range)->getIp());
    }

    public function testAskingForAParticularAddressWorksAndThenDoesNot(): void
    {
        $range = $this->range();

        self::assertSame('10.30.20.51', $this->allocator->reserve($range, '10.30.20.51')->getIp());

        $this->expectException(AddressUnavailableException::class);
        $this->allocator->reserve($range, '10.30.20.51');
    }

    public function testAnAddressOutsideTheWindowIsRefused(): void
    {
        // The window is the whole point of the first/last fields: .1 is the gateway.
        $this->expectException(AddressUnavailableException::class);
        $this->allocator->reserve($this->range(), '10.30.20.1');
    }

    public function testAdoptingADiscoveredAddressStopsItBeingOffered(): void
    {
        $range = $this->range();

        $adopted = $this->allocator->adopt($range, '10.30.20.50', 219, 'pve1', 'srv-gitlab', 'bc:24:11:9f:03:2a');

        self::assertNotNull($adopted);
        self::assertSame(IpAllocationStatus::Confirmed, $adopted->getStatus());
        self::assertSame('BC:24:11:9F:03:2A', $adopted->getMacAddress(), 'MACs are compared as strings, so they are stored uppercase');
        self::assertSame('10.30.20.51', $this->allocator->reserveNext($range)->getIp());
    }

    public function testAdoptingAnAddressAlreadyHeldChangesNothing(): void
    {
        $range = $this->range();
        $this->allocator->reserveNext($range);

        self::assertNull($this->allocator->adopt($range, '10.30.20.50', 219, 'pve1', 'srv-gitlab', null));
    }

    public function testAnExternalAddressIsNeverOffered(): void
    {
        // A printer is not a machine, and the scan must never report it as orphaned either.
        $range = $this->range();
        $this->allocator->declareExternal($range, '10.30.20.50', 'Imprimante salle B12');

        self::assertSame('10.30.20.51', $this->allocator->reserveNext($range)->getIp());
    }

    public function testStaleReservationsAreFreedAndLiveOnesAreNot(): void
    {
        $range = $this->range();
        $abandoned = $this->allocator->reserveNext($range);
        $fresh = $this->allocator->reserveNext($range);

        // Backdated rather than waited for - the rule is about elapsed time, and a test that slept
        // thirty minutes would be a test nobody runs.
        (new \ReflectionProperty(IpAllocation::class, 'reservedAt'))->setValue($abandoned, new \DateTimeImmutable('-2 hours'));
        $this->entityManager->flush();

        self::assertSame(1, $this->allocator->releaseStaleReservations());
        self::assertSame(IpAllocationStatus::Released, $abandoned->getStatus());
        self::assertSame(IpAllocationStatus::Reserved, $fresh->getStatus(), 'a reservation somebody is still using stays');
    }

    public function testAReservationBackedByAnOperationIsNeverConsideredAbandoned(): void
    {
        // A creation that is genuinely under way holds its address however long it takes.
        $range = $this->range();
        $allocation = $this->allocator->reserveNext($range);
        $this->allocator->assign($allocation, 231, 'pve1');

        (new \ReflectionProperty(IpAllocation::class, 'reservedAt'))->setValue($allocation, new \DateTimeImmutable('-2 hours'));
        $this->entityManager->flush();

        self::assertSame(0, $this->allocator->releaseStaleReservations());
    }

    public function testTheLifecycleReachesConfirmedOnlyWhenAMachineAnswers(): void
    {
        $range = $this->range();
        $allocation = $this->allocator->reserveNext($range);

        $this->allocator->assign($allocation, 231, 'pve1');
        self::assertSame(IpAllocationStatus::Assigned, $allocation->getStatus());
        self::assertNull($allocation->getConfirmedAt());

        $this->allocator->confirm($allocation, '02:4D:43:1A:07:E7');
        self::assertSame(IpAllocationStatus::Confirmed, $allocation->getStatus());
        self::assertNotNull($allocation->getConfirmedAt());
        self::assertSame(231, $allocation->getVmid());
    }
}
