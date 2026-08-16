<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Aws\S3\S3Client;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The only object in this application allowed to remove bytes (design/validated/object-deletion.md).
 *
 * Before it, three services deleted - App\Service\FileUploadService through Flysystem,
 * App\Service\AudioUploadService and App\Service\VideoUploadService through the raw S3 client - across
 * nineteen call sites, not one of them guarded. They all delegate here now, and they all keep their
 * name and their signature, which is the point: the nineteen callers are untouched, and what changed
 * is what deleting *means*.
 *
 * ## Mark now, remove later
 *
 * `scheduleDeletion()` writes a row and returns. `remove()` is what actually deletes, and only
 * App\Command\PurgeUploadsCommand calls it. So a file survives its own deletion by the retention
 * window of its origin - which is what gives the file library a corbeille, and what makes a
 * mistaken delete recoverable rather than final.
 *
 * ## Why the row is written with DBAL rather than through the entity manager
 *
 * Because `delete()` is called in the middle of other people's work. A `flush()` from in here would
 * commit whatever else the caller had pending - half a form's changes, an entity removed but not yet
 * validated - and persisting without flushing would be worse: a caller that never flushes would then
 * *silently not delete at all*, leaving bytes nobody points at any more. A single INSERT, outside
 * the unit of work, has neither failure mode. Reading is ordinary Doctrine
 * (App\Repository\DeletedObjectRepository); only this append is raw.
 *
 * ## The environment prefix meets its two conventions here
 *
 * The three services speak in keys *without* the environment prefix (`AWS_S3_PREFIX`): two apply it
 * by hand at the S3 boundary, one gets it for free from Flysystem's storage config. The purge needs
 * the real key. So callers hand over their own unprefixed key and this class is where the full one
 * is built - once, rather than at each of the three.
 *
 * ## What this does not buy, stated rather than implied
 *
 * There is no second S3 user (considered and dropped, 2026-08-16: the purge runs in the same
 * container as the web workers, so the split would have been decorative). Nothing inside the
 * application can therefore stop a bug from deleting more than it should - this makes it auditable
 * and single, not impossible. The recovery net is the bucket's own versioning plus a lifecycle rule
 * expiring noncurrent versions, and that setting is the only way back from a bad purge.
 */
class ObjectStore
{
    /**
     * How long the bytes of a deleted object survive, by origin. Thirty days is the platform rule -
     * a teacher's deleted course material, restorable from the corbeille for the whole window.
     */
    public const int DEFAULT_RETENTION_DAYS = 30;

    /**
     * Origins that are not somebody's file and have no corbeille to appear in: an upload nobody
     * ever claimed, an import batch abandoned halfway. Keeping those for thirty days would be
     * storing accidents.
     *
     * @var array<string, int>
     */
    public const array RETENTION_DAYS_BY_ORIGIN = [
        'staged' => 1,
        'import' => 1,
    ];

    /**
     * How a key names its own origin when the caller does not. The nineteen existing call sites
     * pass a key and nothing else - keeping their signature is the whole reason this class could be
     * introduced without touching them - so the first segment of the key is what answers.
     *
     * @var array<string, string>
     */
    private const array ORIGIN_BY_KEY_PREFIX = [
        'avatars' => 'avatar',
        'messages' => 'message',
        'lesson-logs' => 'lesson-log',
        'assignment-attachments' => 'assignment',
        'assignment-submissions' => 'submission',
        'signup-lists' => 'signup-list',
        'library-resources' => 'sequence-library',
        'library-resource-instances' => 'sequence-library',
        'file-library' => 'file-library',
        'quiz-question-images' => 'quiz',
        'quiz-matching-images' => 'quiz',
        // An import batch is the other short-lived origin: images extracted from a document the
        // teacher may abandon at step 2 of the assistant.
        'quiz-import-images' => 'import',
        'audio-recordings' => 'audio-recording',
        'video-resources' => 'video-resource',
        'programs' => 'program',
        'wiki' => 'wiki',
        'documentation' => 'documentation',
        'staged' => 'staged',
        'diagnostics' => 'diagnostics',
    ];

