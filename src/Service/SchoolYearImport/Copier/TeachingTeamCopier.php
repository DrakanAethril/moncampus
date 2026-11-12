<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\ProgramReferentTeacherOption;
use App\Entity\ProgramTeacherOption;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « Équipe pédagogique » and its three sub-boxes - référents, options of the teachers (holders are
 * set by the copiers of what they hold).
 *
 * The team first: a teacher of the source formation is added when their account is still active
 * and still a teacher (R6), otherwise counted as not taken over. The pair's team is then what the
 * target formation will hold, and référents and options are only ever set on its members.
 */
final readonly class TeachingTeamCopier implements ProgramCopier
{
    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        $target = $pair->target;
        $write = $run->writable($pair);
        foreach ($target?->getTeachers() ?? [] as $teacher) {
            $pair->team[(int) $teacher->getId()] = $teacher;
        }

        if (!$run->has(SchoolYearImportBlock::Team)) {
            return;
        }

        foreach ($pair->source->getTeachers() as $teacher) {
            if ($pair->inTeam($teacher)) {
                continue;
            }
            $refusal = SchoolYearImportRules::teacherRefusal(null !== $teacher->getInactiveDate(), $teacher->getRoles());
            if (null !== $refusal) {
                $run->report->skippedTeacher($teacher, $refusal);
                continue;
            }
            $write?->addTeacher($teacher);
            $pair->team[(int) $teacher->getId()] = $teacher;
            $run->report->add($pair, 'teachers');
        }

        if ($run->has(SchoolYearImportBlock::Referents)) {
            $this->copyReferents($run, $pair);
        }
        if ($run->has(SchoolYearImportBlock::TeacherOptions)) {
            $this->copyTeacherOptions($run, $pair);
        }
    }

    private function copyReferents(ImportRun $run, ProgramPair $pair): void
    {
        $target = $pair->target;
        $write = $run->writable($pair);
        $referents = [];
        foreach ($target?->getReferentTeachers() ?? [] as $referent) {
            $referents[(int) $referent->getId()] = true;
        }
        foreach ($pair->source->getReferentTeachers() as $referent) {
            if (!$pair->inTeam($referent) || isset($referents[(int) $referent->getId()])) {
                continue;
            }
            $write?->addReferentTeacher($referent);
            $referents[(int) $referent->getId()] = true;
            $run->report->add($pair, 'referents');
        }

        $couples = [];
        foreach ($run->data->rowsOf(ProgramReferentTeacherOption::class, $target) as $row) {
            $couples[$row->getReferentTeacher()?->getId().'-'.$row->getOption()?->getId()] = true;
        }
        foreach ($run->data->rowsOf(ProgramReferentTeacherOption::class, $pair->source) as $row) {
            $referent = $row->getReferentTeacher();
            $option = $row->getOption();
            $key = $referent?->getId().'-'.$option?->getId();
            if (null === $referent || null === $option || isset($couples[$key]) || !isset($referents[(int) $referent->getId()]) || !$pair->hasOption($option)) {
                continue;
            }
            $couples[$key] = true;
            if (null !== $write) {
                $run->persist(new ProgramReferentTeacherOption($write, $referent, $option));
            }
            $run->report->add($pair, 'referentOptions');
        }
    }

    private function copyTeacherOptions(ImportRun $run, ProgramPair $pair): void
    {
        $target = $pair->target;
        $write = $run->writable($pair);
        $couples = [];
        foreach ($run->data->rowsOf(ProgramTeacherOption::class, $target) as $row) {
            $couples[$row->getTeacher()?->getId().'-'.$row->getOption()?->getId()] = true;
        }
        foreach ($run->data->rowsOf(ProgramTeacherOption::class, $pair->source) as $row) {
            $teacher = $row->getTeacher();
            $option = $row->getOption();
            $key = $teacher?->getId().'-'.$option?->getId();
            if (null === $teacher || null === $option || isset($couples[$key]) || !$pair->inTeam($teacher) || !$pair->hasOption($option)) {
                continue;
            }
            $couples[$key] = true;
            if (null !== $write) {
                $run->persist(new ProgramTeacherOption($write, $teacher, $option));
            }
            $run->report->add($pair, 'teacherOptions');
        }
    }
}
