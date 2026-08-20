<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assignment;
use App\Entity\LessonLog;
use App\Entity\LessonLogAttachment;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\LessonLogAttachmentSourceType;
use App\Enum\LessonLogSection;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\LessonLogRepository;
use App\Repository\LessonSessionRepository;
use App\Service\FileUploadService;
use App\Service\LessonLogImporter;
use App\Service\SeanceContentResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * What travels when a cahier de texte is taken back, and what stops at the teacher boundary.
 *
 * The twin créneau (design/validated/co-animation.md) is the first case where source and target
 * belong to DIFFERENT teachers, and two of the three things this importer copies break there:
 *
 *  - a document points at a node of the source teacher's library, and its deletion by the author
 *    would silently empty the colleague's administrative record;
 *  - an assignment carries its own audience and collects its own productions, so a copy either
 *    targets the wrong group or doubles every student's board row.
 *
 * So the texts travel and those two do not - and the existing case, one teacher's own two classes,
 * must keep copying all three. Both halves are pinned here.
 */
class LessonLogImporterTest extends TestCase
{
    private const string CONTENU = '<p>Construction de l\'échelle de gravité.</p>';

    public function testACrossTeacherImportCarriesTheThreeTextsAndNothingElse(): void
    {
        $author = new User('author');
        $colleague = new User('colleague');

        $persisted = $this->import($author, $colleague);

        self::assertSame(self::CONTENU, $persisted['log']->getContent(LessonLogSection::During));
        self::assertSame('Relire la cartographie.', $persisted['log']->getContent(LessonLogSection::Before));
        self::assertSame('Rédiger la synthèse.', $persisted['log']->getContent(LessonLogSection::After));

        self::assertSame(0, $persisted['attachments'], 'a colleague\'s documents must not travel');
        self::assertSame(0, $persisted['assignments'], 'a colleague\'s assignments must not travel');
    }

    public function testASameTeacherImportStillCarriesEverything(): void
    {
        $author = new User('author');

        $persisted = $this->import($author, $author);

        self::assertSame(self::CONTENU, $persisted['log']->getContent(LessonLogSection::During));
        self::assertSame(1, $persisted['attachments'], 'the existing case must keep copying documents');
        self::assertSame(1, $persisted['assignments'], 'the existing case must keep copying assignments');
    }

    public function testAnAlreadyWrittenPartIsNeverOverwritten(): void
    {
        // Unchanged by co-animation, and worth keeping pinned next to it: an import completes, it
        // does not erase what the teacher already said.
        $author = new User('author');
        $colleague = new User('colleague');

        $existing = new LessonLog($this->session($colleague));
        $existing->setContenuRealise('<p>Ce que j\'ai déjà écrit.</p>');

        $persisted = $this->import($author, $colleague, $existing);

        self::assertSame('<p>Ce que j\'ai déjà écrit.</p>', $persisted['log']->getContent(LessonLogSection::During));
        self::assertSame('Relire la cartographie.', $persisted['log']->getContent(LessonLogSection::Before));
    }

    /**
     * @return array{log: LessonLog, attachments: int, assignments: int}
     */
    private function import(User $sourceTeacher, User $targetTeacher, ?LessonLog $targetLog = null): array
    {
        $source = $this->session($sourceTeacher);
        $target = $this->session($targetTeacher);

        $sourceLog = new LessonLog($source);
        $sourceLog->setContenuRealise(self::CONTENU);
        $sourceLog->setTravailAvantDescription('Relire la cartographie.');
        $sourceLog->setTravailApresDescription('Rédiger la synthèse.');

        $attachment = new LessonLogAttachment($sourceLog, 'Support de cours');
        $attachment->setType(LessonLogAttachmentSourceType::Link);
        $attachment->setSection(LessonLogSection::During);
        $attachment->setUrl('https://example.test/support');

        $logRepository = $this->createStub(LessonLogRepository::class);
        $logRepository->method('findOneBySession')->willReturnCallback(
            static fn (LessonSession $session): ?LessonLog => $session === $source ? $sourceLog : $targetLog,
        );

        $assignment = new Assignment($source->getProgram());
        $assignment->setTitle('Rapport');
        $assignmentRepository = $this->createStub(AssignmentRepository::class);
        $assignmentRepository->method('findForLessonSession')->willReturn([$assignment]);

        $persisted = [];
        // A stub and not a mock: what is asserted is WHAT was persisted, collected below, not
        // how many times the manager was called.
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $importer = new LessonLogImporter(
            $entityManager,
            $logRepository,
            $this->createStub(LessonSessionRepository::class),
            $assignmentRepository,
            $this->createStub(AssignmentSubmissionRepository::class),
            $this->createStub(AssignmentCompletionRepository::class),
            $this->createStub(FileUploadService::class),
            $this->createStub(SeanceContentResolver::class),
        );

        $importer->import($source, $target, $targetTeacher);

        $logs = array_values(array_filter($persisted, static fn (object $row): bool => $row instanceof LessonLog));

        return [
            'log' => $logs[0],
            'attachments' => \count(array_filter($persisted, static fn (object $row): bool => $row instanceof LessonLogAttachment)),
            'assignments' => \count(array_filter($persisted, static fn (object $row): bool => $row instanceof Assignment)),
        ];
    }

    private function session(User $teacher): LessonSession
    {
        $program = (new \ReflectionClass(Program::class))->newInstanceWithoutConstructor();
        $session = (new \ReflectionClass(LessonSession::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($session, 'program'))->setValue($session, $program);
        (new \ReflectionProperty($session, 'teacher'))->setValue($session, $teacher);
        (new \ReflectionProperty($session, 'options'))->setValue($session, new \Doctrine\Common\Collections\ArrayCollection());

        return $session;
    }
}
