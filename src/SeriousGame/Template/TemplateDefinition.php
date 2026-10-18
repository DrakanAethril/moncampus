<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use App\SeriousGame\Answer\AnswerType;

/**
 * One version of a unit's template, as the engine reads it: the unit, the version number and the
 * JSON document. Independent of Doctrine so that the bench and the tests run on the files of the
 * repository as well as on the rows of the database.
 */
final readonly class TemplateDefinition
{
    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        public string $unit,
        public int $version,
        public array $document,
    ) {
    }

    public static function fromFile(string $path, int $version = 1): self
    {
        $document = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($document)) {
            throw new TemplateFormatException(basename($path), 'le gabarit est un objet JSON');
        }

        /** @var array<string, mixed> $document */
        $unit = \is_string($document['unit'] ?? null) ? $document['unit'] : basename($path, '.json');

        return new self($unit, $version, $document);
    }

    public function label(): string
    {
        return \is_string($this->document['label'] ?? null) ? $this->document['label'] : $this->unit;
    }

    /**
     * The kinds of answer this template asks - `short`, `choice` or both - read from the answer
     * type of each variant. A unit is served when one of them is a kind its game accepts
     * (design/validated/serious-games.md §1.14), and a question of a kind the game refuses is never
     * planned (QuestionEngine).
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        $bodies = \is_array($this->document['variants'] ?? null) ? $this->document['variants'] : [$this->document];
        $kinds = [];
        foreach ($bodies as $body) {
            $type = \is_array($body) && \is_array($body['answer'] ?? null) && \is_string($body['answer']['type'] ?? null) ? AnswerType::tryFrom($body['answer']['type']) : null;
            $kinds[] = ($type ?? AnswerType::Text)->kind();
        }

        return array_values(array_unique($kinds));
    }

    /** @param list<string> $kinds the kinds a game accepts */
    public function servableWith(array $kinds): bool
    {
        return [] !== array_intersect($this->kinds(), $kinds);
    }

    /** @return list<array{id: string, label: string, explanation: ?string}> */
    public function misconceptions(): array
    {
        $out = [];
        foreach (\is_array($this->document['misconceptions'] ?? null) ? $this->document['misconceptions'] : [] as $entry) {
            if (\is_array($entry) && \is_string($entry['id'] ?? null) && \is_string($entry['label'] ?? null)) {
                $out[] = ['id' => $entry['id'], 'label' => $entry['label'], 'explanation' => \is_string($entry['explanation'] ?? null) ? $entry['explanation'] : null];
            }
        }

        return $out;
    }

    public function misconceptionLabel(string $id): ?string
    {
        foreach ($this->misconceptions() as $entry) {
            if ($entry['id'] === $id) {
                return $entry['label'];
            }
        }

        return null;
    }
}
