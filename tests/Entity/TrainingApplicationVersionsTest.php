<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TrainingApplication;
use App\Entity\TrainingApplicationReview;
use App\Entity\TrainingApplicationVersion;
use App\Enum\TrainingApplicationDecision;
use App\Enum\TrainingApplicationElement;
use PHPUnit\Framework\TestCase;

/**
 * Which version a validator is judging, and which ones they may look back at (screen 8d).
 *
 * The association carries no ORM order, so both answers rest on the numbers alone. That is worth
 * pinning: a resend that reads the wrong version would show the validator the text they already
 * commented on, and nothing on the screen would say so.
 */
class TrainingApplicationVersionsTest extends TestCase
{
    private function applicationWith(int ...$numbers): TrainingApplication
    {
        $application = new TrainingApplication();

        foreach ($numbers as $number) {
            $application->addVersion((new TrainingApplicationVersion())->setNumber($number)->setBody('v'.$number));
        }

        return $application;
    }

    public function testTheCurrentVersionIsTheHighestNumberedOne(): void
    {
        // Added out of order on purpose: nothing sorts the collection on the way in.
        $application = $this->applicationWith(2, 1, 3);

        $current = $application->getCurrentVersion();

        self::assertNotNull($current);
        self::assertSame(3, $current->getNumber());
        self::assertSame(3, $application->getVersionNumber());
        self::assertSame('v3', $current->getBody());
    }

    public function testEarlierVersionsAreListedMostRecentFirstAndExcludeTheCurrentOne(): void
    {
        $application = $this->applicationWith(2, 1, 3);

        self::assertSame([2, 1], array_map(
            static fn (TrainingApplicationVersion $version): int => $version->getNumber(),
            $application->previousVersions(),
        ));
    }

    public function testAFirstSendHasNoHistoryToShow(): void
    {
        self::assertSame([], $this->applicationWith(1)->previousVersions());
    }

    public function testEarlierRemarksAreGroupedByVersionMostRecentFirst(): void
    {
        $application = $this->applicationWith(1, 2, 3);
        $this->review($application, 1, TrainingApplicationElement::Mail, TrainingApplicationDecision::Refused, 'mail v1');
        $this->review($application, 2, TrainingApplicationElement::Cv, TrainingApplicationDecision::Refused, 'cv v2');
        $this->review($application, 1, TrainingApplicationElement::Cv, TrainingApplicationDecision::Refused, 'cv v1');
        // A validation carries nothing to act on, and a remark on the version under review is the
        // standing one, shown back in the panel rather than in the history.
        $this->review($application, 2, TrainingApplicationElement::Mail, TrainingApplicationDecision::Validated, 'ok');
        $this->review($application, 3, TrainingApplicationElement::Cv, TrainingApplicationDecision::Refused, 'cv v3');

        $grouped = array_map(
            static fn (array $reviews): array => array_map(static fn (TrainingApplicationReview $review): ?string => $review->getRemark(), $reviews),
            $application->previousRemarksByVersion(),
        );

        // assertSame on arrays is strict about key order too: v2 must come before v1.
        self::assertSame([2 => ['cv v2'], 1 => ['mail v1', 'cv v1']], $grouped);
    }

    public function testAFirstSendHasNoEarlierRemarks(): void
    {
        $application = $this->applicationWith(1);
        $this->review($application, 1, TrainingApplicationElement::Mail, TrainingApplicationDecision::Refused, 'mail v1');

        self::assertSame([], $application->previousRemarksByVersion());
    }

    private function review(TrainingApplication $application, int $version, TrainingApplicationElement $element, TrainingApplicationDecision $decision, string $remark): void
    {
        $application->addReview((new TrainingApplicationReview())
            ->setVersionNumber($version)
            ->setElement($element)
            ->setDecision($decision)
            ->setRemark($remark));
    }
}
