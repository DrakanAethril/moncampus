<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The staging area every upload field of this platform now goes through
 * (design/validated/file-library.md, "Staged uploads").
 *
 * The shape it replaces is the reason it exists: a `FileType` inside a Turbo-submitted form cannot
 * report progress, because nothing in the browser reports how much of a *request body* has gone out
 * - `fetch()` cannot, and a plain form submission certainly cannot. `xhr.upload.onprogress` is the
 * only API that answers, and it needs a request carrying one file and nothing else. So the file
 * travels first, alone, and the form later submits a token.
 *
 * Three things follow from that, and they are worth more than the progress bar:
 *
 * - **the type and the virus are checked before the teacher fills the rest of the form**, not after
 *   they submit it;
 * - **no form on the platform carries file bytes any more**, which removes the failure mode already
 *   recorded for this repository: FrankenPHP answers a POST over `post_max_size` with a 200 HTML
 *   page - no 413, and nothing anyone can act on;
 * - Turbo's POST-must-redirect rule is untouched: staging is an XHR, and the form still posts and
 *   redirects.
 *
 * The cost is honest: an upload now happens before the user commits to the form, so an abandoned
 * screen leaves an object behind. That is what purge() is for, and it is called nightly by
 * App\Command\PurgeUploadsCommand.
 *
 * ## The token
 *
 * Signed, not stored. A staged upload has no row of its own: the token *is* the record, carrying the
 * key, the name, the sniffed type, the size and the owner, with an HMAC over the lot. A table
 * describing staged objects would be a second truth about the same thing, kept in step by hand -
 * the mistake `file-library.md` refuses for the quota as well.
 *
 * Nothing in the token is trusted on the way back in: the signature is verified, and the owner is
 * compared against the user doing the claiming, so one account cannot claim another's object.
 *
 * ## The fuse, and why the purge does not list the bucket
 *
 * An object is written here **already scheduled for deletion**: `stage()` records it through
 * App\Service\ObjectStore with origin `staged`, whose retention is one day. Claiming it defuses that
 * (the object is copied to the caller's prefix and the row goes); abandoning the screen does
 * nothing, and App\Command\PurgeUploadsCommand removes it the next night.
 *
 * design/validated/object-deletion.md described this pass as a listing of `staged/` by age, on the
 * grounds that the bucket is the one source that cannot drift. **It is also a source this deployment
 * cannot read**: the IAM user holds PutObject, GetObject and DeleteObject on the bucket - measured
 * with `app:uploads:check` - but not `s3:ListBucket`, so `ListObjectsV2` answers 403. Scheduling at
 * write time reaches the same rule through a permission the runtime does have, and covers one case
 * the listing missed: an upload that succeeded server-side but whose reply never reached the browser
 * is fused like any other.
 */
class StagedUploadStore
{
    /**
     * Everything staged lives under this prefix, which is what makes the purge a single listing and
     * what an S3 lifecycle rule can be pointed at as the belt to these suspenders.
     */
    public const string PREFIX = 'staged/';

    /**
     * How long an unclaimed object survives, in the one place that decides it: the `staged` entry of
     * App\Service\ObjectStore::RETENTION_DAYS_BY_ORIGIN. Long enough for a slow form, short enough
     * to matter.
     */
    public const string ORIGIN = 'staged';

    public function __construct(
        private readonly FilesystemOperator $uploadsStorage,
        private readonly AntivirusScanner $antivirus,
        private readonly ObjectStore $objectStore,
        private readonly string $appSecret,
    ) {
    }

    /**
     * Writes the file to the staging prefix and hands back the token the form will carry.
     *
     * The scan happens here, before a byte reaches the bucket, for the same reason
     * App\Service\FileUploadService::upload() scans: this is a path that writes to S3, and the rule
     * is that a file in the bucket has been scanned. The form-side constraint is the courtesy;
     * this is the guarantee.
     *
     * @throws InfectedUploadException     the file is hostile
     * @throws ClamAvUnavailableException  the scanner is configured but unreachable - fail closed
     */
    public function stage(UploadedFile $file, int $ownerId): StagedUpload
    {
        $this->antivirus->assertClean($file->getPathname(), $file->getClientOriginalName());

        $originalName = $file->getClientOriginalName();
        $extension = mb_strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));
        $key = \sprintf('%s%d/%s%s', self::PREFIX, $ownerId, bin2hex(random_bytes(16)), '' === $extension ? '' : '.'.$extension);

        $stream = fopen($file->getPathname(), 'r') ?: throw new \RuntimeException(\sprintf('Could not open "%s" for reading.', $file->getPathname()));

        try {
            // The same Content-Disposition rule as every other write to this bucket: a staged object
            // is reachable through the CDN like any other, so "dangerous because of how it opens"
            // must be answered here too, not only once the file is claimed. S3 copies object
            // metadata by default, so the disposition survives the claim.
            $this->uploadsStorage->writeStream($key, $stream, UploadPolicy::servesInline($key) ? [] : ['ContentDisposition' => 'attachment']);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        // The fuse - see the class docblock. Lit before the token is handed out, so an object exists
        // for no longer than a day unless a form claims it, whatever happens to the response.
        $this->objectStore->scheduleDeletion($key, self::ORIGIN);

        // Sniffed, never claimed: getClientMimeType() only repeats what the sender chose to write,
        // and this value is what the field's own narrowing will later be checked against.
        $mimeType = $this->sniff($file);

        return $this->sign($key, $originalName, $mimeType, (int) $file->getSize(), $ownerId);
    }

    /**
     * Reads a token back, or null when it is forged, malformed, or somebody else's.
     *
     * It deliberately does not touch S3: a form redisplayed after a validation error re-resolves
     * every token it carries, and a HEAD per field per redisplay would be paid for nothing. Whether
     * the object is still there is claim()'s question, and it is asked once.
     */
    public function resolve(string $token, int $ownerId): ?StagedUpload
    {
        $parts = explode('.', $token);

        if (2 !== \count($parts)) {
            return null;
        }

        $payload = $this->decodeSegment($parts[0]);

        if (null === $payload || !hash_equals($this->signature($payload), $parts[1])) {
            return null;
        }

        $data = json_decode($payload, true);

        if (!\is_array($data)) {
            return null;
        }

        $key = $data['k'] ?? null;
        $name = $data['n'] ?? null;
        $mimeType = $data['m'] ?? null;
        $size = $data['s'] ?? null;
        $owner = $data['o'] ?? null;

        if (!\is_string($key) || !\is_string($name) || !\is_string($mimeType) || !\is_int($size) || !\is_int($owner)) {
            return null;
        }

        // One account never claims another's object, whatever the signature says: the two checks
        // answer different questions - "did we write this token" and "is it yours".
        if ($owner !== $ownerId) {
            return null;
        }

        return new StagedUpload($token, $key, $name, $mimeType, $size);
    }

    /**
     * Moves a staged object into the feature's own prefix and hands back the final key.
     *
     * A copy followed by a delete rather than a rename, because that is all S3 offers - and the
     * delete is deliberate rather than deferred: the bytes are not going anywhere, they are being
     * taken over by their destination, and leaving the staged copy behind would charge the platform
     * twice for one file until the nightly purge noticed.
     *
     * @param non-empty-string $prefix   must end with '/' - the caller's feature namespace
     * @param non-empty-string $filename the caller's naming scheme, as for FileUploadService
     *
     * @return non-empty-string the full storage key
     */
    public function claim(StagedUpload $staged, string $prefix, string $filename): string
    {
        if (!str_ends_with($prefix, '/')) {
            throw new \InvalidArgumentException(\sprintf('Prefix "%s" must end with "/".', $prefix));
        }

        $key = $prefix.$filename;
        $this->uploadsStorage->copy($staged->key, $key);
        // Through the choke point like every other removal on this platform, and immediate rather
        // than deferred: nobody has ever seen this object as a file of theirs, and it has just been
        // copied - App\Service\ObjectStore's window exists for things somebody may want back. The
        // fuse lit at stage time then has nothing left to burn, so its row goes too.
        $this->objectStore->remove($this->objectStore->storageKeyFor($staged->key));
        $this->objectStore->cancelDeletion($staged->key);

        return $key;
    }

    /**
     * Is the object still there? Asked once, by a controller about to claim, so that an expired
     * token - the only realistic cause being a purge in the meantime - is a form error rather than
     * a 500 out of Flysystem.
     */
    public function exists(StagedUpload $staged): bool
    {
        return $this->uploadsStorage->fileExists($staged->key);
    }

    /**
     * Drops a staged object a screen is done with - a picker whose file the teacher removed again
     * before submitting. Best effort: an object that has already gone is the outcome asked for, and
     * the fuse would have taken it within the day anyway.
     */
    public function discard(StagedUpload $staged): void
    {
        try {
            $this->objectStore->remove($this->objectStore->storageKeyFor($staged->key));
            $this->objectStore->cancelDeletion($staged->key);
        } catch (\Throwable) {
            // Nothing to report: the nightly purge is what actually guarantees this prefix stays
            // empty, and this is only the polite version of it.
        }
    }

    private function sign(string $key, string $originalName, string $mimeType, int $size, int $ownerId): StagedUpload
    {
        $payload = json_encode(['k' => $key, 'n' => $originalName, 'm' => $mimeType, 's' => $size, 'o' => $ownerId], \JSON_THROW_ON_ERROR);
        $token = $this->encodeSegment($payload).'.'.$this->signature($payload);

        return new StagedUpload($token, $key, $originalName, $mimeType, $size);
    }

    private function signature(string $payload): string
    {
        return $this->encodeSegment(hash_hmac('sha256', $payload, $this->appSecret, true));
    }

    private function encodeSegment(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decodeSegment(string $segment): ?string
    {
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }

    /**
     * fileinfo can fail on an unreadable temp file. An empty answer is a real one here: the policy
     * treats "nothing to compare against" as "the extension rules alone decide", exactly as
     * App\Validator\AllowedUploadValidator does.
     */
    private function sniff(UploadedFile $file): string
    {
        try {
            return $file->getMimeType() ?? '';
        } catch (\Throwable) {
            return '';
        }
    }
}
