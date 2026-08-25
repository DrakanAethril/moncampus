<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LdapManageAccount;
use App\Entity\User;
use App\Enum\LdapAccountAction;
use App\Repository\LdapManageAccountRepository;
use App\Service\LdapAccountRequestException;
use App\Service\LdapAccountRequestService;
use App\Service\LoginGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide whether a request may be posted at all - the part of the feature that is
 * pure reasoning, and the only part that can be got wrong silently: a refused request leaves no
 * trace, so nothing but a test says the refusal happened for the right reason.
 */
class LdapAccountRequestServiceTest extends TestCase
{
    private LdapManageAccountRepository&Stub $requests;
    private LoginGenerator&Stub $loginGenerator;
    private EntityManagerInterface&MockObject $entityManager;
    private LdapAccountRequestService $service;
    private User $target;
    private User $administrator;

    protected function setUp(): void
    {
        // Stubs where the test only needs an answer, a mock where what matters is whether the row
        // was written at all - which is precisely the question every refusal below asks.
        $this->requests = $this->createStub(LdapManageAccountRepository::class);
        $this->loginGenerator = $this->createStub(LoginGenerator::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new LdapAccountRequestService(
            $this->requests,
            $this->loginGenerator,
            $this->entityManager,
        );

        $this->target = new User('croux');
        $this->administrator = new User('sthar');
    }

    public function testADeactivationIsQueuedWithASnapshotOfTheLogin(): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $row = $this->service->disable($this->target, $this->administrator);

        self::assertSame(LdapAccountAction::Disable, $row->getActionType());
        self::assertSame('croux', $row->getLogin());
        self::assertNull($row->getNewLogin());
        self::assertSame('sthar', $row->getAddedBy());
        self::assertSame(0, $row->getState());
        self::assertSame($this->target, $row->getUser());
    }

    public function testAReactivationIsTheSameRowWithTheOtherAction(): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->entityManager->expects(self::once())->method('persist');

        $row = $this->service->enable($this->target, $this->administrator);

