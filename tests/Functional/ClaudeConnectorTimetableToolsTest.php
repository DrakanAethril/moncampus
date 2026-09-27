<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LessonLog;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
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
