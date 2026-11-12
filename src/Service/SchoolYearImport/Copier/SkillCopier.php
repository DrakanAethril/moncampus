<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\SkillLevel;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « Blocs de compétences »: a block is recognised by its code, else by its label, and a block
 * already there is not completed; a block created carries every one of its active skills with all
 * their texts, the options the formation has and, with « Titulaires », its holders when they are
 * members of the target team (R6). The formation's own skill levels are recognised by their label.
 * The group is persisted before its skills (§7).
 */
final readonly class SkillCopier implements ProgramCopier
{
    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        if (!$run->has(SchoolYearImportBlock::Skills)) {
            return;
        }

        $target = $pair->target;
        $write = $run->writable($pair);
        $holders = $run->has(SchoolYearImportBlock::Holders);

        $existing = [];
        foreach ($run->data->rowsOf(SkillGroup::class, $target) as $group) {
            if (null === $group->getInactiveDate()) {
                $existing[self::key($group)] = true;
            }
        }

        foreach ($run->data->rowsOf(SkillGroup::class, $pair->source, ['skills', 'options']) as $group) {
            if (null !== $group->getInactiveDate() || isset($existing[self::key($group)])) {
                continue;
            }
            $existing[self::key($group)] = true;
            $skills = array_values(array_filter($group->getSkills()->toArray(), static fn (Skill $skill): bool => null === $skill->getInactiveDate()));
            $run->report->add($pair, 'skillGroups');
            $run->report->add($pair, 'skills', \count($skills));
            if (null === $write) {
                continue;
            }

            $copy = new SkillGroup($group->getLabel(), $write);
            $copy->setCode($group->getCode());
            $copy->setOrder($group->getOrder());
            $copy->setVisibleInBooklet($group->isVisibleInBooklet());
            $copy->setVisibleInProgram($group->isVisibleInProgram());
            foreach ($group->getOptions() as $option) {
                if ($pair->hasOption($option)) {
                    $copy->addOption($option);
                }
            }
            if ($holders && $pair->inTeam($group->getTeacher())) {
                $copy->setTeacher($group->getTeacher());
            }
            $run->persist($copy);

            foreach ($skills as $skill) {
                $skillCopy = new Skill($skill->getLabel(), $copy);
                $skillCopy->setCode($skill->getCode());
                $skillCopy->setOrder($skill->getOrder());
                $skillCopy->setDescriptionHtml($skill->getDescriptionHtml());
                $skillCopy->setOccupationDescription($skill->getOccupationDescription());
                $skillCopy->setKnowledgeHtml($skill->getKnowledgeHtml());
                $skillCopy->setActivitiesHtml($skill->getActivitiesHtml());
                $skillCopy->setPerformanceCriteriaHtml($skill->getPerformanceCriteriaHtml());
                $skillCopy->setDiagnosticAssessmentHtml($skill->getDiagnosticAssessmentHtml());
                $skillCopy->setSummativeAssessmentHtml($skill->getSummativeAssessmentHtml());
                $skillCopy->setCertifyingAssessmentHtml($skill->getCertifyingAssessmentHtml());
                $skillCopy->setVolumeHours($skill->getVolumeHours());
                $skillCopy->setTeachingPeriodLabel($skill->getTeachingPeriodLabel());
                if ($holders && $pair->inTeam($skill->getTeacher())) {
                    $skillCopy->setTeacher($skill->getTeacher());
                }
                $run->persist($skillCopy);
            }
        }

        $levels = [];
        foreach ($run->data->rowsOf(SkillLevel::class, $target) as $level) {
            if (null === $level->getInactiveDate()) {
                $levels[SchoolYearImportRules::key($level->getLabel())] = true;
            }
        }
        foreach ($run->data->rowsOf(SkillLevel::class, $pair->source) as $level) {
            $key = SchoolYearImportRules::key($level->getLabel());
            if (null !== $level->getInactiveDate() || isset($levels[$key])) {
                continue;
            }
            $levels[$key] = true;
            $run->report->add($pair, 'skillLevels');
            if (null === $write) {
                continue;
            }
            $copy = new SkillLevel($level->getLabel(), $level->getColor(), $write);
            $copy->setOrderIndex($level->getOrderIndex());
            $run->persist($copy);
        }
    }

    private static function key(SkillGroup $group): string
    {
        $code = $group->getCode();

        return null !== $code && '' !== trim($code) ? 'c:'.SchoolYearImportRules::key($code) : 'l:'.SchoolYearImportRules::key($group->getLabel());
    }
}
