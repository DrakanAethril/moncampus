<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallList;
use App\Enum\VisibilityLevel;
use App\Enum\WallCardStatus;
use App\Enum\WallFormat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * « Murs collaboratifs » through real requests (design/design_handoff_murs_collaboratifs): what the
 * screens write, and above all what the server refuses whatever the screen hides - « Les
 * permissions doivent être vérifiées côté serveur ».
 *
 * The rule itself is App\Service\Wall\WallAccess and has its own table of tests; these pin that
 * every door asks it.
 *
 * @phpstan-type WallState array{revision: int, board: string, card: ?string, toast: array{message: string, url: string}}
 */
class WallTest extends FunctionalTestCase
{
    private User $teacher;
    private User $colleague;
    private User $student;
    private User $classmate;
    private User $stranger;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'wall.teacher');
        $this->colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'wall.colleague');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'wall.student');
        $this->classmate = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'wall.classmate');
        $this->stranger = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'wall.stranger');
        $this->program = $this->createProgram([$this->student, $this->classmate], [$this->teacher]);
        // A formation starts visible to the administration alone; a teacher is only offered the
        // classes they can see, which is the virtual board's rule and the wall's.
        $this->program->setVisibility(VisibilityLevel::Everyone);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    public function testANewWallOpensOnTheListsOfItsFormat(): void
    {
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/walls');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/walls', ['_token' => $this->csrfToken('wall'), 'title' => 'Projet AP', 'format' => 'columns']);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(['À faire', 'En cours', 'Terminé'], $crawler->filter('.cm-wall-list__title')->each(static fn ($node): string => $node->text()));

        $this->client->request('POST', '/walls', ['_token' => $this->csrfToken('wall'), 'title' => 'Veille', 'format' => 'grid']);
        $wall = $this->em()->getRepository(Wall::class)->findOneBy(['title' => 'Veille']);
        self::assertInstanceOf(Wall::class, $wall);
        self::assertSame(['Cartes'], $wall->getLists()->map(static fn (WallList $list): string => $list->getTitle())->toArray());

        $crawler = $this->client->request('GET', '/walls');
        self::assertCount(2, $crawler->filter('.cm-wall-tile'));
    }

    public function testACardIsWrittenFilledMovedAndDeleted(): void
    {
        $wall = $this->wall($this->teacher);
        [$todo, $done] = $wall->getLists()->toArray();
        $this->open($this->teacher, $wall);

        $state = $this->post('/walls/lists/'.$todo->getId().'/cards', ['title' => '  Rédiger le   cahier des charges ']);
        self::assertStringContainsString('Rédiger le cahier des charges', $state['board']);
        $card = $this->em()->getRepository(WallCard::class)->findOneBy(['title' => 'Rédiger le cahier des charges']);
        self::assertInstanceOf(WallCard::class, $card);
        $url = '/walls/cards/'.$card->getId();

        // Only the fields named are written: the label leaves the text alone, and the reverse.
        $this->post($url, ['text' => 'Besoins et contraintes.']);
        $this->post($url, ['label' => 'Analyse', 'labelTone' => 'blue']);
        $this->post($url, ['linkUrl' => 'ovhcloud.com/fr/vps']);
        $card = $this->fresh($card);
        self::assertSame('Besoins et contraintes.', $card->getText());
        self::assertSame('Analyse', $card->getLabel());
        self::assertSame('https://ovhcloud.com/fr/vps', $card->getLinkUrl());
        self::assertSame('ovhcloud.com', $card->getLinkTitle());

        $this->post($url, ['linkUrl' => 'javascript:alert(1)'], 422);

        $this->post($url.'/checklist', ['text' => 'Contexte']);
        $card = $this->fresh($card);
        $item = $card->getChecklist()[0]['id'];
        $state = $this->post($url.'/checklist/'.$item.'/toggle?card='.$card->getId());
        self::assertStringContainsString('☑ 1/1', $state['board']);
        self::assertStringContainsString('Checklist · 1/1', $state['card']);

        // The requests above left the entity manager cleared: the list is fetched again.
        $second = new WallCard($this->fresh($done), 'Déjà fait', $this->em()->getReference(User::class, $this->teacher->getId()));
        $this->em()->persist($second);
        $this->em()->flush();
        $state = $this->post($url.'/move', ['list' => $done->getId(), 'before' => $second->getId()]);
        // Drawn in the order just written, in the very answer of the request that moved it.
        self::assertLessThan(strpos($state['board'], 'Déjà fait'), strpos($state['board'], 'Rédiger le cahier des charges'));
        $card = $this->fresh($card);
        $second = $this->fresh($second);
        self::assertSame($done->getId(), $card->getList()->getId());
        self::assertSame([0, 1], [$card->getPosition(), $second->getPosition()]);

        $state = $this->post($url.'/duplicate');
        self::assertSame('Carte dupliquée', $state['toast']['message']);
        self::assertStringContainsString('Rédiger le cahier des charges (copie)', $state['board']);

        $state = $this->post($url.'/delete?card='.$card->getId());
        self::assertNull($state['card']);
        self::assertNull($this->em()->getRepository(WallCard::class)->findOneBy(['title' => 'Rédiger le cahier des charges']));
    }

    public function testNobodyOutsideAWallReachesItNotEvenAnAdministrator(): void
    {
        $wall = $this->wall($this->teacher);
        $card = $this->card($wall, $this->teacher);
        $paths = ['/walls/'.$wall->getId(), '/walls/'.$wall->getId().'/state', '/walls/'.$wall->getId().'/projection', '/walls/'.$wall->getId().'/destinations'];

        $this->assertScreens($this->teacher, array_fill_keys($paths, 200));
        foreach ([$this->colleague, $this->student, $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'wall.admin')] as $outsider) {
            $this->assertScreens($outsider, array_fill_keys($paths, 404));
            $this->post('/walls/cards/'.$card->getId(), ['title' => 'Piraté'], 404);
            $this->post('/walls/lists/'.$card->getList()->getId().'/cards', ['title' => 'Intrus'], 404);
            $this->post('/walls/'.$wall->getId().'/settings', ['moderated' => true], 404);
        }

        // A tutor has no walls at all: the list itself does not exist for them.
        $this->assertScreens($this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'wall.tutor'), ['/walls' => 404]);
    }

    public function testAClassSharedWithParticipatesWithinWhatTheWallAllows(): void
    {
        $wall = $this->wall($this->teacher);
        $wall->addProgram($this->em()->getReference(Program::class, $this->program->getId()));
        $this->em()->flush();
        [$todo] = $wall->getLists()->toArray();
        $teachersCard = $this->card($wall, $this->teacher);

        $crawler = $this->open($this->student, $wall);
        // A participant is offered neither the sharing, nor the settings, nor a list's menu.
        self::assertCount(0, $crawler->filter('[data-action="wall#openShare"], [data-action="wall#openSettings"], .cm-wall-list__more, .cm-wall-newlist'));
        self::assertCount(1, $this->client->request('GET', '/walls?tab=shared')->filter('.cm-wall-tile'));

        // …and is refused each of them all the same.
        $this->post('/walls/'.$wall->getId().'/settings', ['commentsEnabled' => true], 403);
        $this->post('/walls/'.$wall->getId().'/lists', ['title' => 'Liste pirate'], 403);
        $this->post('/walls/lists/'.$todo->getId().'/delete', [], 403);
        $this->post('/walls/cards/'.$teachersCard->getId(), ['title' => 'Réécrite'], 403);
        $this->post('/walls/cards/'.$teachersCard->getId().'/delete', [], 403);
        $this->post('/walls/cards/'.$teachersCard->getId().'/duplicate', [], 403);
        $this->post('/walls/cards/'.$teachersCard->getId().'/transfer', ['mode' => 'copy', 'list' => $todo->getId()], 403);
        $this->client->request('POST', '/walls/'.$wall->getId().'/delete', ['_token' => $this->csrfToken('wall')]);
        self::assertResponseStatusCodeSame(403);

        $this->post('/walls/lists/'.$todo->getId().'/cards', ['title' => 'Idée de l’étudiant']);
        $own = $this->em()->getRepository(WallCard::class)->findOneBy(['title' => 'Idée de l’étudiant']);
        self::assertInstanceOf(WallCard::class, $own);
        $this->post('/walls/cards/'.$own->getId(), ['text' => 'Mon texte']);

        // « Ajout de cartes par les participants » off: the same request is now refused.
        $this->open($this->teacher, $wall);
        $this->post('/walls/'.$wall->getId().'/settings', ['participantsMayAdd' => false]);
        $this->open($this->student, $wall);
        $this->post('/walls/lists/'.$todo->getId().'/cards', ['title' => 'Refusée'], 403);

        // « Modification des cartes des autres » on: the teacher's card becomes theirs to change.
        $this->open($this->teacher, $wall);
        $this->post('/walls/'.$wall->getId().'/settings', ['participantsMayEditOthers' => true]);
        $this->open($this->student, $wall);
        $this->post('/walls/cards/'.$teachersCard->getId(), ['title' => 'Réécrite à plusieurs']);
    }

    public function testACardAwaitingValidationIsShownToItsAuthorAndTheManagersOnly(): void
    {
        $wall = $this->wall($this->teacher);
        $wall->addProgram($this->em()->getReference(Program::class, $this->program->getId()))->setModerated(true);
        $this->em()->flush();
        [$todo] = $wall->getLists()->toArray();

        $this->open($this->student, $wall);
        $state = $this->post('/walls/lists/'.$todo->getId().'/cards', ['title' => 'Proposition à valider']);
        self::assertStringContainsString('Proposition à valider', $state['board']);
        self::assertStringContainsString('En attente', $state['board']);
        $card = $this->em()->getRepository(WallCard::class)->findOneBy(['title' => 'Proposition à valider']);
        self::assertInstanceOf(WallCard::class, $card);
        self::assertSame(WallCardStatus::Pending, $card->getStatus());
        // Its author may not validate their own card.
        $this->post('/walls/cards/'.$card->getId().'/approve', [], 403);

        $this->open($this->classmate, $wall);
        self::assertStringNotContainsString('Proposition à valider', $this->get('/walls/'.$wall->getId().'/state')['board']);
        self::assertNull($this->get('/walls/'.$wall->getId().'/state?card='.$card->getId())['card']);
        $this->post('/walls/cards/'.$card->getId(), ['title' => 'Vue quand même'], 404);
        self::assertStringNotContainsString('Proposition à valider', (string) $this->client->request('GET', '/walls/'.$wall->getId().'/projection')->html());

        $this->open($this->teacher, $wall);
        self::assertStringContainsString('Valider', $this->get('/walls/'.$wall->getId().'/state')['board']);
        // Never projected while it waits, even by whoever may read it.
        self::assertStringNotContainsString('Proposition à valider', (string) $this->client->request('GET', '/walls/'.$wall->getId().'/projection')->html());
        $this->post('/walls/cards/'.$card->getId().'/approve');

        $this->open($this->classmate, $wall);
        self::assertStringContainsString('Proposition à valider', $this->get('/walls/'.$wall->getId().'/state')['board']);
    }

    public function testCommentsAreRefusedWhileTheSwitchIsOff(): void
    {
        $wall = $this->wall($this->teacher);
        $wall->addProgram($this->em()->getReference(Program::class, $this->program->getId()));
        $this->em()->flush();
        $card = $this->card($wall, $this->teacher);
        $url = '/walls/cards/'.$card->getId().'/comments';

        $this->open($this->student, $wall);
        $this->post($url, ['text' => 'Trop tôt'], 403);

        $this->open($this->teacher, $wall);
        $this->post('/walls/'.$wall->getId().'/settings', ['commentsEnabled' => true]);

        $this->open($this->student, $wall);
        $state = $this->post($url.'?card='.$card->getId(), ['text' => 'Pensez au RGPD.']);
        self::assertStringContainsString('Pensez au RGPD.', $state['card']);
        $card = $this->fresh($card);
        $comment = $card->getComments()->first();
        self::assertNotFalse($comment);

        // A comment is its author's to withdraw and a manager's to remove - not a classmate's.
        $this->open($this->classmate, $wall);
        $this->post('/walls/comments/'.$comment->getId().'/delete', [], 403);
        $this->open($this->teacher, $wall);
        $this->post('/walls/comments/'.$comment->getId().'/delete');
    }

    public function testACardIsCopiedAndMovedToAnotherWall(): void
    {
        $source = $this->wall($this->teacher, 'Projet AP');
        $target = $this->wall($this->teacher, 'Veille');
        $foreign = $this->wall($this->colleague, 'Mur du collègue');
        $card = $this->card($source, $this->teacher, 'Comparatif VPS');
        $card->setChecklist([['id' => 'abcdef01', 'text' => 'OVH', 'done' => true]]);
        $this->em()->flush();
        [, $targetDone] = $target->getLists()->toArray();

        $this->open($this->teacher, $source);
        // The walls offered are one's own, the current one included - never a colleague's.
        $offered = $this->client->request('GET', '/walls/'.$source->getId().'/destinations?kind=card')
            ->filter('.cm-wall-dest__title')->each(static fn ($node): string => $node->text());
        sort($offered);
        self::assertSame(['Projet AP', 'Veille'], $offered);

        $state = $this->post('/walls/cards/'.$card->getId().'/transfer', ['mode' => 'copy', 'list' => $targetDone->getId()]);
        self::assertSame('Carte copiée dans « Veille › Terminé »', $state['toast']['message']);
        self::assertSame('/walls/'.$target->getId(), $state['toast']['url']);
        $copies = $this->em()->getRepository(WallCard::class)->findBy(['title' => 'Comparatif VPS']);
        self::assertCount(2, $copies);
        $copy = array_values(array_filter($copies, static fn (WallCard $each): bool => $each->getId() !== $card->getId()))[0];
        self::assertSame($targetDone->getId(), $copy->getList()->getId());
        self::assertSame('OVH', $copy->getChecklist()[0]['text']);

        // A wall one has no hand on is no destination, whatever the request names.
        [$foreignList] = $foreign->getLists()->toArray();
        $this->post('/walls/cards/'.$card->getId().'/transfer', ['mode' => 'move', 'list' => $foreignList->getId()], 404);

        $state = $this->post('/walls/cards/'.$card->getId().'/transfer?card='.$card->getId(), ['mode' => 'move', 'list' => $targetDone->getId()]);
        self::assertSame('Carte déplacée dans « Veille › Terminé »', $state['toast']['message']);
        self::assertNull($state['card']);
        self::assertStringNotContainsString('Comparatif VPS', $state['board']);

        [$sourceTodo] = $source->getLists()->toArray();
        $state = $this->post('/walls/lists/'.$sourceTodo->getId().'/transfer', ['mode' => 'copy', 'wall' => $target->getId()]);
        self::assertSame('Liste copiée dans « Veille »', $state['toast']['message']);
        $this->post('/walls/lists/'.$sourceTodo->getId().'/transfer', ['mode' => 'copy', 'wall' => $foreign->getId()], 404);
    }

    public function testATeacherSharesWithColleaguesAndTheirOwnClasses(): void
    {
        $wall = $this->wall($this->teacher);
        $this->open($this->teacher, $wall);

        self::assertSame(['Wall.colleague Test'], $this->people('colleague'));
        // Students are not offered one by one to a teacher: a class is.
        self::assertSame([], $this->people('wall.student'));

        $this->client->request('POST', '/walls/'.$wall->getId().'/sharing', [
            '_token' => $this->csrfToken('wall'),
            'programs' => [$this->program->getId()],
            'members' => [$this->colleague->getId(), $this->student->getId()],
        ]);
        self::assertResponseRedirects('/walls/'.$wall->getId());
        $wall = $this->fresh($wall);
        self::assertSame([$this->colleague->getId()], $wall->getMembers()->map(static fn (User $user): ?int => $user->getId())->getValues());
        self::assertCount(1, $wall->getPrograms());

        // The colleague now runs the wall - and still neither shares nor deletes it.
        $crawler = $this->open($this->colleague, $wall);
        self::assertCount(1, $crawler->filter('[data-action="wall#openSettings"]'));
        self::assertCount(0, $crawler->filter('[data-action="wall#openShare"]'));
        $this->post('/walls/'.$wall->getId().'/settings', ['moderated' => true]);
        $this->client->request('POST', '/walls/'.$wall->getId().'/sharing', ['_token' => $this->csrfToken('wall')]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/walls/'.$wall->getId().'/delete', ['_token' => $this->csrfToken('wall')]);
        self::assertResponseStatusCodeSame(403);

        // A class the owner does not teach is not theirs to add.
        $other = $this->wall($this->colleague);
        $this->open($this->colleague, $other);
        $this->client->request('POST', '/walls/'.$other->getId().'/sharing', ['_token' => $this->csrfToken('wall'), 'programs' => [$this->program->getId()]]);
        $other = $this->fresh($other);
        self::assertCount(0, $other->getPrograms());
    }

    public function testAStudentInvitesClassmatesAndTheirTeachersAndNobodyElse(): void
    {
        $this->client->loginUser($this->student);
        $this->client->request('GET', '/walls');
        self::assertSame(['Wall.classmate Test', 'Wall.teacher Test'], $this->people(''));

        $this->client->request('POST', '/walls', [
            '_token' => $this->csrfToken('wall'),
            'title' => 'Révisions',
            'format' => 'grid',
            'programs' => [$this->program->getId()],
            'members' => [$this->classmate->getId(), $this->teacher->getId(), $this->stranger->getId(), $this->colleague->getId()],
        ]);
        $wall = $this->em()->getRepository(Wall::class)->findOneBy(['title' => 'Révisions']);
        self::assertInstanceOf(Wall::class, $wall);
        self::assertCount(0, $wall->getPrograms());
        $members = $wall->getMembers()->map(static fn (User $user): ?int => $user->getId())->getValues();
        sort($members);
        $expected = [$this->classmate->getId(), $this->teacher->getId()];
        sort($expected);
        self::assertSame($expected, $members);

        // The teacher is a guest on the student's wall: no settings for them.
        $crawler = $this->open($this->teacher, $wall);
        self::assertCount(0, $crawler->filter('[data-action="wall#openSettings"]'));
        $this->post('/walls/'.$wall->getId().'/settings', ['moderated' => true], 403);
    }

    public function testAPictureAndAFileReachACardThroughTheStagingEndpoint(): void
    {
        $wall = $this->wall($this->teacher);
        $card = $this->card($wall, $this->teacher);
        $this->open($this->teacher, $wall);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $image = $this->stage('photo.png', (string) $png);
        $text = $this->stage('notes.txt', "Compte rendu\n");

        // A file that is not a picture is refused as one, and the card is left as it was.
        $this->post('/walls/cards/'.$card->getId(), ['image' => $text], 422);

        $this->post('/walls/cards/'.$card->getId(), ['image' => $image]);
        $this->post('/walls/cards/'.$card->getId(), ['file' => $this->stage('notes.txt', "Compte rendu\n")]);
        $card = $this->fresh($card);
        self::assertStringStartsWith('walls/', (string) $card->getImageKey());
        self::assertStringEndsWith('.png', (string) $card->getImageKey());
        self::assertSame('notes.txt', $card->getFileName());
        self::assertSame(13, $card->getFileSize());

        // A copy owns copies of the two objects, never the same keys.
        $this->post('/walls/cards/'.$card->getId().'/duplicate');
        $copy = $this->em()->getRepository(WallCard::class)->findOneBy(['title' => 'Carte (copie)']);
        self::assertInstanceOf(WallCard::class, $copy);
        self::assertNotNull($copy->getImageKey());
        self::assertNotSame($card->getImageKey(), $copy->getImageKey());
        self::assertNotSame($card->getFileKey(), $copy->getFileKey());

        $this->post('/walls/cards/'.$card->getId(), ['image' => '', 'file' => '']);
        $card = $this->fresh($card);
        self::assertNull($card->getImageKey());
        self::assertNull($card->getFileKey());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The row as the database now holds it. A request resets the kernel's services, the entity
     * manager among them, so what a test built before it is detached afterwards.
     *
     * @template T of Wall|WallList|WallCard
     *
     * @param T $entity
     *
     * @return T
     */
    private function fresh(object $entity): object
    {
        return $this->em()->find($entity::class, $entity->getId()) ?? throw new \LogicException('The row is gone.');
    }

    private function wall(User $owner, string $title = 'Mur de test'): Wall
    {
        $wall = new Wall($this->em()->getReference(User::class, $owner->getId()), $title, WallFormat::Columns);
        $this->em()->persist($wall);
        foreach (['À faire', 'Terminé'] as $position => $name) {
            $this->em()->persist(new WallList($wall, $name, $position));
        }
        $this->em()->flush();

        return $wall;
    }

    private function card(Wall $wall, User $author, string $title = 'Carte'): WallCard
    {
        $list = $wall->getLists()->first();
        \assert($list instanceof WallList);
        $card = new WallCard($list, $title, $this->em()->getReference(User::class, $author->getId()));
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    /** Signs somebody in and opens the wall - which is also what creates the session a token needs. */
    private function open(User $user, Wall $wall): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/walls/'.$wall->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * A request the way the page makes one, and its answer read as the page reads it. A refusal
     * answers another document (`error`, `message`); the callers that expect one do not read it.
     *
     * @param array<string, mixed> $body
     *
     * @return WallState
     */
    private function post(string $url, array $body = [], int $expected = 200): array
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken('wall'),
        ], content: (string) json_encode($body));
        self::assertResponseStatusCodeSame($expected, \sprintf('POST %s', $url));

        /** @var WallState $state */
        $state = (array) json_decode((string) $this->client->getResponse()->getContent(), true);

        return $state;
    }

    /** @return WallState */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        /** @var WallState $state */
        $state = (array) json_decode((string) $this->client->getResponse()->getContent(), true);

        return $state;
    }

    /** @return list<string> */
    private function people(string $search): array
    {
        $this->client->request('GET', '/walls/people?q='.urlencode($search));
        self::assertResponseIsSuccessful();
        /** @var array{results: list<array{id: int, text: string}>} $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        return array_column($answer['results'], 'text');
    }

    private function stage(string $name, string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'wall-test-');
        file_put_contents($path, $bytes);
        $this->client->request('POST', '/uploads/stage', files: ['file' => new UploadedFile($path, $name, null, null, true)], server: [
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken('staged-upload'),
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{token: string} $staged */
        $staged = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $staged['token'];
    }
}
