<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\FileLibraryNode;
use App\Entity\QuizQuestion;
use App\Enum\QuestionType;
use App\Enum\ZoneSupportKind;
use App\Service\FileUploadService;
use App\Service\UploadPolicy;

/**
 * The picture of a quiz question, as the connector carries it: `imageFileId`, an image of the
 * teacher's own file library - the platform never fetches a picture from an address, and a document
 * never names a key of the bucket (App\Mcp\McpQuizDocuments). Claude deposits the picture first
 * (`file_upload`, `file_upload_url`) and names it.
 *
 * The picture is **linked** as the question editor links a library file
 * (App\Controller\QuizLibraryController, App\Service\UploadIntake::store()): the question takes the
 * file's own key and remembers the file, so correcting it in the library corrects it in the quiz.
 *
 * Any question may carry one - the statement's picture -, which is what a question of type « image »
 * needs and what a « zone » or « legende » drawn on an image is placed on. A zone question whose
 * support is a text or a piece of code has no picture to place its zones on, and is refused one.
 */
final readonly class McpQuizImages
{
    public const string KEY = 'imageFileId';

    // Where the question editor and every importer put a question's own picture.
    private const string OWN_IMAGE_PREFIX = 'quiz-question-images/';

    public function __construct(
        private McpLibraryAccess $library,
        private FileUploadService $fileUploads,
    ) {
    }

    /**
     * The pictures a list of document questions names, by position in that list - every one checked,
     * every refusal listed, nothing written.
     *
     * @param list<mixed> $questions the document's questions, as sent
     * @param int         $first     the number the first of them carries in the refusals
     *
     * @return array{images: array<int, FileLibraryNode>, errors: list<string>}
     */
    public function resolve(array $questions, int $first = 1): array
    {
        $images = [];
        $errors = [];
        foreach ($questions as $index => $raw) {
            if (!\is_array($raw) || !\array_key_exists(self::KEY, $raw)) {
                continue;
            }

            try {
                $images[$index] = $this->image($raw[self::KEY], $raw);
            } catch (McpToolException $refusal) {
                $errors[] = \sprintf('Question %d : %s', $index + $first, $refusal->getMessage());
            }
        }

        return ['images' => $images, 'errors' => $errors];
    }

    /**
     * One picture, checked: a file of the teacher's library, an image the question editor accepts,
     * on a question that can show one.
     *
     * @param array<array-key, mixed> $question the document question it is for, when there is one
     *
     * @throws McpToolException
     */
    public function image(mixed $fileId, array $question = []): FileLibraryNode
    {
        if (!\is_int($fileId) && !(\is_string($fileId) && ctype_digit($fileId))) {
            throw new McpToolException('« imageFileId » est l\'identifiant (un entier) d\'une image de la bibliothèque de fichiers.');
        }

        $type = QuestionType::tryFrom(\is_string($question['type'] ?? null) ? $question['type'] : '');
        if (null !== $type && $type->usesZoneConfig()) {
            $support = \is_array($question['support'] ?? null) ? $question['support'] : [];
            if (ZoneSupportKind::Image->value !== ($support['kind'] ?? null)) {
                throw new McpToolException('une image ne va qu\'à une zone ou une légende dont le support est "kind":"image".');
            }
        }

        $file = $this->library->file((int) $fileId);
        if (!UploadPolicy::images()->accepts($file->getName(), $file->getMimeType())) {
            throw new McpToolException(\sprintf('le fichier %d (« %s ») n\'est pas une image JPEG, PNG ou WebP.', (int) $file->getId(), $file->getName()));
        }

        return $file;
    }

    /**
     * Links the picture to the question. A picture the question owned (one uploaded straight into
     * it, or copied there by an import) is handed to the deferred purge; a library file it linked
     * stays where it is.
     */
    public function attach(QuizQuestion $question, FileLibraryNode $file): void
    {
        $key = $file->getStorageKey();
        if (null === $key) {
            throw new McpToolException(\sprintf('Le fichier %d ne contient rien à afficher.', (int) $file->getId()));
        }

        $previous = $question->getImageStorageKey();
        if (null !== $previous && $previous !== $key && null === $question->getLibraryNode() && str_starts_with($previous, self::OWN_IMAGE_PREFIX)) {
            $this->fileUploads->delete($previous);
        }

        $question->attachMedia($key);
        $question->setLibraryNode($file);
    }
}
