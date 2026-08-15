<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AccessConditionComparison;
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
 * @phpstan-type LeafArray array{type?: mixed, instance?: mixed, evaluation?: mixed, assignment?: mixed, recording?: mixed, video?: mixed, resource?: mixed, seance?: mixed, group?: mixed, min_percent?: mixed, max_percent?: mixed, comparison?: mixed, value?: mixed, at?: mixed, moment?: mixed}
 */
final readonly class AccessConditionLeaf
{
    /**
     * @param float|null $value the threshold a grade is compared against, in the evaluation's own
     *                          barème - never a percentage, so it reads as the teacher typed it
     */
    public function __construct(
        public AccessConditionType $type,
        public ?int $targetId = null,
        public ?int $minPercent = null,
        public ?int $maxPercent = null,
        public ?\DateTimeImmutable $at = null,
        public AccessConditionMoment $moment = AccessConditionMoment::End,
        public ?AccessConditionComparison $comparison = null,
        public ?float $value = null,
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

        $comparison = \is_string($raw['comparison'] ?? null) ? AccessConditionComparison::tryFrom($raw['comparison']) : null;
        $value = self::floatOrNull($raw['value'] ?? null);

        // A grade condition with no threshold compares nothing: it would read as "une note à cette
        // évaluation" and open for anybody who has one. It stops being a leaf, exactly as a row
        // pointing at no object does, and the save refuses the whole form rather than storing it.
        if ($type->hasGradeThreshold() && (null === $comparison || null === $value)) {
            return null;
        }

        return new self(
            $type,
            $targetId,
            self::percentOrNull($raw['min_percent'] ?? null),
            $type->hasMaxPercent() ? self::percentOrNull($raw['max_percent'] ?? null) : null,
            $at,
            $moment ?? AccessConditionMoment::End,
            $type->hasGradeThreshold() ? $comparison : null,
            $type->hasGradeThreshold() ? $value : null,
        );
    }

    /** @return array<string, string|int|float> */
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

        if (null !== $this->comparison && null !== $this->value) {
            $row['comparison'] = $this->comparison->value;
            $row['value'] = $this->value;
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

    /**
     * A threshold arrives as a number from the form and as a number from the JSON column, but a
     * teacher writing "12,5" in a French keyboard layout is a string with a comma - read here once
     * rather than refused as unparsable.
     */
    private static function floatOrNull(mixed $value): ?float
    {
        if (\is_float($value) || \is_int($value)) {
            return (float) $value;
        }

        if (!\is_string($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim($value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function percentOrNull(mixed $value): ?int
    {
        $percent = self::intOrNull($value);

        return null === $percent ? null : max(0, min(100, $percent));
    }
}