        self::assertSame(LdapAccountAction::Enable, $row->getActionType());
        self::assertNull($row->getNewLogin());
    }

    public function testARenameCarriesBothLogins(): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->loginGenerator->method('loginTaken')->willReturn(false);
        $this->entityManager->expects(self::once())->method('persist');

        $row = $this->service->changeLogin($this->target, 'cderoux', $this->administrator);

        self::assertSame(LdapAccountAction::LoginChange, $row->getActionType());
        self::assertSame('croux', $row->getLogin());
        self::assertSame('cderoux', $row->getNewLogin());
    }

    /**
     * The rule the whole queue rests on. Two renames crossing, or a deactivation landing in the
     * middle of a rename, would run two scripts on the same login in an order nobody chose.
     */
    public function testASecondRequestIsRefusedWhileOneIsStillRunning(): void
    {
        $pending = new LdapManageAccount($this->target, LdapAccountAction::LoginChange, 'cderoux');
        $this->requests->method('findPendingForUser')->willReturn($pending);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(LdapAccountRequestException::class);
        $this->expectExceptionMessage('ldapAccountRequestAlreadyPendingMessage');

        $this->service->disable($this->target, $this->administrator);
    }

    /** Same rule, whichever of the three gestures asks for it. */
    public function testTheRuleHoldsForEveryAction(): void
    {
        $pending = new LdapManageAccount($this->target, LdapAccountAction::Disable);
        $this->requests->method('findPendingForUser')->willReturn($pending);
        $this->loginGenerator->method('loginTaken')->willReturn(false);
        $this->entityManager->expects(self::never())->method('persist');

        foreach ([
            fn () => $this->service->disable($this->target, $this->administrator),
            fn () => $this->service->enable($this->target, $this->administrator),
            fn () => $this->service->changeLogin($this->target, 'cderoux', $this->administrator),
        ] as $gesture) {
            try {
                $gesture();
                self::fail('A second request must be refused while one is still in the queue.');
            } catch (LdapAccountRequestException $exception) {
                self::assertSame('ldapAccountRequestAlreadyPendingMessage', $exception->getMessage());
            }
        }
    }

    /**
     * There is one administrator on this platform. Closing their own account would lock them out of
     * the only screen that could open it again.
     */
    public function testAnAdministratorCannotDeactivateTheirOwnAccount(): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(LdapAccountRequestException::class);
        $this->expectExceptionMessage('userCannotDeactivateSelfFlashMessage');

        $this->service->disable($this->administrator, $this->administrator);
    }

    /** Reactivating and renaming oneself close nothing: only the deactivation is refused. */
    public function testTheOtherTwoGesturesOnOneselfArePerfectlyLegitimate(): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->loginGenerator->method('loginTaken')->willReturn(false);
        $this->entityManager->expects(self::exactly(2))->method('persist');

        self::assertSame(LdapAccountAction::Enable, $this->service->enable($this->administrator, $this->administrator)->getActionType());
        self::assertSame('stharaud', $this->service->changeLogin($this->administrator, 'stharaud', $this->administrator)->getNewLogin());
    }

    public function testALoginAlreadyTakenIsRefused(): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->loginGenerator->method('loginTaken')->willReturn(true);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(LdapAccountRequestException::class);
        $this->expectExceptionMessage('ldapAccountLoginTakenMessage');

        $this->service->changeLogin($this->target, 'cderoux', $this->administrator);
    }

    public function testRenamingToTheCurrentLoginIsRefused(): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(LdapAccountRequestException::class);
        $this->expectExceptionMessage('ldapAccountLoginUnchangedMessage');

        $this->service->changeLogin($this->target, 'croux', $this->administrator);
    }

    /**
     * The typed login is normalised before anything else looks at it: the directory's identifiers
     * are lowercase, and " Cderoux " is somebody who typed a login, not a different one.
     */
    public function testTheTypedLoginIsTrimmedAndLowercased(): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->entityManager->expects(self::once())->method('persist');
        $asked = [];
        $this->loginGenerator->method('loginTaken')->willReturnCallback(
            static function (string $login) use (&$asked): bool {
                $asked[] = $login;

                return false;
            },
        );

        self::assertSame('cderoux', $this->service->changeLogin($this->target, '  CDeRoux ', $this->administrator)->getNewLogin());
        self::assertSame(['cderoux'], $asked, 'The question asked of the two sources is the normalised login.');
    }

    /** And "croux" typed as "CROUX" is still the current login, so still refused. */
    public function testNormalisationRunsBeforeTheUnchangedCheck(): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(LdapAccountRequestException::class);
        $this->expectExceptionMessage('ldapAccountLoginUnchangedMessage');

        $this->service->changeLogin($this->target, 'CROUX', $this->administrator);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedLogins(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'a space inside' => ['c de roux'];
        yield 'an accent' => ['cdéroux'];
        yield 'a slash' => ['../etc/passwd'];
        yield 'an at sign' => ['cderoux@etu.beaupeyrat.org'];
        yield 'starting with a digit' => ['1cderoux'];
        yield 'a single character' => ['c'];
    }

    /**
     * A login reaches a shell command on the domain controller and names a directory on the file
     * server. It is checked here, at the boundary, and not left to the script to survive.
     */
    #[DataProvider('malformedLogins')]
    public function testAMalformedLoginIsRefused(string $login): void
    {
        $this->requests->method('findPendingForUser')->willReturn(null);
        // A malformed login is refused on its shape alone: nothing is asked of the database about a
        // string that was never going to be a login.
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(LdapAccountRequestException::class);
        $this->expectExceptionMessage('ldapAccountLoginInvalidMessage');

        $this->service->changeLogin($this->target, $login, $this->administrator);
    }

    /**
     * Retrying a failed row inserts a new one rather than resetting the old: a queue row is the
     * trace of one attempt, not a counter. The old row is state 3, so it does not block.
     */
    public function testRetryingPostsANewRowRatherThanReusingTheFailedOne(): void
    {
        $failed = new LdapManageAccount($this->target, LdapAccountAction::LoginChange, 'cderoux');
        $failed->setState(3);

        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->loginGenerator->method('loginTaken')->willReturn(false);
        $this->entityManager->expects(self::once())->method('persist');

        $retry = $this->service->retry($failed, $this->administrator);

        self::assertNotSame($failed, $retry);
        self::assertSame(0, $retry->getState());
        self::assertSame(3, $failed->getState(), 'The failed attempt keeps saying it failed.');
        self::assertSame(LdapAccountAction::LoginChange, $retry->getActionType());
        self::assertSame('cderoux', $retry->getNewLogin());
    }

    /** A retry of a rename whose target login was taken meanwhile is refused like any other. */
    public function testRetryingARenameOntoATakenLoginIsRefused(): void
    {
        $failed = new LdapManageAccount($this->target, LdapAccountAction::LoginChange, 'cderoux');
        $failed->setState(3);

        $this->requests->method('findPendingForUser')->willReturn(null);
        $this->loginGenerator->method('loginTaken')->willReturn(true);
        $this->entityManager->expects(self::never())->method('persist');

        $this->expectException(LdapAccountRequestException::class);
        $this->expectExceptionMessage('ldapAccountLoginTakenMessage');

        $this->service->retry($failed, $this->administrator);
    }
}
