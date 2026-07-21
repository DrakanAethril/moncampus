<?php

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\User;
use Aws\S3\S3Client;

/**
 * Presigned-PUT upload for teacher audio appreciations (design's Part C) - unlike
 * App\Service\FileUploadService (server-mediated, via Flysystem), the browser's recorded Blob is
 * PUT directly to S3 from JS (assets/controllers/grade_audio_comment_controller.js) using the URL
 * this returns, never round-tripping through PHP. Uses the raw Aws\S3\S3Client service directly -
 * Flysystem's FilesystemOperator has no presigned-URL capability.
 *
 * $awsS3Prefix is applied manually here (unlike FileUploadService, which gets it "for free" via
 * flysystem.yaml's storage-level prefix config) since this bypasses Flysystem entirely.
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

    /** @return non-empty-string a presigned PUT URL, valid for 5 minutes */
    public function createUploadUrl(string $key): string
    {
        $command = $this->s3Client->getCommand('PutObject', [
            'Bucket' => $this->awsS3Bucket,
            'Key' => $this->awsS3Prefix.$key,
            'ContentType' => 'audio/webm',
        ]);

        return (string) $this->s3Client->createPresignedRequest($command, '+5 minutes')->getUri();
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
            return sprintf('https://%s/%s%s', $this->awsCloudfrontDomain, $this->awsS3Prefix, $key);
        }

        return sprintf('%s/%s/%s%s', rtrim($this->awsS3PublicEndpoint, '/'), $this->awsS3Bucket, $this->awsS3Prefix, $key);
    }
}
