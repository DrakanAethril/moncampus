<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\GuestAccount;
use App\Entity\User;
use App\Security\Voter\GuestAccountVoter;

/**
 * The rule behind « Mes machines virtuelles », and it admits exactly one thing: the account is
 * yours. This is the voter that lets a student start a machine and set a password on it without
 * holding any administrative role - so what it must never do is let a role stand in for ownership.
 */
class GuestAccountVoterTest extends VoterTestCase
{
    public function testTheAccountsOwnerPassesAndNobodyElseDoes(): void
    {
        $owner = $this->identifiedUser(1, ['ROLE_USER', 'ROLE_STUDENT'], 'celia.l');
        $classmate = $this->identifiedUser(2, ['ROLE_USER', 'ROLE_STUDENT'], 'ana.r');
        $account = $this->account($owner);

        $this->assertGranted(new GuestAccountVoter(), $owner, $account, GuestAccountVoter::OWN);
        $this->assertDenied(new GuestAccountVoter(), $classmate, $account, GuestAccountVoter::OWN);
    }

    /**
     * No staff bypass, deliberately. An administrator reaches every machine through Infrastructure;
     * this attribute answers a question about owning an account, and a bypass would quietly turn it
     * into a question about rank.
     */
    public function testNoRoleStandsInForOwnership(): void
    {
        $owner = $this->identifiedUser(1, ['ROLE_USER', 'ROLE_STUDENT'], 'celia.l');

        foreach ([['ROLE_USER', 'ROLE_ADMIN'], ['ROLE_USER', 'ROLE_TEACHER'], ['ROLE_USER', 'ROLE_STAFF']] as $roles) {
            $this->assertDenied(
                new GuestAccountVoter(),
                $this->identifiedUser(9, $roles, 'somebody'),
                $this->account($owner),
                GuestAccountVoter::OWN,
            );
        }
    }

    /** An account whose person is gone belongs to nobody - not to everybody. */
    public function testAnOwnerlessAccountIsNobodys(): void
    {
        $this->assertDenied(
            new GuestAccountVoter(),
            $this->identifiedUser(1, ['ROLE_USER'], 'celia.l'),
            $this->account(null),
            GuestAccountVoter::OWN,
        );
    }

    /**
     * Two people who have never been saved both answer null, and `null === null` would make every
     * account everybody's. Worth its own test because it is invisible in every other one.
     */
    public function testTwoUnsavedPeopleAreNotTheSamePerson(): void
    {
        $this->assertDenied(
            new GuestAccountVoter(),
            $this->user(['ROLE_USER'], 'nobody'),
            $this->account($this->user(['ROLE_USER'], 'somebody-else')),
            GuestAccountVoter::OWN,
        );
    }

    private function account(?User $owner): GuestAccount
    {
        $account = $this->createStub(GuestAccount::class);
        $account->method('getUser')->willReturn($owner);

        return $account;
    }

    private function identifiedUser(int $id, array $roles, string $username): User
    {
        $user = $this->user($roles, $username);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
