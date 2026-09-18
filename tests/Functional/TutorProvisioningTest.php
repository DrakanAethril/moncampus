<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Service\InternshipTutorProvisioningService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The account behind an entreprise tutor, against the real schema.
 *
 * Both paths that create one - the alternance screen (App\Service\InternshipTutorFormResolver) and
 * the contract import (App\Service\AlternanceImport\ImportExecutor) - go through the one service
 * asserted here, so the rule is pinned once rather than per screen.
 *
 * What it is worth pinning: nobody ever tells a tutor their password. The directory script invents
 * one, and neither path mails the tutor anything on creation - so the account must arrive in the
 * restricted session App\EventSubscriber\ForcePasswordRenewalSubscriber enforces, whatever reached
 * them having reached them through a person.
 */
class TutorProvisioningTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private InternshipTutorProvisioningService $provisioningService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->provisioningService = static::getContainer()->get(InternshipTutorProvisioningService::class);
    }

    public function testANewTutorMustRenewTheirPasswordBeforeReachingAnything(): void
    {
        $tutor = $this->provision();

        self::assertTrue($tutor->isMustChangePassword());
    }

    // The same account, on the two things the restricted session does not change: the address staff
    // typed is trusted outright (no confirmation mail), and the directory request is queued for the
    // consumer script rather than waited on.
    public function testTheAccountIsOtherwiseTheOneStaffWouldHaveCreatedByHand(): void
    {
        $tutor = $this->provision();

        self::assertSame('tuteur@example.org', $tutor->getContactEmail());
        self::assertTrue($tutor->isContactEmailVerified());
        self::assertContains('ROLE_TUTOR', $tutor->getRoles());
    }

    private function provision(): User
    {
        $operator = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'tutor.operator');
        $tutorLink = new InternshipTutorLink($this->createProgram());

        $tutor = $this->provisioningService->provision(
            $tutorLink,
            'Camille',
            'Berthier',
            'tuteur@example.org',
            '0600000000',
            false,
            $operator,
        );

        $this->entityManager->flush();

        return $tutor;
    }
}
