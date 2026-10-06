<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enterprise;
use App\Entity\InternshipFormationCenter;
use App\Entity\InternshipTutorLink;
use App\Entity\Modality;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\VisibilityLevel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Section > Formation > « Livrets d'alternance »: the teachers of a formation in alternance list
 * its alternants and read each booklet - and nothing else.
 *
 * Three things are pinned, each of which can drift on its own:
 *
 * - **the menu offers exactly what answers**: the entry and the screen read one rule
 *   (App\Security\ProgramInternshipBookletAccess), so a formation without the alternance modality
 *   has neither;
 * - **the door is the formation's**: a teacher of another class is refused, a student too;
 * - **it reads only**: the list carries no form and no link out of its own three addresses, the
 *   reader no export.
 */
class ProgramInternshipBookletsTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $admin;
    private User $teacher;
    private User $otherTeacher;
    private User $student;
    private Program $program;
    private InternshipTutorLink $tutorLink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'booklets.admin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'booklets.teacher');
        $this->otherTeacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'booklets.colleague');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'booklets.student');
        $this->program = $this->createProgram([$this->student], [$this->teacher], $this->admin);
        // A class is born « Staff et admin »: its whole submenu is then hidden from its teachers,
        // which is the class's own decision and not the one pinned here.
        $this->program->setVisibility(VisibilityLevel::Everyone);
        // The booklet's first part prints the training centre every installation has.
        $center = new InternshipFormationCenter();
        $center->setCreatedBy($this->admin);
        $this->entityManager->persist($center);
        $this->tutorLink = $this->alternance($this->student);
    }

    public function testAFormationWithoutTheAlternanceModalityHasNeitherTheEntryNorTheScreen(): void
    {
        self::assertNotContains($this->path(), $this->navHrefs($this->teacher));

        $this->client->request('GET', $this->path());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testATeacherOfAFormationInAlternanceIsOfferedTheEntryAndReadsTheList(): void
    {
        $this->openToAlternance();

        self::assertContains($this->path(), $this->navHrefs($this->teacher));

        $crawler = $this->client->request('GET', $this->path());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $main = $crawler->filter('main, .page-body')->first();
        self::assertStringContainsString('Booklets.student Test', $main->text());
        self::assertStringContainsString('ACME', $main->text());
        self::assertContains($this->path('/'.$this->tutorLink->getId()), $main->filter('a')->extract(['href']));
    }

    public function testTheListOffersNothingButReadingABooklet(): void
    {
        $this->openToAlternance();
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', $this->path());

        $table = $crawler->filter('table.card-table');
        self::assertCount(0, $table->filter('form, button'));
        self::assertSame([$this->path('/'.$this->tutorLink->getId())], $table->filter('a')->extract(['href']));
    }

    public function testTheReaderShowsTheBookletWithoutAnyExport(): void
    {
        $this->openToAlternance();
        $this->client->loginUser($this->teacher);

        $crawler = $this->client->request('GET', $this->path('/'.$this->tutorLink->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame($this->path('/'.$this->tutorLink->getId().'/frame'), $crawler->filter('iframe')->attr('src'));
        self::assertCount(0, $crawler->filter('[data-controller~="pdf-download"]'));
        // Outside the navbar, whose calendar entry is a PDF of its own.
        self::assertCount(0, $crawler->filter('.page-wrapper a[href*="/pdf"]'));

        $this->client->request('GET', $this->path('/'.$this->tutorLink->getId().'/frame'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testATeacherOfAnotherClassAndAStudentAreRefused(): void
    {
        $this->openToAlternance();

        foreach ([$this->otherTeacher, $this->student] as $user) {
            self::assertNotContains($this->path(), $this->navHrefs($user), $user->getUsername().' is offered the entry');

            foreach (['', '/'.$this->tutorLink->getId(), '/'.$this->tutorLink->getId().'/frame'] as $suffix) {
                $this->client->request('GET', $this->path($suffix));
                self::assertSame(403, $this->client->getResponse()->getStatusCode(), $user->getUsername().' reaches '.$suffix);
            }
        }
    }

    public function testAFormationThatDoesNotRunItsLivretHereHasNoSuchScreen(): void
    {
        $this->openToAlternance();
        $this->program->setInternshipManagementEnabled(false);
        $this->entityManager->flush();

        self::assertNotContains($this->path(), $this->navHrefs($this->teacher));

        $this->client->request('GET', $this->path());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testOnlyTheLiveAlternancesOfThisFormationAreListedAndRead(): void
    {
        $this->openToAlternance();

        $gone = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'booklets.gone');
        $terminated = $this->alternance($gone);
        $terminated->setInactiveDate(new \DateTimeImmutable('-1 week'));

        $fake = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'booklets.fake');
        $test = $this->alternance($fake);
        $test->setTestAlternance(true);
        $this->entityManager->flush();

        $this->client->loginUser($this->teacher);
        $text = $this->client->request('GET', $this->path())->filter('table.card-table')->text();

        self::assertStringContainsString('Booklets.student Test', $text);
        self::assertStringNotContainsString('Booklets.gone Test', $text);
        self::assertStringNotContainsString('Booklets.fake Test', $text);

        foreach ([$terminated, $test] as $hidden) {
            $this->client->request('GET', $this->path('/'.$hidden->getId()));
            self::assertSame(404, $this->client->getResponse()->getStatusCode());
        }
    }

    private function openToAlternance(): void
    {
        $modality = new Modality('Alternance', '#1b6ec2');
        $modality->setIsAlternance(true);
        $modality->setCreatedBy($this->admin);
        $this->entityManager->persist($modality);
        $this->program->addModality($modality);
        $this->entityManager->flush();
    }

    private function alternance(User $student): InternshipTutorLink
    {
        $enterprise = new Enterprise('ACME');
        $enterprise->setCreatedBy($this->admin);
        $this->entityManager->persist($enterprise);

        $tutorLink = new InternshipTutorLink($this->program);
        $tutorLink->setStudent($student)
            ->setTutor($this->createUser(['ROLE_USER', 'ROLE_TUTOR'], $student->getUsername().'.tutor'))
            ->setEnterprise($enterprise)
            ->setContractStartDate(new \DateTimeImmutable('-1 month'))
            ->setContractEndDate(new \DateTimeImmutable('+1 year'));
        $tutorLink->setCreatedBy($this->admin);
        $this->entityManager->persist($tutorLink);
        $this->entityManager->flush();

        return $tutorLink;
    }

    private function path(string $suffix = ''): string
    {
        return '/programs/'.$this->program->getId().'/internship-booklets'.$suffix;
    }

    /** @return list<string> */
    private function navHrefs(User $user): array
    {
        $this->client->loginUser($user);
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', '/');
        $this->client->followRedirects(false);

        return array_values($crawler->filter('header.navbar a')->extract(['href']));
    }
}
