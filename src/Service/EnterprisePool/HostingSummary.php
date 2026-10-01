<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool;

use App\Enum\HostingKind;

/**
 * What a result line says of a company of the vivier: per kind, per filière, the school years.
 * « Stage · SIO SLAM · 2024-25, 2025-26 » and « Alternance · SIO SISR · 2026-27 » are two lines,
 * never one count (D2).
 */
final class HostingSummary
{
    /** @var array<string, array<string, list<int>>> kind => domain => year starts */
    private array $lines = [];

    public function add(HostingRecord $record): void
    {
        $years = $this->lines[$record->kind->value][$record->domain()] ?? [];
        if (!\in_array($record->yearStart, $years, true)) {
            $years[] = $record->yearStart;
            rsort($years);
        }
        $this->lines[$record->kind->value][$record->domain()] = $years;
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }

    public function has(HostingKind $kind): bool
    {
        return isset($this->lines[$kind->value]);
    }

    /**
     * @return list<array{kind: HostingKind, domain: string, years: list<string>}>
     */
    public function lines(): array
    {
        $lines = [];
        foreach (HostingKind::cases() as $kind) {
            foreach ($this->lines[$kind->value] ?? [] as $domain => $years) {
                $lines[] = [
                    'kind' => $kind,
                    'domain' => (string) $domain,
                    'years' => array_map(static fn (int $year): string => $year.'-'.substr((string) ($year + 1), 2), $years),
                ];
            }
        }

        return $lines;
    }
}
