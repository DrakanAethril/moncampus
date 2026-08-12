<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Checks that an uploaded course video really is an MP4, and not one over the cap.
 *
 * The decision behind it is the one taken for the whole video chantier: **no transcoding**. The app
 * accepts MP4/H.264 and says so, rather than pretending to be a video platform - so what is refused
 * here is not "an unsupported format" but "everything that is not the one format every browser of
 * the school plays without help".
 *
 * The cap pairs with frankenphp/conf.d/10-app.ini's upload_max_filesize: PHP drops an oversized
 * upload before Symfony ever sees it, so the two must be raised together or this validator would
 * never get the chance to explain the refusal.
 *
 * The type is read from the file's own content (fileinfo) rather than from the client's
 * Content-Type header, which is whatever the sender chose to write.
 */
class VideoUploadValidator
{
    public const int MAX_BYTES = 200 * 1024 * 1024;

    /**
     * What fileinfo answers for an MP4 depending on its brand box. `application/mp4` shows up for
     * files whose ftyp names no video brand, and `video/quicktime` for the MOV-branded MP4s some
     * exporters produce - both play as MP4 and refusing them would only puzzle the teacher.
     */
    private const array MP4_MIME_TYPES = ['video/mp4', 'application/mp4', 'video/quicktime'];

    /** @return ?string a translation key when the file is refused, null when it passes */
    public function validate(?UploadedFile $file): ?string
    {
        if (null === $file) {
            return 'videoUploadFailedError';
        }

        // A file bigger than PHP's own upload limit never arrives whole: it lands invalid and
        // sizeless, which would otherwise be reported as "not an MP4" - and the teacher would go
        // looking for a converter instead of a shorter video.
        if (!$file->isValid()) {
            return \UPLOAD_ERR_INI_SIZE === $file->getError() || \UPLOAD_ERR_FORM_SIZE === $file->getError()
                ? 'videoUploadTooLargeError'
                : 'videoUploadFailedError';
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return 'videoUploadTooLargeError';
        }

        // getMimeType() sniffs the content; getClientMimeType() only repeats what was sent.
        $mimeType = $file->getMimeType() ?? $file->getClientMimeType();

        if (!\in_array($mimeType, self::MP4_MIME_TYPES, true)) {
            return 'videoUploadNotMp4Error';
        }

        return null;
    }
}
