<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\FileLibraryNode;
use App\Entity\OnlineCourseMaterial;
use App\Entity\OnlineCourseMaterialRevision;
use App\Service\AntivirusScanner;
use App\Service\FileUploadService;
use App\Service\StagedUpload;
use App\Service\StagedUploadStore;
use App\Service\UploadIntake;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Unpacks an interactive course into its own folder of the bucket - **the only door HTML and
 * JavaScript enter the storage by** (design/validated/cours-en-ligne.md, §6).
 *
 * Everywhere else on the platform `html` and `js` are accepted inside an archive only, and an
 * archive is stored as an archive: the CDN serves what it holds, so a page stored as a page would
 * be a page hosted. That stays true. What this class does is the one deliberate exception, and it
 * is narrow on purpose: it writes under `online-courses/` and nowhere else, only what
 * App\Service\OnlineCourse\OnlineCourseBundleReader has accepted, each file under the type that
 * reader's list gives it and never one sniffed from its bytes.
 *
 * Why it is safe to serve: the CDN is another origin than the application
 * (App\Service\OnlineCourse\OnlineCourseContentOrigin refuses to draw a frame otherwise), so a
 * course's JavaScript reads neither the session nor the page it is embedded in.
 *
 * A revision's folder is written once and never again - its number only grows - so every object is
 * marked immutable: the CDN and the browser may keep it for as long as they like.
 */
class OnlineCourseBundlePublisher
{
    /** A page written by the Claude connector: text, so two megabytes is already a very long course. */
    public const int MAX_PAGE_BYTES = 2 * 1024 * 1024;

    private const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        private readonly OnlineCourseBundleReader $reader,
        private readonly FilesystemOperator $uploadsStorage,
        private readonly UploadIntake $intake,
        private readonly StagedUploadStore $stagedUploads,
        private readonly AntivirusScanner $antivirus,
        private readonly FileUploadService $fileUploads,
    ) {
    }

    /**
     * @throws OnlineCourseMaterialRefused when the archive is not one the reader accepts
     */
    public function publishArchive(OnlineCourseMaterial $material, UploadedFile|StagedUpload|FileLibraryNode $file): OnlineCourseMaterialRevision
    {
        $local = $this->intake->asLocalFile($file);
        $this->antivirus->assertClean($local->getPathname(), UploadIntake::originalName($file));

        // Everything is decided before the first write: a refused archive leaves nothing behind.
        $bundle = $this->reader->read($local->getPathname());

        // Hundreds of small objects, one request each: a real course takes longer than a page does.
        set_time_limit(600);

        $number = $material->nextRevisionNumber();
        $prefix = $material->storagePrefixFor($number);
        $written = 0;
        $keys = [];

        $zip = new \ZipArchive();
        if (true !== $zip->open($local->getPathname(), \ZipArchive::RDONLY)) {
            throw new OnlineCourseMaterialRefused('onlineCourseBundleNotAnArchiveMessage');
        }

        try {
            foreach ($bundle->files as $entry) {
                $key = $prefix.$entry['path'];
                $buffer = $this->unpack($zip, $entry['entry'], $entry['size']);

                try {
                    $this->uploadsStorage->writeStream($key, $buffer, [
                        'ContentType' => OnlineCourseBundleReader::contentTypeOf($entry['path']),
                        'CacheControl' => self::CACHE_CONTROL,
                    ]);
                } finally {
                    if (\is_resource($buffer)) {
                        fclose($buffer);
                    }
                }

                $keys[] = $key;
                $written += $entry['size'];
            }
        } catch (OnlineCourseMaterialRefused $refused) {
            // Through the one path that removes bytes on this platform, like every other removal:
            // what a refused archive did write is handed to the deferred purge.
            foreach ($keys as $key) {
                $this->fileUploads->delete($key);
            }

            throw $refused;
        } finally {
            $zip->close();
        }

        // The archive itself has done its work: nothing reads it again, the files are the course.
        if ($file instanceof StagedUpload) {
            $this->stagedUploads->discard($file);
        }

        return new OnlineCourseMaterialRevision(
            $material,
            $number,
            $prefix,
            $prefix.OnlineCourseBundleReader::ENTRY_POINT,
            UploadIntake::originalName($file),
            $written,
            $bundle->paths(),
        );
    }

    /**
     * One file of the archive, unpacked into a temporary stream that is exactly as long as the
     * archive's directory said it would be.
     *
     * The reader decided on declared sizes, and a hostile archive can declare anything: reading one
     * byte past the declaration is how a lie is caught, before a single byte reaches the bucket.
     *
     * @return resource
     */
    private function unpack(\ZipArchive $zip, string $entry, int $declaredSize)
    {
        $source = $zip->getStream($entry);
        $buffer = fopen('php://temp/maxmemory:2097152', 'w+');

        if (false === $source || false === $buffer) {
            throw new OnlineCourseMaterialRefused('onlineCourseBundleUnreadableFileMessage', ['%file%' => $entry]);
        }

        try {
            $copied = stream_copy_to_stream($source, $buffer, $declaredSize + 1);
        } finally {
            fclose($source);
        }

        if ($copied !== $declaredSize) {
            fclose($buffer);

            throw new OnlineCourseMaterialRefused('onlineCourseBundleUnreadableFileMessage', ['%file%' => $entry]);
        }

        rewind($buffer);

        return $buffer;
    }

    /**
     * An interactive course written as one page - what the Claude connector produces. It is the
     * archive of a single `index.html`, written by the same hand.
     *
     * @throws OnlineCourseMaterialRefused
     */
    public function publishPage(OnlineCourseMaterial $material, string $html): OnlineCourseMaterialRevision
    {
        if ('' === trim($html)) {
            throw new OnlineCourseMaterialRefused('onlineCoursePageEmptyMessage');
        }

        if (\strlen($html) > self::MAX_PAGE_BYTES) {
            throw new OnlineCourseMaterialRefused('onlineCourseMaterialTooLargeMessage', ['%max%' => '2 Mo']);
        }

        $number = $material->nextRevisionNumber();
        $prefix = $material->storagePrefixFor($number);
        $key = $prefix.OnlineCourseBundleReader::ENTRY_POINT;

        $this->uploadsStorage->write($key, $html, [
            'ContentType' => OnlineCourseBundleReader::EXTENSIONS['html'],
            'CacheControl' => self::CACHE_CONTROL,
        ]);

        return new OnlineCourseMaterialRevision($material, $number, $prefix, $key, OnlineCourseBundleReader::ENTRY_POINT, \strlen($html), [OnlineCourseBundleReader::ENTRY_POINT]);
    }
}
