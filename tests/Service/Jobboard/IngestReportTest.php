<?php

declare(strict_types=1);

namespace App\Tests\Service\Jobboard;

use App\Service\Jobboard\IngestLine;
use App\Service\Jobboard\IngestOutcome;
use App\Service\Jobboard\IngestReport;
use App\Service\Jobboard\JobboardRejection;
use PHPUnit\Framework\TestCase;

/**
 * What a deposit says about itself, and to whom.
 *
 * The blacklist is the only thing on this report that is told to one reader and not the other: the
 * import screen names the sites it dropped, the API's answer does not mention them at all. These
 * tests pin that asymmetry, because a `blocked` key appearing in the JSON would look harmless and
 * would tell a collecting agent to stop collecting a site somebody may want back tomorrow.
 */
class IngestReportTest extends TestCase
{
    public function testTheTotalsCountEachOutcomeApart(): void
    {
        $report = $this->report();

        $this->assertSame(1, $report->created());
        $this->assertSame(1, $report->reviewed());
        $this->assertSame(1, $report->rejected());
        $this->assertSame(2, $report->blocked());
    }

    public function testTheApiAnswerNeitherCountsNorEchoesABlockedLine(): void
    {
        $answer = $this->report()->toArray();

        $this->assertArrayNotHasKey('blocked', $answer);
        $this->assertIsArray($answer['offers']);
        // Three lines out of five: the two blocked ones are not there at all, not even as an
        // outcome the agent could learn to ignore.
        $this->assertCount(3, $answer['offers']);
        $this->assertSame([0, 1, 2], array_column($answer['offers'], 'index'));
    }

    /** Named once each, in the order they were met - it is a list of sites, not of lines. */
    public function testTheBlockedSitesAreNamedOnceEach(): void
    {
        $this->assertSame(['aliptic'], $this->report()->blockedSources());
        $this->assertCount(2, $this->report()->blockedLines());
    }

    private function report(): IngestReport
    {
        return new IngestReport([
            new IngestLine(0, IngestOutcome::Created, 'hellowork', '1'),
            new IngestLine(1, IngestOutcome::Reviewed, 'hellowork', '2'),
            new IngestLine(2, IngestOutcome::Rejected, reason: JobboardRejection::InvalidUrl, field: 'url'),
            new IngestLine(3, IngestOutcome::Blocked, 'aliptic', '3'),
            new IngestLine(4, IngestOutcome::Blocked, 'aliptic', '4'),
        ]);
    }
}
