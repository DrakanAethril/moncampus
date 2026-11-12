<?php

declare(strict_types=1);

namespace App\Tests\Functional\SchoolYearImport;

use App\Entity\EvaluationPeriodGroup;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\PortfolioValidator;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Entity\ProgramFinancialItem;
use App\Entity\SchoolYearImport;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Enum\SchoolYearImportBlock;
use App\Enum\VisibilityLevel;
use App\Service\FileUploadService;
use App\Service\SchoolYearImport\SchoolYearImportChoices;
use App\Service\SchoolYearImport\SchoolYearImporter;
use App\Service\SchoolYearImport\SchoolYearImportPlanner;
use App\Service\SchoolYearImport\SchoolYearImportRefusal;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The planner and the importer on a real database (design/validated/school-year-import.md §8):
 * a formation created with every block, a second import that creates nothing twice and modifies
 * nothing, a formation made by hand recognised, R6, an import from a more recent year, test
 * formations, all or nothing, the PDF copied under another key.
 */
class SchoolYearImporterTest extends FunctionalTestCase
{
    use SchoolYearImportFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function choices(?array $blocks = null): SchoolYearImportChoices
    {
        if (null === $blocks) {
            return SchoolYearImportChoices::defaults((int) $this->sourceYear->getId(), [(int) $this->sio->getId()]);
        }

        return SchoolYearImportChoices::fromInput((int) $this->sourceYear->getId(), ['blocks' => $blocks, 'programs' => [(string) $this->sio->getId()]]);
    }

    private function import(?SchoolYearImportChoices $choices = null): SchoolYearImport
    {
        return static::getContainer()->get(SchoolYearImporter::class)->import($this->sourceYear, $this->targetYear, $choices ?? $this->choices(), $this->admin);
    }

    /** @return list<Program> */
    private function targetPrograms(): array
    {
        return $this->em()->getRepository(Program::class)->findBy(['schoolYear' => $this->targetYear]);
    }

    private function rows(string $class, array $criteria): int
    {
        return $this->em()->getRepository($class)->count($criteria);
    }

    public function testAFormationIsCreatedWithEveryBlock(): void
    {
        $journal = $this->import();

        $programs = $this->targetPrograms();
        self::assertCount(1, $programs);
        $created = $programs[0];
        self::assertSame('BTS SIO 1 2027-2028', $created->getName());
        self::assertSame('SIO1 2027-28', $created->getShortName());
        self::assertSame(VisibilityLevel::StaffAdmin, $created->getVisibility());
        self::assertSame('2027-09-01', $created->getStartDate()?->format('Y-m-d'));
        self::assertSame('2028-07-07', $created->getEndDate()?->format('Y-m-d'));
        self::assertSame($this->sio->getId(), $created->getImportedFrom()?->getId());
        self::assertTrue($created->isTsfExportEnabled());
        self::assertSame(['SLAM'], array_map(static fn ($o) => $o->getShortName(), $created->getOptions()->toArray()));
        self::assertCount(1, $created->getModalities());

        // R6: only the teacher still active and still a teacher.
        self::assertSame([$this->teacher->getId()], array_map(static fn ($u) => $u->getId(), $created->getTeachers()->toArray()));
        self::assertSame([$this->teacher->getId()], array_map(static fn ($u) => $u->getId(), $created->getReferentTeachers()->toArray()));

        $topics = $this->em()->getRepository(Topic::class)->findBy(['program' => $created], ['name' => 'ASC']);
        self::assertSame(['Anglais', 'Mathématiques'], array_map(static fn (Topic $t) => $t->getName(), $topics));
        self::assertCount(0, $topics[0]->getTeachers(), 'Anglais was held by a deactivated account only.');
        self::assertSame([$this->teacher->getId()], array_map(static fn ($u) => $u->getId(), $topics[1]->getTeachers()->toArray()));
        self::assertSame('60.00', $topics[1]->getTargetCmHours());
        self::assertSame($this->teacher->getId(), $topics[1]->getTopicGroup()?->getTeacher()?->getId());

        $block = $this->em()->getRepository(SkillGroup::class)->findOneBy(['program' => $created]);
        self::assertSame('B1', $block?->getCode());
        self::assertSame(2, $this->rows(Skill::class, ['skillGroup' => $block]));

        // Calendars: copied, shifted by 52 weeks, the last period brought back to the end of the year.
        $calendar = $this->em()->getRepository(PeriodGroup::class)->findOneBy(['schoolYear' => $this->targetYear]);
        self::assertSame('Alternance 2027-2028', $calendar->getName());
        $periods = $this->em()->getRepository(Period::class)->findBy(['periodGroup' => $calendar], ['startDate' => 'ASC']);
        self::assertSame('2027-09-06', $periods[0]->getStartDate()?->format('Y-m-d'));
        self::assertSame('2028-07-07', $periods[1]->getEndDate()?->format('Y-m-d'));
        self::assertTrue($periods[0]->isShifted());
        self::assertCount(1, $periods[0]->getModalities());
        $link = $created->getProgramPeriodGroups()->first();
        self::assertNotFalse($link);
        self::assertSame($calendar->getId(), $link->getPeriodGroup()?->getId());

        $evaluation = $created->getEvaluationPeriodGroup();
        self::assertNotNull($evaluation);
        self::assertNotSame($this->semesters->getId(), $evaluation->getId());
        self::assertSame('Semestres BTS 2027-2028', $evaluation->getName());

        self::assertSame(1, $this->rows(ProgramFinancialItem::class, ['program' => $created]));
        $certification = $this->em()->getRepository(ProgramCertification::class)->findOneBy(['program' => $created]);
        self::assertSame('2019-04-02', $certification?->getDecreeDate()?->format('Y-m-d'), 'The decree date is kept.');
        $bilan = $this->em()->getRepository(InternshipEvaluationPeriod::class)->findOneBy(['program' => $created]);
        self::assertSame('2028-01-03', $bilan->getStartDate()?->format('Y-m-d'));
        self::assertTrue($bilan->isShifted());

        self::assertSame(2029, $created->getPortfolioExamSession());
        self::assertSame('2028-05-12 18:00', $created->getPortfolioE6Deadline()?->format('Y-m-d H:i'));
        self::assertNotNull($created->getPortfolioDatesShiftedAt());
        self::assertSame(1, $this->rows(PortfolioValidator::class, ['program' => $created]));

        $report = $journal->getReport();
        self::assertSame('created', $report['programs'][0]['status']);
        self::assertSame($created->getId(), $report['programs'][0]['targetId']);
        self::assertSame(1, $report['programs'][0]['withoutHolder']);
        self::assertCount(2, $report['skippedTeachers']);
        self::assertSame(52, $report['weeks']);
    }

