<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\InternshipLivretEngagementRepository;
use App\Service\AlternanceEngagementService;
use App\Service\UfaActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The three engagement signatures arrive from three different portals - the tutor's own, the
 * student's own and the staff screen - so "a terminated alternance is not signed" is asserted here,
 * where all three pass, rather than once per controller.
 */
class AlternanceEngagementServiceTest extends TestCase
{
    public function testNeitherTheTutorNorTheStudentSignsATerminatedAlternance(): void
    {
        $engagement = $this->engagementOfATerminatedAlternance();
        $signatory = $this->createStub(User::class);

        foreach (['signAsTutor', 'signAsStudent'] as $signature) {
            try {
                $this->service()->{$signature}($engagement, $signatory);
                $this->fail(\sprintf('%s() accepted a signature on a terminated alternance.', $signature));
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull($engagement->getSignedTutorAt());
        $this->assertNull($engagement->getSignedStudentAt());
    }

    // The centre's own signature is the one that would otherwise open the evaluation periods, and
    // it has a refusal of its own ("wait for the other two"): both earlier signatures are in here,
    // so the termination is the only thing left that can refuse it.
    public function testTheCentreDoesNotMakeATerminatedAlternancesBookletAvailable(): void
    {
        $engagement = $this->engagementOfATerminatedAlternance();
        $engagement->setSignedTutorAt(new \DateTimeImmutable('2026-01-05'));
        $engagement->setSignedStudentAt(new \DateTimeImmutable('2026-01-06'));

        $this->expectException(\DomainException::class);

        try {
            $this->service()->signAsCenter($engagement, $this->createStub(User::class));
        } finally {
            $this->assertNull($engagement->getSignedCenterAt());
        }
    }

    public function testARunningAlternanceStillTakesTheTutorSignature(): void
    {
        $tutorLink = new InternshipTutorLink($this->createStub(Program::class));
        $engagement = new InternshipLivretEngagement($tutorLink);

        $this->service()->signAsTutor($engagement, $this->createStub(User::class));

        $this->assertNotNull($engagement->getSignedTutorAt());
    }

    private function engagementOfATerminatedAlternance(): InternshipLivretEngagement
    {
        $tutorLink = new InternshipTutorLink($this->createStub(Program::class));
        $tutorLink->setInactiveDate(new \DateTimeImmutable('2026-03-12'));

        return new InternshipLivretEngagement($tutorLink);
    }

    private function service(): AlternanceEngagementService
    {
        return new AlternanceEngagementService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(InternshipLivretEngagementRepository::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(UfaActivityRecorder::class),
        );
    }
}
