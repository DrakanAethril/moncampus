<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The images deposited on the import screen before the questions are generated, each under a short
 * reference - img1, img2… - which is the only thing about them the prompt carries.
 *
 * The reference is the point of this object. A model has to reproduce it *exactly* in the JSON it
 * gives back, which rules out the two obvious alternatives: a public URL (which would make the
 * application the party that sends a pupil's material to a third party, and which a model truncates
 * or rewrites) and a signed URL (long, perishable, and a worse identifier still). Reference and
 * upload are also two separate jobs on purpose - the teacher attaches the same files to their
 * conversation, which is what actually makes the model *see* them; the key only says *which* one.
 * See design/comparaison/conception_import_quiz_ia.md, section 5 ter.
 *
 * Lives in the session between the deposit and the confirmation, like the parsed document itself
 * (App\Controller\QuizImportController's class docblock) - a batch is a handful of rows.
 *
 * @phpstan-type QuizImportImage array{ref: string, name: string, key: string}
 */
final class QuizImportImageBatch
{
    private const string REFERENCE_PREFIX = 'img';

    /** @var list<QuizImportImage> */
    private array $images = [];

    // The highest number ever handed out, not the count: a removed reference must never come back
    // (see below).
    private int $lastNumber = 0;

    private function __construct()
    {
    }

    /** Reads back whatever the session holds, treating anything unexpected as an empty batch. */
    public static function fromSession(mixed $raw): self
    {
        $batch = new self();
        if (!\is_array($raw)) {
            return $batch;
        }

        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $ref = self::stringOf($entry['ref'] ?? null);
            $key = self::stringOf($entry['key'] ?? null);
            if (null === $ref || null === $key) {
                continue;
            }

            $batch->images[] = ['ref' => $ref, 'name' => self::stringOf($entry['name'] ?? null) ?? $ref, 'key' => $key];
            $batch->lastNumber = max($batch->lastNumber, (int) substr($ref, \strlen(self::REFERENCE_PREFIX)));
        }

        return $batch;
    }

    /** @return list<QuizImportImage> */
    public function toSession(): array
    {
        return $this->images;
    }

    /** @return list<QuizImportImage> */
    public function all(): array
    {
        return $this->images;
    }

    public function isEmpty(): bool
    {
        return [] === $this->images;
    }

    /** The freshly allocated reference, e.g. "img3". */
    public function add(string $originalName, string $storageKey): string
    {
        $ref = self::REFERENCE_PREFIX.++$this->lastNumber;
        $this->images[] = ['ref' => $ref, 'name' => $originalName, 'key' => $storageKey];

        return $ref;
    }

    /**
     * Drops a reference and returns the storage key it held, for the caller to delete.
     *
     * The number is deliberately *not* freed: the prompt has already been copied into a conversation
     * this application cannot see, and handing "img1" to another photo would answer a question about
     * the picture the teacher just removed - plausibly, and wrongly.
     */
    public function remove(string $ref): ?string
    {
        foreach ($this->images as $index => $image) {
            if ($image['ref'] === $ref) {
                unset($this->images[$index]);
                $this->images = array_values($this->images);

                return $image['key'];
            }
        }

        return null;
    }

    public function keyFor(string $ref): ?string
    {
        foreach ($this->images as $image) {
            if ($image['ref'] === $ref) {
                return $image['key'];
            }
        }

        return null;
    }

    public function nameFor(string $ref): ?string
    {
        foreach ($this->images as $image) {
            if ($image['ref'] === $ref) {
                return $image['name'];
            }
        }

        return null;
    }

    /** @return list<string> every stored object of the batch, for the clean-up at confirmation */
    public function storageKeys(): array
    {
        return array_map(static fn (array $image): string => $image['key'], $this->images);
    }

    private static function stringOf(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }
}
