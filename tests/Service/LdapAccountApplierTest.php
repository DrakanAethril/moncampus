<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LdapManageAccount;
use App\Entity\User;
use App\Entity\UserLogin;
use App\Enum\LdapAccountAction;
use App\Repository\UserLoginRepository;
use App\Repository\UserRepository;
use App\Service\LdapAccountApplier;
use App\Service\LdapAccountVerifier;
use App\Service\StudentMailProvisioner;
use App\Service\UserLoginHistory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Who draws the consequence, and when.
 *
 * Two rules, and the second is the one this whole design turns on: a deactivation has already been
 * applied by the time it gets here, and a rename must not be applied until the directory has
 * confirmed *and* this application has read that confirmation for itself.
 */
class LdapAccountApplierTest extends TestCase
{
    /**
     * @param ?User $loginHolder     who currently answers to the login being taken, if anybody
     * @param ?User $historicHolder  who *used to* answer to it - just as disqualifying, and the
     *                               reason `user_login` exists: a login another account was renamed
     *                               away from is that account's for ever
     */
    private function applier(?User $loginHolder = null, bool $verifies = false, ?User $historicHolder = null): LdapAccountApplier
    {
        $verifier = $this->createStub(LdapAccountVerifier::class);

        if ($verifies) {
            $verifier->method('verify')->willReturnCallback(
                static fn (LdapManageAccount $row) => $row->setVerificationDate(new \DateTimeImmutable()),
            );
        }

        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturn($loginHolder);

        $logins = $this->createStub(UserLoginRepository::class);
        $logins->method('findOneByLogin')->willReturnCallback(
            fn (string $login): ?UserLogin => null === $historicHolder ? null : new UserLogin($historicHolder, $login),
        );

        return new LdapAccountApplier(
            $verifier,
            $users,
            $this->createStub(StudentMailProvisioner::class),
            new UserLoginHistory($logins, $this->createStub(EntityManagerInterface::class)),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function rename(User $user, string $newLogin, int $state = 2, ?\DateTimeImmutable $verifiedAt = null): LdapManageAccount
    {
        $row = new LdapManageAccount($user, LdapAccountAction::LoginChange, $newLogin);
        $row->setState($state)->setVerificationDate($verifiedAt);

        return $row;
    }

    public function testAVerifiedRenameSwitchesTheUsername(): void
    {
        $user = new User('croux');
        $row = $this->rename($user, 'cderoux', verifiedAt: new \DateTimeImmutable());

        $this->applier()->process($row);

        self::assertSame('cderoux', $user->getUsername());
        self::assertNotNull($row->getAppliedAt());
    }

    public function testAnUnverifiedRenameChangesNothing(): void
    {
        $user = new User('croux');
        $row = $this->rename($user, 'cderoux');

        $this->applier()->process($row);

        self::assertSame('croux', $user->getUsername(), 'The login waits on the directory, always.');
        self::assertNull($row->getAppliedAt());
    }

    public function testAFailedRenameChangesNothing(): void
    {
        $user = new User('croux');
        $row = $this->rename($user, 'cderoux', state: 3, verifiedAt: new \DateTimeImmutable());

        $this->applier()->process($row);

        self::assertSame('croux', $user->getUsername());
        self::assertNull($row->getAppliedAt());
    }

    /** The verifier is asked on the way past, so one call verifies and applies. */
    public function testItVerifiesFirstWhenNobodyHasYet(): void
    {
        $user = new User('croux');
        $row = $this->rename($user, 'cderoux');

        $this->applier(verifies: true)->process($row);

        self::assertNotNull($row->getVerificationDate());
        self::assertSame('cderoux', $user->getUsername());
    }

    /** Both deactivation actions have nothing to apply - only the loop to close. */
    public function testADeactivationOnlyRecordsThatTheLoopIsClosed(): void
    {
        $user = new User('croux');
        $user->setInactiveDate(new \DateTimeImmutable());
        $row = new LdapManageAccount($user, LdapAccountAction::Disable);
        $row->setState(2)->setVerificationDate(new \DateTimeImmutable());

        $this->applier()->process($row);

        self::assertNotNull($row->getAppliedAt());
        self::assertSame('croux', $user->getUsername());
        self::assertNotNull($user->getInactiveDate(), 'Applied at the click, long before this.');
    }

    /**
     * The login was free when the request was posted and somebody took it in between. The directory
     * has already renamed its entry, so this is a state a human has to look at - not a unique
     * constraint blowing up inside a cron.
     */
    public function testARenameOntoALoginSomebodyTookMeanwhileIsRefusedRatherThanCrashing(): void
    {
        $user = new User('croux');
        $row = $this->rename($user, 'cderoux', verifiedAt: new \DateTimeImmutable());

        $this->applier(loginHolder: new User('cderoux'))->process($row);

        self::assertSame('croux', $user->getUsername());
        self::assertNull($row->getAppliedAt(), 'Not settled, so the screen goes on saying so.');
        self::assertSame(LdapAccountApplier::NOTE_LOGIN_TAKEN_LOCALLY, $row->getVerificationNote());
    }

    /** Applying twice is applying once: the fiche's polling and the cron can cross at a minute. */
    public function testApplyingTwiceIsApplyingOnce(): void
    {
        $user = new User('croux');
        $row = $this->rename($user, 'cderoux', verifiedAt: new \DateTimeImmutable());

        $applier = $this->applier();
        $applier->process($row);
        $stamped = $row->getAppliedAt();
        $applier->process($row);

        self::assertSame($stamped, $row->getAppliedAt());
        self::assertSame('cderoux', $user->getUsername());
    }

    /**
     * A login another account was renamed *away from* is that account's for ever. Before
     * `user_login` existed it was free the moment the rename applied, and whoever took it inherited
     * the first person's mail - which is the whole reason for the table.
     */
    public function testARenameOntoALoginAnotherAccountUsedToHoldIsRefused(): void
    {
        $user = new User('croux');
        $row = $this->rename($user, 'cderoux', verifiedAt: new \DateTimeImmutable());

        $this->applier(historicHolder: new User('somebodyelse'))->process($row);

        self::assertSame('croux', $user->getUsername());
        self::assertNull($row->getAppliedAt());
        self::assertSame(LdapAccountApplier::NOTE_LOGIN_TAKEN_LOCALLY, $row->getVerificationNote());
    }

    /**
     * The symmetry the rule needs: reserved against everybody *else*. An account taking back a login
     * it used to answer to is reviving its own row, not competing for somebody's.
     */
    public function testAnAccountMayTakeBackALoginItHeldBefore(): void
    {
        $user = new User('cderoux');
        $row = $this->rename($user, 'croux', verifiedAt: new \DateTimeImmutable());

        $this->applier(historicHolder: $user)->process($row);

        self::assertSame('croux', $user->getUsername());
        self::assertNotNull($row->getAppliedAt());
        self::assertNull($row->getVerificationNote());
    }

    /** The login left behind is written down, not merely overwritten - the bug this table fixes. */
    public function testTheDisplacedLoginIsRecordedAndReleased(): void
    {
        $user = new User('croux');
        $row = $this->rename($user, 'cderoux', verifiedAt: new \DateTimeImmutable());

        $this->applier()->process($row);

        $byLogin = [];
        foreach ($user->getLoginHistory() as $entry) {
            $byLogin[$entry->getLogin()] = $entry;
        }

        self::assertArrayHasKey('croux', $byLogin, 'The login it was renamed away from survives.');
        self::assertNotNull($byLogin['croux']->getReleasedAt());
        self::assertArrayHasKey('cderoux', $byLogin);
        self::assertTrue($byLogin['cderoux']->isCurrent());
    }

    /**
     * The other caller got there first, between this row being read and this line. The row still has
     * to be stamped, or it would be looked at for ever.
     */
    public function testARenameAlreadyReflectedInTheUsernameIsStillStamped(): void
    {
        $user = new User('cderoux');
        $row = $this->rename($user, 'cderoux', verifiedAt: new \DateTimeImmutable());

        $this->applier(loginHolder: $user)->process($row);

        self::assertNotNull($row->getAppliedAt());
        self::assertNull($row->getVerificationNote());
    }
}
