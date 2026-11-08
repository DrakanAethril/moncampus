<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\FileLibraryNode;
use App\Service\MixedJsonImporter;
use App\Service\QuizCsvImportException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A « moncampus-quiz/1 » document as the connector reads it: the import screen's own reader
 * (App\Service\MixedJsonImporter), and three rules of its own.
 *
 * - **All or nothing.** The screen lets a teacher look at the rejected questions and create the
 *   quiz without them; the connector has nobody looking, so one rejected question refuses the whole
 *   document and names it - Claude corrects it and sends the document again.
 * - **No image reference.** A `mediaRef` points into the import session of a browser, which a tool
 *   call does not have; an `imageKey` is a raw key of the bucket, and accepting one would let a
 *   document copy any object it can name into the teacher's quiz.
 * - **A picture is a library file.** A question names its picture by `imageFileId`, a file of the
 *   teacher's own library (App\Mcp\McpQuizImages), checked here with the rest of the document and
 *   handed back by position under `images` - the readers ignore the key; the tools link the file
 *   once the questions exist.
 *
 * @phpstan-type QuizDocument array{format: string, name: string, subject: ?string, description: ?string, fileName: string, questions: list<array<string, mixed>>, errors: list<string>, images: array<int, FileLibraryNode>}
 */
final readonly class McpQuizDocuments
{
    private const array IMAGE_KEYS = ['mediaRef', 'imageKey'];

    public function __construct(
        private MixedJsonImporter $importer,
        private TranslatorInterface $translator,
        private McpQuizImages $images,
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

        $document = json_decode($json, true);
        $imageRefs = $this->imageReferences($document ?? []);
        if ([] !== $imageRefs) {
            $payload['errors'][] = \sprintf(
                'Références d\'image refusées (%s) : une image se désigne par "imageFileId", l\'identifiant d\'une image de la bibliothèque de fichiers (déposée avec file_upload ou file_upload_url).',
                implode(', ', array_unique($imageRefs)),
            );
        }

        $questions = \is_array($document) && \is_array($document['questions'] ?? null) ? array_values($document['questions']) : [];
        $resolved = $this->images->resolve($questions);
        array_push($payload['errors'], ...$resolved['errors']);
        // The pictures go by position: one question read for each question sent, which a refused
        // question would break - but a refused question refuses the document anyway.
        if ([] !== $resolved['images'] && \count($payload['questions']) !== \count($questions)) {
            $payload['errors'][] = 'Les images n\'ont pas pu être rapportées à leurs questions : corrigez d\'abord les questions refusées.';
        }

        return $payload + ['images' => $resolved['images']];
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
