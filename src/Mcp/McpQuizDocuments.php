<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Service\MixedJsonImporter;
use App\Service\QuizCsvImportException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A « moncampus-quiz/1 » document as the connector reads it: the import screen's own reader
 * (App\Service\MixedJsonImporter), and two rules of its own.
 *
 * - **All or nothing.** The screen lets a teacher look at the rejected questions and create the
 *   quiz without them; the connector has nobody looking, so one rejected question refuses the whole
 *   document and names it - Claude corrects it and sends the document again.
 * - **No image reference.** A `mediaRef` points into the import session of a browser, which a tool
 *   call does not have; an `imageKey` is a raw key of the bucket, and accepting one would let a
 *   document copy any object it can name into the teacher's quiz.
 *
 * @phpstan-type QuizDocument array{format: string, name: string, subject: ?string, description: ?string, fileName: string, questions: list<array<string, mixed>>, errors: list<string>}
 */
final readonly class McpQuizDocuments
{
    private const array IMAGE_KEYS = ['mediaRef', 'imageKey'];

    public function __construct(
        private MixedJsonImporter $importer,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return QuizDocument
     *
     * @throws McpToolException when the document is unusable as a whole
     */
    public function read(string $json): array
    {
        try {
            $payload = $this->importer->parse($json, 'claude.json');
        } catch (QuizCsvImportException $exception) {
            throw new McpToolException($this->translator->trans($exception->getMessageKey(), $exception->getParameters()));
        }

        $imageRefs = $this->imageReferences(json_decode($json, true) ?? []);
        if ([] !== $imageRefs) {
            $payload['errors'][] = \sprintf(
                'Références d\'image refusées (%s) : ce connecteur ne transporte pas d\'images. Retirez-les ; l\'enseignant ajoutera les images dans MonCampus.',
                implode(', ', array_unique($imageRefs)),
            );
        }

        return $payload;
    }

    /**
     * @return QuizDocument
     *
     * @throws McpToolException when anything in the document was refused
     */
    public function readStrictly(string $json): array
    {
        $payload = $this->read($json);

        if ([] !== $payload['errors']) {
            throw new McpToolException('Le document a été refusé ; rien n\'a été créé. Corrigez ces points puis renvoyez-le en entier :', $payload['errors']);
        }

        return $payload;
    }

    /**
     * @return list<string> the image keys found, wherever they are nested
     */
    private function imageReferences(mixed $node): array
    {
        if (!\is_array($node)) {
            return [];
        }

        $found = [];
        foreach ($node as $key => $value) {
            if (\in_array($key, self::IMAGE_KEYS, true) && null !== $value && '' !== $value) {
                $found[] = (string) $key;
            }
            $found = [...$found, ...$this->imageReferences($value)];
        }

        return $found;
    }
}
