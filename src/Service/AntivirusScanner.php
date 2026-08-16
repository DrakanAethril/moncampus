<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The one place that answers "is this uploaded file hostile" (design/validated/upload-policy.md).
 *
 * It applies to **every upload on the platform**, not to the wiki's: avatars, quiz question images,
 * program syllabus and alternance calendar PDFs, messaging attachments, lesson-log materials,
 * library resources, student submissions, signup-list files, UFA and quiz imports, audio
 * recordings, video resources, wiki attachments and wiki inline images. There is no "scanned" and
 * "unscanned" upload here; a file that reaches the bucket has been scanned.
 *
 * It answers a different question from App\Service\UploadPolicy, which is why they are two objects:
 * the policy says what *kind* of file this is, this says whether it is hostile. Neither substitutes
 * for the other - the policy cannot see inside a `.zip`, and the scanner does not care what the
 * extension claims.
 *
 * Five decisions, each of which is a way to get this wrong:
 *
 * - **Fail closed, not open.** Configured but unreachable refuses the upload
 *   (ClamAvUnavailableException). An antivirus that silently lets files through when it is down is
 *   worse than none, because it is believed.
 * - **Unconfigured means disabled, and that is a different state from unreachable.** With no
 *   ANTIVIRUS_DSN, scanning is off entirely - the same shape FileUploadService::url() already uses
 *   for an absent CloudFront domain. Dev and CI run unconfigured; production runs configured.
 * - **Therefore the service belongs in compose.prod.yaml**, not in compose.yaml: anything added to
 *   the base file is on CI's boot path, and ClamAV downloads a few hundred megabytes of signatures
 *   on first start and holds roughly 1.5 GB of RAM. Dev gets an opt-in `--profile antivirus`.
 * - **Synchronous in this version.** A 20 M file over clamd's INSTREAM is fast enough to sit inside
 *   a request. The 200 M media ceiling is the case that may not be, and "just make it async" is not
 *   free here - Messenger runs sync:// with no consumer, so an async path means standing one up.
 *   Decide it when the media path proves slow, not before.
 * - **The scan happens on the temp file, before a byte reaches S3.** A rejected file never enters
 *   the bucket, so nothing has to be cleaned up after the fact.
 *
 * Two layers call it, and the memo below is why that costs one scan rather than two: the
 * App\Validator\AllowedUpload constraint scans so the user gets a message on the form, and the
 * services that write to the bucket scan as the guarantee that nothing unscanned gets stored - the
 * paths that upload without a form (the mobile API, the import assistants) have no first layer at
 * all, and the second one is what covers them.
 */
class AntivirusScanner
{
    /**
     * Files already cleared during this request, keyed by identity rather than by path: two
     * different uploads can reuse the same temp name across requests, and a worker process serves
     * many of them.
     *
     * @var array<string, true>
     */
    private array $cleared = [];

    public function __construct(
        private readonly ClamAvClient $client,
        private readonly string $antivirusDsn,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->antivirusDsn;
    }

    /**
     * @param string $path        the uploaded temp file, still on local disk
     * @param string $displayName the name to put in the refusal, i.e. the user's own file name
     *
     * @throws InfectedUploadException      when clamd names a signature
     * @throws ClamAvUnavailableException   when scanning is configured but could not be carried out
     */
    public function assertClean(string $path, string $displayName): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $key = $this->identityOf($path);

        if (isset($this->cleared[$key])) {
            return;
        }

        $reply = $this->client->scan($this->antivirusDsn, $path);

        if (null !== $reply->signature()) {
            throw new InfectedUploadException($reply->signature(), $displayName);
        }

        if (!$reply->isClean()) {
            throw new ClamAvUnavailableException(\sprintf('clamd answered "%s", which is not a verdict.', $reply->raw));
        }

        $this->cleared[$key] = true;
    }

    /**
     * Path plus size plus mtime: enough to tell two uploads apart within one request, and cheap.
     * An unreadable file has no identity, which is itself a refusal - a file the scanner cannot
     * stat is a file it cannot scan.
     */
    private function identityOf(string $path): string
    {
        $size = @filesize($path);
        $modified = @filemtime($path);

        if (false === $size || false === $modified) {
            throw new ClamAvUnavailableException(\sprintf('Could not stat "%s" for scanning.', $path));
        }

        return $path.':'.$size.':'.$modified;
    }
}
