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

    /** @param array<string, mixed> $query */
    private function wizard(array $query): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->loginUser($this->createUser(['ROLE_ADMIN'], 'wizard.viewer'));

        $crawler = $this->client->request('GET', '/infrastructure/batches/new', $query);

        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
