<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\ClassBoardPhoto;
use App\Repository\ClassBoardPhotoRepository;
use App\Service\AntivirusScanner;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;

/**
 * The photograph behind the « Photo » boards, a new one each day (design/validated/tableau-virtuel.md §3).
 *
 * fetch() and clean() are `app:class-board:photo`'s two halves; shown() is what a board reads. The
 * photograph is drawn at random from CommonsNaturePhotos' pool, never one shown in the last two
 * years, copied into the uploads bucket and served by the CDN like any upload: the classroom's
 * browser never asks Commons anything.
 */
class ClassBoardPhotoOfTheDay
{
    public const string PREFIX = 'class-board-photos/';
    public const string ORIGIN = 'class-board-photo';

    /**
     * Days a photograph's bytes outlive its own day. A board left open overnight still points at
     * yesterday's; beyond that nothing does.
     */
    public const int KEEP_DAYS = 2;

    /** No repeat within that many days - the pool holds about 1 200 files, about 400 of them fit. */
    public const int NO_REPEAT_DAYS = 730;

    /** Candidates examined per pass: two Commons queries of 50 at most. */
    private const int CANDIDATES = 100;

    public function __construct(
        private readonly CommonsNaturePhotos $commons,
        private readonly ClassBoardPhotoRepository $photos,
        private readonly FilesystemOperator $uploadsStorage,
        private readonly FileUploadService $fileUploads,
        private readonly AntivirusScanner $antivirus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
    }

    /**
     * The photograph a board shows now, or null - the board then falls back to the one shipped
     * with the application.
     */
    public function shown(): ?ClassBoardPhoto
    {
        return $this->photos->findShown(self::today());
    }

    /**
     * The day's photograph, fetched if it is not there yet. `$replace` draws another one for a day
     * that already has its own (the previous one's bytes are cleaned like any other).
     *
     * @return array{ClassBoardPhoto, bool} the photograph, and whether this call fetched it
     *
     * @throws CommonsUnavailableException
     */
    public function fetch(\DateTimeImmutable $day, bool $replace = false): array
    {
        $existing = $this->photos->findForDay($day);
        if (null !== $existing && !$replace) {
            return [$existing, false];
        }

        $photo = $this->draw($day);
        $bytes = $this->commons->download($photo);
        $this->assertClean($bytes, $photo->title);

        $key = sprintf('%s%s-%s.jpg', self::PREFIX, $day->format('Y-m-d'), bin2hex(random_bytes(6)));
        $this->uploadsStorage->write($key, $bytes, [
            'ContentType' => 'image/jpeg',
            // A new key at every photograph, so the CDN may keep each one for good.
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        if (null !== $existing) {
            $this->forget($existing);
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        $row = new ClassBoardPhoto($day, $photo->title, $key, $photo->author, $photo->licenseName, $photo->licenseUrl, $photo->sourceUrl);
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return [$row, true];
    }

    /**
     * Deletes the bytes of the photographs older than KEEP_DAYS. The rows stay: they are what keeps
     * a photograph from coming back.
     *
     * @return int how many were cleaned
     */
    public function clean(\DateTimeImmutable $today): int
    {
        $stale = $this->photos->findStoredBefore($today->modify(sprintf('-%d days', self::KEEP_DAYS)));
        foreach ($stale as $photo) {
            $this->forget($photo);
        }
        $this->entityManager->flush();

        return \count($stale);
    }

    /**
     * @throws CommonsUnavailableException
     */
    private function draw(\DateTimeImmutable $day): CommonsPhoto
    {
        $titles = $this->commons->titles();
        if ([] === $titles) {
            throw new CommonsUnavailableException('The Commons pool is empty.');
        }

        $shown = array_flip($this->photos->titlesShownSince($day->modify(sprintf('-%d days', self::NO_REPEAT_DAYS))));
        $fresh = array_values(array_filter($titles, static fn (string $title): bool => !isset($shown[$title])));
        // The whole pool shown within the window: start again rather than show nothing.
        $candidates = [] === $fresh ? $titles : $fresh;
        shuffle($candidates);

        foreach (array_chunk(\array_slice($candidates, 0, self::CANDIDATES), 50) as $batch) {
            $photos = $this->commons->photographs($batch);
            if ([] !== $photos) {
                return $photos[0];
            }
        }

        throw new CommonsUnavailableException(sprintf('No photograph a board can show among %d candidates.', min(\count($candidates), self::CANDIDATES)));
    }

    /**
     * Every way into the bucket scans (tests/Service/BucketWritePathsTest.php), a file fetched from
     * Commons included: it is still bytes nobody here composed. A refusal is thrown on - the pass
     * fails loudly, and the boards keep the last photograph.
     */
    private function assertClean(string $bytes, string $title): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cbphoto') ?: throw new \RuntimeException('Could not create a temporary file.');

        try {
            file_put_contents($path, $bytes);
            $this->antivirus->assertClean($path, $title);
        } finally {
            @unlink($path);
        }
    }

    private function forget(ClassBoardPhoto $photo): void
    {
        $key = $photo->getStorageKey();
        if (null !== $key) {
            $this->fileUploads->delete($key, self::ORIGIN);
            $photo->forgetBytes();
        }
    }
}
