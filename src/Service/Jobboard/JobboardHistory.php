<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardBatch;
use App\Entity\JobboardToken;
use App\Entity\Section;
use App\Repository\JobboardBatchRepository;
use App\Repository\JobboardTokenRepository;

/**
 * What « Configuration > Jobboard > Historique » reads: every deposit of offers, most recent first.
 *
 * **The two doors are not counted at the same grain, and that is the whole design.** A collecting
 * pass is a machine's routine - three batches in a morning are one veille, and printing them as
 * three lines would make an unremarkable day look like an incident - so the API side is grouped by
 * (key, day). An import is a gesture somebody made, so it keeps its own line, with its hour and its
 * author. The counts are the same four either way, which is what lets the two sit in one table.
 *
 * Nothing here recomputes anything: the four figures were tallied when the offers were filed, and
 * a row whose key has since been revoked still reads - a key is never deleted, precisely so this
 * screen stays true.
 *
 * @phpstan-type JobboardHistoryRow array{kind: 'api'|'import', at: \DateTimeImmutable, dayOnly: bool, token: ?JobboardToken, batch: ?JobboardBatch, section: Section, passes: int, created: int, reviewed: int, closed: int, rejected: int}
 */
final readonly class JobboardHistory
{
    /** Beyond this the screen would be a data export, which is not what it is for. */
    public const int DEFAULT_LIMIT = 200;

    public function __construct(
        private JobboardBatchRepository $batches,
        private JobboardTokenRepository $tokens,
    ) {
    }

    /**
     * @return list<JobboardHistoryRow>
     */
    public function rows(int $limit = self::DEFAULT_LIMIT): array
    {
        $rows = [...$this->apiRows($limit), ...$this->importRows($limit)];

        usort($rows, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return \array_slice($rows, 0, $limit);
    }

    /**
     * @return list<JobboardHistoryRow>
     */
    private function apiRows(int $limit): array
    {
        $tallies = $this->batches->dayTallies($limit);

        if ([] === $tallies) {
            return [];
        }

        $known = [];
        foreach ($this->tokens->findBy(['id' => array_column($tallies, 'tokenId')]) as $token) {
            $known[(int) $token->getId()] = $token;
        }

        $rows = [];
        foreach ($tallies as $tally) {
            // A key deleted rather than revoked would land here. It is not supposed to happen -
            // revoking is the documented gesture - and dropping the line is better than printing
            // a filière nobody can name.
            $token = $known[$tally['tokenId']] ?? null;

            if (!$token instanceof JobboardToken) {
                continue;
            }

            $rows[] = [
                'kind' => 'api',
                'at' => $tally['lastAt'],
                'dayOnly' => true,
                'token' => $token,
                'batch' => null,
                'section' => $token->getSection(),
                'passes' => $tally['passes'],
                'created' => $tally['created'],
                'reviewed' => $tally['reviewed'],
                'closed' => $tally['closed'],
                'rejected' => $tally['rejected'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<JobboardHistoryRow>
     */
    private function importRows(int $limit): array
    {
        $rows = [];
        foreach ($this->batches->findImports($limit) as $batch) {
            $rows[] = [
                'kind' => 'import',
                'at' => $batch->getOpenedAt(),
                'dayOnly' => false,
                'token' => null,
                'batch' => $batch,
                'section' => $batch->getSection(),
                'passes' => 1,
                'created' => $batch->getCreatedCount(),
                'reviewed' => $batch->getReviewedCount(),
                'closed' => $batch->getClosedCount(),
                'rejected' => $batch->getRejectedCount(),
            ];
        }

        return $rows;
    }
}
