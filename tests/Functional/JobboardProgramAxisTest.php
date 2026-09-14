<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JobboardOffer;
use App\Entity\Program;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\JobboardContract;
use App\Enum\VisibilityLevel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Jobboard's second axis: each formation says who reads the veille's offers
 * (Program::$jobboardVisibility), **on top of** the role matrix that gates the feature.
 *
 * Four rules, each of which somebody could reasonably have decided the other way:
 *
 * - « Masqué » - what every formation starts on - takes the filière out of the perimeter, so the
 *   offer is not merely undrawn: it cannot be reached by its own URL either;
 * - the tier is a tier, not a switch: a formation may open its board to its teachers a term before
 *   its students, which is the whole reason this is not a checkbox;
 * - two formations resolve the **most permissive**, filière by filière - one open formation is
 *   enough for its own filière, and never for the other's;
 * - an **administrator is outside the rule**: they are the account that garnishes and audits the
 *   veille, and the only one shown an offer's source.
 *
 * The base class opens the axis on every fixture formation (see createProgram()), so each test
 * here closes or re-tiers what it is about.
 */
class JobboardProgramAxisTest extends FunctionalTestCase
{
    private ?User $author = null;

    public function testAMaskedFormationTakesItsFiliereOutOfThePerimeter(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.axis.student');
        $track = $this->trackOf($this->program([$student], [], VisibilityLevel::Hidden));

        $this->offer($track, 'Technicien de ma filière');

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/jobboard');

        // 200 and empty, never 404: the feature is perfectly well lit, there is simply nothing in
        // this reader's perimeter.
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Technicien de ma filière', $crawler->html());
        $this->assertCount(0, $crawler->filter('.cm-jb-row'));
    }

    public function testAnOpenFormationOpensTheBoardToItsStudents(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.axis.student');
        $track = $this->trackOf($this->program([$student], [], VisibilityLevel::Everyone));

        $this->offer($track, 'Technicien de ma filière');

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Technicien de ma filière', $crawler->html());
    }

    /** The point of a tier rather than a checkbox: the teachers first, the class later. */
    public function testATeachersOnlyFormationOpensTheBoardToTheTeacherAndNotToTheStudent(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.axis.student');
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'jobboard.axis.teacher');
        $track = $this->trackOf($this->program([$student], [$teacher], VisibilityLevel::TeachersOnly));

        $this->offer($track, 'Technicien de ma filière');

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/jobboard');
        $this->assertStringContainsString('Technicien de ma filière', $crawler->html());

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/jobboard');
        $this->assertStringNotContainsString('Technicien de ma filière', $crawler->html());
    }

    /** Most permissive across formations - and filière by filière, never across. */
    public function testOneOpenFormationOpensItsOwnFiliereAndNotTheOther(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.axis.student');
        $open = $this->trackOf($this->program([$student], [], VisibilityLevel::Everyone));
        $masked = $this->trackOf($this->program([$student], [], VisibilityLevel::Hidden));

        $this->offer($open, 'Technicien de la formation ouverte');
        $this->offer($masked, 'Technicien de la formation masquée');

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertStringContainsString('Technicien de la formation ouverte', $crawler->html());
        $this->assertStringNotContainsString('Technicien de la formation masquée', $crawler->html());
    }

    /**
     * The personnel are members of no formation, so their reading is the establishment's, narrowed
     * by what each formation decided - and « Personnel et administration » is the tier that says so.
     */
    public function testThePersonnelReadTheFilieresTheFormationsOpenToThem(): void
    {
        $staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'jobboard.axis.staff');
        $masked = $this->trackOf($this->program([], [], VisibilityLevel::Hidden));
        $open = $this->trackOf($this->program([], [], VisibilityLevel::StaffAdmin));

        $this->offer($masked, 'Technicien de la formation masquée');
        $this->offer($open, 'Technicien de la formation ouverte au personnel');

        $this->client->loginUser($staff);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertStringContainsString('Technicien de la formation ouverte au personnel', $crawler->html());
        $this->assertStringNotContainsString('Technicien de la formation masquée', $crawler->html());
    }

    /** An administrator reads a masked formation's filière: no formation closes the audit. */
    public function testAnAdministratorReadsAMaskedFormation(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.axis.admin');
        $track = $this->trackOf($this->program([], [], VisibilityLevel::Hidden));

        $this->offer($track, 'Technicien vu par un administrateur');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/jobboard');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Technicien vu par un administrateur', $crawler->html());
    }

    /**
     * The nav follows the perimeter, not the feature alone: an entry opening on a board that can
     * only ever be empty is a promise the screen cannot keep.
     */
    public function testTheNavEntryFollowsTheFormationsTier(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'jobboard.axis.student');
        $program = $this->program([$student], [], VisibilityLevel::Hidden);

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('href="/jobboard"', $crawler->html());

        $program->setJobboardVisibility(VisibilityLevel::Everyone);
        $this->manager()->flush();

        $crawler = $this->client->request('GET', '/');
        $this->assertStringContainsString('href="/jobboard"', $crawler->html());
    }

    /** @param list<User> $students @param list<User> $teachers */
    private function program(array $students, array $teachers, VisibilityLevel $jobboard): Program
    {
        $program = $this->createProgram($students, $teachers, $this->author());
        $program->setJobboardVisibility($jobboard);
        $this->manager()->flush();

        return $program;
    }

    private function trackOf(Program $program): Track
    {
        $track = $program->getCohort()?->getTrack();
        $this->assertInstanceOf(Track::class, $track);

        return $track;
    }

    /**
     * One author for every structure a test builds: createProgram() mints « fixture.author » when
     * it is given none, and that username is unique - two calls in one test collide.
     */
    private function author(): User
    {
        return $this->author ??= $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.axis.fixture.author');
    }

    private function offer(Track $track, string $position): JobboardOffer
    {
        $offer = new JobboardOffer($track, $this->jobboardSource(), 'REF-'.$track->getId().'-'.crc32($position), new \DateTimeImmutable());
        $offer
            ->setUrl('https://www.hellowork.com/fr-fr/emplois/83313525.html')
            ->setPosition($position)
            ->setCompany('Astek')
            ->setContract(JobboardContract::Cdi)
            ->setLevel('Bac+2')
            ->setPublication(new \DateTimeImmutable('2026-09-12'), false)
        ;

        $this->manager()->persist($offer);
        $this->manager()->flush();

        return $offer;
    }

    /**
     * The same manager the base class opened its transaction on - taken from the container rather
     * than kept as a field, so this test writes on the connection the requests read from.
     */
    private function manager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
