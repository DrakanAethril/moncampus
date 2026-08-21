<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The fields of the batch wizard, and when they appear.
 *
 * What this pins is a defect that no unit test can see and that made the screen unusable rather
 * than merely awkward: which fields exist is decided server-side by the shape and the program, so
 * until the form had been submitted once, the saved set and the option filters were **not on the
 * page at all**. Choosing them "before previewing" was impossible - previewing was how you revealed
 * them. The selects that decide now submit on change, which is the whole fix.
 */
class VmBatchWizardFieldsTest extends FunctionalTestCase
{
    public function testTheFieldsThatDecideWhichOthersExistSubmitOnTheirOwn(): void
    {
        $crawler = $this->wizard([]);

        foreach (['#programId', '#shape'] as $selector) {
            self::assertSame(
                'change->auto-submit#submit',
                $crawler->filter($selector)->attr('data-action'),
                $selector.' must reload the form so the fields it governs appear',
            );
        }
    }

    public function testChoosingAProgramIsEnoughToOfferTheSavedSets(): void
    {
        $program = $this->createProgram();

        // No preview pressed, no filters, nothing else: naming the program is the whole gesture.
        $crawler = $this->wizard(['programId' => $program->getId(), 'shape' => 'per_group']);

        self::assertCount(1, $crawler->filter('#groupBatchId'));
    }

    public function testChoosingAProgramIsEnoughToOfferTheOptionFilters(): void
    {
        $entityManager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $program = $this->createProgram();
        $option = new \App\Entity\Option('Solutions d’infrastructure', 'SISR', '#1B6BA8');
        $option->setCreatedBy($this->createUser(['ROLE_ADMIN'], 'wizard.author'));
        $entityManager->persist($option);
        $program->addOption($option);
        $entityManager->flush();

        $crawler = $this->wizard(['programId' => $program->getId(), 'shape' => 'per_student']);

        self::assertCount(1, $crawler->filter('input[name="options[]"]'));
    }

    public function testTheChosenAccountsShapeOffersAPeoplePicker(): void
    {
        $program = $this->createProgram();

        $crawler = $this->wizard(['programId' => $program->getId(), 'shape' => 'for_accounts']);

        $picker = $crawler->filter('#batch-users');

        self::assertCount(1, $picker);
        self::assertSame('tom-select', $picker->attr('data-controller'), 'picking Users always goes through tomselect + ajax');
        self::assertStringContainsString('/infrastructure/batches/users/search', (string) $picker->attr('data-tom-select-url-value'));
        // The set picker belongs to the other shape and would only be noise here.
        self::assertCount(0, $crawler->filter('#groupBatchId'));
    }

