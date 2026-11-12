<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\Program;
use App\Enum\ProgramAlternanceCalendarMode;
use App\Enum\VisibilityLevel;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;
use App\Service\SchoolYearImport\SchoolYearImportRefusal;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « La formation elle-même », always taken over.
 *
 * pairs() decides, for each source formation, whether it is created or completed (R5). copy()
 * creates it - the target year's dates, hidden from students (R10), still a test formation if it
 * was one (R9), its visibilities, modules and modes - or, for a formation already there, changes
 * none of its fields. Either way the active options and modalities it lacks are added: they are
 * shared structure, the formation is added to them, never copied (§7).
 */
final readonly class ProgramShellCopier implements ProgramCopier
{
    /**
     * @param list<Program> $sources the source formations taken over
     *
     * @return list<ProgramPair>
     */
    public function pairs(ImportRun $run, array $sources): array
    {
        /** @var list<Program> $targets */
        $targets = $run->entityManager->getRepository(Program::class)->findBy(['schoolYear' => $run->target, 'inactiveDate' => null], ['id' => 'ASC']);
        $byId = [];
        $targetRows = [];
        foreach ($targets as $target) {
            $byId[(int) $target->getId()] = $target;
            $targetRows[] = [
                'id' => (int) $target->getId(),
                'cohortId' => (int) $target->getCohort()?->getId(),
                'name' => $target->getName(),
                'importedFromId' => $target->getImportedFrom()?->getId(),
                'created' => $target->getCreationDate()->format('Y-m-d H:i:s'),
            ];
        }

        $sourceRows = [];
        foreach ($sources as $source) {
            $sourceRows[] = ['id' => (int) $source->getId(), 'cohortId' => (int) $source->getCohort()?->getId(), 'name' => $run->names->rewrite($source->getName())];
        }
        $matches = SchoolYearImportRules::matchPrograms($sourceRows, $targetRows);

        $pairs = [];
        foreach ($sources as $source) {
            $match = $matches[(int) $source->getId()] ?? null;
            $target = null !== $match ? $byId[$match['target']] : null;
            $pair = new ProgramPair(
                $source,
                $target,
                null === $target,
                null !== $match && $match['ambiguous'],
                $target?->getName() ?? $run->names->rewrite($source->getName()),
            );
            $run->report->program($pair);
            if ($pair->ambiguous) {
                $run->report->note($pair, 'schoolYearImportNoteAmbiguous');
            }
            $pairs[] = $pair;
        }

        return $pairs;
    }

    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        $source = $pair->source;

        if ($pair->created && !$run->dryRun) {
            $name = trim($run->names->rewrite($source->getName()));
            $shortName = trim($run->names->rewrite($source->getShortName()));
            $cohort = $source->getCohort();
            if ('' === $name || '' === $shortName || null === $cohort) {
                throw SchoolYearImportRefusal::because('schoolYearImportRefusalEmptyName', ['%program%' => $source->getName()]);
            }

            $program = new Program($name, $shortName, $cohort, $run->target);
            $program->setStartDate($run->target->getStartDate());
            $program->setEndDate($run->target->getEndDate());
            $program->setImportedFrom($source);
            $program->setTestProgram($source->isTestProgram());
            $program->setVisibility(VisibilityLevel::StaffAdmin);
            $program->setTimetableManagementEnabled($source->isTimetableManagementEnabled());
            $program->setTimetableVisibility($source->getTimetableVisibility());
            $program->setAssignmentManagementEnabled($source->isAssignmentManagementEnabled());
            $program->setFinancialManagementEnabled($source->isFinancialManagementEnabled());
            $program->setInternshipManagementEnabled($source->isInternshipManagementEnabled());
            $program->setSchoolMailEnabled($source->isSchoolMailEnabled());
            $program->setTsfExportEnabled($source->isTsfExportEnabled());
            $program->setCustomSkillLevelsEnabled($source->isCustomSkillLevelsEnabled());
            $program->setGameEnabled($source->isGameEnabled());
            $program->setGameTrack($source->getGameTrack());
            $program->setJobboardVisibility($source->getJobboardVisibility());
            $program->setAlternanceCalendarVisibility($source->getAlternanceCalendarVisibility());
            $run->persist($program);
            $pair->target = $program;
        }

        // The uploaded alternance calendar is never taken over (§4): a formation that served one
        // is created on the generated calendar, and told so.
        if ($pair->created && ProgramAlternanceCalendarMode::File === $source->getAlternanceCalendarMode()) {
            $run->report->note($pair, 'schoolYearImportNoteAlternanceCalendarFile');
        }

        if (!$pair->created && null === $pair->target?->getImportedFrom() && !$run->dryRun) {
            $pair->target?->setImportedFrom($source);
        }

        $target = $pair->target;
        $write = $run->writable($pair);
        foreach ($target?->getOptions() ?? [] as $option) {
            $pair->options[(int) $option->getId()] = $option;
        }
        foreach ($source->getOptions() as $option) {
            if ($pair->hasOption($option)) {
                continue;
            }
            if (null !== $option->getInactiveDate()) {
                $run->report->note($pair, 'schoolYearImportNoteOptionInactive', ['%option%' => $option->getShortName()]);
                continue;
            }
            $write?->addOption($option);
            $pair->options[(int) $option->getId()] = $option;
            if (!$pair->created) {
                $run->report->add($pair, 'options');
            }
        }

        $modalities = [];
        foreach ($target?->getModalities() ?? [] as $modality) {
            $modalities[(int) $modality->getId()] = true;
        }
        foreach ($source->getModalities() as $modality) {
            if (isset($modalities[(int) $modality->getId()])) {
                continue;
            }
            if (null !== $modality->getInactiveDate()) {
                $run->report->note($pair, 'schoolYearImportNoteModalityInactive', ['%modality%' => $modality->getName()]);
                continue;
            }
            $write?->addModality($modality);
            if (!$pair->created) {
                $run->report->add($pair, 'modalities');
            }
        }
    }
}
