<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

use App\Enum\SchoolYearImportBlock;

/**
 * What an import was asked to do: the source year, the boxes ticked, the formations ticked.
 *
 * Read from the screen's GET form (screen 2 → screen 3) and from the hidden fields of the POST
 * (screen 3 → import), both through fromInput() on the raw parameter array - never trusted as typed.
 * The dependencies of SchoolYearImportBlock are applied on the way in: a box whose dependency is
 * unticked is unticked, whatever the request says.
 *
 * @phpstan-type ImportChoices array{source: int, blocks: list<string>, programs: list<int>}
 */
final readonly class SchoolYearImportChoices
{
    /** @var list<SchoolYearImportBlock> */
    public array $blocks;

    /** @var list<int> */
    public array $programIds;

    /**
     * @param list<SchoolYearImportBlock> $blocks
     * @param list<int>                   $programIds
     */
    public function __construct(public int $sourceId, array $blocks, array $programIds)
    {
        $this->blocks = self::applyDependencies($blocks);
        $this->programIds = array_values(array_unique($programIds));
    }

    /**
     * The screen's first state: every default box, the formations the caller proposes (every active
     * one but the test formations, R9).
     *
     * @param list<int> $programIds
     */
    public static function defaults(int $sourceId, array $programIds): self
    {
        return new self($sourceId, array_values(array_filter(SchoolYearImportBlock::cases(), static fn (SchoolYearImportBlock $block): bool => $block->isDefault())), $programIds);
    }

    /**
     * `blocks[]` and `programs[]` off a submitted form; unknown blocks and ids that are not
     * positive integers are dropped.
     *
     * @param array<array-key, mixed> $input
     */
    public static function fromInput(int $sourceId, array $input): self
    {
        $blocks = [];
        foreach (self::listOf($input, 'blocks') as $value) {
            $block = \is_string($value) ? SchoolYearImportBlock::tryFrom($value) : null;
            if (null !== $block) {
                $blocks[] = $block;
            }
        }

        $programIds = [];
        foreach (self::listOf($input, 'programs') as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $programIds[] = (int) $value;
            }
        }

        return new self($sourceId, $blocks, $programIds);
    }

    /** @param ImportChoices $choices */
    public static function fromArray(array $choices): self
    {
        return self::fromInput($choices['source'], $choices);
    }

    public function has(SchoolYearImportBlock $block): bool
    {
        return \in_array($block, $this->blocks, true);
    }

    public function hasProgram(int $id): bool
    {
        return \in_array($id, $this->programIds, true);
    }

    /** @return ImportChoices */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceId,
            'blocks' => array_map(static fn (SchoolYearImportBlock $block): string => $block->value, $this->blocks),
            'programs' => $this->programIds,
        ];
    }

    /**
     * @param list<SchoolYearImportBlock> $blocks
     *
     * @return list<SchoolYearImportBlock> in the enum's order, without the boxes whose dependency is missing
     */
    private static function applyDependencies(array $blocks): array
    {
        $kept = array_values(array_filter(SchoolYearImportBlock::cases(), static fn (SchoolYearImportBlock $block): bool => \in_array($block, $blocks, true)));

        do {
            $before = \count($kept);
            $kept = array_values(array_filter($kept, static function (SchoolYearImportBlock $block) use ($kept): bool {
                foreach ($block->needs() as $needed) {
                    if (!\in_array($needed, $kept, true)) {
                        return false;
                    }
                }
                if ([] === $block->needsAny()) {
                    return true;
                }
                foreach ($block->needsAny() as $needed) {
                    if (\in_array($needed, $kept, true)) {
                        return true;
                    }
                }

                return false;
            }));
        } while (\count($kept) !== $before);

        return $kept;
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @return list<mixed>
     */
    private static function listOf(array $input, string $key): array
    {
        $value = $input[$key] ?? [];

        return \is_array($value) ? array_values($value) : [];
    }
}
