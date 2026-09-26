<?php

declare(strict_types=1);

namespace App\Service\Equipment;

/**
 * The annual report of Gestion > Matériel, built from the incidents of one school year.
 *
 * - **Net of what was answered.** Each incident arrives with how much of it a « Retrouvé » or a
 *   « Réparé » line has since answered; only the rest is a loss. The answer counts against the
 *   incident's own year, whenever it happened: a mouse lost in June and found in September was not
 *   lost in June.
 * - **Value at the type's unit price.** A type with no price still counts its pieces but adds nothing
 *   to the value, and is flagged so the screen can say the figure is partial rather than guess.
 * - Kind (Disparu / Hors d'usage) is crossed with cause: « 14 disparus, dont 9 vols ».
 *
 * Pure: the rows come from App\Repository\EquipmentMovementRepository::findIncidentRows(), so the
 * arithmetic is tested without a database.
 *
 * @phpstan-type IncidentRow array{kind: string, quantity: int, resolved: int, cause: string|null, occurredAt: \DateTimeImmutable, typeId: int, typeName: string, unitPrice: string|null, categoryName: string|null, roomName: string|null}
 * @phpstan-type Line array{label: string|null, missing: int, outOfOrder: int, net: int, value: float, unpriced: bool}
 * @phpstan-type Report array{
 *     totals: array{missing: int, outOfOrder: int, net: int, answered: int, value: float, unpriced: bool},
 *     byType: list<Line>,
 *     byCategory: list<Line>,
 *     byRoom: list<Line>,
 *     byCause: array<string, array<string, int>>,
 *     byMonth: list<array{month: string, missing: int, outOfOrder: int, net: int}>
 * }
 */
final class EquipmentLossReport
{
    /**
     * @param list<IncidentRow>  $rows
     * @param \DateTimeImmutable $yearStart the first day of the school year - its month opens the twelve
     *
     * @return Report
     */
    public function build(array $rows, \DateTimeImmutable $yearStart): array
    {
        $totals = ['missing' => 0, 'outOfOrder' => 0, 'net' => 0, 'answered' => 0, 'value' => 0.0, 'unpriced' => false];
        $byType = [];
        $byCategory = [];
        $byRoom = [];
        $byCause = [];

        $firstMonth = $yearStart->modify('first day of this month')->setTime(0, 0);
        $byMonth = [];
        for ($i = 0; $i < 12; ++$i) {
            $byMonth[$firstMonth->modify(\sprintf('+%d months', $i))->format('Y-m')] = ['missing' => 0, 'outOfOrder' => 0, 'net' => 0];
        }

        foreach ($rows as $row) {
            $answered = min($row['resolved'], $row['quantity']);
            $totals['answered'] += $answered;
            $net = $row['quantity'] - $answered;

            if ($net <= 0) {
                continue;
            }

            $isMissing = 'missing' === $row['kind'];
            $price = null !== $row['unitPrice'] ? (float) $row['unitPrice'] : null;
            $value = null !== $price ? $net * $price : 0.0;

            $totals[$isMissing ? 'missing' : 'outOfOrder'] += $net;
            $totals['net'] += $net;
            $totals['value'] += $value;
            $totals['unpriced'] = $totals['unpriced'] || null === $price;

            self::add($byType, (string) $row['typeId'], $row['typeName'], $isMissing, $net, $value, null === $price);
            self::add($byCategory, $row['categoryName'] ?? '', $row['categoryName'], $isMissing, $net, $value, null === $price);
            self::add($byRoom, $row['roomName'] ?? '', $row['roomName'], $isMissing, $net, $value, null === $price);

            $cause = $row['cause'] ?? 'unknown';
            $byCause[$row['kind']][$cause] = ($byCause[$row['kind']][$cause] ?? 0) + $net;

            $month = $row['occurredAt']->format('Y-m');
            if (isset($byMonth[$month])) {
                $byMonth[$month][$isMissing ? 'missing' : 'outOfOrder'] += $net;
                $byMonth[$month]['net'] += $net;
            }
        }

        foreach ($byCause as $kind => $causes) {
            arsort($causes);
            $byCause[$kind] = $causes;
        }

        $months = [];
        foreach ($byMonth as $month => $figures) {
            $months[] = ['month' => $month] + $figures;
        }

        return [
            'totals' => $totals,
            'byType' => self::sorted($byType),
            'byCategory' => self::sorted($byCategory),
            'byRoom' => self::sorted($byRoom),
            'byCause' => $byCause,
            'byMonth' => $months,
        ];
    }

    /**
     * @param array<string, Line> $lines
     */
    private static function add(array &$lines, string $key, ?string $label, bool $isMissing, int $net, float $value, bool $unpriced): void
    {
        $lines[$key] ??= ['label' => $label, 'missing' => 0, 'outOfOrder' => 0, 'net' => 0, 'value' => 0.0, 'unpriced' => false];
        $lines[$key][$isMissing ? 'missing' : 'outOfOrder'] += $net;
        $lines[$key]['net'] += $net;
        $lines[$key]['value'] += $value;
        $lines[$key]['unpriced'] = $lines[$key]['unpriced'] || $unpriced;
    }

    /**
     * Heaviest first; the line without a label (no room, no category) last whatever it weighs.
     *
     * @param array<string, Line> $lines
     *
     * @return list<Line>
     */
    private static function sorted(array $lines): array
    {
        $lines = array_values($lines);
        usort($lines, static fn (array $a, array $b): int => [null === $a['label'], -$a['net'], (string) $a['label']] <=> [null === $b['label'], -$b['net'], (string) $b['label']]);

        return $lines;
    }
}
