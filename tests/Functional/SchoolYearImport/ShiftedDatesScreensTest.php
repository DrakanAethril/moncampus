<?php

declare(strict_types=1);

namespace App\Tests\Functional\SchoolYearImport;

use App\Entity\EvaluationPeriod;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\Period;
use App\Entity\PeriodGroup;
use App\Entity\Program;
use App\Service\SchoolYearImport\SchoolYearImportChoices;
use App\Service\SchoolYearImport\SchoolYearImporter;
use App\Tests\Functional\FunctionalTestCase;

/**
 * « À relire » on the four screens that show dates the import moved, and « Confirmer ces dates »
 * on each (design/validated/school-year-import.md §6, lot 5).
 */
class ShiftedDatesScreensTest extends FunctionalTestCase
{
    use SchoolYearImportFixtures;

    private Program $created;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        static::getContainer()->get(SchoolYearImporter::class)->import(
            $this->sourceYear,
            $this->targetYear,
            SchoolYearImportChoices::defaults((int) $this->sourceYear->getId(), [(int) $this->sio->getId()]),
            $this->admin,
        );
        $this->created = $this->em()->getRepository(Program::class)->findOneBy(['schoolYear' => $this->targetYear]) ?? throw new \LogicException('Nothing imported.');
        $this->client->loginUser($this->admin);
    }

    /** Opens the screen, checks the notice, posts its « Confirmer ces dates ». */
    private function confirmOn(string $url): void
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.cm-syi-shifted');

        $this->client->submit($crawler->filter('.cm-syi-shifted form')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorNotExists('.cm-syi-shifted');
        $this->em()->clear();
    }

    public function testThePeriodCalendar(): void
    {
        $group = $this->em()->getRepository(PeriodGroup::class)->findOneBy(['schoolYear' => $this->targetYear]);
        self::assertNotNull($group);

        $this->confirmOn('/settings/structure/period-groups/'.$group->getId().'/periods');

        foreach ($this->em()->getRepository(Period::class)->findBy(['periodGroup' => $group->getId()]) as $period) {
            self::assertFalse($period->isShifted());
        }
    }

    public function testTheEvaluationCalendar(): void
    {
        $group = $this->created->getEvaluationPeriodGroup();
        self::assertNotNull($group);

        $this->confirmOn('/settings/structure/evaluation-period-groups/'.$group->getId().'/edit');

        foreach ($this->em()->getRepository(EvaluationPeriod::class)->findBy(['evaluationPeriodGroup' => $group->getId()]) as $period) {
            self::assertFalse($period->isShifted());
        }
    }

    public function testTheBookletPeriods(): void
    {
        $this->confirmOn('/ufa/programs/'.$this->created->getId());

        self::assertFalse($this->em()->getRepository(InternshipEvaluationPeriod::class)->findOneBy(['program' => $this->created->getId()])?->isShifted());
    }

    public function testThePortfolioDeadlines(): void
    {
        $this->confirmOn('/programs/'.$this->created->getId().'/settings/portfolio');

        self::assertNull($this->em()->find(Program::class, $this->created->getId())?->getPortfolioDatesShiftedAt());
    }

    public function testTheStaffReadsThePortfolioMarkWithoutConfirmingIt(): void
    {
        $staff = $this->createUser(['ROLE_STAFF'], 'import.staff.reader');
        $this->client->loginUser($staff);

        $this->client->request('GET', '/programs/'.$this->created->getId().'/settings/portfolio');

        self::assertSelectorExists('.cm-syi-shifted');
        self::assertSelectorNotExists('.cm-syi-shifted form');
    }
}
