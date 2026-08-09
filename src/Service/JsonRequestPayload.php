<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * The typed reading of a fetch/Stimulus JSON body.
 *
 * Every small action posted from a Stimulus controller sends its arguments as a JSON body rather
 * than as form fields, and each caller used to decode it inline and cast the values one by one.
 * That is where most of the untyped values in the controller layer came from: json_decode() answers
 * mixed, so `$payload['ids']` and `(string) $payload['raw']` are unchecked all the way down.
 *
 * Reading through this object narrows once, at the boundary, and gives every caller the same answer
 * for a missing or malformed key - which is what the controllers were each deciding for themselves.
 *
 * Deliberately a value object built by fromRequest() rather than an injected service: it holds one
 * request's decoded body, so there is nothing to share between calls.
 */
final class JsonRequestPayload
{
    /** @param array<array-key, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * A body that is absent, malformed or not a JSON object yields an empty payload rather than an
     * error: these endpoints already treat "nothing usable was sent" as "nothing to do", and the
     * CSRF check that precedes them is what actually guards the action.
     */
    /**
     * For values that never were JSON but carry the same problem - a session entry, say, whose keys
     * are only as trustworthy as whatever staged them.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromJson($request->getContent());
    }

    /**
     * For the actions whose JSON travels in a form field rather than as the request body - the
     * "envoyer par message" flows post a hidden input holding the JSON the screen built.
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        return new self(\is_array($decoded) ? $decoded : []);
    }

    /**
     * A bare JSON array of objects, as a list of payloads - the shape a screen posts when it sends
     * rows rather than a keyed object.
     *
     * @return list<self>
     */
    public static function listFromJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!\is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $entry) {
            if (\is_array($entry)) {
                $rows[] = new self($entry);
            }
        }

        return $rows;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;

        return \is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? null;

        return \is_bool($value) ? $value : $default;
    }

    /**
     * The ids an ordering or bulk action was given, in the order they were sent - which for a
     * reorder endpoint *is* the payload, so the order is preserved and never sorted.
     *
     * @return list<int>
     */
    public function ids(string $key = 'ids'): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_map(intval(...), array_filter($value, is_numeric(...))));
    }

    /**
     * A list of int lists - groups of student ids, pairs of them, and the like. Entries that are not
     * arrays are dropped; within an entry, non-numeric values are dropped rather than read as 0,
     * which would silently designate a real row.
     *
     * @return list<list<int>>
     */
    public function intLists(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $lists = [];
        foreach ($value as $entry) {
            if (\is_array($entry)) {
                $lists[] = array_values(array_map(intval(...), array_filter($entry, is_numeric(...))));
            }
        }

        return $lists;
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($value, \is_scalar(...))));
    }

    /**
     * A nested object, as another payload - so a caller reading `question.label` narrows exactly
     * like one reading a top-level key.
     */
    public function object(string $key): self
    {
        $value = $this->data[$key] ?? null;

        return new self(\is_array($value) ? $value : []);
    }

    /**
     * A list of nested objects. Entries that are not objects are dropped rather than yielding empty
     * payloads, so a caller can trust the count.
     *
     * @return list<self>
     */
    public function objects(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $entry) {
            if (\is_array($entry)) {
                $rows[] = new self($entry);
            }
        }

        return $rows;
    }

    /**
     * The decoded body as it came, for the few callers that still hand a raw row to something else.
     * Prefer the typed readers above - this is the escape hatch, not the door.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function isEmpty(): bool
    {
        return [] === $this->data;
    }
}
