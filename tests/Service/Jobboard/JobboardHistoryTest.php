<?php

declare(strict_types=1);

namespace App\Tests\Service\Jobboard;

use App\Entity\JobboardBatch;
use App\Entity\JobboardToken;
use App\Entity\Track;
use App\Repository\JobboardBatchRepository;
use App\Repository\JobboardSourceLearningRepository;
use App\Repository\JobboardTokenRepository;
use App\Service\Jobboard\JobboardHistory;
use PHPUnit\Framework\TestCase;

/**
 * The one rule this screen rests on: **the two doors are not counted at the same grain.** A
 * collecting pass is a routine, so a day of it is one line whatever the number of batches; an
 * import is a gesture somebody made, so it keeps its own line and its own hour.
 *
 * Everything else here is ordering - most recent first, the two kinds interleaved - and the fact
 * that a tally whose key can no longer be named is dropped rather than printed anonymously.
 */
class JobboardHistoryTest extends TestCase
{
    public function testACollectingDayIsOneLineAndAnImportIsAnother(): void
    {
        $token = $this->token(7, 'Veille SIO');

        $history = $this->history(
            tallies: [$this->tally(7, '2026-09-12', passes: 3, created: 12, reviewed: 87, closed: 4, rejected: 1)],
            tokens: [$token],
            imports: [$this->import('2026-09-11 14:32:00', created: 3, reviewed: 41, rejected: 6)],
        );

        $rows = $history->rows();

        $this->assertCount(2, $rows);

        $this->assertSame('api', $rows[0]['kind']);
        $this->assertTrue($rows[0]['dayOnly']);
        $this->assertSame(3, $rows[0]['passes']);
        $this->assertSame([12, 87, 4, 1], [$rows[0]['created'], $rows[0]['reviewed'], $rows[0]['closed'], $rows[0]['rejected']]);
        $this->assertSame($token, $rows[0]['token']);

        // The hour is kept on this one, and only on this one: it is when somebody pressed Importer.
        $this->assertSame('import', $rows[1]['kind']);
        $this->assertFalse($rows[1]['dayOnly']);
        $this->assertSame(1, $rows[1]['passes']);
        $this->assertSame([3, 41, 0, 6], [$rows[1]['created'], $rows[1]['reviewed'], $rows[1]['closed'], $rows[1]['rejected']]);
    }

    public function testTheTwoKindsAreInterleavedMostRecentFirst(): void
    {
        $history = $this->history(
            tallies: [
                $this->tally(7, '2026-09-12', lastAt: '2026-09-12 06:05:00'),
                $this->tally(7, '2026-09-10', lastAt: '2026-09-10 06:05:00'),
            ],
            tokens: [$this->token(7, 'Veille SIO')],
            imports: [$this->import('2026-09-11 14:32:00')],
        );

        $this->assertSame(
            ['2026-09-12', '2026-09-11', '2026-09-10'],
            array_map(static fn (array $row): string => $row['at']->format('Y-m-d'), $history->rows()),
        );
    }

    public function testATallyWhoseKeyCannotBeNamedIsDropped(): void
    {
        // Revoking is the documented gesture and keeps the row, so this is not supposed to happen;
        // dropping the line is still better than printing a filière nobody can name.
        $history = $this->history(
            tallies: [$this->tally(7, '2026-09-12')],
            tokens: [],
            imports: [],
        );

        $this->assertSame([], $history->rows());
    }

    public function testTheLimitIsAppliedToTheMergedList(): void
    {
        $history = $this->history(
            tallies: [
                $this->tally(7, '2026-09-12', lastAt: '2026-09-12 06:05:00'),
                $this->tally(7, '2026-09-10', lastAt: '2026-09-10 06:05:00'),
            ],
            tokens: [$this->token(7, 'Veille SIO')],
            imports: [$this->import('2026-09-11 14:32:00')],
        );

        $rows = $history->rows(2);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-12', $rows[0]['at']->format('Y-m-d'));
        $this->assertSame('2026-09-11', $rows[1]['at']->format('Y-m-d'));
    }

    /**
     * @param list<array<string, mixed>> $tallies
     * @param list<JobboardToken>        $tokens
     * @param list<JobboardBatch>        $imports
     */
    private function history(array $tallies, array $tokens, array $imports): JobboardHistory
    {
        $batches = $this->createStub(JobboardBatchRepository::class);
        $batches->method('dayTallies')->willReturn($tallies);
        $batches->method('findImports')->willReturn($imports);

        $keys = $this->createStub(JobboardTokenRepository::class);
        $keys->method('findBy')->willReturn($tokens);

        return new JobboardHistory($batches, $keys, $this->createStub(JobboardSourceLearningRepository::class));
    }

    /** @return array<string, mixed> */
    private function tally(
        int $tokenId,
        string $day,
        ?string $lastAt = null,
        int $passes = 1,
        int $created = 0,
        int $reviewed = 0,
        int $closed = 0,
        int $rejected = 0,
    ): array {
        return [
            'tokenId' => $tokenId,
            'day' => $day,
            'passes' => $passes,
            'created' => $created,
            'reviewed' => $reviewed,
            'closed' => $closed,
            'rejected' => $rejected,
            'lastAt' => new \DateTimeImmutable($lastAt ?? $day.' 06:05:00'),
        ];
    }

    private function token(int $id, string $label): JobboardToken
    {
        $token = new JobboardToken($label, $this->createStub(Track::class), 'selector0001', 'hash', null);
        (new \ReflectionProperty($token, 'id'))->setValue($token, $id);

        return $token;
    }

    private function import(string $at, int $created = 0, int $reviewed = 0, int $rejected = 0): JobboardBatch
    {
        $batch = JobboardBatch::forImport($this->createStub(Track::class), null);
        (new \ReflectionProperty($batch, 'openedAt'))->setValue($batch, new \DateTimeImmutable($at));
        $batch->tally($created, $reviewed, $rejected);

        return $batch;
    }
}
