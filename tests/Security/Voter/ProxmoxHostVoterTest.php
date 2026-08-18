<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\ProxmoxHost;
use App\Security\Voter\ProxmoxHostVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Three attributes over a hypervisor, and each one is a different question:
 *
 *  - VIEW      is only about the role. A deactivated or unreachable host still reads.
 *  - OPERATE   also needs the host to be active and to allow at least one power action.
 *  - PROVISION also needs the second credential set - the flag alone is not enough, and that is
 *              the pairing worth pinning: allowCreate without a provisioning secret is the state
 *              an administrator lands in by ticking a box and leaving the fields empty.
 *
 * And the attribute that must never appear: there is no destroy. If one is ever added, the fact
 * that nothing here mentions it is what should make the addition feel wrong.
 */
class ProxmoxHostVoterTest extends VoterTestCase
{
    private function voter(bool $isAdmin): ProxmoxHostVoter
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($isAdmin);

        return new ProxmoxHostVoter($checker);
    }

    private function host(
        bool $active = true,
        bool $allowStart = true,
        bool $allowStop = true,
        bool $allowCreate = false,
        bool $hasProvisionCredentials = false,
    ): ProxmoxHost {
        $host = new ProxmoxHost('Serveur labo', 'pve-lab.example.lan', 'svc-moncampus');
        // A persisted host, since that is the only kind a voter is ever asked about - Doctrine
        // hydrates the identifier without going through the constructor, so a test that skipped it
        // would be judging an object the application never sees. Same shape as AudienceResolverTest.
        (new \ReflectionProperty(ProxmoxHost::class, 'id'))->setValue($host, 1);
        $host->setAllowStart($allowStart);
        $host->setAllowStop($allowStop);
        $host->setAllowCreate($allowCreate);

        if ($hasProvisionCredentials) {
            $host->setProvisionUsername('svc-moncampus-provision');
            $host->setProvisionSecretCipher('v1.sealed.sealed');
        }

        if (!$active) {
            $host->setInactiveDate(new \DateTimeImmutable());
        }

        return $host;
    }

    public function testAnAdminReadsPilotsAndCreates(): void
    {
        $voter = $this->voter(true);
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'admin');
        $host = $this->host(allowCreate: true, hasProvisionCredentials: true);

        $this->assertGranted($voter, $admin, $host, ProxmoxHostVoter::VIEW);
        $this->assertGranted($voter, $admin, $host, ProxmoxHostVoter::OPERATE);
        $this->assertGranted($voter, $admin, $host, ProxmoxHostVoter::PROVISION);
    }

    public function testEverybodyElseIsRefusedOnAllThree(): void
    {
        $voter = $this->voter(false);
        $host = $this->host(allowCreate: true, hasProvisionCredentials: true);

        foreach ([
            ['ROLE_USER', 'ROLE_STUDENT'],
            ['ROLE_USER', 'ROLE_TEACHER'],
            ['ROLE_USER', 'ROLE_STAFF'],
            ['ROLE_USER', 'ROLE_STAFF-LEAD'],
            ['ROLE_USER', 'ROLE_TUTOR'],
            ['ROLE_USER', 'ROLE_SUPPORT-TECH'],
        ] as $roles) {
            $user = $this->user($roles, 'someone');

            $this->assertDenied($voter, $user, $host, ProxmoxHostVoter::VIEW, implode('/', $roles).' must not read a hypervisor');
            $this->assertDenied($voter, $user, $host, ProxmoxHostVoter::OPERATE);
            $this->assertDenied($voter, $user, $host, ProxmoxHostVoter::PROVISION);
        }
    }

    public function testAnAnonymousVisitorIsRefused(): void
    {
        $this->assertDenied($this->voter(false), null, $this->host(), ProxmoxHostVoter::VIEW);
    }

    public function testADeactivatedHostStillReadsButIsNoLongerDriven(): void
    {
        $voter = $this->voter(true);
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'admin');
        $host = $this->host(active: false, allowCreate: true, hasProvisionCredentials: true);

        $this->assertGranted($voter, $admin, $host, ProxmoxHostVoter::VIEW, 'reading is how one notices a host is deactivated');
        $this->assertDenied($voter, $admin, $host, ProxmoxHostVoter::OPERATE);
        $this->assertDenied($voter, $admin, $host, ProxmoxHostVoter::PROVISION);
    }

    public function testAHostThatAllowsNoPowerActionIsNotOperated(): void
    {
        $voter = $this->voter(true);
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'admin');

        $this->assertDenied($voter, $admin, $this->host(allowStart: false, allowStop: false), ProxmoxHostVoter::OPERATE);
        $this->assertGranted($voter, $admin, $this->host(allowStart: false, allowStop: true), ProxmoxHostVoter::OPERATE);
    }

    public function testTickingCreateWithoutAProvisioningAccountGrantsNothing(): void
    {
        $voter = $this->voter(true);
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'admin');

        $this->assertDenied(
            $voter,
            $admin,
            $this->host(allowCreate: true, hasProvisionCredentials: false),
            ProxmoxHostVoter::PROVISION,
            'the flag without the second credential set must not open the creation wizard',
        );
    }

    public function testAProvisioningAccountWithoutTheFlagGrantsNothingEither(): void
    {
        $this->assertDenied(
            $this->voter(true),
            $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'admin'),
            $this->host(allowCreate: false, hasProvisionCredentials: true),
            ProxmoxHostVoter::PROVISION,
        );
    }

    public function testTheVoterStaysOutOfOtherDecisions(): void
    {
        $voter = $this->voter(true);
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'admin');

        $this->assertAbstains($voter, $admin, $this->host(), 'PROXMOX_HOST_DESTROY');
        $this->assertAbstains($voter, $admin, new \stdClass(), ProxmoxHostVoter::VIEW);
    }
}
