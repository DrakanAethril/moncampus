<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestionDefinition;

/**
 * The uploaded images of an Apparier question, as a lifecycle rather than as a column.
 *
 * A zones question owns exactly one image (QuizQuestion::$imageStorageKey) and the three places
 * that copy a question each handle it inline. An apparier question owns N, buried inside its config
 * JSON, so the same three places plus the importer would each need the same walk - which is how a
 * copied question ends up sharing its originals and losing them when the source template is
 * deleted. Everything that duplicates, launches or imports one goes through copyImages(); everything
 * that removes one goes through deleteImages().
 */
final class MatchingImageStore
{
    public const string UPLOAD_PREFIX = 'quiz-matching-images/';

    public function __construct(private readonly FileUploadService $fileUploadService)
    {
    }

    /**
     * A copy of $config whose every image key points at a fresh object. The copy must survive the
     * source being deleted, which is the whole reason this is a copy and not a shared reference -
     * same rule as QuizInstantiationService's handling of a zones image.
     *
     * Keys are copied once each and reused: the same photo used by two pairs stays one object.
     *
     * @param array<string, mixed>|null $config
     *
     * @return array<string, mixed>|null
     */
    public function copyImages(?array $config): ?array
    {
        if (null === $config) {
            return null;
        }

        $copies = [];
        $copy = function (mixed $key) use (&$copies): mixed {
            if (!\is_scalar($key) || '' === trim((string) $key)) {
                return $key;
            }
            $source = trim((string) $key);

            return $copies[$source] ??= $this->duplicate($source);
        };

        // Rebuilt rather than written back in place: this is stored JSON, so every level is mixed
        // until it has been checked, and rewriting through $config['pairs'][$i][...] would be
        // indexing into something no narrowing covers.
        if (\is_array($config['pairs'] ?? null)) {
            $pairs = [];
            foreach ($config['pairs'] as $pair) {
                if (!\is_array($pair)) {
                    $pairs[] = $pair;
                    continue;
                }
                foreach (['leftImage', 'rightImage'] as $side) {
                    if (isset($pair[$side])) {
                        $pair[$side] = $copy($pair[$side]);
                    }
                }
                $pairs[] = $pair;
            }
            $config['pairs'] = $pairs;
        }

        if (\is_array($config['distractorImages'] ?? null)) {
            $config['distractorImages'] = array_map($copy, $config['distractorImages']);
        }

        return $config;
    }

    /**
     * Drops every image a question owns - called when the question or its template goes away, so a
     * deleted bank does not leave its photos behind in the bucket.
     */
    public function deleteImages(QuizQuestionDefinition $question): void
    {
        foreach ($question->getMatchingImageKeys() as $key) {
            $this->fileUploadService->delete($key);
        }
    }

    /** @param list<string> $keys */
    public function deleteKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $this->fileUploadService->delete($key);
        }
    }

    /**
     * A fresh key under this module's own prefix, so an imported or duplicated image can never be
     * mistaken for the one it came from.
     */
    private function duplicate(string $sourceKey): string
    {
        $extension = pathinfo($sourceKey, \PATHINFO_EXTENSION);
        $newKey = self::UPLOAD_PREFIX.bin2hex(random_bytes(16)).('' !== $extension ? '.'.$extension : '');
        $this->fileUploadService->copy($sourceKey, $newKey);

        return $newKey;
    }
}
