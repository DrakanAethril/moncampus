<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LdapManageAccount;
use App\Entity\User;
use App\Enum\LdapAccountAction;
use App\Service\LdapAccountVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Ldap\Adapter\CollectionInterface;
use Symfony\Component\Ldap\Adapter\QueryInterface;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\LdapInterface;

/**
 * The second proof, read attribute by attribute.
 *
 * What is under test is mostly what the verifier refuses to say. Three situations look identical
 * from a distance - the directory being down, the attribute not being configured, the entry not
 * carrying it - and in all three the honest answer is "not verified, and here is why", never "the
 * script is lying". Development lives permanently in the second of them, and a warning that cries
 * wolf at every local deactivation is a warning nobody reads on the day it means something.
 */
class LdapAccountVerifierTest extends TestCase
{
    /**
     * @param array<string, Entry|null> $entriesByLogin
     */
    private function verifier(array $entriesByLogin, string $statusAttribute = 'userAccountControl', bool $unreachable = false): LdapAccountVerifier
    {
        $ldap = $this->createStub(LdapInterface::class);
        $ldap->method('escape')->willReturnArgument(0);

        if ($unreachable) {
            $ldap->method('bind')->willThrowException(new ConnectionException('down'));
        }

        $ldap->method('query')->willReturnCallback(
            function (string $dn, string $filter) use ($entriesByLogin): QueryInterface {
                // The filter is "(uid=someone)"; the login is what sits between "=" and ")".
                $login = substr($filter, (int) strpos($filter, '=') + 1, -1);
                $entry = $entriesByLogin[$login] ?? null;

                $collection = $this->createStub(CollectionInterface::class);
                $collection->method('offsetExists')->willReturn(null !== $entry);
                $collection->method('offsetGet')->willReturn($entry);

                $query = $this->createStub(QueryInterface::class);
                $query->method('execute')->willReturn($collection);

                return $query;
            },
        );

        return new LdapAccountVerifier(
            $ldap,
            'dc=beaupeyrat,dc=lan',
            'ou=users,dc=beaupeyrat,dc=lan',
            'cn=svc-app,ou=service,dc=beaupeyrat,dc=lan',
            'password',
            'uid',
            $statusAttribute,
        );
    }

    private function entry(string $login, ?int $userAccountControl = null): Entry
    {
        $attributes = ['uid' => [$login]];

        if (null !== $userAccountControl) {
            $attributes['userAccountControl'] = [(string) $userAccountControl];
        }

        return new Entry(\sprintf('uid=%s,ou=users,dc=beaupeyrat,dc=lan', $login), $attributes);
    }

    private function request(LdapAccountAction $action, ?string $newLogin = null, int $state = 2): LdapManageAccount
    {
        $request = new LdapManageAccount(new User('croux'), $action, $newLogin);
        $request->setState($state);

        return $request;
    }

    // --- The ACCOUNTDISABLE bit ------------------------------------------------------------------

    public function testADisabledEntryConfirmsADeactivation(): void
    {
        // 512 is NORMAL_ACCOUNT, 514 the same with 0x0002 set.
        $request = $this->request(LdapAccountAction::Disable);
        $this->verifier(['croux' => $this->entry('croux', 514)])->verify($request);

        self::assertNotNull($request->getVerificationDate());
        self::assertNull($request->getVerificationNote());
        self::assertTrue($request->isSucceededAndVerified());
    }

    public function testAnEntryStillEnabledDoesNotConfirmADeactivation(): void
    {
        $request = $this->request(LdapAccountAction::Disable);
        $this->verifier(['croux' => $this->entry('croux', 512)])->verify($request);

        self::assertNull($request->getVerificationDate());
        self::assertSame(LdapAccountVerifier::NOTE_STILL_ENABLED, $request->getVerificationNote());
        self::assertTrue($request->isSucceededUnverified(), 'Orange, and never green.');
    }

    public function testAnEnabledEntryConfirmsAReactivation(): void
    {
        $request = $this->request(LdapAccountAction::Enable);
        $this->verifier(['croux' => $this->entry('croux', 512)])->verify($request);

        self::assertNotNull($request->getVerificationDate());
    }

    public function testAnEntryStillDisabledDoesNotConfirmAReactivation(): void
    {
        $request = $this->request(LdapAccountAction::Enable);
        $this->verifier(['croux' => $this->entry('croux', 514)])->verify($request);

        self::assertSame(LdapAccountVerifier::NOTE_STILL_DISABLED, $request->getVerificationNote());
    }

