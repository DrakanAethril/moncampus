<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\Room;
use App\Entity\SeatingPlan;
use App\Entity\SeatingRoomLayout;
use App\Entity\User;
use App\Enum\VisibilityLevel;
use App\Repository\SeatingPlanRepository;
use App\Service\SeatingPlan\SeatingPlanDocument;
use App\Service\SeatingPlan\SeatingRoomTemplates;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Plans de classe » end to end, through the requests the screens make: create a plan in a room
 * already drawn, propose, save, refuse a stale save, share, print - and who sees what.
 */
class SeatingPlanFlowTest extends FunctionalTestCase
{
    private User $teacher;
    private User $colleague;
    private User $admin;
    private User $student;
    private Program $program;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'sp.teacher');
        $this->colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'sp.colleague');
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'sp.admin');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'sp.student');
        $students = [$this->student];
        for ($i = 1; $i <= 5; ++$i) {
            $students[] = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'sp.student'.$i);
        }
        $this->program = $this->createProgram($students, [$this->teacher, $this->colleague], $this->admin);

        $entityManager = $this->entityManager();
        // A formation is hidden from its teachers until it is published; this one is.
        $this->program->setVisibility(VisibilityLevel::Everyone);
        $this->room = new Room('B204');
        $this->room->setCreatedBy($this->admin);
        $entityManager->persist($this->room);
        $entityManager->persist(new SeatingRoomLayout($this->room, (new SeatingRoomTemplates())->rows()->toArray(), $this->teacher));
        $entityManager->flush();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function createPlan(): SeatingPlan
    {
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/tools/seating-plans');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/tools/seating-plans', [
            '_token' => $this->csrfToken('seating_plan'),
            'program' => (string) $this->program->getId(),
            'room' => (string) $this->room->getId(),
            'name' => '',
        ]);
        self::assertResponseRedirects();

        $plans = static::getContainer()->get(SeatingPlanRepository::class)->findBy(['program' => $this->program->getId()]);
        self::assertCount(1, $plans);

        return $plans[0];
    }

    /** @return array<string, mixed> */
    private function json(string $path, array $body): array
    {
        $this->client->request('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken('seating_plan'),
        ], content: json_encode($body, \JSON_THROW_ON_ERROR));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function testAPlanStartsOnTheRoomsDispositionAndIsProposedThenSaved(): void
    {
        $plan = $this->createPlan();
        self::assertSame('B204', $plan->getRoomName());
        self::assertCount(15, SeatingPlanDocument::room($plan->getDocument()['room'] ?? null)->tables, 'the disposition of the room, copied');
        self::assertStringContainsString('B204', $plan->getName());

        $this->client->request('GET', '/tools/seating-plans/'.$plan->getId().'?step=room');
        self::assertResponseIsSuccessful();

        $document = $plan->getDocument();
        $proposal = $this->json('/tools/seating-plans/'.$plan->getId().'/propose', ['document' => $document]);
        self::assertResponseIsSuccessful();
        self::assertCount(6, $proposal['seats'], 'every student of the class seated');
        self::assertSame(2, $proposal['seed']);

        $document['seats'] = $proposal['seats'];
        $document['seed'] = $proposal['seed'];
        $saved = $this->json('/tools/seating-plans/'.$plan->getId().'/save', ['name' => 'Mon plan', 'revision' => 1, 'document' => $document]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $saved['revision']);

        // The same plan saved from a tab that still holds revision 1 would erase the first save.
        $this->json('/tools/seating-plans/'.$plan->getId().'/save', ['name' => 'Mon plan', 'revision' => 1, 'document' => $document]);
        self::assertResponseStatusCodeSame(409);

        $this->entityManager()->clear();
        $stored = static::getContainer()->get(SeatingPlanRepository::class)->find($plan->getId());
        self::assertSame('Mon plan', $stored?->getName());
        self::assertCount(6, $stored->getDocument()['seats']);

        // The printed sheet reads the saved plan: every student's name is on it.
        $crawler = $this->client->request('GET', '/tools/seating-plans/'.$plan->getId().'/print');
        self::assertResponseIsSuccessful();
        $names = $crawler->filter('.cm-sp-seat--print .cm-sp-seat__first')->each(static fn ($node): string => $node->text());
        sort($names);
        self::assertSame(['Sp.student', 'Sp.student1', 'Sp.student2', 'Sp.student3', 'Sp.student4', 'Sp.student5'], $names);
    }

    public function testAMalformedDocumentIsRefusedWhole(): void
    {
        $plan = $this->createPlan();
        $document = SeatingPlanDocument::fromArray($plan->getDocument(), [])->toArray();
        $document['room']['tables'][] = ['id' => 'tx', 'type' => 'double', 'x' => 1, 'y' => 2, 'rotated' => false];

        $answer = $this->json('/tools/seating-plans/'.$plan->getId().'/save', ['name' => 'Mon plan', 'revision' => 1, 'document' => $document]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('seatingPlanOverlappingTablesError', $answer['error']);
    }

    public function testAPlanIsItsAuthorsUntilShared(): void
    {
        $plan = $this->createPlan();
        $path = '/tools/seating-plans/'.$plan->getId();

        $this->assertScreens($this->teacher, [$path => 200, $path.'/print' => 200, $path.'/print?view=desk&full=1' => 200]);
        $this->assertScreens($this->colleague, [$path => 404, $path.'/print' => 404]);
        $this->assertScreens($this->admin, [$path => 404]);

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', $path);
        $this->client->request('POST', $path.'/share', [
            '_token' => $this->csrfToken('seating_plan'),
            'teachers' => [(string) $this->colleague->getId(), (string) $this->student->getId()],
            'right' => 'read',
        ]);
        self::assertResponseRedirects();

        $this->entityManager()->clear();
        $stored = static::getContainer()->get(SeatingPlanRepository::class)->find($plan->getId());
        self::assertCount(1, $stored?->getSharedTeachers() ?? [], 'a student is never a colleague');

        $this->assertScreens($this->colleague, [$path => 200, '/tools/seating-plans' => 200]);
        self::assertStringContainsString($plan->getName(), (string) $this->client->getResponse()->getContent());

        // Read only: the colleague's save is a plan that does not exist for them.
        $this->client->request('GET', $path);
        $this->json($path.'/save', ['name' => 'Repris', 'revision' => 1, 'document' => $plan->getDocument()]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheToolIsATeachersOne(): void
    {
        $this->assertScreens($this->teacher, ['/tools/seating-plans' => 200, '/tools/seating-plans?class=all' => 200, '/tools/seating-plans?class=' => 200]);
        $this->assertScreens($this->admin, ['/tools/seating-plans' => 200]);
        $this->assertScreens($this->student, ['/tools/seating-plans' => 404]);
    }
}
