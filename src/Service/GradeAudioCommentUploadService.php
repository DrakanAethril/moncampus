<?php

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\User;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Storage for the teacher's audio appreciations (design's Part C), recorded in the browser by
 * assets/controllers/evaluation_entry_controller.js and posted to this app, which writes them to
 * S3 itself.
 *
 * The recording used to be PUT straight from the browser to the bucket through a presigned URL,
 * saving PHP the transfer. That saving was never collected: a cross-origin PUT needs a CORS rule
 * on the bucket, the browser refused the request at the preflight, and the teacher was told the
 * comment could not be saved. An audio comment weighs a few hundred kilobytes - handing it to PHP
 * costs nothing and works in every environment without any bucket configuration.
 *
 * $awsS3Prefix is applied manually here (unlike FileUploadService, which gets it "for free" via
 * flysystem.yaml's storage-level prefix config) since this uses the raw S3 client.
 */
class GradeAudioCommentUploadService
{
    public function __construct(
        private readonly S3Client $s3Client,
        private readonly string $awsS3Bucket,
        private readonly string $awsS3Prefix,
        private readonly string $awsS3PublicEndpoint,
        private readonly string $awsCloudfrontDomain,
    ) {
    }

    public function keyFor(Evaluation $evaluation, User $student): string
    {
        return sprintf('audio-appreciations/%d/%d.webm', $evaluation->getId(), $student->getId());
    }

    /**
     * What the browser is allowed to hand over: whatever MediaRecorder produces, which is WebM/Opus
     * on Chrome and Ogg/Opus on Firefox. A WebM container carrying only an audio track is still
     * reported as video/webm by fileinfo, hence its presence here.
     */
    private const array AUDIO_MIME_TYPES = [
        'audio/webm' => 'audio/webm',
        'video/webm' => 'audio/webm',
        'audio/ogg' => 'audio/ogg',
        'video/ogg' => 'audio/ogg',
        'audio/mpeg' => 'audio/mpeg',
        'audio/mp4' => 'audio/mp4',
    ];

    /**
     * @return bool whether the file was really an audio recording and is now in the bucket
     */
    public function store(string $key, UploadedFile $file): bool
    {
        // A recording bigger than PHP's upload limit never arrives whole: it lands invalid and
        // sizeless, and would be stored as an empty object nobody can play.
        if (!$file->isValid() || 0 === $file->getSize()) {
            return false;
        }

        $contentType = self::AUDIO_MIME_TYPES[$file->getMimeType() ?? ''] ?? null;
        if (null === $contentType) {
            return false;
        }

        $stream = fopen($file->getPathname(), 'r') ?: throw new \RuntimeException(sprintf('Could not open "%s" for reading.', $file->getPathname()));

        try {
            $this->s3Client->putObject([
                'Bucket' => $this->awsS3Bucket,
                'Key' => $this->awsS3Prefix.$key,
                'Body' => $stream,
                'ContentType' => $contentType,
            ]);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return true;
    }

    public function delete(string $key): void
    {
        $this->s3Client->deleteObject(['Bucket' => $this->awsS3Bucket, 'Key' => $this->awsS3Prefix.$key]);
    }

    // Same CloudFront-first/direct-endpoint-fallback logic as FileUploadService::url() - the
    // bucket is private (CloudFront Origin Access Control only), so this is the same "obscure but
    // not access-controlled" delivery every other uploaded file in this app already gets, not a
    // stricter guarantee.
    public function playbackUrl(string $key): string
    {
        if ('' !== $this->awsCloudfrontDomain) {
            // No prefix here, unlike every other method of this class: the distribution's Origin
            // Path already points inside the environment's folder (AWS_S3_PREFIX documents the
            // pairing), so repeating it asks the bucket for dev/dev/... and earns a 403.
            return sprintf('https://%s/%s', $this->awsCloudfrontDomain, $key);
        }

        return sprintf('%s/%s/%s%s', rtrim($this->awsS3PublicEndpoint, '/'), $this->awsS3Bucket, $this->awsS3Prefix, $key);
    }
}
