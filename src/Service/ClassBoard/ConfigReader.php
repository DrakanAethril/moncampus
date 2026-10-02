<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

/**
 * The strict reading of one widget's `config` object, for ClassBoardLayout.
 *
 * Stricter than App\Service\JsonRequestPayload on purpose: a payload answers its default for a key
 * of the wrong type, which suits an action that may do nothing; a layout is a document refused
 * whole at its first malformed value (§3), so a wrong type is an error here, and only an *absent*
 * key takes the default. Numbers are clamped rather than refused - out of range is a slider pushed
 * too far, not a forged document.
 */
final class ConfigReader
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $widgetId,
    ) {
    }

    public function int(string $key, int $min, int $max, int $default): int
    {
        if (!\array_key_exists($key, $this->data)) {
            return $default;
        }
        $value = $this->data[$key];
        if (!\is_int($value)) {
            throw $this->invalid($key);
        }

        return max($min, min($max, $value));
    }

    public function bool(string $key, bool $default): bool
    {
        if (!\array_key_exists($key, $this->data)) {
            return $default;
        }
        $value = $this->data[$key];
        if (!\is_bool($value)) {
            throw $this->invalid($key);
        }

        return $value;
    }

    /**
     * A string refused beyond $maxLength characters rather than cut: a label truncated in silence
     * would project something the teacher never wrote.
     */
    public function string(string $key, int $maxLength, string $default): string
    {
        if (!\array_key_exists($key, $this->data) || null === $this->data[$key]) {
            return $default;
        }
        $value = $this->data[$key];
        if (!\is_string($value) || mb_strlen($value) > $maxLength) {
            throw $this->invalid($key);
        }

        return $value;
    }

    /**
     * A reference to another object: a positive id, or null for « none chosen ».
     */
    public function id(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_int($value) || $value < 1) {
            throw $this->invalid($key);
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $key, array $allowed, string $default): string
    {
        if (!\array_key_exists($key, $this->data)) {
            return $default;
        }
        $value = $this->data[$key];
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            throw $this->invalid($key);
        }

        return $value;
    }

    /**
     * Several values of a closed list, kept in the list's own order and without repeats.
     *
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public function choices(string $key, array $allowed): array
    {
        $value = $this->data[$key] ?? [];
        if (!\is_array($value)) {
            throw $this->invalid($key);
        }
        foreach ($value as $entry) {
            if (!\is_string($entry) || !\in_array($entry, $allowed, true)) {
                throw $this->invalid($key);
            }
        }

        return array_values(array_filter($allowed, static fn (string $candidate): bool => \in_array($candidate, $value, true)));
    }

    /**
     * @param int|null $max none when the list is bounded by the size of the document alone
     *
     * @return list<self>
     */
    public function objects(string $key, ?int $max = null): array
    {
        $value = $this->data[$key] ?? [];
        if (!\is_array($value) || !array_is_list($value) || (null !== $max && \count($value) > $max)) {
            throw $this->invalid($key);
        }

        $objects = [];
        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                throw $this->invalid($key);
            }
            $objects[] = new self($entry, $this->widgetId);
        }

        return $objects;
    }

    private function invalid(string $key): InvalidClassBoardLayoutException
    {
        return new InvalidClassBoardLayoutException(\sprintf('Invalid "%s" in the config of "%s".', $key, $this->widgetId));
    }
}
