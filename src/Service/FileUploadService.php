<?php

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploads files to the "uploads.storage" S3 bucket (config/packages/flysystem.yaml). Every
 * caller supplies its own feature prefix (e.g. "avatars/") so one bucket can host multiple
 * unrelated features without their keys colliding - see App\Entity\User::$avatarKey for the
 * first caller.
 *
 * Delivery is via CloudFront, not this app: the bucket is never made public, and CloudFront's
 * Origin Access Control is what's allowed to read it. url() just builds the CloudFront URL - no
 * signing, no byte-proxying - falling back to a direct MinIO URL only in local dev when no
 * CloudFront domain is configured.
 */
class FileUploadService
{
    public function __construct(
        private readonly FilesystemOperator $uploadsStorage,
        private readonly string $awsS3Bucket,
        private readonly string $awsS3PublicEndpoint,
        private readonly string $awsCloudfrontDomain,
    ) {
    }

    /**
     * @param non-empty-string $prefix   must end with '/' - the caller's feature namespace
     * @param non-empty-string $filename stored as-is under the prefix (caller decides the naming
     *                                   scheme - e.g. deterministic per-entity or a generated UUID)
     *
     * @return non-empty-string the full storage key (prefix + filename)
     */
    public function upload(string $prefix, string $filename, UploadedFile $file): string
    {
        if (!str_ends_with($prefix, '/')) {
            throw new \InvalidArgumentException(sprintf('Prefix "%s" must end with "/".', $prefix));
        }

        $key = $prefix.$filename;
        $stream = fopen($file->getPathname(), 'r') ?: throw new \RuntimeException(sprintf('Could not open "%s" for reading.', $file->getPathname()));

        try {
            $this->uploadsStorage->writeStream($key, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return $key;
    }

    public function delete(string $key): void
    {
        $this->uploadsStorage->delete($key);
    }

    public function url(string $key): string
    {
        if ('' !== $this->awsCloudfrontDomain) {
            return sprintf('https://%s/%s', $this->awsCloudfrontDomain, $key);
        }

        // Local dev without a CloudFront domain configured (plain MinIO) - direct bucket URL via
        // the browser-facing endpoint (not AWS_S3_ENDPOINT, which is the internal Docker-network
        // address PHP uses for S3 API calls and isn't reachable from a browser on the host).
        return sprintf('%s/%s/%s', rtrim($this->awsS3PublicEndpoint, '/'), $this->awsS3Bucket, $key);
    }
}