    public function testThePickerOnlyOffersActiveStudentsAndTeachers(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_ADMIN'], 'wizard.admin'));
        $student = $this->createUser(['ROLE_STUDENT'], 'celia.l');
        $tutor = $this->createUser(['ROLE_TUTOR'], 'tuteur.entreprise');

        $this->client->request('GET', '/infrastructure/batches/users/search', ['q' => '']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($payload);
        self::assertIsArray($payload['results'] ?? null);

        $joined = implode(' ', array_column($payload['results'], 'text'));

        self::assertStringContainsString($student->getUsername(), $joined);
        // A tutor is somebody's employer; they have no business holding a Unix login in a classroom.
        self::assertStringNotContainsString($tutor->getUsername(), $joined);
    }

    /**
     * Who a per-class batch may name: the teachers of that class, and never an administrator - who
     * already reaches every machine through this very area, so an account for them on twenty-four
     * of them is twenty-four nobody asked for.
     */
    public function testAPerClassBatchOffersTheClassesOwnNonAdminTeachers(): void
    {
        $teacher = $this->createUser(['ROLE_TEACHER'], 'p.roux');
        $adminTeacher = $this->createUser(['ROLE_TEACHER', 'ROLE_ADMIN'], 'la.direction');
        $outsider = $this->createUser(['ROLE_TEACHER'], 'ailleurs');
        $program = $this->createProgram([], [$teacher, $adminTeacher]);

        $crawler = $this->wizard(['programId' => $program->getId(), 'shape' => 'per_student']);

        $offered = $crawler->filter('input[name="teachers[]"]')->extract(['value']);

        self::assertContains((string) $teacher->getId(), $offered);
        self::assertNotContains((string) $adminTeacher->getId(), $offered);
        self::assertNotContains((string) $outsider->getId(), $offered);
    }

    /**
     * A saved set is somebody's piece of work: a batch built on it must not put an account on every
     * machine for a colleague who has never seen it.
     */
    public function testAPerGroupBatchOffersOnlyTheSetsAuthorAndThoseItWasSharedWith(): void
    {
        $author = $this->createUser(['ROLE_TEACHER'], 'p.roux');
        $shared = $this->createUser(['ROLE_TEACHER'], 'a.blanc');
        $stranger = $this->createUser(['ROLE_TEACHER'], 'c.noir');
        $program = $this->createProgram([], [$author, $shared, $stranger]);

        // The administrator building the batch only ever sees sets that are readable by them -
        // their own or shared with them - which is why they are on this one too. Being an
        // administrator, they are not themselves offered as a teacher below.
        $builder = $this->createUser(['ROLE_ADMIN'], 'wizard.viewer');

        $entityManager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $set = new \App\Entity\GroupBatch($program, $author, 'Groupes du TP', [[1], [2]]);
        $set->addSharedTeacher($shared);
        $set->addSharedTeacher($builder);
        $entityManager->persist($set);
        $entityManager->flush();

        $crawler = $this->wizard([
            'programId' => $program->getId(),
            'shape' => 'per_group',
            'groupBatchId' => $set->getId(),
        ], $builder);

        $offered = $crawler->filter('input[name="teachers[]"]')->extract(['value']);

        self::assertContains((string) $author->getId(), $offered);
        self::assertContains((string) $shared->getId(), $offered);
        // In the class, but the set is not theirs.
        self::assertNotContains((string) $stranger->getId(), $offered);
    }

    /**
     * The whole chain, on the screen: a teacher ticked before the preview appears in the accounts of
     * **every** machine, and adds none. This is what the person pressing « Créer » is agreeing to,
     * so it has to be readable there rather than discovered on the machines afterwards.
     */
    public function testATickedTeacherShowsUpOnEveryMachineOfThePreview(): void
    {
        $entityManager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $author = $this->createUser(['ROLE_ADMIN'], 'wizard.viewer');
        $teacher = $this->createUser(['ROLE_TEACHER'], 'p.roux');
        $program = $this->createProgram(
            [$this->createUser(['ROLE_STUDENT'], 'celia.l'), $this->createUser(['ROLE_STUDENT'], 'ana.r')],
            [$teacher],
            $author,
        );

        $host = new \App\Entity\ProxmoxHost('campus', '192.0.2.10', 'svc');
        $host->setPort(8006)->setSecretCipher('sealed')->setCreatedBy($author);
        $entityManager->persist($host);
        $entityManager->flush();

        $crawler = $this->wizard([
            'programId' => $program->getId(),
            'hostId' => $host->getId(),
            'shape' => 'per_student',
            'teachers' => [$teacher->getId()],
        ], $author);

        $accounts = $crawler->filter('table tbody tr td:last-child')->each(static fn ($cell): string => $cell->text());

        self::assertCount(2, $accounts, 'a named teacher adds no machine');

        foreach ($accounts as $cell) {
            self::assertStringContainsString('p.roux', $cell);
        }

        // And the student is still on their own machine, which naming a teacher must not displace.
        self::assertStringContainsString('celia.l', implode(' ', $accounts));
        self::assertStringContainsString('ana.r', implode(' ', $accounts));
    }

    /** @param array<string, mixed> $query */
    private function wizard(array $query, ?\App\Entity\User $as = null): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->loginUser($as ?? $this->createUser(['ROLE_ADMIN'], 'wizard.viewer'));

        $crawler = $this->client->request('GET', '/infrastructure/batches/new', $query);

        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
