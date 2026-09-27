<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileLibraryNode;
use App\Entity\LessonLog;
use App\Entity\LessonLogAttachment;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Enum\LessonLogAttachmentSourceType;
use App\Enum\LessonLogSection;
use App\Enum\LessonLogVisibility;
use App\Enum\VisibilityLevel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The emploi du temps, the cahier de texte and the progression through the Claude connector: a
 * séance found by date, its cahier de texte written only by whoever delivers it, never over the
 * teacher's own words unless asked, and hidden from the students until somebody publishes it.
 */
class ClaudeConnectorTimetableToolsTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    private EntityManagerInterface $entityManager;
    private User $teacher;
    private Program $program;
    private Topic $topic;
    private LessonSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'claude.admin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'eleve.claude');
        $this->program = $this->createProgram([$student], [$this->teacher], $admin);
        $this->program->setVisibility(VisibilityLevel::Everyone);

        $group = new TopicGroup('Groupe', $this->program);
        $group->setCreatedBy($admin);
        $this->topic = new Topic('B1 Programmation', $this->program, $group);
        $this->topic->setCreatedBy($admin);
        $this->topic->addTeacher($this->teacher);
        $this->entityManager->persist($group);
        $this->entityManager->persist($this->topic);

        $this->session = $this->slot(new \DateTimeImmutable('today'), '08:00', '10:00', $this->teacher);
        $this->slot(new \DateTimeImmutable('today +7 days'), '08:00', '10:00', $this->teacher);
        $this->entityManager->flush();
    }

    public function testTodaysSeanceIsFoundWithItsClassAndMatiere(): void
    {
        $token = $this->accessTokenFor($this->teacher);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $result = $this->callTool($token, 'timetable_get', ['from' => $today, 'to' => $today]);

        self::assertFalse($result['isError'], $result['text']);
        $sessions = $result['data']['sessions'];
        self::assertIsArray($sessions);
        self::assertCount(1, $sessions);
        $row = $sessions[0];
        self::assertIsArray($row);
        self::assertSame($this->session->getId(), $row['sessionId']);
        self::assertSame(120, $row['minutes']);
        self::assertSame(['id' => $this->topic->getId(), 'name' => 'B1 Programmation'], $row['topic']);
        self::assertSame(['filled' => false, 'canEdit' => true], $row['lessonLog']);
    }

    public function testTheCahierDeTexteIsWrittenFromMarkdownAndStaysHidden(): void
    {
        $token = $this->accessTokenFor($this->teacher);

        $read = $this->callTool($token, 'lesson_log_get', ['sessionId' => $this->session->getId()]);
        self::assertFalse($read['isError'], $read['text']);
        self::assertTrue($read['data']['canEdit']);

        $written = $this->callTool($token, 'lesson_log_write', [
            'sessionId' => $this->session->getId(),
            'during' => "Les tableaux en HTML :\n\n- `<table>`, `<tr>`, `<td>`\n- **fusion** de cellules",
        ]);

        self::assertFalse($written['isError'], $written['text']);
        self::assertStringContainsString('masquée', $written['text']);

        $log = $this->logOf($this->session);
        self::assertInstanceOf(LessonLog::class, $log);
        $content = (string) $log->getContenuRealise();
        self::assertStringContainsString('<li>', $content);
        self::assertStringContainsString('<strong>fusion</strong>', $content);
        // The tags the teacher named are text, not markup the students' page would run.
        self::assertStringContainsString('&lt;table&gt;', $content);
        self::assertSame(LessonLogVisibility::Hidden, $log->getVisibility(LessonLogSection::During));
        self::assertSame($this->teacher->getId(), $log->getCreatedBy()?->getId());
        self::assertNull($log->getTravailAvantDescription());
    }

    public function testAPartAlreadyWrittenIsReplacedOnlyWhenAsked(): void
    {
        $log = new LessonLog($this->session);
        $log->setContenuRealise('<p>Écrit à la main</p>');
        $log->setCreatedBy($this->teacher);
        $this->entityManager->persist($log);
        $this->entityManager->flush();
        $token = $this->accessTokenFor($this->teacher);

        $refused = $this->callTool($token, 'lesson_log_write', ['sessionId' => $this->session->getId(), 'during' => 'Autre chose', 'after' => 'Exercices 1 à 3']);

        self::assertTrue($refused['isError']);
        self::assertStringContainsString('pendant', $refused['text']);
        $log = $this->logOf($this->session);
        self::assertInstanceOf(LessonLog::class, $log);
        self::assertSame('<p>Écrit à la main</p>', $log->getContenuRealise());
        // Refused whole: the part that was free is not written either.
        self::assertNull($log->getTravailApresDescription());

        $replaced = $this->callTool($token, 'lesson_log_write', ['sessionId' => $this->session->getId(), 'during' => 'Autre chose', 'replace' => true, 'visibilityDuring' => 'now']);

        self::assertFalse($replaced['isError'], $replaced['text']);
        $log = $this->logOf($this->session);
        self::assertInstanceOf(LessonLog::class, $log);
        self::assertSame('<p>Autre chose</p>', $log->getContenuRealise());
        self::assertSame(LessonLogVisibility::Now, $log->getVisibility(LessonLogSection::During));
        self::assertSame($this->teacher->getId(), $log->getLastUpdatedBy()?->getId());
    }

    public function testAColleagueWhoDoesNotDeliverTheSeanceCannotWriteIt(): void
    {
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.colleague');
        $this->program->addTeacher($colleague);
        $this->entityManager->flush();

        $result = $this->callTool($this->accessTokenFor($colleague), 'lesson_log_write', ['sessionId' => $this->session->getId(), 'during' => 'Tableaux']);

        self::assertTrue($result['isError']);
        self::assertNull($this->logOf($this->session));
    }

    public function testAScheduledVisibilityIsLeftToTheScreen(): void
    {
        $result = $this->callTool($this->accessTokenFor($this->teacher), 'lesson_log_write', ['sessionId' => $this->session->getId(), 'during' => 'Tableaux', 'visibilityDuring' => 'scheduled']);

        self::assertTrue($result['isError']);
        self::assertNull($this->logOf($this->session));
    }

    public function testALibraryFileIsAttachedToAPartAsAReference(): void
    {
        $token = $this->accessTokenFor($this->teacher);
        $created = $this->callTool($token, 'file_create', ['title' => 'Énoncé TP tableaux', 'content' => 'Créer un tableau de 3 colonnes.', 'format' => 'md']);
        self::assertFalse($created['isError'], $created['text']);

        $attached = $this->callTool($token, 'lesson_log_attach', ['sessionId' => $this->session->getId(), 'fileId' => $created['data']['fileId'], 'section' => 'after']);

        self::assertFalse($attached['isError'], $attached['text']);
        self::assertStringContainsString('masquée', $attached['text']);
        $log = $this->logOf($this->session);
        self::assertInstanceOf(LessonLog::class, $log);
        self::assertSame($this->teacher->getId(), $log->getCreatedBy()?->getId());
        $documents = $log->getAttachmentsForSection(LessonLogSection::After);
        self::assertCount(1, $documents);
        $document = $documents->first();
        self::assertInstanceOf(LessonLogAttachment::class, $document);
        $file = $this->entityManager->find(FileLibraryNode::class, $created['data']['fileId']);
        self::assertInstanceOf(FileLibraryNode::class, $file);
        self::assertSame(LessonLogAttachmentSourceType::Library, $document->getType());
        self::assertSame($file->getName(), $document->getLabel());
        // A reference: the library's own object, nothing copied.
        self::assertSame($file->getStorageKey(), $document->getStorageKey());
        self::assertSame($file->getId(), $document->getLibraryNode()?->getId());
        self::assertNull($document->getVisibleAt());

        // A retried call answers with the row already there rather than a second line.
        $again = $this->callTool($token, 'lesson_log_attach', ['sessionId' => $this->session->getId(), 'fileId' => $created['data']['fileId'], 'section' => 'after']);
        self::assertFalse($again['isError'], $again['text']);
        self::assertFalse($again['data']['created']);
        self::assertSame($document->getId(), $again['data']['attachmentId']);
        self::assertCount(1, $this->entityManager->getRepository(LessonLogAttachment::class)->findBy(['lessonLog' => $log]));

        $listed = $this->callTool($token, 'file_list', ['linked' => 'linked']);
        $files = $listed['data']['files'];
        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertIsArray($files[0]);
        $linkedTo = $files[0]['linkedTo'] ?? null;
        self::assertIsArray($linkedTo);
        self::assertIsArray($linkedTo[0]);
        self::assertSame(['kind' => 'lesson_log', 'id' => $this->session->getId()], array_intersect_key($linkedTo[0], array_flip(['kind', 'id'])));
    }

    public function testALinkIsAttachedAndOnlyHttpIsAccepted(): void
    {
        $token = $this->accessTokenFor($this->teacher);

        $refused = $this->callTool($token, 'lesson_log_attach', ['sessionId' => $this->session->getId(), 'url' => 'javascript:alert(1)']);
        self::assertTrue($refused['isError']);
        // Refused before anything was opened: no empty cahier de texte left behind.
        self::assertNull($this->logOf($this->session));

        $both = $this->callTool($token, 'lesson_log_attach', ['sessionId' => $this->session->getId(), 'url' => 'https://developer.mozilla.org/fr/docs/Web/HTML/Element/table', 'fileId' => 1]);
        self::assertTrue($both['isError']);

        $attached = $this->callTool($token, 'lesson_log_attach', ['sessionId' => $this->session->getId(), 'url' => 'https://developer.mozilla.org/fr/docs/Web/HTML/Element/table', 'label' => 'MDN : <table>']);
        self::assertFalse($attached['isError'], $attached['text']);
        $log = $this->logOf($this->session);
        self::assertInstanceOf(LessonLog::class, $log);
        $document = $log->getAttachmentsForSection(LessonLogSection::During)->first();
        self::assertInstanceOf(LessonLogAttachment::class, $document);
        self::assertSame(LessonLogAttachmentSourceType::Link, $document->getType());
        self::assertSame('MDN : <table>', $document->getLabel());
        self::assertNull($document->getStorageKey());
    }

    public function testAColleagueWhoDoesNotDeliverTheSeanceCannotAttachToIt(): void
    {
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.colleague');
        $this->program->addTeacher($colleague);
        $this->entityManager->flush();

        $result = $this->callTool($this->accessTokenFor($colleague), 'lesson_log_attach', ['sessionId' => $this->session->getId(), 'url' => 'https://example.org/']);

        self::assertTrue($result['isError']);
        self::assertNull($this->logOf($this->session));
    }

    public function testSomebodyElsesFileCannotBeAttached(): void
    {
        $owner = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.owner');
        $theirs = $this->callTool($this->accessTokenFor($owner), 'file_create', ['title' => 'Privé', 'content' => 'Secret', 'format' => 'md']);
        self::assertFalse($theirs['isError'], $theirs['text']);

        $result = $this->callTool($this->accessTokenFor($this->teacher), 'lesson_log_attach', ['sessionId' => $this->session->getId(), 'fileId' => $theirs['data']['fileId']]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('introuvable', $result['text']);
        self::assertNull($this->logOf($this->session));
    }

    public function testTheProgressionIsReadWithTheYearsCreneaux(): void
    {
        $progression = new Progression($this->topic, $this->teacher);
        $this->entityManager->persist($progression);
        $this->entityManager->flush();
        $token = $this->accessTokenFor($this->teacher);

        $overview = $this->callTool($token, 'progression_get');
        self::assertFalse($overview['isError'], $overview['text']);
        $topics = $overview['data']['topics'];
        self::assertIsArray($topics);
        self::assertCount(1, $topics);
        self::assertIsArray($topics[0]);
        self::assertSame(['slots' => 2, 'minutes' => 240, 'remainingSlots' => 2, 'remainingMinutes' => 240], array_intersect_key((array) $topics[0]['timetable'], array_flip(['slots', 'minutes', 'remainingSlots', 'remainingMinutes'])));

        $detail = $this->callTool($token, 'progression_get', ['topicId' => $this->topic->getId()]);
        self::assertFalse($detail['isError'], $detail['text']);
        self::assertIsArray($detail['data']['slots']);
        self::assertCount(2, $detail['data']['slots']);
        self::assertIsArray($detail['data']['progression']);
        self::assertSame($progression->getId(), $detail['data']['progression']['id']);
    }

    public function testAMatiereOfSomebodyElseIsNotFound(): void
    {
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.colleague');
        $this->program->addTeacher($colleague);
        $this->entityManager->flush();

        $result = $this->callTool($this->accessTokenFor($colleague), 'progression_get', ['topicId' => $this->topic->getId()]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('introuvable', $result['text']);
    }

    private function slot(\DateTimeImmutable $day, string $start, string $end, User $teacher): LessonSession
    {
        $session = new LessonSession($this->program);
        $session->setDay($day);
        $session->setStartHour(new \DateTimeImmutable($start));
        $session->setEndHour(new \DateTimeImmutable($end));
        $session->setLength('2');
        $session->setTopic($this->topic);
        $session->setTeacher($teacher);
        $this->entityManager->persist($session);

        return $session;
    }

    private function logOf(LessonSession $session): ?LessonLog
    {
        return $this->entityManager->getRepository(LessonLog::class)->findOneBy(['lessonSession' => $session]);
    }
}