    public function testThePlanAnnouncesWhatTheImportWritesAndWritesNothing(): void
    {
        $plan = static::getContainer()->get(SchoolYearImportPlanner::class)->plan($this->sourceYear, $this->targetYear, $this->choices())->toArray();

        self::assertSame([], $this->targetPrograms());
        self::assertSame(0, $this->rows(PeriodGroup::class, ['schoolYear' => $this->targetYear]));

        $journal = $this->import();
        $written = $journal->getReport();
        $plan['programs'][0]['targetId'] = $written['programs'][0]['targetId'];
        self::assertSame($plan, $written);
    }

    public function testASecondImportCreatesNothingTwiceAndModifiesNothing(): void
    {
        $this->import();
        $created = $this->targetPrograms()[0];

        $maths = $this->em()->getRepository(Topic::class)->findOneBy(['program' => $created, 'name' => 'Mathématiques']);
        $maths?->setTargetCmHours('12.00');
        $created->setPortfolioCursusYear(2);
        $created->setVisibility(VisibilityLevel::Everyone);
        $this->em()->flush();
        $this->em()->clear();
        $this->reload();

        $journal = $this->import();
        $this->em()->clear();
        $this->reload();

        self::assertCount(1, $this->targetPrograms());
        $created = $this->targetPrograms()[0];
        self::assertSame(VisibilityLevel::Everyone, $created->getVisibility());
        self::assertSame(2, $created->getPortfolioCursusYear());
        self::assertSame('12.00', $this->em()->getRepository(Topic::class)->findOneBy(['program' => $created, 'name' => 'Mathématiques'])?->getTargetCmHours());
        self::assertSame(2, $this->rows(Topic::class, ['program' => $created]));
        self::assertSame(1, $this->rows(TopicGroup::class, ['program' => $created]));
        self::assertSame(1, $this->rows(SkillGroup::class, ['program' => $created]));
        self::assertSame(1, $this->rows(PeriodGroup::class, ['schoolYear' => $this->targetYear]));
        self::assertSame(1, $this->rows(EvaluationPeriodGroup::class, ['name' => 'Semestres BTS 2027-2028']));
        self::assertSame(1, $this->rows(InternshipEvaluationPeriod::class, ['program' => $created]));
        self::assertSame(1, $this->rows(PortfolioValidator::class, ['program' => $created]));
        self::assertCount(1, $created->getProgramPeriodGroups());

        $entry = $journal->getReport()['programs'][0];
        self::assertSame('completed', $entry['status']);
        self::assertSame([], $entry['added']);
        self::assertSame([], $journal->getReport()['shiftedDates']);
    }

