<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\ProgramFinancialItem;
use App\Entity\ProgramLessonTypeCost;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « Financier »: the budget lines, recognised by title + source + type, and the hourly cost of each
 * lesson type, recognised by the type.
 */
final readonly class FinancialCopier implements ProgramCopier
{
    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        if (!$run->has(SchoolYearImportBlock::Financial)) {
            return;
        }

        $target = $pair->target;
        $write = $run->writable($pair);

        $items = [];
        foreach ($run->data->rowsOf(ProgramFinancialItem::class, $target) as $item) {
            $items[self::itemKey($item)] = true;
        }
        foreach ($run->data->rowsOf(ProgramFinancialItem::class, $pair->source) as $item) {
            if (isset($items[self::itemKey($item)])) {
                continue;
            }
            $items[self::itemKey($item)] = true;
            $run->report->add($pair, 'financialItems');
            if (null === $write) {
                continue;
            }
            $copy = new ProgramFinancialItem($item->getTitle(), $item->getSource(), $item->getType(), $write);
            $copy->setDescription($item->getDescription());
            $copy->setQuantity($item->getQuantity());
            $copy->setValue((string) $item->getValue());
            $copy->setLessonType($item->getLessonType());
            $run->persist($copy);
        }

        $costs = [];
        foreach ($run->data->rowsOf(ProgramLessonTypeCost::class, $target) as $cost) {
            $costs[(int) $cost->getLessonType()?->getId()] = true;
        }
        foreach ($run->data->rowsOf(ProgramLessonTypeCost::class, $pair->source) as $cost) {
            $lessonType = $cost->getLessonType();
            if (null === $lessonType || isset($costs[(int) $lessonType->getId()])) {
                continue;
            }
            $costs[(int) $lessonType->getId()] = true;
            $run->report->add($pair, 'lessonTypeCosts');
            if (null !== $write) {
                $run->persist(new ProgramLessonTypeCost($write, $lessonType, (string) $cost->getCost()));
            }
        }
    }

    private static function itemKey(ProgramFinancialItem $item): string
    {
        return SchoolYearImportRules::key($item->getTitle()).'|'.$item->getSource()->value.'|'.$item->getType()->value;
    }
}
