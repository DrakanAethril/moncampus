<?php

declare(strict_types=1);

namespace App\Tests\Service\WordCloud;

use App\Entity\WordCloud;
use App\Service\WordCloud\WordCloudBoard;
use App\Service\WordCloud\WordCloudBoardData;
use App\Service\WordCloud\WordCloudLiveNotifier;
use App\Service\WordCloud\WordCloudWeighting;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Telling the board must never cost the word it is reporting on.
 *
 * The submission is committed before the notifier runs, so a hub that is down used to answer 500 to
 * a student whose word had in fact been taken - the worst of both, since they would write it again
 * and be told they already had. A board that missed an update catches up on its next load.
 *
 * Found by CI rather than by design, and reproduced locally against an unreachable hub before being
 * fixed: six functional tests answered 500 on a green working tree.
 */
class WordCloudLiveNotifierTest extends TestCase
{
    public function testAHubThatIsDownDoesNotBreakTheSubmissionItReportsOn(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('Connection refused'));

        $logger = $this->createMock(LoggerInterface::class);
        // Swallowed, never silent: a board that stops updating has to be findable in the logs.
        $logger->expects(self::once())->method('warning');

        $this->notifier($hub, $logger)->publish($this->cloud());
    }

    public function testAnOrdinaryPublishGoesOutOnTheCloudsOwnTopic(): void
    {
        $published = [];

        // A stub, not a mock: what is asserted is the Update that came out, captured below, not a
        // call count on the hub.
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = $update;

            return 'id';
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->notifier($hub, $logger)->publish($this->cloud());

        self::assertCount(1, $published);
        self::assertSame(['/word-clouds/7'], $published[0]->getTopics());
        // Private: the board is a class's own words, not something a passer-by subscribes to.
        self::assertTrue($published[0]->isPrivate());
    }

    private function notifier(HubInterface $hub, LoggerInterface $logger): WordCloudLiveNotifier
    {
        $board = $this->createStub(WordCloudBoard::class);
        $board->method('build')->willReturn(new WordCloudBoardData([], [], [], 0, 0, 0, 0));

        return new WordCloudLiveNotifier($hub, $board, new WordCloudWeighting(), $logger);
    }

    private function cloud(): WordCloud
    {
        $cloud = $this->createStub(WordCloud::class);
        $cloud->method('getId')->willReturn(7);

        return $cloud;
    }
}
