<?php

declare(strict_types=1);

namespace App\Tests\Functional\SchoolYearImport;

use App\Entity\Program;
use App\Entity\SchoolYearImport;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The import's screens through HTTP: choose, check, the POST that writes and redirects, the report,
 * the trace on the school-years list - and who reaches them.
 */
class SchoolYearImportScreensTest extends FunctionalTestCase
{
    use SchoolYearImportFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function url(string $suffix = ''): string
    {
        return '/settings/structure/school-years/'.$this->targetYear->getId().'/import'.$suffix;
    }

    public function testTheWholeWalkFromChoosingToTheReport(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', $this->url().'?source='.$this->sourceYear->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1, .page-title, title', '2027-2028');
        self::assertCount(1, $crawler->filter('input[name="programs[]"][value="'.$this->sio->getId().'"][checked]'));
        self::assertCount(1, $crawler->filter('input[name="blocks[]"][value="team"][checked]'));
        self::assertCount(0, $crawler->filter('input[name="blocks[]"][value="game"][checked]'));

        $crawler = $this->client->request('GET', $this->url().'?'.http_build_query([
            'source' => $this->sourceYear->getId(),
            'submitted' => 1,
            'step' => 'check',
            'blocks' => ['team', 'topics', 'period_calendars'],
            'programs' => [$this->sio->getId()],
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'non repris');
        self::assertSame([], $this->em()->getRepository(Program::class)->findBy(['schoolYear' => $this->targetYear]), 'The check writes nothing.');

        $form = $crawler->filter('form[action$="/import"][method="post"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();
        $journal = $this->em()->getRepository(SchoolYearImport::class)->findOneBy(['target' => $this->targetYear]);
        self::assertNotNull($journal);
        foreach (['team', 'topics', 'period_calendars'] as $block) {
            self::assertContains($block, $journal->getChoices()['blocks']);
        }
        self::assertStringEndsWith('/imports/'.$journal->getId(), (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-syi-done', '2026-2027');

        $this->client->request('GET', '/settings/structure/school-years/data?draw=1&start=0&length=10');
        /** @var array{data: list<array{id: int, programs: int, imported: bool, importTrace: string}>} $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $target = array_values(array_filter($payload['data'], fn (array $row): bool => $row['id'] === $this->targetYear->getId()))[0];
        self::assertSame(1, $target['programs']);
        self::assertTrue($target['imported']);
        self::assertStringContainsString('2026-2027', $target['importTrace']);
    }

    public function testThePostRefusesAForgedToken(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', $this->url(), ['_token' => 'nope', 'source' => $this->sourceYear->getId(), 'programs' => [$this->sio->getId()]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAReportOfAnotherYearIsNotFound(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/settings/structure/school-years/'.$this->sourceYear->getId().'/imports/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testWhoReachesTheImport(): void
    {
        $staff = $this->createUser(['ROLE_STAFF'], 'import.staff2');
        $student = $this->createUser(['ROLE_STUDENT'], 'import.student');

        $this->assertScreens($staff, [$this->url() => 200]);
        $this->assertScreens($this->teacher, [$this->url() => 403]);
        $this->assertScreens($student, [$this->url() => 403]);
    }
}