    /**
     * The bit is read, not the number: an account with other flags set (66048 is
     * NORMAL_ACCOUNT | DONT_EXPIRE_PASSWORD, and 66050 the same one closed) must read the same way.
     */
    public function testOtherFlagsInTheSameAttributeChangeNothing(): void
    {
        $disabled = $this->request(LdapAccountAction::Disable);
        $this->verifier(['croux' => $this->entry('croux', 66050)])->verify($disabled);
        self::assertNotNull($disabled->getVerificationDate());

        $stillOpen = $this->request(LdapAccountAction::Disable);
        $this->verifier(['croux' => $this->entry('croux', 66048)])->verify($stillOpen);
        self::assertSame(LdapAccountVerifier::NOTE_STILL_ENABLED, $stillOpen->getVerificationNote());
    }

    // --- The three things that stop it short -----------------------------------------------------

    /** Development, every day: the OpenLDAP container has no such attribute at all. */
    public function testAnUnconfiguredAttributeLeavesItUnverifiedWithTheReason(): void
    {
        $request = $this->request(LdapAccountAction::Disable);
        $this->verifier(['croux' => $this->entry('croux')], statusAttribute: '')->verify($request);

        self::assertNull($request->getVerificationDate());
        self::assertSame(LdapAccountVerifier::NOTE_ATTRIBUTE_NOT_CONFIGURED, $request->getVerificationNote());
    }

    public function testAnEntryWithoutTheAttributeLeavesItUnverified(): void
    {
        $request = $this->request(LdapAccountAction::Disable);
        $this->verifier(['croux' => $this->entry('croux')])->verify($request);

        self::assertSame(LdapAccountVerifier::NOTE_ATTRIBUTE_MISSING, $request->getVerificationNote());
    }

    public function testAnUnreachableDirectoryIsNotAFailure(): void
    {
        $request = $this->request(LdapAccountAction::Disable);
        $this->verifier([], unreachable: true)->verify($request);

        self::assertNull($request->getVerificationDate());
        self::assertSame(LdapAccountVerifier::NOTE_DIRECTORY_UNREACHABLE, $request->getVerificationNote());
        self::assertSame(2, $request->getState(), 'What the script said, the script said.');
    }

    public function testAMissingEntryIsReported(): void
    {
        $request = $this->request(LdapAccountAction::Disable);
        $this->verifier([])->verify($request);

        self::assertSame(LdapAccountVerifier::NOTE_ENTRY_MISSING, $request->getVerificationNote());
    }

    // --- The rename, which is conclusive on both directories -------------------------------------

    public function testARenameIsConfirmedWhenTheNewLoginAnswersAndTheOldDoesNot(): void
    {
        $request = $this->request(LdapAccountAction::LoginChange, 'cderoux');
        $this->verifier(['cderoux' => $this->entry('cderoux')])->verify($request);

        self::assertNotNull($request->getVerificationDate());
    }

    public function testARenameIsNotConfirmedWhileTheOldLoginStillAnswers(): void
    {
        $request = $this->request(LdapAccountAction::LoginChange, 'cderoux');
        $this->verifier([
            'cderoux' => $this->entry('cderoux'),
            'croux' => $this->entry('croux'),
        ])->verify($request);

        self::assertNull($request->getVerificationDate());
        self::assertSame(LdapAccountVerifier::NOTE_OLD_LOGIN_STILL_THERE, $request->getVerificationNote());
    }

    public function testARenameIsNotConfirmedWhenTheNewLoginIsNowhere(): void
    {
        $request = $this->request(LdapAccountAction::LoginChange, 'cderoux');
        $this->verifier(['croux' => $this->entry('croux')])->verify($request);

        self::assertSame(LdapAccountVerifier::NOTE_NEW_LOGIN_MISSING, $request->getVerificationNote());
    }

    /** A rename is verified even where no status attribute is configured - dev included. */
    public function testARenameDoesNotNeedTheStatusAttribute(): void
    {
        $request = $this->request(LdapAccountAction::LoginChange, 'cderoux');
        $this->verifier(['cderoux' => $this->entry('cderoux')], statusAttribute: '')->verify($request);

        self::assertNotNull($request->getVerificationDate());
    }

    // --- What it refuses to look at at all -------------------------------------------------------

    public function testAFailedRowHasNothingToConfirm(): void
    {
        $request = $this->request(LdapAccountAction::Disable, state: 3);
        $this->verifier(['croux' => $this->entry('croux', 514)])->verify($request);

        self::assertNull($request->getVerificationDate());
        self::assertNull($request->getVerificationNote());
    }

    public function testARowStillRunningHasNotMadeItsClaimYet(): void
    {
        $request = $this->request(LdapAccountAction::Disable, state: 1);
        $this->verifier(['croux' => $this->entry('croux', 514)])->verify($request);

        self::assertNull($request->getVerificationDate());
    }

    public function testAnAlreadyVerifiedRowIsNotReadAgain(): void
    {
        $request = $this->request(LdapAccountAction::Disable);
        $stamped = new \DateTimeImmutable('2026-08-24 09:00:00');
        $request->setVerificationDate($stamped);

        $this->verifier(['croux' => $this->entry('croux', 512)])->verify($request);

        self::assertSame($stamped, $request->getVerificationDate());
    }
}