    public function __construct(
        private readonly S3Client $s3Client,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly string $awsS3Bucket,
        private readonly string $awsS3Prefix,
    ) {
    }

    /**
     * Records that this object is due to go. The bytes stay put.
     *
     * @param string  $key    the caller's own key, **without** the environment prefix
     * @param ?string $origin null lets the key name it - see ORIGIN_BY_KEY_PREFIX
     */
    public function scheduleDeletion(string $key, ?string $origin = null): void
    {
        $storageKey = $this->awsS3Prefix.$key;
        $user = $this->security->getUser();

        // ON DUPLICATE KEY: deleting a key that is already listed is not an error - a corbeille can
        // be emptied twice, and "Supprimer définitivement" is exactly a re-dating of an existing
        // row. The later date wins, which is the conservative direction: it can only postpone the
        // removal, never bring it forward past a window somebody is still relying on.
        $this->connection->executeStatement(
            'INSERT INTO deleted_object (storage_key, deleted_at, deleted_by_id, origin, attempts)
             VALUES (:key, :deletedAt, :deletedBy, :origin, 0)
             ON DUPLICATE KEY UPDATE deleted_at = GREATEST(deleted_at, VALUES(deleted_at)), origin = VALUES(origin)',
            [
                'key' => $storageKey,
                'deletedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'deletedBy' => $user instanceof User ? $user->getId() : null,
                'origin' => $origin ?? $this->originOf($key),
            ],
        );
    }

    /**
     * Un-schedules a deletion, for a key about to be written again.
     *
     * Without this, a caller using a deterministic key - which App\Service\FileUploadService's
     * docblock explicitly allows - deletes, re-uploads the same key, and the purge removes the
     * **new** object thirty days later. Today's callers happen to be safe (avatars carry a
     * timestamp, everything else a UUID); "happens to be safe" is not a rule, and this is one query.
     */
    public function cancelDeletion(string $key): void
    {
        $this->connection->executeStatement(
            'DELETE FROM deleted_object WHERE storage_key = :key AND purged_at IS NULL',
            ['key' => $this->awsS3Prefix.$key],
        );
    }

    /**
     * Removes the bytes - the only method on this platform that does.
     *
     * Called by the purge, and by App\Command\CheckUploadsCommand's probe - which is the one place
     * an immediate delete is the thing being tested rather than a shortcut around the window.
     *
     * @param string $storageKey the full key, environment prefix included, as stored on the row
     */
    public function remove(string $storageKey): void
    {
        $this->s3Client->deleteObject(['Bucket' => $this->awsS3Bucket, 'Key' => $storageKey]);
    }

    /** Whether the object is still there - the probe's second and fourth questions. */
    public function exists(string $storageKey): bool
    {
        return $this->s3Client->doesObjectExist($this->awsS3Bucket, $storageKey);
    }

    /**
     * Writes a diagnostic object, for App\Command\CheckUploadsCommand and nothing else.
     *
     * It lives here rather than in the command so that the S3 client stays inside this one class -
     * App\Tests\Service\BucketWritePathsTest is what keeps that true, and a command holding its own
     * client would be a fourth way into the bucket for a reason no upload needs.
     *
     * **It never carries user bytes**, which is why it does not scan: the body is a sentence this
     * application composes, and the key is one the command generates. Any other caller wanting to
     * write belongs in one of the three upload services, behind the antivirus.
     */
    public function writeProbe(string $storageKey, string $body): void
    {
        $this->s3Client->putObject([
            'Bucket' => $this->awsS3Bucket,
            'Key' => $storageKey,
            'Body' => $body,
            'ContentType' => 'text/plain',
        ]);
    }

    /** The full key of a caller's key, for the two places that have to name a row. */
    public function storageKeyFor(string $key): string
    {
        return $this->awsS3Prefix.$key;
    }

    /** How long this origin's bytes survive, in days. */
    public static function retentionDaysFor(string $origin): int
    {
        return self::RETENTION_DAYS_BY_ORIGIN[$origin] ?? self::DEFAULT_RETENTION_DAYS;
    }

    private function originOf(string $key): string
    {
        $segment = explode('/', $key)[0];

        return self::ORIGIN_BY_KEY_PREFIX[$segment] ?? 'other';
    }
}
