<?php

namespace App\Service;

use App\Entity\LessonType;
use App\Entity\Program;
use App\Entity\ProgramFinancialItem;
use App\Enum\FinancialItemSource;
use App\Enum\FinancialItemType;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramLessonTypeCostRepository;

/**
 * Resolves the effective hourly cost of a program's session types (program override falling
 * back to the LessonType's structure-wide default) and computes each financial item's quantity
 * and running totals for a program - shared between the Financial settings tab (which shows the
 * live quantity/cost preview) and the read-only Reporting page (which shows the same totals).
 */
class ProgramFinancialCalculator
{
    public function __construct(
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly ProgramLessonTypeCostRepository $programLessonTypeCostRepository,
    ) {
    }

    /** @return array<int, float> LessonType id => total hours scheduled for this program */
    public function getHoursPerLessonType(Program $program): array
    {
        $hoursByLessonTypeId = [];

        foreach ($this->lessonSessionRepository->findForProgram($program) as $session) {
            $lessonType = $session->getLessonType();

            if (null === $lessonType) {
                continue;
            }

            // LessonSession::$length is manually entered, not derived from startHour/endHour
            // (those only position the session on the timetable).
            $hoursByLessonTypeId[$lessonType->getId()] = ($hoursByLessonTypeId[$lessonType->getId()] ?? 0.0) + (float) $session->getLength();
        }

        return $hoursByLessonTypeId;
    }

    public function getEffectiveCost(Program $program, LessonType $lessonType): ?string
    {
        $override = $this->programLessonTypeCostRepository->findOneForProgramAndLessonType($program, $lessonType);

        return $override?->getCost() ?? $lessonType->getDefaultCost();
    }

    /**
     * @param iterable<LessonType> $lessonTypes
     *
     * @return array<int, string|null> LessonType id => effective cost
     */
    public function getEffectiveCostMap(Program $program, iterable $lessonTypes): array
    {
        $overrides = $this->programLessonTypeCostRepository->findCostMapForProgram($program);
        $map = [];

        foreach ($lessonTypes as $lessonType) {
            $map[$lessonType->getId()] = $overrides[$lessonType->getId()] ?? $lessonType->getDefaultCost();
        }

        return $map;
    }

    /**
     * @return array{items: list<array{item: ProgramFinancialItem, quantity: float, lineTotal: float}>, totalGain: float, totalCost: float, totalGlobal: float}
     */
    public function computeTotals(Program $program): array
    {
        $hoursPerLessonType = $this->getHoursPerLessonType($program);
        $studentCount = $program->getStudents()->count();

        $items = [];
        $totalGain = 0.0;
        $totalCost = 0.0;

        foreach ($program->getFinancialItems() as $financialItem) {
            $quantity = match ($financialItem->getSource()) {
                FinancialItemSource::Lesson => $hoursPerLessonType[$financialItem->getLessonType()?->getId()] ?? 0.0,
                FinancialItemSource::Student => (float) $studentCount,
                FinancialItemSource::Manual => (float) $financialItem->getQuantity(),
            };

            $lineTotal = $quantity * (float) $financialItem->getValue();

            if (FinancialItemType::Gain === $financialItem->getType()) {
                $totalGain += $lineTotal;
            } else {
                $totalCost += $lineTotal;
            }

            $items[] = [
                'item' => $financialItem,
                'quantity' => $quantity,
                'lineTotal' => $lineTotal,
            ];
        }

        return [
            'items' => $items,
            'totalGain' => $totalGain,
            'totalCost' => $totalCost,
            'totalGlobal' => $totalGain - $totalCost,
        ];
    }
}
