<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardBatch;
use App\Entity\JobboardSourceLearning;
use App\Entity\JobboardToken;
use App\Entity\Track;
use App\Repository\JobboardBatchRepository;
use App\Repository\JobboardSourceLearningRepository;
use App\Repository\JobboardTokenRepository;

/**
 * What « Configuration > Jobboard > Historique » reads: every deposit of offers, most recent first.
 *
 * **The two doors are not counted at the same grain, and that is the whole design.** A collecting
 * pass is a machine's routine - three batches in a morning are one veille, and printing them as
 * three lines would make an unremarkable day look like an incident - so the API side is grouped by
 * (key, day). An import is a gesture somebody made, so it keeps its own line, with its hour and its
 * author. The counts are the same five either way, which is what lets the two sit in one table -
 * « Bloquées » being the one that is read after a decision rather than before: it is where the
 * offers of a site somebody put on the blacklist go.
 *
 * Nothing here recomputes anything: the five figures were tallied when the offers were filed, and
 * a row whose key has since been revoked still reads - a key is never deleted, precisely so this
 * screen stays true.
 *
 * The screen also reads `learnings()`, which is not a sixth figure but a second list: what the
 * source resolution decided by itself. It sits apart because it is not counted at either grain -
 * one site created can serve four hundred offers over three passes.
 *
 * @phpstan-type JobboardHistoryRow array{kind: 'api'|'import', at: \DateTimeImmutable, dayOnly: bool, token: ?JobboardToken, batch: ?JobboardBatch, track: Track, passes: int, created: int, reviewed: int, closed: int, rejected: int, blocked: int}
 */
final readonly class JobboardHistory
{
    /** Beyond this the screen would be a data export, which is not what it is for. */
    public const int DEFAULT_LIMIT = 200;

    /** Enough to see a habit forming without turning the screen into an export of its own. */
    public const int LEARNING_LIMIT = 50;

    public function __construct(
        private JobboardBatchRepository $batches,
        private JobboardTokenRepository $tokens,
        private JobboardSourceLearningRepository $learnings,
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
     * What the resolution decided on its own, most recent first - a site it created, a domain it
     * attached to a known site.
     *
     * Listed rather than counted, and beside the deposits rather than inside them, because a number
     * in a column would say « ce passage a appris 2 choses » and nothing an administrator can act
     * on. What is actionable is the name: reading « domaine `bit.ly` rattaché à HelloWork, déclaré
     * hellowork » is what makes a wrong attachment obvious, and the sources screen is two clicks
     * away.
     *
     * @return list<JobboardSourceLearning>
     */
    public function learnings(int $limit = self::LEARNING_LIMIT): array
    {
        return $this->learnings->findLatest($limit);
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
                'track' => $token->getTrack(),
                'passes' => $tally['passes'],
                'created' => $tally['created'],
                'reviewed' => $tally['reviewed'],
                'closed' => $tally['closed'],
                'rejected' => $tally['rejected'],
                'blocked' => $tally['blocked'],
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
                'track' => $batch->getTrack(),
                'passes' => 1,
                'created' => $batch->getCreatedCount(),
                'reviewed' => $batch->getReviewedCount(),
                'closed' => $batch->getClosedCount(),
                'rejected' => $batch->getRejectedCount(),
                'blocked' => $batch->getBlockedCount(),
            ];
        }

        return $rows;
    }
}
