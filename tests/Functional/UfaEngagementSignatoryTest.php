<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enterprise;
use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The centre's box of the engagement names who is about to sign. The click signs as the connected
 * person, so that is the name the box must carry - never the alternance's suiveur, which it showed
 * to everybody until 2026-10-06.
 */
class UfaEngagementSignatoryTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $supervisor;
    private User $signatory;
    private InternshipTutorLink $tutorLink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->supervisor = $this->createUser(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_TEACHER'], 'engagement.supervisor');
        $this->signatory = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'engagement.signatory');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'engagement.student');
        $tutor = $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'engagement.tutor');
        $program = $this->createProgram([$student], [$this->supervisor], $this->supervisor);

        $enterprise = new Enterprise('ACME');
        $enterprise->setCreatedBy($this->supervisor);
        $this->entityManager->persist($enterprise);

        $this->tutorLink = new InternshipTutorLink($program);
        $this->tutorLink->setStudent($student)
            ->setTutor($tutor)
            ->setSupervisor($this->supervisor)
            ->setEnterprise($enterprise)
            ->setContractStartDate(new \DateTimeImmutable('-1 month'))
            ->setContractEndDate(new \DateTimeImmutable('+1 year'));
        $this->tutorLink->setCreatedBy($this->supervisor);
        $this->entityManager->persist($this->tutorLink);

        $engagement = new InternshipLivretEngagement($this->tutorLink);
        $engagement->setCreatedBy($this->supervisor);
        $engagement->setSignedTutorAt(new \DateTimeImmutable('-2 days'));
        $engagement->setSignedTutorBy($tutor);
        $engagement->setSignedStudentAt(new \DateTimeImmutable('-1 day'));
        $engagement->setSignedStudentBy($student);
        $this->entityManager->persist($engagement);
        $this->entityManager->flush();
    }

    public function testTheCentresBoxNamesTheConnectedPerson(): void
    {
        $this->client->loginUser($this->signatory);
        $crawler = $this->client->request('GET', '/ufa/alternances/'.$this->tutorLink->getId().'/engagement');

        self::assertResponseIsSuccessful();
        $box = $crawler->filter('form[action$="/engagement/sign"]')->ancestors()->first()->text();
        self::assertStringContainsString('Engagement.signatory Test', $box);
        self::assertStringNotContainsString('Engagement.supervisor Test', $box);
    }

    public function testTheSignatureIsTheConnectedPersons(): void
    {
        $this->client->loginUser($this->signatory);
        $this->client->request('GET', '/ufa/alternances/'.$this->tutorLink->getId().'/engagement');
        $this->client->request('POST', '/ufa/alternances/'.$this->tutorLink->getId().'/engagement/sign', [
            '_token' => $this->csrfToken('ufa_alternance_engagement_sign'),
        ]);

        self::assertResponseRedirects();
        $engagement = $this->entityManager->getRepository(InternshipLivretEngagement::class)->findOneBy(['tutorLink' => $this->tutorLink]);
        self::assertInstanceOf(InternshipLivretEngagement::class, $engagement);
        self::assertSame($this->signatory->getId(), $engagement->getSignedCenterBy()?->getId());
    }
}
