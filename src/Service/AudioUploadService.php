<?php

declare(strict_types=1);

namespace App\Service;

use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Storage for the audio files recorded from the microphone in the browser and posted to the app,
 * which writes them to the bucket itself.
 *
 * Taken as-is from GradeAudioCommentUploadService, whose whole chain the "Enregistrements audio"
 * tool inherits - that is the handoff's migration constraint: same codec, same container, same
 * upload, same storage. Only the key changes (audio-recordings/… instead of audio-appreciations/…),
 * the gradebook's audio comments having gone with their screen.
 *
 * The recording does not go straight from the browser to the bucket through a presigned URL: a
 * cross-origin PUT needs a CORS rule on the bucket, the browser refused the request at the
 * preflight, and the teacher was only told "not saved". An audio file of a few hundred kilobytes
 * costs nothing to hand to PHP, and it works in every environment with nothing to configure on the
 * AWS side.
 *
 * $awsS3Prefix is applied by hand here (unlike FileUploadService, which gets it "for free" via
 * flysystem.yaml's storage-level prefix config) since this goes through the raw S3 client.
 */
class AudioUploadService
{
    public function __construct(
        private readonly S3Client $s3Client,
        private readonly AntivirusScanner $antivirus,
        private readonly string $awsS3Bucket,
        private readonly string $awsS3Prefix,
        private readonly string $awsS3PublicEndpoint,
        private readonly string $awsCloudfrontDomain,
    ) {
    }

    /**
     * The key of one recording file. It carries a random token rather than the file's id: the row
     * does not exist yet when the object is written, and the last thing wanted is a re-recording
     * silently overwriting an object a student may be playing right now.
     */
    public function keyForRecording(int $recordingId, ?int $studentId = null): string
    {
        return sprintf(
            'audio-recordings/%d/%s%s.webm',
            $recordingId,
            null === $studentId ? 'common-' : sprintf('student-%d-', $studentId),
            bin2hex(random_bytes(8)),
        );
    }

    /**
     * What the browser is allowed to hand over: whatever MediaRecorder produces, which is WebM/Opus
     * on Chrome and Ogg/Opus on Firefox. A WebM container carrying only an audio track is still
     * reported as video/webm by fileinfo, hence its presence here.
     *
     * This is the "media" narrowing of the platform upload policy (design/validated/
     * upload-policy.md) expressed as a sniffed-type allowlist rather than through
     * App\Service\UploadPolicy, and stricter than it: the recording arrives as a Blob from
     * MediaRecorder with a synthetic name, so there is no filename to run the extension rules
     * against - and the closed list below already decides both what is accepted and the
     * Content-Type the object is served with.
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

        // This service writes to the bucket through the raw S3 client, so hooking the scanner into
        // FileUploadService alone would have left every audio recording unscanned - and nothing
        // would have said so. See App\Tests\Service\BucketWritePathsTest, which fails when a fourth
        // path appears.
        $this->antivirus->assertClean($file->getPathname(), $file->getClientOriginalName());

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

    // Same CloudFront-first/direct-endpoint-fallback logic as FileUploadService::url() - the bucket
    // is private (CloudFront Origin Access Control only), so this is the same "obscure but not
    // access-controlled" delivery every other uploaded file in this app already gets, not a stricter
    // guarantee.
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
