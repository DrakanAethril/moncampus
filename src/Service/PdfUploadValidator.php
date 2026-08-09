<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Checks that an uploaded file really is a PDF, and not an oversized one
 * (design_handoff_workflow_postulation: the offer description, the CV and the cover letter).
 *
 * Server-side because the browser side is a courtesy, not a control: `accept="application/pdf"` is
 * a filter in a file dialog, and anything that posts a form directly ignores it. The screens
 * announce "PDF uniquement · 10 Mo max", and this is what makes that sentence true.
 *
 * The type is read from the file's own content (fileinfo) rather than from the client's
 * Content-Type header, which is whatever the sender chose to write.
 */
class PdfUploadValidator
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

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
                ? 'pdfUploadTooLargeError'
                : 'pdfUploadFailedError';
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return 'pdfUploadTooLargeError';
        }

        // getMimeType() sniffs the content; getClientMimeType() only repeats what was sent.
        $mimeType = $file->getMimeType() ?? $file->getClientMimeType();

        if ('application/pdf' !== $mimeType) {
            return 'pdfUploadNotPdfError';
        }

        return null;
    }
}
