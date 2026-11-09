<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enterprise;
use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * An alternance's optional second tutor holds the booklet with the principal's rights - their own
 * home lists the alternance, they sign « le tuteur »'s part of the engagement, which one signature
 * of either settles - and is named, kept and taken off from the alternance's edit screen.
 */
class SecondaryTutorTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $admin;
    private User $principal;
    private User $secondary;
    private InternshipTutorLink $tutorLink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'secondary.admin');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'secondary.student');
        $this->principal = $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'secondary.principal');
        $this->secondary = $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'secondary.second');
        $this->principal->setContactEmail('principal@acme.test');
        $this->secondary->setContactEmail('second@acme.test');
        $program = $this->createProgram([$student], [], $this->admin);

        $enterprise = new Enterprise('ACME');
        $enterprise->setCreatedBy($this->admin);
        $this->entityManager->persist($enterprise);

        $this->tutorLink = new InternshipTutorLink($program);
        $this->tutorLink->setStudent($student)
            ->setTutor($this->principal)
            ->setSecondaryTutor($this->secondary)
            ->setEnterprise($enterprise)
            ->setContractStartDate(new \DateTimeImmutable('-1 month'))
            ->setContractEndDate(new \DateTimeImmutable('+1 year'));
        $this->tutorLink->setCreatedBy($this->admin);
        $this->entityManager->persist($this->tutorLink);
        $this->entityManager->flush();
    }

    public function testTheSecondaryTutorFindsTheAlternanceOnTheirHome(): void
    {
        $this->client->loginUser($this->secondary);
        $this->client->request('GET', '/my/internship');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Secondary.student Test');
        // « Vous » is whoever reads the page, not the principal tutor.
        self::assertSelectorTextContains('body', 'Secondary.second Test');
        self::assertSelectorTextNotContains('body', 'Secondary.principal');
    }

    public function testTheSecondaryTutorSignsTheTutorsPartOfTheEngagement(): void
    {
        $this->client->loginUser($this->secondary);
        $this->client->request('GET', '/my/internship/'.$this->tutorLink->getId().'/engagement');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/my/internship/'.$this->tutorLink->getId().'/engagement/sign', [
            '_token' => $this->csrfToken('internship_tutor_engagement_sign'),
        ]);

        self::assertResponseRedirects();
        $engagement = $this->entityManager->getRepository(InternshipLivretEngagement::class)->findOneBy(['tutorLink' => $this->tutorLink]);
        self::assertInstanceOf(InternshipLivretEngagement::class, $engagement);
        self::assertNotNull($engagement->getSignedTutorAt());
        self::assertSame($this->secondary->getId(), $engagement->getSignedTutorBy()?->getId());
    }

    public function testAnotherTutorStaysOut(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'secondary.stranger'));
        $this->client->request('GET', '/my/internship/'.$this->tutorLink->getId().'/engagement');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAReminderToTheTutorsGoesToThePrincipalWithTheSecondaryInCopy(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/ufa/alternances/'.$this->tutorLink->getId());
        $this->client->request('POST', '/ufa/alternances/'.$this->tutorLink->getId().'/reminder/send', [], [], [
            'HTTP_X-CSRF-Token' => $this->csrfToken('ufa_alternance_reminder_send'),
        ]);

        self::assertResponseRedirects();
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(['principal@acme.test'], array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo()));
        self::assertSame(['second@acme.test'], array_map(static fn (Address $address): string => $address->getAddress(), $email->getCc()));
    }

    public function testTheEditScreenKeepsThenTakesOffTheSecondaryTutor(): void
    {
        $this->client->loginUser($this->admin);

        // Saved untouched: the block opens on the secondary tutor already named.
        $crawler = $this->client->request('GET', '/ufa/alternances/'.$this->tutorLink->getId().'/edit');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="internship_tutor_link"]')->form());
        self::assertResponseRedirects();
        self::assertSame($this->secondary->getId(), $this->reloadedLink()->getSecondaryTutor()?->getId());

        // « Aucun » takes them off.
        $crawler = $this->client->request('GET', '/ufa/alternances/'.$this->tutorLink->getId().'/edit');
        $form = $crawler->filter('form[name="internship_tutor_link"]')->form();
        $form['internship_tutor_link[secondaryTutor][mode]'] = 'none';
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertNull($this->reloadedLink()->getSecondaryTutor());
        self::assertSame($this->principal->getId(), $this->reloadedLink()->getTutor()?->getId());
    }

    public function testThePrincipalCannotAlsoBeTheSecondaryTutor(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/ufa/alternances/'.$this->tutorLink->getId().'/edit');
        $form = $crawler->filter('form[name="internship_tutor_link"]')->form();
        $form['internship_tutor_link[secondaryTutor][existingTutorId]'] = (string) $this->principal->getId();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le tuteur secondaire doit être une autre personne que le tuteur principal.');
        self::assertSame($this->secondary->getId(), $this->reloadedLink()->getSecondaryTutor()?->getId());
    }

    private function reloadedLink(): InternshipTutorLink
    {
        $this->entityManager->clear();
        $link = $this->entityManager->getRepository(InternshipTutorLink::class)->find($this->tutorLink->getId());
        self::assertInstanceOf(InternshipTutorLink::class, $link);

        return $link;
    }
}
