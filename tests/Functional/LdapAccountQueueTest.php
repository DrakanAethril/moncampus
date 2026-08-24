<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LdapManageAccount;
use App\Enum\LdapAccountAction;
use App\Repository\LdapManageAccountRepository;
use App\Service\LdapAccountRequestException;
use App\Service\LdapAccountRequestService;
use App\Service\LoginGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The queue against a real database - what the unit test of LdapAccountRequestService cannot prove,
 * since it answers its own questions through a stub.
 *
 * Three things only a real query settles: that "still in the queue" means states 0 and 1 and not
 * one more, that a finished row stops blocking, and that the enumeration survives the round trip
 * through a VARCHAR column.
 */
class LdapAccountQueueTest extends FunctionalTestCase
{
    public function testARowSurvivesTheRoundTripThroughTheColumn(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'croux');

        $row = new LdapManageAccount($user, LdapAccountAction::LoginChange, 'cderoux');
        $row->setAddedBy('sthar');
        $entityManager->persist($row);
        $entityManager->flush();
        $entityManager->clear();

        $repository = static::getContainer()->get(LdapManageAccountRepository::class);
        $reloaded = $repository->find($row->getId());

        self::assertNotNull($reloaded);
        self::assertSame(LdapAccountAction::LoginChange, $reloaded->getActionType());
        self::assertSame('croux', $reloaded->getLogin(), 'The login is a snapshot taken at request time.');
        self::assertSame('cderoux', $reloaded->getNewLogin());
        self::assertSame('sthar', $reloaded->getAddedBy());
        self::assertSame(0, $reloaded->getState());
        self::assertNull($reloaded->getVerificationDate());
        self::assertNull($reloaded->getAppliedAt());
        self::assertTrue($reloaded->isPending());
    }

    /**
     * States 0 and 1 block; 2 and 3 do not. Written against the query rather than against
     * isPending(), because it is the query the service asks.
     */
    public function testOnlyPendingAndRunningRowsBlockASecondRequest(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(LdapManageAccountRepository::class);
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'blocking.user');

        $row = new LdapManageAccount($user, LdapAccountAction::Disable);
        $entityManager->persist($row);
        $entityManager->flush();

        foreach ([0 => true, 1 => true, 2 => false, 3 => false] as $state => $blocks) {
            $row->setState($state);
            $entityManager->flush();

            self::assertSame($blocks, null !== $repository->findPendingForUser($user), \sprintf('State %d.', $state));
        }
    }

    public function testTheServiceRefusesASecondRequestAndAcceptsOneAfterTheFirstIsDone(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        // Built by hand rather than pulled from the container: with no consumer yet (the screens
        // arrive at lot 4) the service is inlined at compile time and has no id to ask for.
        $service = new LdapAccountRequestService(
            static::getContainer()->get(LdapManageAccountRepository::class),
            static::getContainer()->get(LoginGenerator::class),
            $entityManager,
        );
        $administrator = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'queue.admin');
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'queue.target');

        $first = $service->disable($user, $administrator);
        self::assertNotNull($first->getId());

        try {
            $service->changeLogin($user, 'newlogin', $administrator);
            self::fail('A second request must be refused.');
        } catch (LdapAccountRequestException $exception) {
            self::assertSame('ldapAccountRequestAlreadyPendingMessage', $exception->getMessage());
        }

        $first->setState(2);
        $entityManager->flush();

        $second = $service->enable($user, $administrator);
        self::assertNotSame($first->getId(), $second->getId(), 'Once the first is done, the next one goes through.');
    }

    /**
     * What the cron command picks up - and, above all, what it stops picking up.
     *
     * A row nothing can ever verify (a directory with no account-status attribute, which is every
     * development machine) would otherwise sit at the head of the queue for ever and, past fifty of
     * them, keep today's requests from ever being read.
     */
    public function testTheCronOnlyChasesRowsItStillHasAChanceOfSettling(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(LdapManageAccountRepository::class);
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'awaiting.user');

        $fresh = new LdapManageAccount($user, LdapAccountAction::Disable);
        $fresh->setState(2)->setEndedAt(new \DateTimeImmutable('-2 minutes'));

        $stale = new LdapManageAccount($user, LdapAccountAction::Disable);
        $stale->setState(2)->setEndedAt(new \DateTimeImmutable('-3 days'));

        $failed = new LdapManageAccount($user, LdapAccountAction::Disable);
        $failed->setState(3)->setEndedAt(new \DateTimeImmutable('-2 minutes'));

        $settled = new LdapManageAccount($user, LdapAccountAction::Disable);
        $settled->setState(2)
            ->setEndedAt(new \DateTimeImmutable('-2 minutes'))
            ->setVerificationDate(new \DateTimeImmutable('-1 minute'))
            ->setAppliedAt(new \DateTimeImmutable('-1 minute'));

        foreach ([$fresh, $stale, $failed, $settled] as $row) {
            $entityManager->persist($row);
        }
        $entityManager->flush();

        $awaiting = $repository->findAwaitingApplication();

        self::assertContains($fresh, $awaiting, 'A success nobody has read back yet.');
        self::assertNotContains($stale, $awaiting, 'Three days old: history, not work.');
        self::assertNotContains($failed, $awaiting, 'A failure has nothing to confirm.');
        self::assertNotContains($settled, $awaiting, 'Verified and applied: done.');
    }

    /** No row at all is the ordinary case: most accounts never go through this queue. */
    public function testAnAccountWithNoHistoryHasNoLastOperation(): void
    {
        $repository = static::getContainer()->get(LdapManageAccountRepository::class);
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'untouched.user');

        self::assertNull($repository->findMostRecentForUser($user));
        self::assertSame(0, $repository->countForUser($user));
    }

    /** The fiche's banner reads the last row, whatever state it is in. */
    public function testTheMostRecentRowIsTheLastOnePosted(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(LdapManageAccountRepository::class);
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'history.user');

        foreach ([LdapAccountAction::Disable, LdapAccountAction::Enable, LdapAccountAction::LoginChange] as $action) {
            $row = new LdapManageAccount($user, $action, $action->requiresNewLogin() ? 'renamed' : null);
            $row->setState(2);
            $entityManager->persist($row);
            $entityManager->flush();
        }

        $latest = $repository->findMostRecentForUser($user);
        self::assertInstanceOf(LdapManageAccount::class, $latest);
        self::assertSame(LdapAccountAction::LoginChange, $latest->getActionType());
        self::assertSame(3, $repository->countForUser($user));
    }
}
