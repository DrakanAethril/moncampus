<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\DocumentationAudience;
use App\Enum\DocumentationStatus;
use App\Service\DocumentationAccess;
use PHPUnit\Framework\TestCase;

/**
 * Who reads what in the documentation base.
 *
 * The rule is an AND of two halves that must never stand in for one another: an article says what
 * kind of person it is written for (audiences) *and* which section of the campus it is posted on
 * (perimeter). Widening one has never been meant to widen the other, and this is where that is
 * pinned down.
 */
class DocumentationAccessTest extends TestCase
{
    private DocumentationAccess $access;

    protected function setUp(): void
    {
        $this->access = new DocumentationAccess();
    }

    public function testManagerReadsEverything(): void
    {
        // Draft, addressed to nobody, posted nowhere: staff/staff-lead/admin still read it - they
        // are who repairs it.
        self::assertTrue($this->access->allows(
            DocumentationStatus::Draft, null, null, [], [], [], [], true, $this->now()
        ));
    }

    public function testReaderNeedsBothHalves(): void
    {
        $student = [DocumentationAudience::Student];

        // Right audience, right perimeter.
        self::assertTrue($this->allowsFor([9], [DocumentationAudience::Student], [9, 11], $student));
        // Right audience, wrong perimeter.
        self::assertFalse($this->allowsFor([14], [DocumentationAudience::Student], [9, 11], $student));
        // Right perimeter, wrong audience.
        self::assertFalse($this->allowsFor([9], [DocumentationAudience::Teacher], [9, 11], $student));
    }

    public function testAnArticlePostedNowhereOrAddressedToNobodyReachesNobody(): void
    {
        $student = [DocumentationAudience::Student];

        self::assertFalse($this->allowsFor([], [DocumentationAudience::Student], [9], $student));
        self::assertFalse($this->allowsFor([9], [], [9], $student));
    }

    public function testReaderWithNoPerimeterAtAllReadsNothing(): void
    {
        // A tutor, today: ROLE_TUTOR and nothing else. Naming "Tuteurs" in the visibility is not
        // enough - the perimeter half is unanswered, so the article stays out of reach.
        self::assertFalse($this->allowsFor([8], [DocumentationAudience::Tutor], [], [DocumentationAudience::Tutor]));
    }

    public function testOnlyPublishedArticlesAreReadable(): void
    {
        foreach ([DocumentationStatus::Draft, DocumentationStatus::Archived] as $status) {
            self::assertFalse($this->access->allows(
                $status, null, null, [9], [DocumentationAudience::Student], [9], [DocumentationAudience::Student], false, $this->now()
            ), $status->value.' must not be readable');
        }
    }

    public function testDiffusionWindowBoundsAPublishedArticle(): void
    {
        $now = $this->now();
        $before = $now->modify('-1 day');
        $after = $now->modify('+1 day');

        self::assertTrue($this->allowsWindow($before, $after, $now));
        self::assertFalse($this->allowsWindow($after, null, $now), 'not started yet');
        self::assertFalse($this->allowsWindow(null, $before, $now), 'already over');
        // An open bound on either side is what "Permanente" is made of.
        self::assertTrue($this->allowsWindow($before, null, $now));
        self::assertTrue($this->allowsWindow(null, $after, $now));
        self::assertTrue($this->allowsWindow(null, null, $now));
    }

    public function testWindowBoundsAreInclusive(): void
    {
        $now = $this->now();

        self::assertTrue($this->allowsWindow($now, null, $now));
        self::assertTrue($this->allowsWindow(null, $now, $now));
    }

    public function testAudiencesOfReadsTheRolesTheWayTheEnumDoes(): void
    {
        self::assertSame(
            [DocumentationAudience::Staff],
            $this->access->audiencesOf(['ROLE_SUPPORT-TECH', 'ROLE_CAMPUS']),
        );
        self::assertSame([], $this->access->audiencesOf(['ROLE_USER']));
    }

    public function testManagerIsStaffStaffLeadOrAdmin(): void
    {
        self::assertTrue($this->access->isManagerRole(['ROLE_STAFF']));
        self::assertTrue($this->access->isManagerRole(['ROLE_STAFF-LEAD']));
        self::assertTrue($this->access->isManagerRole(['ROLE_ADMIN']));
        self::assertFalse($this->access->isManagerRole(['ROLE_TEACHER', 'ROLE_CAMPUS']));
        self::assertFalse($this->access->isManagerRole(['ROLE_SUPPORT-TECH']));
    }

    /**
     * @param list<int>                    $articlePerimeterIds
     * @param list<DocumentationAudience>  $articleAudiences
     * @param list<int>                    $readerGroupIds
     * @param list<DocumentationAudience>  $readerAudiences
     */
    private function allowsFor(array $articlePerimeterIds, array $articleAudiences, array $readerGroupIds, array $readerAudiences): bool
    {
        return $this->access->allows(
            DocumentationStatus::Published,
            null,
            null,
            $articlePerimeterIds,
            $articleAudiences,
            $readerGroupIds,
            $readerAudiences,
            false,
            $this->now(),
        );
    }

    private function allowsWindow(?\DateTimeImmutable $start, ?\DateTimeImmutable $end, \DateTimeImmutable $now): bool
    {
        return $this->access->allows(
            DocumentationStatus::Published,
            $start,
            $end,
            [9],
            [DocumentationAudience::Student],
            [9],
            [DocumentationAudience::Student],
            false,
            $now,
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-15 10:00:00');
    }
}
