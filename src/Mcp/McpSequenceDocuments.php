<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Service\SequenceImportException;
use App\Service\SequenceJsonImporter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A « moncampus-sequence/1 » document as the connector reads it - the assistant's own reader
 * (App\Service\SequenceJsonImporter), whose refusals come back translated, and whose warnings and
 * transposition report are handed to Claude so it can tell the teacher what it deduced and what it
 * could not place.
 *
 * @phpstan-import-type SequenceImportPayload from SequenceJsonImporter
 */
final readonly class McpSequenceDocuments
{
    public function __construct(
        private SequenceJsonImporter $importer,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return SequenceImportPayload
     *
     * @throws McpToolException when the document is unusable as a whole
     */
    public function read(string $json): array
    {
        try {
            return $this->importer->parse($json, 'claude.json');
        } catch (SequenceImportException $exception) {
            throw new McpToolException($this->translator->trans($exception->getMessageKey(), $exception->getParameters()));
        }
    }

    /**
     * What a creation tool says back besides the link: the counts, the warnings, and the report the
     * format obliges the document to carry.
     *
     * @param SequenceImportPayload $payload
     *
     * @return array<string, mixed>
     */
    public function summary(array $payload): array
    {
        return [
            'seances' => $payload['counts']['seances'],
            'phases' => $payload['counts']['phases'],
            'warnings' => $payload['warnings'],
            'report' => [
                'deduced' => $payload['report']['deduit'],
                'notPlaced' => array_map(static fn (array $block): string => $block['titre'], $payload['report']['nonPlace']),
                'empty' => $payload['report']['vide'],
            ],
        ];
    }
}
