<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The lifecycle of the images deposited on the import screen: stored privately like every other
 * upload, listed under their short reference (App\Service\QuizImportImageBatch), copied onto the
 * questions that name them, and dropped as a whole once the import is confirmed or started over.
 *
 * The batch lives in the session next to the parsed document, for the same reason
 * (App\Controller\QuizImportController's class docblock): it belongs to one import, not to the
 * library. Nothing here is ever made public - the model sees the files because the teacher attaches
 * them to their conversation, not because the application publishes them.
 */
final class QuizImportImages
{
    public const string UPLOAD_PREFIX = 'quiz-import-images/';

    // Where a resolved image lands once it belongs to a question - the very prefix
    // QuizInstantiationService copies question images under.
    private const string QUESTION_PREFIX = 'quiz-question-images/';

    private const string SESSION_KEY = 'quiz_import_images';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    public function batch(): QuizImportImageBatch
    {
        return QuizImportImageBatch::fromSession($this->requestStack->getSession()->get(self::SESSION_KEY));
    }

    /** Stores an uploaded file and returns the reference the prompt will carry. */
    public function add(UploadedFile $file): string
    {
        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
        $key = $this->fileUploadService->upload(
            self::UPLOAD_PREFIX,
            bin2hex(random_bytes(16)).('' !== $extension ? '.'.$extension : ''),
            $file,
        );

        return $this->batchAdd($file->getClientOriginalName(), $key);
    }

    /**
     * Registers an already-stored object under a fresh reference. Separate from add() so a caller
     * that uploaded by another route - and the tests - can use the same numbering.
     */
    public function batchAdd(string $originalName, string $storageKey): string
    {
        $batch = $this->batch();
        $ref = $batch->add($originalName, $storageKey);
        $this->save($batch);

        return $ref;
    }

    public function remove(string $ref): void
    {
        $batch = $this->batch();
        $key = $batch->remove($ref);
        if (null === $key) {
            return;
        }

        $this->save($batch);
        $this->fileUploadService->delete($key);
    }

    /**
     * Ends the batch: every deposited object goes, referenced or not. Called once the import is
     * confirmed - the questions that needed one hold their own copy by then - and when the teacher
     * starts over. It is what keeps abandoned deposits from accumulating in the bucket.
     */
    public function clear(): void
    {
        foreach ($this->batch()->storageKeys() as $key) {
            $this->fileUploadService->delete($key);
        }

        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * The storage key a question should carry for a reference, or null when the batch does not hold
     * it (which leaves the question incomplete, not in error).
     *
     * @param bool $copy false for the preview, whose transient entities must leave nothing behind:
     *                   it shows the deposited object itself. True on confirmation, where the
     *                   question gets its own copy - the batch is dropped right after.
     */
    public function keyForQuestion(string $ref, bool $copy = true): ?string
    {
        $source = $this->batch()->keyFor($ref);
        if (null === $source || !$copy) {
            return $source;
        }

        $extension = pathinfo($source, \PATHINFO_EXTENSION);
        $key = self::QUESTION_PREFIX.bin2hex(random_bytes(16)).('' !== $extension ? '.'.$extension : '');
        $this->fileUploadService->copy($source, $key);

        return $key;
    }

    private function save(QuizImportImageBatch $batch): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $batch->toSession());
    }
}
