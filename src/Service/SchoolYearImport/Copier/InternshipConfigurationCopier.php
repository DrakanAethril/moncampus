<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipOptionExamModality;
use App\Entity\InternshipOptionLegalName;
use App\Entity\InternshipProgramInfo;
use App\Entity\ProgramCertification;
use App\Entity\ProgramContractModality;
use App\Entity\ProgramEcfSettings;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « Alternance (UFA) »: the formation's denomination and certifications per option (their dates -
 * arrêté, J.O., date d'effet, template update - kept as they are), its legal name, exam modalities
 * and booklet team, the modalities per contract type, the booklet's evaluation periods (shifted and
 * « À relire ») and the ECF booklet's settings.
 *
 * A certification is either one row without option or one per certifying option (§7): on a
 * formation already there, the two shapes are never mixed - if the target has the other shape,
 * nothing is added and the report says so. A text field of the program info left empty is filled;
 * a text already written is kept.
 */
final readonly class InternshipConfigurationCopier implements ProgramCopier
{
    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        if (!$run->has(SchoolYearImportBlock::Internship)) {
            return;
        }

        $this->certifications($run, $pair);
        $this->programInfo($run, $pair);
        $this->optionTexts($run, $pair);
        $this->contractModalities($run, $pair);
        $this->evaluationPeriods($run, $pair);
        $this->ecfSettings($run, $pair);
    }

    private function certifications(ImportRun $run, ProgramPair $pair): void
    {
        $write = $run->writable($pair);
        $sources = $run->data->rowsOf(ProgramCertification::class, $pair->source);
        $existing = $run->data->rowsOf(ProgramCertification::class, $pair->target);
        if ([] === $sources) {
            return;
        }

        $sourceGlobal = null === $sources[0]->getOption();
        if ([] !== $existing && (null === $existing[0]->getOption()) !== $sourceGlobal) {
            $run->report->note($pair, 'schoolYearImportNoteCertificationShape');

            return;
        }

        $held = [];
        foreach ($existing as $certification) {
            $held[(int) $certification->getOption()?->getId()] = true;
        }
        foreach ($sources as $certification) {
            $option = $certification->getOption();
            if ((null === $option) !== $sourceGlobal || isset($held[(int) $option?->getId()])) {
                continue;
            }
            if (null !== $option && !$pair->hasOption($option)) {
                continue;
            }
            $held[(int) $option?->getId()] = true;
            $run->report->add($pair, 'certifications');
            if (null === $write) {
                continue;
            }
            $copy = new ProgramCertification($write, $option, $certification->getLabel());
            $copy->setKind($certification->getKind());
            $copy->setRncpCode($certification->getRncpCode());
            $copy->setLevel($certification->getLevel());
            $copy->setCertifier($certification->getCertifier());
            $copy->setTitleCode($certification->getTitleCode());
            $copy->setMillesime($certification->getMillesime());
            $copy->setDecreeDate($certification->getDecreeDate());
            $copy->setJournalDate($certification->getJournalDate());
            $copy->setEffectiveDate($certification->getEffectiveDate());
            $copy->setModelUpdatedDate($certification->getModelUpdatedDate());
            $run->persist($copy);
        }
    }

    private function programInfo(ImportRun $run, ProgramPair $pair): void
    {
        $source = $run->data->rowsOf(InternshipProgramInfo::class, $pair->source)[0] ?? null;
        if (null === $source) {
            return;
        }
        $write = $run->writable($pair);
        $existing = $run->data->rowsOf(InternshipProgramInfo::class, $pair->target)[0] ?? null;

        if (null === $existing) {
            $run->report->add($pair, 'internshipInfo');
            if (null !== $write) {
                $copy = new InternshipProgramInfo($write);
                $copy->setLegalName($source->getLegalName());
                $copy->setExamModalityText($source->getExamModalityText());
                $copy->setTeachingTeam($source->getTeachingTeam());
                $run->persist($copy);
            }

            return;
        }

        $filled = false;
        if (self::blank($existing->getLegalName()) && !self::blank($source->getLegalName())) {
            $filled = true;
            if (!$run->dryRun) {
                $existing->setLegalName($source->getLegalName());
            }
        }
        if (self::blank($existing->getExamModalityText()) && !self::blank($source->getExamModalityText())) {
            $filled = true;
            if (!$run->dryRun) {
                $existing->setExamModalityText($source->getExamModalityText());
            }
        }
        if ([] === $existing->getTeachingTeam() && [] !== $source->getTeachingTeam()) {
            $filled = true;
            if (!$run->dryRun) {
                $existing->setTeachingTeam($source->getTeachingTeam());
            }
        }
        if ($filled) {
            $run->report->add($pair, 'internshipInfo');
        }
    }

    private function optionTexts(ImportRun $run, ProgramPair $pair): void
    {
        $write = $run->writable($pair);

        $names = [];
        foreach ($run->data->rowsOf(InternshipOptionLegalName::class, $pair->target) as $row) {
            $names[(int) $row->getOption()?->getId()] = true;
        }
        foreach ($run->data->rowsOf(InternshipOptionLegalName::class, $pair->source) as $row) {
            $option = $row->getOption();
            if (null === $option || isset($names[(int) $option->getId()]) || !$pair->hasOption($option)) {
                continue;
            }
            $names[(int) $option->getId()] = true;
            $run->report->add($pair, 'legalNames');
            if (null !== $write) {
                $run->persist(new InternshipOptionLegalName($write, $option, (string) $row->getLegalName()));
            }
        }

        $exams = [];
        foreach ($run->data->rowsOf(InternshipOptionExamModality::class, $pair->target) as $row) {
            $exams[(int) $row->getOption()?->getId()] = true;
        }
        foreach ($run->data->rowsOf(InternshipOptionExamModality::class, $pair->source) as $row) {
            $option = $row->getOption();
            if (null === $option || isset($exams[(int) $option->getId()]) || !$pair->hasOption($option)) {
                continue;
            }
            $exams[(int) $option->getId()] = true;
            $run->report->add($pair, 'examModalities');
            if (null !== $write) {
                $run->persist(new InternshipOptionExamModality($write, $option, (string) $row->getExamModalityText()));
            }
        }
    }

    private function contractModalities(ImportRun $run, ProgramPair $pair): void
    {
        $write = $run->writable($pair);
        $held = [];
        foreach ($run->data->rowsOf(ProgramContractModality::class, $pair->target) as $row) {
            $held[(int) $row->getContractType()?->getId()] = true;
        }
        foreach ($run->data->rowsOf(ProgramContractModality::class, $pair->source) as $row) {
            $type = $row->getContractType();
            if (null === $type || isset($held[(int) $type->getId()])) {
                continue;
            }
            $held[(int) $type->getId()] = true;
            $run->report->add($pair, 'contractModalities');
            if (null !== $write) {
                $run->persist(new ProgramContractModality($write, $type, (string) $row->getModalitiesHtml()));
            }
        }
    }

    private function evaluationPeriods(ImportRun $run, ProgramPair $pair): void
    {
        $write = $run->writable($pair);
        $held = [];
        foreach ($run->data->rowsOf(InternshipEvaluationPeriod::class, $pair->target) as $period) {
            if (null === $period->getInactiveDate()) {
                $held[SchoolYearImportRules::key($period->getName())] = true;
            }
        }
        foreach ($run->data->rowsOf(InternshipEvaluationPeriod::class, $pair->source) as $period) {
            $key = SchoolYearImportRules::key($period->getName());
            $start = $period->getStartDate();
            $end = $period->getEndDate();
            if (null !== $period->getInactiveDate() || isset($held[$key]) || null === $start || null === $end) {
                continue;
            }
            $held[$key] = true;
            $range = $run->shift->moveRange($start, $end);
            $run->report->add($pair, 'internshipPeriods');
            $run->report->shiftedDate($pair, $pair->name.' · Livret · '.$period->getName(), $start, $end, $range->start, $range->end, CopierFlags::of($range));
            if (null === $write) {
                continue;
            }
            $copy = new InternshipEvaluationPeriod($write);
            $copy->setName($period->getName());
            $copy->setStartDate($range->start);
            $copy->setEndDate($range->end);
            $copy->markShifted($run->now);
            $run->persist($copy);
        }
    }

    private function ecfSettings(ImportRun $run, ProgramPair $pair): void
    {
        $source = $run->data->rowsOf(ProgramEcfSettings::class, $pair->source)[0] ?? null;
        if (null === $source || [] !== $run->data->rowsOf(ProgramEcfSettings::class, $pair->target)) {
            return;
        }
        $run->report->add($pair, 'ecfSettings');
        $write = $run->writable($pair);
        if (null === $write) {
            return;
        }
        $copy = new ProgramEcfSettings($write);
        $copy->setEnabled($source->isEnabled());
        $copy->setOrganisation($source->getOrganisation());
        $copy->setPlace($source->getPlace());
        $run->persist($copy);
    }

    private static function blank(?string $text): bool
    {
        return null === $text || '' === trim(strip_tags($text));
    }
}
