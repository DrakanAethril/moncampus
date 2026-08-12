<?php

declare(strict_types=1);

namespace App\Service;

use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Storage for the course videos a teacher uploads, written to the bucket by the app itself.
 *
 * Modelled on AudioUploadService, whose chain it shares - same client, same bucket, same
 * CloudFront-or-endpoint address. What it does not share is the acceptance rule, which lives in
 * App\Service\VideoUploadValidator: MP4 only, and a cap paired with PHP's own upload_max_filesize.
 *
 * The file does not go straight from the browser to the bucket through a presigned URL: that needs a
 * CORS rule on the bucket, and the browser refuses the request at the preflight if it is missing -
 * telling the teacher nothing but "not saved". Handing the file to PHP works in every environment
 * with nothing to configure on the AWS side, and streaming it to S3 keeps it out of memory.
 *
 * $awsS3Prefix is applied by hand here (unlike FileUploadService, which gets it "for free" via
 * flysystem.yaml's storage-level prefix config) since this goes through the raw S3 client.
 */
class VideoUploadService
{
    public function __construct(
        private readonly S3Client $s3Client,
        private readonly string $awsS3Bucket,
        private readonly string $awsS3Prefix,
        private readonly string $awsS3PublicEndpoint,
        private readonly string $awsCloudfrontDomain,
    ) {
    }

    /**
     * The key of one video file. It carries a random token rather than the file's id: the row does
     * not exist yet when the object is written, and a re-upload must never silently overwrite an
     * object a student may be watching right now.
     */
    public function keyForResource(int $resourceId): string
    {
        return \sprintf('video-resources/%d/%s.mp4', $resourceId, bin2hex(random_bytes(8)));
    }

    /**
     * Writes the object. The caller has already asked VideoUploadValidator whether the file is
     * acceptable - this only puts an accepted one in the bucket.
     *
     * @return bool whether the object is now really in the bucket
     */
    public function store(string $key, UploadedFile $file): bool
    {
        $stream = fopen($file->getPathname(), 'r');

        if (false === $stream) {
            return false;
        }

        try {
            $this->s3Client->putObject([
                'Bucket' => $this->awsS3Bucket,
                'Key' => $this->awsS3Prefix.$key,
                'Body' => $stream,
                'ContentType' => 'video/mp4',
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

    /**
     * The address the player is handed, and only on first play: a video weighs ten to a hundred
     * times an audio file, so laying it into a page that may never be played is bandwidth given
     * away.
     *
     * Same CloudFront-first/direct-endpoint-fallback logic as FileUploadService::url() - the bucket
     * is private (CloudFront Origin Access Control only), so this is the same "obscure but not
     * access-controlled" delivery every other uploaded file in this app already gets.
     */
    public function playbackUrl(string $key): string
    {
        if ('' !== $this->awsCloudfrontDomain) {
            // No prefix here, unlike every other method of this class: the distribution's Origin
            // Path already points inside the environment's folder (AWS_S3_PREFIX documents the
            // pairing), so repeating it asks the bucket for dev/dev/... and earns a 403.
            return \sprintf('https://%s/%s', $this->awsCloudfrontDomain, $key);
        }

        return \sprintf('%s/%s/%s%s', rtrim($this->awsS3PublicEndpoint, '/'), $this->awsS3Bucket, $this->awsS3Prefix, $key);
    }
}
