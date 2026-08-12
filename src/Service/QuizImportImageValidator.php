<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What may be deposited on the import screen: a real image, of a size a question can carry.
 *
 * Server-side for the same reason as App\Service\PdfUploadValidator - `accept=` filters a file
 * dialog, it controls nothing - and the same three image types the question editor already accepts
 * (App\Form\QuizQuestionType), since that is where these files end up.
 */
class QuizImportImageValidator
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    private const array MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @return ?string a translation key when the file is refused, null when it passes */
    public function validate(?UploadedFile $file): ?string
    {
        if (null === $file) {
            return null;
        }

        // A file bigger than PHP's own upload limit never arrives whole: it lands invalid and
        // sizeless, which would otherwise sail through both checks below.
        if (!$file->isValid()) {
            return \UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError()
                ? 'quizImportImageTooLargeError'
                : 'quizImportImageFailedError';
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return 'quizImportImageTooLargeError';
        }

        // getMimeType() sniffs the content; getClientMimeType() only repeats what was sent.
        return \in_array($file->getMimeType() ?? $file->getClientMimeType(), self::MIME_TYPES, true)
            ? null
            : 'quizImportImageNotAnImageError';
    }
}
