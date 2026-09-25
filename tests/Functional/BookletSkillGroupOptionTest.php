<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enterprise;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipFormationCenter;
use App\Entity\InternshipProgramInfo;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorEvaluationSkill;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramStudentOption;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\SkillLevel;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Service\AlternanceTutorWizardStepBuilder;
use App\Service\InternshipBookletBuilder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A competency group narrowed to options appears in the Livret de l'alternant only for a student
 * holding one of them - the booklet itself, and the tutor's « Compétences » step that fills it.
 * The same goes for the lines of its « Équipe pédagogique » (App\Service\TeachingTeam).
 *
 * Against the real schema, because what the wizard got wrong was never the rule but the rows: an
 * evaluation keeps the skill rows it was created with, and a group put on an option afterwards
 * left them in the form. See App\Service\BookletSkillGroups.
 */
class BookletSkillGroupOptionTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $author;
    private User $student;
    private Program $program;
    private Option $slam;
    private Option $sisr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->author = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'booklet.author');
        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'booklet.student');
        $this->program = $this->createProgram([$this->student], [], $this->author);
        $this->slam = $this->option('SLAM');
        $this->sisr = $this->option('SISR');
    }

    public function testTheBookletShowsTheCommonGroupsAndTheStudentsOwnOptionOnly(): void
    {
        $this->giveStudentOption($this->slam);
        $this->group('Commun');
        $this->group('Réservé SLAM', $this->slam);
        $this->group('Réservé SISR', $this->sisr);

        self::assertSame(['Commun', 'Réservé SLAM'], $this->bookletGroupLabels($this->tutorLink()));
    }

    public function testAStudentWithNoOptionSeesNoGroupReservedToOne(): void
    {
        $this->group('Commun');
        $this->group('Réservé SISR', $this->sisr);

        self::assertSame(['Commun'], $this->bookletGroupLabels($this->tutorLink()));
    }

    public function testTheTeachingTeamListsTheCommonLinesAndTheStudentsOwnOptionOnly(): void
    {
        $this->giveStudentOption($this->slam);
        $info = new InternshipProgramInfo($this->program);
        $info->setCreatedBy($this->author);
        $info->setTeachingTeam([
            ['id' => 'a1', 'topic' => 'Réservé SLAM', 'teacher' => 'Y', 'optionIds' => [$this->slam->getId()]],
            ['id' => 'b2', 'topic' => 'Réservé SISR', 'teacher' => 'Z', 'optionIds' => [$this->sisr->getId()]],
            ['id' => 'c3', 'topic' => 'Commun', 'teacher' => 'X', 'optionIds' => []],
        ]);
        $this->entityManager->persist($info);
        // The page below needs the training centre every installation has.
        $center = new InternshipFormationCenter();
        $center->setCreatedBy($this->author);
        $this->entityManager->persist($center);
        $this->entityManager->flush();

        $tutorLink = $this->tutorLink();
        /** @var list<array{topic: string}> $teamRows */
        $teamRows = $this->booklet($tutorLink)['teamRows'];

        self::assertSame(['Commun', 'Réservé SLAM'], array_column($teamRows, 'topic'));

        // And the page prints them as typed.
        $this->client->loginUser($this->author);
        $crawler = $this->client->request('GET', '/ufa/alternances/'.$tutorLink->getId().'/booklet/frame');
        self::assertResponseIsSuccessful();
        $team = $crawler->filter('#section-i-4 + table tbody tr')->each(static fn ($row): string => trim(preg_replace('/\s+/', ' ', $row->text()) ?? ''));
        self::assertSame(['Commun X', 'Réservé SLAM Y'], $team);
    }

    public function testRowsStoredBeforeTheGroupWasNarrowedLeaveTheSkillsStep(): void
    {
        $this->giveStudentOption($this->slam);
        $common = $this->group('Commun');
        $narrowed = $this->group('Devenu SISR');
        $tutorLink = $this->tutorLink();
        $period = $this->period();
        $level = new SkillLevel('Acquis');
        $level->setCreatedBy($this->author);
        $this->entityManager->persist($level);

        // The tutor opened the form while both groups were common to everyone...
        $evaluation = new InternshipTutorEvaluation($tutorLink, $period);
        $evaluation->setCreatedBy($this->author);
        foreach ([$common, $narrowed] as $group) {
            foreach ($group->getSkills() as $skill) {
                $evaluation->addSkillEvaluation(new InternshipTutorEvaluationSkill($skill));
            }
        }
        $this->entityManager->persist($evaluation);
        // ...then the second one was put on SISR.
        $narrowed->addOption($this->sisr);
        $this->entityManager->flush();

        $stepBuilder = static::getContainer()->get(AlternanceTutorWizardStepBuilder::class);
        $form = $stepBuilder->buildStepForm('competences', $evaluation, $this->program);

        self::assertCount(1, $form->get('skillEvaluations'));
        self::assertFalse($stepBuilder->isStepComplete('competences', $evaluation));

        $form->submit(['skillEvaluations' => [['skillLevel' => (string) $level->getId()]]]);

        // The answer lands on the stored row, the hidden row stays where it was - kept, not deleted,
        // and no longer what holds the tutor back.
        self::assertSame($level, $this->rowFor($evaluation, $common)->getSkillLevel());
        self::assertNull($this->rowFor($evaluation, $narrowed)->getSkillLevel());
        self::assertCount(2, $evaluation->getSkillEvaluations());
        self::assertTrue($stepBuilder->isStepComplete('competences', $evaluation));
    }

    /** @return list<string> */
    private function bookletGroupLabels(InternshipTutorLink $tutorLink): array
    {
        /** @var list<SkillGroup> $groups */
        $groups = $this->booklet($tutorLink)['skillGroups'];

        return array_map(static fn (SkillGroup $group): string => $group->getLabel(), $groups);
    }

    /** @return array<string, mixed> */
    private function booklet(InternshipTutorLink $tutorLink): array
    {
        $this->entityManager->clear();
        $tutorLink = $this->entityManager->find(InternshipTutorLink::class, $tutorLink->getId());
        self::assertInstanceOf(InternshipTutorLink::class, $tutorLink);

        return static::getContainer()->get(InternshipBookletBuilder::class)->build($tutorLink);
    }

    private function rowFor(InternshipTutorEvaluation $evaluation, SkillGroup $group): InternshipTutorEvaluationSkill
    {
        foreach ($evaluation->getSkillEvaluations() as $row) {
            if ($row->getSkill()?->getSkillGroup() === $group) {
                return $row;
            }
        }

        self::fail('No row for '.$group->getLabel());
    }

    private function option(string $name): Option
    {
        $option = new Option($name, $name, '#000000');
        $option->setCreatedBy($this->author);
        $option->addProgram($this->program);
        $this->entityManager->persist($option);
        $this->entityManager->flush();

        return $option;
    }

    private function giveStudentOption(Option $option): void
    {
        $this->entityManager->persist(new ProgramStudentOption($this->program, $this->student, $option));
        $this->entityManager->flush();
    }

    private function group(string $label, ?Option $option = null): SkillGroup
    {
        $group = new SkillGroup($label, $this->program);
        $group->setCreatedBy($this->author);
        if (null !== $option) {
            $group->addOption($option);
        }
        $this->entityManager->persist($group);

        $skill = new Skill('Compétence '.$label, $group);
        $skill->setCreatedBy($this->author);
        $this->entityManager->persist($skill);
        $this->entityManager->flush();

        return $group;
    }

    private function tutorLink(): InternshipTutorLink
    {
        $enterprise = new Enterprise('ACME');
        $enterprise->setCreatedBy($this->author);
        $this->entityManager->persist($enterprise);

        $tutorLink = new InternshipTutorLink($this->program);
        $tutorLink->setStudent($this->student)
            ->setTutor($this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'booklet.tutor'))
            ->setEnterprise($enterprise)
            ->setContractStartDate(new \DateTimeImmutable('-1 month'))
            ->setContractEndDate(new \DateTimeImmutable('+1 year'))
            ->setContractType(ContractTypeCode::Apprentissage);
        $tutorLink->setCreatedBy($this->author);
        $this->entityManager->persist($tutorLink);
        $this->entityManager->flush();

        return $tutorLink;
    }

    private function period(): InternshipEvaluationPeriod
    {
        $period = new InternshipEvaluationPeriod($this->program);
        $period->setName('Période 1')
            ->setStartDate(new \DateTimeImmutable('-1 week'))
            ->setEndDate(new \DateTimeImmutable('+1 week'));
        $period->setCreatedBy($this->author);
        $this->entityManager->persist($period);

        return $period;
    }
}
