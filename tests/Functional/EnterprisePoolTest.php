<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enterprise;
use App\Entity\EnterpriseContact;
use App\Entity\EnterpriseHosting;
use App\Entity\EnterpriseNote;
use App\Entity\EnterpriseTeacherContact;
use App\Entity\InternshipTutorLink;
use App\Entity\JobApplication;
use App\Entity\JobSearch;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\HostingKind;
use App\Enum\HostingSource;
use App\Service\EnterprisePool\EnterpriseHostings;
use App\Service\EnterprisePool\Import\HostingImportAnalyzer;
use App\Service\EnterprisePool\Import\HostingImportLine;
use App\Service\EnterprisePool\Import\HostingImportRow;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Trouver une entreprise » and the vivier d'entreprises through real requests
 * (design/validated/vivier-entreprises.md): who reaches which screen, what each role reads of a
 * fiche - the team's notes and the hosted students' names above all - and the two places the
 * vivier is written from outside its own screens (the démarches, the closing of a search).
 *
 * Nothing here reaches the État's register: the test client answers « indisponible » (see
 * config/services.yaml, when@test), so every fiche used carries no confirmed SIRET.
 */
class EnterprisePoolTest extends FunctionalTestCase
{
    private User $student;
    private User $teacher;
    private User $admin;
    private Program $program;
    private Enterprise $enterprise;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'pool.student');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'pool.teacher');
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'pool.admin');
        $this->program = $this->createProgram([$this->student], [$this->teacher], $this->admin);

        $this->enterprise = new Enterprise('Clinique des Essais');
        $this->enterprise->setCity('Limoges');
        $this->enterprise->setCreatedBy($this->admin);
        $this->entityManager->persist($this->enterprise);

        $public = (new EnterpriseContact())->setEnterprise($this->enterprise)->setName('Contact Public')->setShareableWithStudents(true);
        $private = (new EnterpriseContact())->setEnterprise($this->enterprise)->setName('Contact Privé');
        $this->entityManager->persist($public);
        $this->entityManager->persist($private);

        $this->entityManager->persist((new EnterpriseHosting())
            ->setEnterprise($this->enterprise)
            ->setKind(HostingKind::Stage)
            ->setYearStart(2023)
            ->setTrack($this->program->getCohort()?->getTrack())
            ->setStudentName('Élève Secrète')
            ->setMissions('Supervision du parc')
            ->setContact($private));
        $this->entityManager->persist(new EnterpriseNote($this->enterprise, $this->teacher, 'Note confidentielle de l\'équipe'));
        $this->entityManager->persist((new EnterpriseTeacherContact($this->enterprise, $this->teacher))->setNote('Raison connue'));
        $this->entityManager->flush();
    }

    public function testWhoReachesWhichScreen(): void
    {
        $fiche = '/enterprises/'.$this->enterprise->getId();

        $this->assertScreens($this->student, [
            '/company-search' => 200,
            '/company-search/categories' => 403,
            $fiche => 200,
            '/enterprises' => 403,
            '/enterprises/hostings/choose' => 403,
            '/enterprises/import' => 403,
        ]);
        $this->assertScreens($this->teacher, [
            '/company-search' => 200,
            '/company-search/categories' => 403,
            $fiche => 200,
            '/enterprises' => 200,
            '/enterprises/hostings/choose' => 403,
            '/enterprises/import' => 403,
        ]);
        $this->assertScreens($this->admin, [
            '/company-search' => 200,
            '/company-search/categories' => 200,
            $fiche => 200,
            '/enterprises' => 200,
            '/enterprises/hostings/choose' => 200,
            '/enterprises/hostings/new?enterprise='.$this->enterprise->getId() => 200,
            '/enterprises/import' => 200,
        ]);
    }

    /** D4, D8, D10, Q2: each role reads its own share of a fiche, and a student none of the team's. */
    public function testEachRoleReadsItsShareOfTheFiche(): void
    {
        $fiche = '/enterprises/'.$this->enterprise->getId();

        $student = $this->contentFor($this->student, $fiche);
        self::assertStringContainsString('Supervision du parc', $student);
        self::assertStringContainsString('Contact Public', $student);
        self::assertStringNotContainsString('Contact Privé', $student);
        self::assertStringNotContainsString('Note confidentielle', $student);
        self::assertStringNotContainsString('Raison connue', $student);
        self::assertStringNotContainsString('Élève Secrète', $student);

        $teacher = $this->contentFor($this->teacher, $fiche);
        self::assertStringContainsString('Contact Privé', $teacher);
        self::assertStringContainsString('Note confidentielle', $teacher);
        self::assertStringContainsString('Raison connue', $teacher);
        self::assertStringNotContainsString('Élève Secrète', $teacher);

        self::assertStringContainsString('Élève Secrète', $this->contentFor($this->admin, $fiche));
    }

    /** D7: the filters on stage and alternance are the administrator's; anybody else's URL is ignored. */
    public function testOnlyTheAdministratorFiltersOnHostings(): void
    {
        self::assertStringContainsString('Clinique des Essais', $this->contentFor($this->teacher, '/enterprises?kind[]=alternance'));
        self::assertStringNotContainsString('Clinique des Essais', $this->contentFor($this->admin, '/enterprises?kind[]=alternance'));
        self::assertStringContainsString('Clinique des Essais', $this->contentFor($this->admin, '/enterprises?kind[]=stage'));
    }

    public function testATeacherNotesAndDeclaresButOnlyEditsTheirOwnNote(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/enterprises/'.$this->enterprise->getId());
        $this->client->request('POST', '/enterprises/'.$this->enterprise->getId().'/notes', [
            '_token' => $this->csrfToken('enterprise_note'), 'body' => 'Une note de l\'administrateur',
        ]);
        self::assertResponseRedirects();

        $note = $this->entityManager->getRepository(EnterpriseNote::class)->findOneBy(['author' => $this->teacher]);
        self::assertNotNull($note);
        $this->client->request('POST', '/enterprises/'.$this->enterprise->getId().'/notes/'.$note->getId().'/edit', [
            '_token' => $this->csrfToken('enterprise_note_edit'), 'body' => 'Réécrite par un autre',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->student);
        $this->client->request('GET', '/company-search');
        $this->client->request('POST', '/enterprises/'.$this->enterprise->getId().'/notes', [
            '_token' => $this->csrfToken('enterprise_note'), 'body' => 'Un étudiant ne note pas',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /** R3: an alternance the UFA holds is read once, from its contract - a typed copy is not a second one. */
    public function testTheUfasAlternancesAreReadAndNeverCountedTwice(): void
    {
        $tutor = $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'pool.tutor');
        $link = (new InternshipTutorLink($this->program))
            ->setStudent($this->student)
            ->setTutor($tutor)
            ->setEnterprise($this->enterprise)
            ->setContractStartDate(new \DateTimeImmutable('today'))
            ->setContractEndDate(new \DateTimeImmutable('+1 year'));
        $link->setCreatedBy($this->admin);
        $this->entityManager->persist($link);

        $hostings = static::getContainer()->get(EnterpriseHostings::class);
        $year = $hostings->yearOf($link);
        $this->entityManager->persist((new EnterpriseHosting())
            ->setEnterprise($this->enterprise)
            ->setKind(HostingKind::Alternance)
            ->setYearStart($year)
            ->setTrack($this->program->getCohort()?->getTrack())
            ->setStudent($this->student));
        $this->entityManager->flush();

        $records = $hostings->forEnterprise($this->enterprise, $this->admin);
        $alternances = array_values(array_filter($records, static fn ($record): bool => HostingKind::Alternance === $record->kind));

        self::assertCount(1, $alternances);
        self::assertTrue($alternances[0]->isFromUfa());
        self::assertCount(1, array_filter($records, static fn ($record): bool => HostingKind::Stage === $record->kind));
    }

    /** §6: a démarche « à écrire » takes a note its student writes, and leaves when nothing was sent. */
    public function testAStudentNotesAndRemovesADemarcheToWrite(): void
    {
        $application = (new JobApplication())->setStudent($this->student)->setProgram($this->program)->setName('Clinique des Essais')->setSiret('12345678900012');
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        $this->client->loginUser($this->student);
        $this->client->request('GET', '/my/applications?filter=to_write');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Clinique des Essais');

        $this->client->request('POST', '/my/applications/'.$application->getId().'/note', [
            '_token' => $this->csrfToken('job_application_note'), 'note' => 'Appeler lundi',
        ]);
        $this->entityManager->clear();
        self::assertSame('Appeler lundi', $this->entityManager->find(JobApplication::class, $application->getId())?->getStudentNote());

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/students/'.$this->student->getId().'/job-search');
        self::assertSelectorTextContains('body', 'Appeler lundi');

        $this->client->loginUser($this->student);
        $this->client->request('GET', '/my/applications');
        $this->client->request('POST', '/my/applications/'.$application->getId().'/remove', ['_token' => $this->csrfToken('job_application_remove')]);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(JobApplication::class, $application->getId()));
    }

    /** §6.4: « Stage trouvé » on a démarche the vivier knows enters the vivier; « Alternance » does not. */
    public function testClosingASearchOnAStageFeedsTheVivier(): void
    {
        $application = (new JobApplication())->setStudent($this->student)->setProgram($this->program)
            ->setName('Clinique des Essais')->setSiret('12345678900012')->setEnterprise($this->enterprise);
        $this->entityManager->persist($application);
        $this->entityManager->flush();

        $path = '/programs/'.$this->program->getId().'/job-search-tracking/'.$this->student->getId().'/close';
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $path, [
            '_token' => $this->csrfToken('job_search_close'), 'outcome' => 'stage', 'application' => $application->getId(),
        ]);
        self::assertResponseRedirects();

        $search = $this->entityManager->getRepository(JobSearch::class)->findOneBy(['student' => $this->student]);
        self::assertSame(HostingKind::Stage, $search?->getOutcomeKind());
        $hosting = $this->entityManager->getRepository(EnterpriseHosting::class)->findOneBy(['enterprise' => $this->enterprise, 'source' => HostingSource::JobSearch]);
        self::assertNotNull($hosting);
        self::assertSame($this->student->getId(), $hosting->getStudent()?->getId());
    }

    /** §7.2: each line says how its company was found; one blocking line refuses the whole file. */
    public function testTheImportAnalysisJudgesEveryLine(): void
    {
        $row = static fn (int $line, array $values): HostingImportRow => new HostingImportRow($line, [
            'type' => 'stage', 'annee' => '2022-2023', 'filiere' => 'Filière de test', 'etudiant' => 'Ancien Élève', ...$values,
        ]);
        $analyzer = static::getContainer()->get(HostingImportAnalyzer::class);

        $analysis = $analyzer->analyze([
            $row(2, ['entreprise' => 'clinique des essais']),
            $row(3, ['entreprise' => 'Nouvelle Entreprise', 'siret' => '123']),
            $row(4, ['entreprise' => 'Nouvelle Entreprise']),
            $row(5, ['entreprise' => 'Autre', 'siret' => '48931910300037', 'type' => 'alternance', 'annee' => '2021/22']),
        ], $this->admin);

        self::assertSame(0, $analysis->blockingCount());
        [$known, $invalidSiret, $duplicate, $withSiret] = $analysis->lines;
        self::assertSame(HostingImportLine::POOL_BY_NAME, $known->enterpriseVerdict);
        self::assertSame($this->enterprise, $known->enterprise);
        self::assertSame(HostingImportLine::NEW_WITHOUT_SIRET, $invalidSiret->enterpriseVerdict);
        self::assertNotEmpty($invalidSiret->warnings);
        self::assertTrue($duplicate->skipped);
        self::assertSame(HostingImportLine::NEW_WITH_SIRET, $withSiret->enterpriseVerdict);
        self::assertSame(2021, $withSiret->yearStart);
        self::assertSame('Ancien Élève', $withSiret->studentName);
        self::assertSame(2, $analysis->newEnterpriseCount());

        $refused = $analyzer->analyze([$row(2, ['entreprise' => 'X', 'type' => 'stagiaire'])], $this->admin);
        self::assertSame(1, $refused->blockingCount());
        self::assertFalse($refused->isImportable());
    }

    private function contentFor(User $user, string $path): string
    {
        $this->client->loginUser($user);
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return (string) $this->client->getResponse()->getContent();
    }
}
