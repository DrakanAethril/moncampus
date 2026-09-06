<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\WordCloudModerationState;
use App\Repository\WordCloudSubmissionRepository;

/**
 * One reading of a cloud's submissions, serving the pilot preview, both projections and the live
 * payload that refreshes them.
 *
 * There is a single reading on purpose: the meta line on the pilot screen and the one at the top of
 * the board must not be able to disagree, and the surest way for them to disagree is to be counted
 * twice. Everything below - the cloud itself, « Les plus cités », « Derniers arrivés », the
 * participation figures - comes out of one pass over the same rows.
 *
 * @phpstan-type WordCloudWord array{word: string, count: int}
 * @phpstan-type WordCloudLatest array{text: string, author: string}
 * @phpstan-type WordCloudTop array{word: string, count: int, share: int}
 */
class WordCloudBoard
{
    /** « Derniers mots » on the pilot screen, « Derniers arrivés » on the board. */
    private const int LATEST_SIZE = 5;

    /** « Les plus cités ». */
    private const int TOP_SIZE = 5;

    public function __construct(
        private readonly WordCloudSubmissionRepository $submissions,
        private readonly WordCloudAggregator $aggregator,
    ) {
    }

    public function build(WordCloud $cloud): WordCloudBoardData
    {
        return $this->buildFrom($cloud, $this->submissions->findForCloud($cloud));
    }

    /**
     * @param list<WordCloudSubmission> $submissions every row of the cloud, chronological
     */
    public function buildFrom(WordCloud $cloud, array $submissions): WordCloudBoardData
    {
        $counted = array_values(array_filter($submissions, static fn (WordCloudSubmission $one): bool => $one->isCounted()));

        $words = $this->aggregator->aggregate(array_map(
            fn (WordCloudSubmission $one): array => [
                'text' => (string) $one->getText(),
                'key' => $this->aggregator->key((string) $one->getText(), $cloud->isGroupVariants()),
            ],
            $counted,
        ));

        // Participants are counted on everything submitted, refused words included: somebody who
        // wrote and had their word turned down did take part, and telling them otherwise on the
        // « N'ont pas participé » list would be plainly wrong.
        $participantIds = [];
        foreach ($submissions as $submission) {
            $participantIds[(int) $submission->getStudent()?->getId()] = true;
        }

        $latest = array_map(
            static fn (WordCloudSubmission $one): array => [
                'text' => (string) $one->getText(),
                'author' => self::shortName($one->getStudent()),
            ],
            \array_slice(array_reverse($counted), 0, self::LATEST_SIZE),
        );

        $max = $words[0]['count'] ?? 0;
        $top = array_map(
            static fn (array $word): array => [
                'word' => $word['word'],
                'count' => $word['count'],
                'share' => $max > 0 ? (int) round($word['count'] / $max * 100) : 0,
            ],
            \array_slice($words, 0, self::TOP_SIZE),
        );

        return new WordCloudBoardData(
            $words,
            $latest,
            $top,
            \count($words),
            \count($counted),
            \count($participantIds),
            \count(array_filter(
                $submissions,
                static fn (WordCloudSubmission $one): bool => WordCloudModerationState::Pending === $one->getModerationState(),
            )),
        );
    }

    /** « Inès M. » - the flow of words is read at a glance, and a full name would fill the chip. */
    private static function shortName(?User $student): string
    {
        if (null === $student) {
            return '';
        }

        return $student->getShortDisplayName() ?? $student->getUsername();
    }
}
