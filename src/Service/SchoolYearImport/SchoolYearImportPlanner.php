<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Service\SchoolYearImport\Copier\CalendarLinkCopier;
use App\Service\SchoolYearImport\Copier\CrmCopier;
use App\Service\SchoolYearImport\Copier\EvaluationCalendarCopier;
use App\Service\SchoolYearImport\Copier\FinancialCopier;
use App\Service\SchoolYearImport\Copier\GameSettingsCopier;
use App\Service\SchoolYearImport\Copier\InternshipConfigurationCopier;
use App\Service\SchoolYearImport\Copier\PeriodCalendarCopier;
use App\Service\SchoolYearImport\Copier\PortfolioSettingsCopier;
use App\Service\SchoolYearImport\Copier\ProgramCopier;
use App\Service\SchoolYearImport\Copier\ProgramShellCopier;
use App\Service\SchoolYearImport\Copier\SkillCopier;
use App\Service\SchoolYearImport\Copier\SyllabusCopier;
use App\Service\SchoolYearImport\Copier\TeachingTeamCopier;
use App\Service\SchoolYearImport\Copier\TopicCopier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The plan of a school-year import (design/validated/school-year-import.md, screen 3): which
 * formations are created and which completed (R5), what each would receive block by block, the
 * teachers left out (R6), every date moved (R4) - written nowhere.
 *
 * The plan is the copiers' own dry run: the importer walks the very same copiers, in the same
 * order, for real (walk()), so the screen cannot announce what the import would not do.
 */
final readonly class SchoolYearImportPlanner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private PeriodCalendarCopier $periodCalendars,
        private EvaluationCalendarCopier $evaluationCalendars,
        private ProgramShellCopier $shell,
        private TeachingTeamCopier $team,
        private TopicCopier $topics,
        private SyllabusCopier $syllabus,
        private SkillCopier $skills,
        private CalendarLinkCopier $calendarLinks,
        private FinancialCopier $financial,
        private InternshipConfigurationCopier $internship,
        private PortfolioSettingsCopier $portfolio,
        private GameSettingsCopier $game,
        private CrmCopier $crm,
    ) {
    }

    /** @return list<Program> a year's active formations, by name - what can be taken over from it (§7) */
    public function activePrograms(SchoolYear $year): array
    {
        /** @var list<Program> $programs */
        $programs = $this->entityManager->getRepository(Program::class)->findBy(['schoolYear' => $year, 'inactiveDate' => null], ['name' => 'ASC', 'id' => 'ASC']);

        return $programs;
    }

    public function plan(SchoolYear $source, SchoolYear $target, SchoolYearImportChoices $choices): SchoolYearImportReport
    {
        $run = $this->begin($source, $target, $choices, true, null);
        $this->walk($run);

        return $run->report;
    }

    /** @throws SchoolYearImportRefusal when R1 does not hold */
    public function begin(SchoolYear $source, SchoolYear $target, SchoolYearImportChoices $choices, bool $dryRun, ?User $actor): ImportRun
    {
        $sourceStart = $source->getStartDate();
        $sourceEnd = $source->getEndDate();
        $targetStart = $target->getStartDate();
        $targetEnd = $target->getEndDate();
        if ($source === $target || null === $sourceStart || null === $sourceEnd || null === $targetStart || null === $targetEnd) {
            throw SchoolYearImportRefusal::because('schoolYearImportRefusalSameYear');
        }
        if (null !== $source->getInactiveDate() || null !== $target->getInactiveDate()) {
            throw SchoolYearImportRefusal::because('schoolYearImportRefusalInactiveYear');
        }

        $shift = SchoolYearDateShift::between($sourceStart, $targetStart, $targetEnd);
        $names = SchoolYearNameRewriter::forYears($sourceStart, $sourceEnd, $targetStart, $targetEnd);
        $data = new SchoolYearImportData($this->entityManager, [...$this->selectedPrograms($source, $choices), ...$this->activePrograms($target)]);

        return new ImportRun(
            $source,
            $target,
            $choices,
            $shift,
            $names,
            $data,
            new SchoolYearImportReport($names->sourceLabel(), $names->targetLabel(), $shift->weeks),
            $this->entityManager,
            $dryRun,
            $this->clock->now(),
            $actor,
        );
    }

    /**
     * Every copier, in the order the rows depend on each other (§7): calendars before the links
     * that point at them, the formation before what hangs off it, the team before the holders.
     * The CRM comes last, after `$beforeCrm` - the importer flushes there, the CRM's settings
     * being read by formation id.
     *
     * @param (\Closure(list<ProgramPair>): void)|null $beforeCrm
     *
     * @return list<ProgramPair>
     */
    public function walk(ImportRun $run, ?\Closure $beforeCrm = null): array
    {
        $sources = $this->selectedPrograms($run->source, $run->choices);

        $this->periodCalendars->copy($run);
        $this->evaluationCalendars->copy($run, $sources);

        $pairs = $this->shell->pairs($run, $sources);
        /** @var list<ProgramCopier> $copiers */
        $copiers = [$this->shell, $this->team, $this->topics, $this->syllabus, $this->skills, $this->calendarLinks, $this->financial, $this->internship, $this->portfolio, $this->game];
        foreach ($pairs as $pair) {
            foreach ($copiers as $copier) {
                $copier->copy($run, $pair);
            }
        }

        if (null !== $beforeCrm) {
            $beforeCrm($pairs);
        }
        foreach ($pairs as $pair) {
            $this->crm->copy($run, $pair);
        }

        return $pairs;
    }

    /** @return list<Program> the source formations ticked, active ones only */
    private function selectedPrograms(SchoolYear $source, SchoolYearImportChoices $choices): array
    {
        return array_values(array_filter($this->activePrograms($source), static fn (Program $program): bool => $choices->hasProgram((int) $program->getId())));
    }
}
