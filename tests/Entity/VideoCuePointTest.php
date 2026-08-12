<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Entity\VideoCuePoint;
use App\Entity\VideoResourceFile;
use PHPUnit\Framework\TestCase;

/**
 * The marker itself: where it sits, how it behaves, and the passage it sends a student back to.
 *
 * That last one is the point of the whole feature (créas 5B, screen 4): a wrong answer produces
 * thirty seconds to watch again, not a mark.
 */
final class VideoCuePointTest extends TestCase
{
    public function testDefaultsPauseWithoutBlocking(): void
    {
        $cue = $this->cue(340);

        // Decided on 2026-08-12: the video stops so the question can be read, but a wrong answer
        // does not hold the student there. Blocking exists per marker and is off.
        self::assertTrue($cue->isPauseVideo());
        self::assertFalse($cue->isBlocking());
    }

    public function testTimecodeNeverGoesBelowZero(): void
    {
        self::assertSame(0, $this->cue(-30)->getTimecodeSeconds());
    }

    public function testReplayStartsAheadOfTheQuestion(): void
    {
        $cue = $this->cue(340);

        self::assertSame(340 - VideoCuePoint::REPLAY_LEAD_SECONDS, $cue->getReplayFromSeconds());
    }

    public function testReplayOfAnEarlyQuestionStartsAtTheBeginning(): void
    {
        // A question at 0:10 has no thirty seconds behind it - sending the player to a negative
        // position would either be refused or silently land at 0 anyway.
        self::assertSame(0, $this->cue(10)->getReplayFromSeconds());
    }

    public function testFormattedTimecodeIsWhatTheTimelineDraws(): void
    {
        self::assertSame('5:40', $this->cue(340)->getFormattedTimecode());
    }

    private function cue(int $timecodeSeconds): VideoCuePoint
    {
        $question = new QuizQuestion(new QuizTemplate(new User('stharaud')));
        $question->setLabel('À quoi sert un VLAN ?');

        return new VideoCuePoint(new VideoResourceFile('video/1.mp4', 0), $question, $timecodeSeconds);
    }
}
