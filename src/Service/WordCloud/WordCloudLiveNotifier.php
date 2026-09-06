<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\WordCloud;
use App\Enum\WordCloudScale;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Pushes a cloud's new shape to whoever is watching it - the pilot screen and the board.
 *
 * The payload carries the words **already laid out**, at all three scales, rather than raw counts:
 * the weighting rule then exists once, in PHP, and a browser cannot draw a cloud the server would
 * have drawn differently. It costs a few hundred bytes and removes an entire class of "the
 * projection and the preview disagree" bug.
 *
 * Published on a private topic and read through the bundle's cookie mechanism (see
 * App\Controller\WordCloud\PilotController), for the same reason as the live quiz: an EventSource
 * cannot send an Authorization header, and no token should ever reach page JS.
 */
class WordCloudLiveNotifier
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly WordCloudBoard $board,
        private readonly WordCloudWeighting $weighting,
    ) {
    }

    public function topic(WordCloud $cloud): string
    {
        return \sprintf('/word-clouds/%d', (int) $cloud->getId());
    }

    public function publish(WordCloud $cloud): void
    {
        $this->hub->publish(new Update(
            $this->topic($cloud),
            json_encode($this->snapshot($cloud), \JSON_THROW_ON_ERROR),
            true,
        ));
    }

    /**
     * @return array{
     *     words: array<string, list<array{word: string, count: int, size: int, step: string}>>,
     *     latest: list<array{text: string, author: string}>,
     *     top: list<array{word: string, count: int, share: int}>,
     *     distinctWords: int,
     *     submissionCount: int,
     *     participantCount: int,
     *     pendingCount: int
     * }
     */
    public function snapshot(WordCloud $cloud): array
    {
        return $this->payload($this->board->build($cloud));
    }

    /**
     * @return array{
     *     words: array<string, list<array{word: string, count: int, size: int, step: string}>>,
     *     latest: list<array{text: string, author: string}>,
     *     top: list<array{word: string, count: int, share: int}>,
     *     distinctWords: int,
     *     submissionCount: int,
     *     participantCount: int,
     *     pendingCount: int
     * }
     */
    public function payload(WordCloudBoardData $data): array
    {
        $laidOut = [];
        foreach (WordCloudScale::cases() as $scale) {
            $laidOut[$scale->value] = $this->weighting->layout($data->words, $scale);
        }

        return [
            'words' => $laidOut,
            'latest' => $data->latest,
            'top' => $data->top,
            'distinctWords' => $data->distinctWords,
            'submissionCount' => $data->submissionCount,
            'participantCount' => $data->participantCount,
            'pendingCount' => $data->pendingCount,
        ];
    }
}
