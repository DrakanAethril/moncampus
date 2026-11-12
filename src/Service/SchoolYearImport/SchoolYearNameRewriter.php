<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

/**
 * R8 of the school-year import: a name (a formation's, its short name, a calendar's) is taken over as
 * it is, except the source year written in it - « 2026-2027 », « 2026/2027 », « 2026-27 », spaces
 * around the separator allowed - which becomes the target year in the same form. Digits glued to
 * the year make it something else (a code), and are left alone.
 *
 * Pure, on the four years only.
 */
final readonly class SchoolYearNameRewriter
{
    private function __construct(
        private int $sourceStart,
        private int $sourceEnd,
        private int $targetStart,
        private int $targetEnd,
    ) {
    }

    public static function between(int $sourceStart, int $sourceEnd, int $targetStart, int $targetEnd): self
    {
        return new self($sourceStart, $sourceEnd, $targetStart, $targetEnd);
    }

    public static function forYears(\DateTimeImmutable $sourceStart, \DateTimeImmutable $sourceEnd, \DateTimeImmutable $targetStart, \DateTimeImmutable $targetEnd): self
    {
        return new self((int) $sourceStart->format('Y'), (int) $sourceEnd->format('Y'), (int) $targetStart->format('Y'), (int) $targetEnd->format('Y'));
    }

    public function sourceLabel(): string
    {
        return $this->sourceStart.'-'.$this->sourceEnd;
    }

    public function targetLabel(): string
    {
        return $this->targetStart.'-'.$this->targetEnd;
    }

    public function rewrite(string $name): string
    {
        $long = \sprintf('/(?<!\d)%d(\s*[-\/–]\s*)%d(?!\d)/u', $this->sourceStart, $this->sourceEnd);
        $name = (string) preg_replace_callback($long, fn (array $m): string => $this->targetStart.$m[1].$this->targetEnd, $name);

        $short = \sprintf('/(?<!\d)%d(\s*[-\/–]\s*)%02d(?!\d)/u', $this->sourceStart, $this->sourceEnd % 100);

        return (string) preg_replace_callback($short, fn (array $m): string => $this->targetStart.$m[1].\sprintf('%02d', $this->targetEnd % 100), $name);
    }

    /**
     * The rewritten name, or the name followed by the target year when it carried no year at all -
     * what an evaluation calendar, which belongs to no year, is named in its new one.
     */
    public function rewriteOrSuffix(string $name): string
    {
        $rewritten = $this->rewrite($name);
        if ($rewritten !== $name || str_contains($name, $this->targetLabel())) {
            return $rewritten;
        }

        return rtrim($name).' '.$this->targetLabel();
    }
}
