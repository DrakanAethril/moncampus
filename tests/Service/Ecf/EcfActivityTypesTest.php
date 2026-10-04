<?php

declare(strict_types=1);

namespace App\Tests\Service\Ecf;

use App\Entity\Cohort;
use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\User;
use App\Repository\SkillGroupRepository;
use App\Service\BookletSkillGroups;
use App\Service\Ecf\EcfActivityTypes;
use PHPUnit\Framework\TestCase;

/**
 * The ECF reads the Livret de l'alternant's groups (options included, decided by
 * BookletSkillGroups) and only orders, numbers and keys them by code. What is pinned: the order
 * and numbering, the competences in their own order without the inactive ones, a group without a
 * code left out, and the orphans.
 */
class EcfActivityTypesTest extends TestCase
{
    private Program $program;

    protected function setUp(): void
    {
        $this->program = new Program('CDA 2', 'CDA2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
    }

    private function group(int $id, ?string $code, string $label, int $order, array $skills = [], bool $visibleInBooklet = true): SkillGroup
    {
        $group = new SkillGroup($label, $this->program);
        (new \ReflectionProperty(SkillGroup::class, 'id'))->setValue($group, $id);
        $group->setCode($code)->setOrder($order)->setVisibleInBooklet($visibleInBooklet);
        foreach ($skills as $index => [$skillLabel, $skillOrder, $active]) {
            $skill = new Skill($skillLabel, $group);
            (new \ReflectionProperty(Skill::class, 'id'))->setValue($skill, $id * 100 + $index);
            $skill->setOrder($skillOrder);
            if (!$active) {
                $skill->setInactiveDate(new \DateTimeImmutable());
            }
        }

        return $group;
    }

    /** @param list<SkillGroup> $forStudent @param list<SkillGroup> $all */
    private function types(array $forStudent, array $all = []): EcfActivityTypes
    {
        $booklet = $this->createStub(BookletSkillGroups::class);
        $booklet->method('forTutorLink')->willReturn($forStudent);
        $repository = $this->createStub(SkillGroupRepository::class);
        $repository->method('findAllActiveForProgram')->willReturn($all ?: $forStudent);

        return new EcfActivityTypes($booklet, $repository);
    }

    private function tutorLink(): InternshipTutorLink
    {
        $link = $this->createStub(InternshipTutorLink::class);
        $link->method('getProgram')->willReturn($this->program);

        return $link;
    }

    public function testNumbersGroupsInTheirOrderWithActiveCompetencesInOrder(): void
    {
        $at2 = $this->group(7, 'AT2', 'Concevoir', 2, [['Analyser', 1, true]]);
        $at1 = $this->group(9, ' AT1 ', 'Développer', 1, [['Composants', 3, true], ['Ancienne', 2, false], ['Environnement', 1, true]]);

        $types = $this->types([$at2, $at1])->forTutorLink($this->tutorLink());

        self::assertSame([1, 2], array_map(static fn ($t) => $t->number, $types));
        self::assertSame(['AT1', 'AT2'], array_map(static fn ($t) => $t->code, $types));
        self::assertSame(['Environnement', 'Composants'], $types[0]->competences);
    }

    public function testAGroupWithoutCodeIsLeftOut(): void
    {
        $types = $this->types([$this->group(1, 'AT1', 'A', 1), $this->group(2, null, 'B', 2)])->forTutorLink($this->tutorLink());

        self::assertSame(['AT1'], array_map(static fn ($t) => $t->code, $types));
    }

    public function testExcludedForOptionsListsTheBookletGroupsTheStudentDoesNotGet(): void
    {
        $common = $this->group(1, 'AT1', 'Commun', 1);
        $option = $this->group(2, 'OPT', 'Option Data', 2);
        $hidden = $this->group(3, 'HID', 'Hors livret', 3, [], false);
        $uncoded = $this->group(4, null, 'Sans code', 4);

        $excluded = $this->types([$common], [$common, $option, $hidden, $uncoded])->excludedForOptions($this->tutorLink());

        self::assertSame([$option], $excluded);
    }

    public function testHasActivityTypesOnlyWithABookletGroupThatHasACode(): void
    {
        self::assertFalse($this->types([])->hasActivityTypes($this->program));
        self::assertFalse($this->types([], [$this->group(1, '', 'A', 1), $this->group(2, 'AT2', 'B', 2, [], false)])->hasActivityTypes($this->program));
        self::assertTrue($this->types([], [$this->group(1, null, 'A', 1), $this->group(2, 'AT2', 'B', 2)])->hasActivityTypes($this->program));
    }

    public function testOrphansAreTheActivitiesWhoseCodeIsNoLongerOffered(): void
    {
        $booklet = new EcfBooklet(new User('candidate'), 'TP-01281', '04');
        new EcfActivity($booklet, 'AT1');
        $gone = new EcfActivity($booklet, 'AT9');
        $types = $this->types([$this->group(1, 'AT1', 'A', 1)])->forTutorLink($this->tutorLink());

        self::assertSame([$gone], $this->types([])->orphans($booklet, $types));
    }
}
