<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AccessConditionMoment;
use App\Enum\AccessConditionType;

/**
 * One condition of an access condition, read off the access_condition JSON column and typed here
 * once - the boundary this application types rather than casting further in, exactly as
 * JsonRequestPayload does for a request body.
 *
 * A leaf holds a reference and its parameters, never a result: what the student has actually done
 * is StudentAccessFacts's business, and keeping the two apart is what makes the decision a pure
 * function.
 *
 * @phpstan-type LeafArray array{type?: mixed, instance?: mixed, assignment?: mixed, recording?: mixed, video?: mixed, resource?: mixed, seance?: mixed, group?: mixed, min_percent?: mixed, max_percent?: mixed, at?: mixed, moment?: mixed}
 */
final readonly class AccessConditionLeaf
{
    public function __construct(
        public AccessConditionType $type,
        public ?int $targetId = null,
        public ?int $minPercent = null,
        public ?int $maxPercent = null,
        public ?\DateTimeImmutable $at = null,
        public AccessConditionMoment $moment = AccessConditionMoment::End,
    ) {
    }

    /**
     * Rebuilds a leaf from a stored row, or null when the row cannot describe one. Null rather than
     * an exception: a condition written by an older format, or pointing at an object since deleted,
     * must not take a screen down - it simply stops being a leaf.
     *
     * @param LeafArray|array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $type = \is_string($raw['type'] ?? null) ? AccessConditionType::tryFrom($raw['type']) : null;

        if (null === $type) {
            return null;
        }

        $targetKey = $type->targetKey();
        $targetId = null === $targetKey ? null : self::intOrNull($raw[$targetKey] ?? null);

        if (null !== $targetKey && null === $targetId) {
            return null;
        }

        $at = null;
        if (AccessConditionType::DateFrom === $type) {
            $raw['at'] ??= null;
            if (!\is_string($raw['at'])) {
                return null;
            }

            try {
                $at = new \DateTimeImmutable($raw['at']);
            } catch (\Exception) {
                return null;
            }
        }

        $moment = \is_string($raw['moment'] ?? null) ? AccessConditionMoment::tryFrom($raw['moment']) : null;

        return new self(
            $type,
            $targetId,
            self::percentOrNull($raw['min_percent'] ?? null),
            $type->hasMaxPercent() ? self::percentOrNull($raw['max_percent'] ?? null) : null,
            $at,
            $moment ?? AccessConditionMoment::End,
        );
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        $row = ['type' => $this->type->value];

        $targetKey = $this->type->targetKey();
        if (null !== $targetKey && null !== $this->targetId) {
            $row[$targetKey] = $this->targetId;
        }

        if (null !== $this->minPercent) {
            $row['min_percent'] = $this->minPercent;
        }

        if (null !== $this->maxPercent) {
            $row['max_percent'] = $this->maxPercent;
        }

        if (null !== $this->at) {
            $row['at'] = $this->at->format(\DateTimeInterface::ATOM);
        }

        if (AccessConditionType::SeancePassed === $this->type) {
            $row['moment'] = $this->moment->value;
        }

        return $row;
    }

    /** The percentage a listening or a watching has to reach; asking for none means the whole thing. */
    public function requiredPercent(): int
    {
        return $this->minPercent ?? 100;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    private static function percentOrNull(mixed $value): ?int
    {
        $percent = self::intOrNull($value);

        return null === $percent ? null : max(0, min(100, $percent));
    }
}
