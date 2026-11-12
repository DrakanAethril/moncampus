<?php

declare(strict_types=1);

namespace App\Tests\Functional\SchoolYearImport;

use App\Entity\Cohort;
use App\Entity\EvaluationPeriod;
use App\Entity\EvaluationPeriodGroup;
use App\Entity\GameRule;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipProgramInfo;
use App\Entity\LessonType;
use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\PeriodType;
use App\Entity\PortfolioValidator;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Entity\ProgramFinancialItem;
use App\Entity\ProgramLessonTypeCost;
use App\Entity\ProgramPeriodGroup;
use App\Entity\ProgramTeacherOption;
use App\Entity\Referential;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\FinancialItemSource;
use App\Enum\FinancialItemType;
use App\Enum\VisibilityLevel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Two school years and one formation of the first carrying something in every block of the
 * import's catalogue - the fixture of the import's functional tests.
 *
 * In its own file because ParaTest loads test files apart (tests/README.md).
 */
trait SchoolYearImportFixtures
{
    protected User $admin;
    protected User $teacher;
    protected User $formerTeacher;
    protected User $staffOnly;
    protected SchoolYear $sourceYear;
    protected SchoolYear $targetYear;
    protected Cohort $cohort;
    protected Option $slam;
    protected Modality $initial;
    protected Program $sio;
    protected PeriodGroup $calendar;
    protected EvaluationPeriodGroup $semesters;

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function buildFixtures(): void
    {
        $em = $this->em();

        $this->admin = $this->createUser(['ROLE_ADMIN'], 'import.admin');
        $this->teacher = $this->createUser(['ROLE_TEACHER'], 'import.teacher');
        $this->formerTeacher = $this->createUser(['ROLE_TEACHER'], 'import.former');
        $this->formerTeacher->setInactiveDate(new \DateTimeImmutable('-1 month'));
        $this->staffOnly = $this->createUser(['ROLE_STAFF'], 'import.staff');

        $section = new Section('BTS');
        $track = new Track('SIO', $section);
        $this->cohort = new Cohort('SIO 1', $track);
        $this->slam = new Option('Solutions logicielles', 'SLAM', '#336699');
        $this->initial = new Modality('Initiale', '#999999');
        foreach ([$section, $track, $this->cohort, $this->slam, $this->initial] as $node) {
            $node->setCreatedBy($this->admin);
            $em->persist($node);
        }

        $this->sourceYear = $this->year('2026-09-01', '2027-07-03');
        $this->targetYear = $this->year('2027-09-01', '2028-07-07');

        $periodType = new PeriodType('École', '#00aa00');
        $periodType->setCreatedBy($this->admin);
        $em->persist($periodType);
        $this->calendar = new PeriodGroup('Alternance 2026-2027', $this->sourceYear);
        $this->calendar->setCreatedBy($this->admin);
        $em->persist($this->calendar);
        foreach ([['Période 1', '2026-09-07', '2026-09-18'], ['Période finale', '2027-06-28', '2027-07-09']] as [$name, $start, $end]) {
            $period = new Period($name, new \DateTimeImmutable($start), new \DateTimeImmutable($end), $periodType, $this->calendar);
            $period->setCreatedBy($this->admin);
            $this->initial->addPeriod($period);
            $em->persist($period);
        }

        $this->semesters = new EvaluationPeriodGroup('Semestres BTS');
        $this->semesters->setCreatedBy($this->admin);
        $em->persist($this->semesters);
        $semester = new EvaluationPeriod();
        $semester->setName('Semestre 1');
        $semester->setStartDate(new \DateTimeImmutable('2026-09-01'));
        $semester->setEndDate(new \DateTimeImmutable('2027-01-22'));
        $this->semesters->addPeriod($semester);
        $em->persist($semester);

        $this->sio = new Program('BTS SIO 1 2026-2027', 'SIO1 2026-27', $this->cohort, $this->sourceYear);
        $this->sio->setCreatedBy($this->admin);
        $this->sio->setVisibility(VisibilityLevel::Everyone);
        $this->sio->setTsfExportEnabled(true);
        $this->sio->setEvaluationPeriodGroup($this->semesters);
        $this->sio->addOption($this->slam);
        $this->sio->addModality($this->initial);
        foreach ([$this->teacher, $this->formerTeacher, $this->staffOnly] as $member) {
            $this->sio->addTeacher($member);
        }
        $this->sio->addReferentTeacher($this->teacher);
        $referential = new Referential('BTS-SIO-TEST', 'BTS SIO', '2020');
        $referential->setCreatedBy($this->admin);
        $em->persist($referential);
        $this->sio->setPortfolioEnabled(true);
        $this->sio->setPortfolioReferential($referential);
        $this->sio->setPortfolioCursusYear(1);
        $this->sio->setPortfolioExamSession(2028);
        $this->sio->setPortfolioE6Deadline(new \DateTimeImmutable('2027-05-14 18:00'));
        $em->persist($this->sio);

        $link = new ProgramPeriodGroup($this->sio, $this->calendar, 1);
        $this->sio->getProgramPeriodGroups()->add($link);
        $em->persist($link);
        $em->persist(new ProgramTeacherOption($this->sio, $this->teacher, $this->slam));
        $em->persist(new PortfolioValidator($this->sio, $this->slam, $this->teacher, $this->admin));

        $group = new TopicGroup('Enseignements généraux', $this->sio);
        $group->setCreatedBy($this->admin);
        $group->setTeacher($this->teacher);
        $em->persist($group);
        $maths = new Topic('Mathématiques', $this->sio, $group);
        $maths->setCreatedBy($this->admin);
        $maths->setTargetCmHours('60.00');
        $maths->addTeacher($this->teacher);
        $maths->addTeacher($this->formerTeacher);
        $em->persist($maths);
        $english = new Topic('Anglais', $this->sio, $group);
        $english->setCreatedBy($this->admin);
        $english->addTeacher($this->formerTeacher);
        $em->persist($english);

        $block = new SkillGroup('Support et mise à disposition', $this->sio);
        $block->setCreatedBy($this->admin);
        $block->setCode('B1');
        $block->addOption($this->slam);
        $em->persist($block);
        foreach (['Gérer le patrimoine', 'Répondre aux incidents'] as $label) {
            $skill = new Skill($label, $block);
            $skill->setCreatedBy($this->admin);
            $skill->setKnowledgeHtml('<p>Savoirs</p>');
            $em->persist($skill);
        }

        $item = new ProgramFinancialItem('Frais de dossier', FinancialItemSource::Manual, FinancialItemType::Gain, $this->sio);
        $item->setValue('150.00');
        $em->persist($item);
        $lessonType = new LessonType('Cours', '#123456');
        $lessonType->setCreatedBy($this->admin);
        $em->persist($lessonType);
        $em->persist(new ProgramLessonTypeCost($this->sio, $lessonType, '55.00'));

        $certification = new ProgramCertification($this->sio, $this->slam, 'BTS SIO option SLAM');
        $certification->setDecreeDate(new \DateTimeImmutable('2019-04-02'));
        $em->persist($certification);
        $info = new InternshipProgramInfo($this->sio);
        $info->setCreatedBy($this->admin);
        $info->setLegalName('BTS Services informatiques aux organisations');
        $em->persist($info);
        $bilan = new InternshipEvaluationPeriod($this->sio);
        $bilan->setCreatedBy($this->admin);
        $bilan->setName('Bilan 1');
        $bilan->setStartDate(new \DateTimeImmutable('2027-01-04'));
        $bilan->setEndDate(new \DateTimeImmutable('2027-01-29'));
        $em->persist($bilan);

        $em->persist(new GameRule($this->sio, 'attendance', 5));

        $em->flush();
    }

    /** The fixtures again, from the database - after a clear(), the ones held are detached. */
    protected function reload(): void
    {
        $em = $this->em();
        $this->admin = $em->find(User::class, $this->admin->getId()) ?? $this->admin;
        $this->sourceYear = $em->find(SchoolYear::class, $this->sourceYear->getId()) ?? $this->sourceYear;
        $this->targetYear = $em->find(SchoolYear::class, $this->targetYear->getId()) ?? $this->targetYear;
        $this->sio = $em->find(Program::class, $this->sio->getId()) ?? $this->sio;
        $this->cohort = $em->find(Cohort::class, $this->cohort->getId()) ?? $this->cohort;
    }

    protected function year(string $start, string $end): SchoolYear
    {
        $year = new SchoolYear(new \DateTimeImmutable($start), new \DateTimeImmutable($end));
        $year->setCreatedBy($this->admin);
        $this->em()->persist($year);

        return $year;
    }
}
