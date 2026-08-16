<?php

declare(strict_types=1);

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
        private readonly AntivirusScanner $antivirus,
        private readonly ObjectStore $objectStore,
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

        // Before a single byte reaches S3, so a rejected file never enters the bucket and nothing
        // has to be cleaned up afterwards. Usually a no-op: the form constraint already cleared
        // this exact temp file, and App\Service\AntivirusScanner remembers it for the request. The
        // paths with no form at all - the mobile API, the import assistants - are why it is here
        // and not only there.
        $this->antivirus->assertClean($file->getPathname(), $file->getClientOriginalName());

        $key = $prefix.$filename;
        $stream = fopen($file->getPathname(), 'r') ?: throw new \RuntimeException(sprintf('Could not open "%s" for reading.', $file->getPathname()));

        try {
            $this->uploadsStorage->writeStream($key, $stream, $this->dispositionFor($key));
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        // Since deletion became deferred (design/validated/object-deletion.md), writing a key that
        // is pending removal has to cancel that removal - otherwise a caller using a deterministic
        // key, which this method's docblock explicitly allows, would have its **new** object purged
        // thirty days later.
        $this->objectStore->cancelDeletion($key);

        return $key;
    }

    /**
     * The single highest-value measure of the platform upload policy (design/validated/
     * upload-policy.md), and the one that does not depend on the allowlist being right.
     *
     * Objects are served by CloudFront, unsigned, from the school's own CDN domain - so anything
     * the browser can render was, until this, rendered **inline** on that domain. Handing everything
     * but images and PDF over as a download neutralises the whole "dangerous because of how it
     * opens" family - HTML, SVG, MHTML - regardless of what any allowlist says. The allowlist then
     * only has to answer the other half: dangerous because of *what the file is*.
     *
     * The two media services that write to this bucket through the raw S3 client
     * (App\Service\AudioUploadService, App\Service\VideoUploadService) deliberately do not do this:
     * they set their own Content-Type from a closed audio/MP4 allowlist, so nothing that opens as
     * anything else can reach the bucket by those paths.
     *
     * @return array{ContentDisposition?: string}
     */
    private function dispositionFor(string $key): array
    {
        if (UploadPolicy::servesInline($key)) {
            return [];
        }

        return ['ContentDisposition' => 'attachment'];
    }

    /**
     * Marks the object for removal - it does **not** remove it
     * (design/validated/object-deletion.md).
     *
     * The name and the signature are unchanged on purpose: nineteen call sites across the
     * application say `delete($key)` and none of them had to learn anything. What changed is what
     * the word means - the bytes now live on for the retention window of their origin, which is
     * what gives the file library a corbeille and what makes a mistaken delete recoverable.
     *
     * `$origin` is optional for the same reason: the existing callers pass a key and nothing else,
     * and App\Service\ObjectStore reads the origin off the key's own prefix when they do.
     */
    public function delete(string $key, ?string $origin = null): void
    {
        $this->objectStore->scheduleDeletion($key, $origin);
    }

    /**
     * Duplicates an existing object under a new key, byte-for-byte, without round-tripping
     * through PHP memory - used when a feature needs a real, independent second copy of an
     * already-uploaded file (e.g. App\Service\SequenceInstantiationService duplicating a
     * LibraryResource at instantiation time) rather than accepting a fresh HTTP upload.
     *
     * @param non-empty-string $sourceKey
     * @param non-empty-string $destinationKey
     */
    public function copy(string $sourceKey, string $destinationKey): void
    {
        $this->uploadsStorage->copy($sourceKey, $destinationKey);
    }

    /**
     * Reads back the raw bytes of a previously uploaded file - needed when a stored file must be
     * handed to a downstream API as bytes rather than served via a URL (e.g.
     * App\Service\GotenbergClient::mergePdfs() for a PDF-type cover/calendar upload).
     *
     * @return non-empty-string
     */
    public function read(string $key): string
    {
        return $this->uploadsStorage->read($key);
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
