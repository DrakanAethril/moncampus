<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AccessConditionMode;
use App\Enum\AccessConditionType;

/**
 * A whole access condition: a handful of leaves and how they combine. The stored shape is
 * {"all": [...]} or {"any": [...]}, flat by design - see AccessConditionMode.
 *
 * null is the absence of a condition and travels as null all the way through the evaluator: an
 * empty tree and no tree must read alike, otherwise saving an emptied form would silently lock an
 * object nobody meant to lock.
 */
final readonly class AccessConditionTree
{
    /** @param list<AccessConditionLeaf> $leaves */
    public function __construct(
        public AccessConditionMode $mode,
        public array $leaves,
    ) {
    }

    /**
     * @param array<array-key, mixed>|null $raw the access_condition column, as Doctrine hands it over
     */
    public static function fromArray(?array $raw): ?self
    {
        if (null === $raw) {
            return null;
        }

        foreach (AccessConditionMode::cases() as $mode) {
            $rows = $raw[$mode->value] ?? null;

            if (!\is_array($rows)) {
                continue;
            }

            $leaves = [];
            foreach ($rows as $row) {
                $leaf = \is_array($row) ? AccessConditionLeaf::fromArray($row) : null;

                if (null !== $leaf) {
                    $leaves[] = $leaf;
                }
            }

            return [] === $leaves ? null : new self($mode, $leaves);
        }

        return null;
    }

    /**
     * The teacher's form, read: the stored shape except that the picked object always arrives under
     * a single "target" key. The screen has one select for it, and teaching it to write "instance"
     * for a quiz and "recording" for a listening would only mean it eventually writes one of them
     * wrong.
     *
     * Unreadable rows fall out here exactly as they do on the way in, so a half-filled row left
     * behind on the screen saves nothing rather than saving a lock nobody can open.
     *
     * @param list<array<array-key, mixed>> $rows
     */
    public static function fromSubmitted(string $mode, array $rows): ?self
    {
        $named = [];
        foreach ($rows as $row) {
            $type = \is_string($row['type'] ?? null) ? AccessConditionType::tryFrom($row['type']) : null;
            $targetKey = $type?->targetKey();

            if (null !== $targetKey && \array_key_exists('target', $row)) {
                $row[$targetKey] = $row['target'];
            }

            unset($row['target']);
            $named[] = $row;
        }

        return self::fromArray([(AccessConditionMode::tryFrom($mode) ?? AccessConditionMode::All)->value => $named]);
    }

    /** @return array<string, list<array<string, string|int>>> */
    public function toArray(): array
    {
        return [$this->mode->value => array_map(
            static fn (AccessConditionLeaf $leaf): array => $leaf->toArray(),
            $this->leaves,
        )];
    }

    /**
     * The ids this condition points at, per type - what the facts loader turns into one query per
     * type instead of one per leaf.
     *
     * @return array<string, list<int>>
     */
    public function targetIdsByType(): array
    {
        $ids = [];
        foreach ($this->leaves as $leaf) {
            if (null !== $leaf->targetId) {
                $ids[$leaf->type->value][] = $leaf->targetId;
            }
        }

        return array_map(static fn (array $some): array => array_values(array_unique($some)), $ids);
    }
}