    public function testAFormationMadeByHandIsRecognisedAndCompleted(): void
    {
        $byHand = new Program('BTS SIO 1 2027-2028', 'SIO1', $this->cohort, $this->targetYear);
        $byHand->setCreatedBy($this->admin);
        $byHand->setVisibility(VisibilityLevel::Everyone);
        $this->em()->persist($byHand);
        $mine = new TopicGroup('Enseignements généraux', $byHand);
        $mine->setCreatedBy($this->admin);
        $this->em()->persist($mine);
        $topic = new Topic('mathématiques', $byHand, $mine);
        $topic->setCreatedBy($this->admin);
        $topic->setTargetCmHours('30.00');
        $this->em()->persist($topic);
        $this->em()->flush();

        $journal = $this->import();
        $this->em()->clear();
        $this->reload();

        self::assertCount(1, $this->targetPrograms());
        $completed = $this->targetPrograms()[0];
        self::assertSame('SIO1', $completed->getShortName(), 'Its fields do not move.');
        self::assertSame(VisibilityLevel::Everyone, $completed->getVisibility(), 'R10 is for formations created only.');
        self::assertSame($this->sio->getId(), $completed->getImportedFrom()?->getId());
        $topics = $this->em()->getRepository(Topic::class)->findBy(['program' => $completed], ['name' => 'ASC']);
        self::assertSame(['Anglais', 'mathématiques'], array_map(static fn (Topic $t) => $t->getName(), $topics));
        self::assertSame('30.00', $topics[1]->getTargetCmHours());
        self::assertCount(1, $completed->getTeachers());

        $entry = $journal->getReport()['programs'][0];
        self::assertSame('completed', $entry['status']);
        self::assertSame(1, $entry['added']['topics']);
        self::assertSame(1, $entry['added']['teachers']);
        self::assertArrayNotHasKey('topicGroups', $entry['added']);
    }

    public function testWithoutTheTeamNothingNamesATeacher(): void
    {
        $this->import($this->choices(['topics', 'skills', 'holders', 'referents']));

        $created = $this->targetPrograms()[0];
        self::assertCount(0, $created->getTeachers());
        self::assertCount(0, $created->getReferentTeachers());
        foreach ($this->em()->getRepository(Topic::class)->findBy(['program' => $created]) as $topic) {
            self::assertCount(0, $topic->getTeachers());
        }
    }

    public function testImportFromAMoreRecentYearShiftsBackward(): void
    {
        $older = $this->year('2025-09-01', '2026-07-04');
        $this->em()->flush();

        $journal = static::getContainer()->get(SchoolYearImporter::class)->import($this->sourceYear, $older, $this->choices(), $this->admin);

        $created = $this->em()->getRepository(Program::class)->findOneBy(['schoolYear' => $older]);
        self::assertSame('BTS SIO 1 2025-2026', $created->getName());
        self::assertSame(2027, $created->getPortfolioExamSession());
        self::assertSame(-52, $journal->getReport()['weeks']);
        $calendar = $this->em()->getRepository(PeriodGroup::class)->findOneBy(['schoolYear' => $older]);
        $first = $this->em()->getRepository(Period::class)->findOneBy(['periodGroup' => $calendar], ['startDate' => 'ASC']);
        self::assertSame('2025-09-08', $first?->getStartDate()?->format('Y-m-d'));
    }

    public function testATestFormationStaysATestFormation(): void
    {
        $this->sio->setTestProgram(true);
        $this->em()->flush();

        $this->import();

        self::assertTrue($this->targetPrograms()[0]->isTestProgram());
    }

    public function testAnErrorWritesNothing(): void
    {
        // A formation whose name is only the year would arrive nameless after R8 - refused, mid-import.
        $nameless = new Program('2026-2027', '2026-27', $this->cohort, $this->sourceYear);
        $nameless->setCreatedBy($this->admin);
        $this->em()->persist($nameless);
        $this->em()->flush();
        $nameless->setName(' ');
        $this->em()->flush();

        $choices = SchoolYearImportChoices::fromInput((int) $this->sourceYear->getId(), [
            'blocks' => array_map(static fn (SchoolYearImportBlock $b) => $b->value, SchoolYearImportBlock::cases()),
            'programs' => [(string) $this->sio->getId(), (string) $nameless->getId()],
        ]);

        try {
            $this->import($choices);
            self::fail('The import should have been refused.');
        } catch (SchoolYearImportRefusal $refusal) {
            self::assertSame('schoolYearImportRefusalEmptyName', $refusal->key);
        }

        self::assertSame(0, $this->rows(Program::class, ['schoolYear' => $this->targetYear]));
        self::assertSame(0, $this->rows(PeriodGroup::class, ['schoolYear' => $this->targetYear]));
        self::assertSame(0, $this->rows(SchoolYearImport::class, ['target' => $this->targetYear]));
    }

    public function testTheSyllabusIsCopiedUnderANewKey(): void
    {
        $files = static::getContainer()->get(FileUploadService::class);
        $storage = static::getContainer()->get('uploads.storage');
        $storage->write('programs/syllabus/source.pdf', '%PDF-1.4 test');
        $this->sio->setSyllabusFileKey('programs/syllabus/source.pdf');
        $this->em()->flush();

        $this->import();

        $key = $this->targetPrograms()[0]->getSyllabusFileKey();
        self::assertNotNull($key);
        self::assertNotSame('programs/syllabus/source.pdf', $key);
        self::assertSame('%PDF-1.4 test', $files->read($key));
    }

    public function testTheSameYearIsRefused(): void
    {
        $this->expectException(SchoolYearImportRefusal::class);

        static::getContainer()->get(SchoolYearImporter::class)->import($this->sourceYear, $this->sourceYear, $this->choices(), $this->admin);
    }
}
