<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier;

use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\DossierReview;
use App\Entity\DossierSubmission;
use App\Entity\User;
use App\Enum\DossierDepositType;
use App\Enum\DossierDocumentStatus;
use App\Enum\DossierReviewAction;
use App\Enum\DossierValidationProfile;
use App\Service\Dossier\DossierStatusResolver;
use PHPUnit\Framework\TestCase;

/**
 * The seven derived statuses, and the two rules everything else on this feature is drawn from.
 *
 * The statuses are computed from the dates and the dépôts, never stored, so the cases below are all
 * a matter of moving « today » rather than of writing a column - which is the point: six of the
 * seven change without anybody touching a row.
 */
class DossierStatusResolverTest extends TestCase
{
    private const string TODAY = '2026-10-10';

    private function document(
        string $visibleFrom = '2026-10-01',
        string $dueOn = '2026-10-15',
        bool $lateAllowed = false,
        DossierValidationProfile $profile = DossierValidationProfile::Deposit,
    ): DossierDocument {
        return (new DossierDocument(new Dossier()))
            ->setName('Rapport de stage')
            ->setDepositType(DossierDepositType::Upload)
            ->setVisibleFrom(new \DateTimeImmutable($visibleFrom))
            ->setDueOn(new \DateTimeImmutable($dueOn))
            ->setLateAllowed($lateAllowed)
            ->setValidationProfile($profile);
    }

    private function submission(DossierDocument $document, int $version = 1): DossierSubmission
    {
        return DossierSubmission::ofFile($document, new User('student'), $version, 'k', 'rapport.pdf', 1024);
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }

    private function resolve(DossierDocument $document, array $submissions): DossierDocumentStatus
    {
        return (new DossierStatusResolver())->resolve($document, $submissions, $this->today());
    }

    public function testADocumentNotYetVisibleDoesNotExistForTheCible(): void
    {
        self::assertSame(
            DossierDocumentStatus::Upcoming,
            $this->resolve($this->document(visibleFrom: '2026-10-20', dueOn: '2026-11-01'), []),
        );
    }

    public function testVisibilityStartsOnItsOwnDayAndNotTheDayAfter(): void
    {
        self::assertSame(DossierDocumentStatus::Missing, $this->resolve($this->document(visibleFrom: self::TODAY), []));
    }

    public function testNothingHandedInBeforeTheDeadlineIsMissing(): void
    {
        self::assertSame(DossierDocumentStatus::Missing, $this->resolve($this->document(), []));
    }

    public function testTheDeadlineDayItselfIsNotLate(): void
    {
        self::assertSame(DossierDocumentStatus::Missing, $this->resolve($this->document(dueOn: self::TODAY), []));
    }

    public function testNothingHandedInAfterTheDeadlineIsLate(): void
    {
        self::assertSame(DossierDocumentStatus::Late, $this->resolve($this->document(dueOn: '2026-10-09'), []));
    }

    public function testAPlainDocumentIsSettledTheMomentItIsDeposited(): void
    {
        $document = $this->document();

        self::assertSame(DossierDocumentStatus::Deposited, $this->resolve($document, [$this->submission($document)]));
    }

    public function testTheSameDepositOnADocumentInValidationIsNotSettled(): void
    {
        $document = $this->document(profile: DossierValidationProfile::Validation);

        self::assertSame(DossierDocumentStatus::AwaitingReview, $this->resolve($document, [$this->submission($document)]));
    }

    public function testTheLastReviewDecides(): void
    {
        $document = $this->document(profile: DossierValidationProfile::Validation);
        $submission = $this->submission($document);
        new DossierReview($submission, new User('validator'), DossierReviewAction::CorrectionRequested, 'À reprendre.');

        self::assertSame(DossierDocumentStatus::ToCorrect, $this->resolve($document, [$submission]));

        new DossierReview($submission, new User('validator'), DossierReviewAction::Validated, null);

        self::assertSame(DossierDocumentStatus::Validated, $this->resolve($document, [$submission]));
    }

    public function testANewVersionGoesBackIntoTheQueueRatherThanKeepingTheOldVerdict(): void
    {
        $document = $this->document(profile: DossierValidationProfile::Validation);
        $first = $this->submission($document, 1);
        new DossierReview($first, new User('validator'), DossierReviewAction::CorrectionRequested, 'À reprendre.');
        $second = $this->submission($document, 2);

        // Read on the latest version only: v2 carries no échange, so the cell is awaiting a
        // validateur again - which is exactly what the queue is.
        self::assertSame(DossierDocumentStatus::AwaitingReview, $this->resolve($document, [$first, $second]));
    }

    public function testTheLatestVersionIsTheHighestOneWhateverOrderItIsHandedIn(): void
    {
        $document = $this->document();
        $second = $this->submission($document, 2);

        self::assertSame($second, DossierStatusResolver::latest([$second, $this->submission($document, 1)]));
        self::assertNull(DossierStatusResolver::latest([]));
    }

    public function testDepositsCloseOnTheDeadlineUnlessTheDocumentSaysOtherwise(): void
    {
        $resolver = new DossierStatusResolver();

        self::assertTrue($resolver->acceptsSubmission($this->document(dueOn: '2026-10-15'), [], $this->today()));
        self::assertFalse($resolver->acceptsSubmission($this->document(dueOn: '2026-10-09'), [], $this->today()));
        self::assertTrue($resolver->acceptsSubmission($this->document(dueOn: '2026-10-09', lateAllowed: true), [], $this->today()));
    }

    public function testACorrectionRequestReopensADocumentTheDeadlineHadClosed(): void
    {
        $document = $this->document(dueOn: '2026-10-09', profile: DossierValidationProfile::Validation);
        $submission = $this->submission($document);
        new DossierReview($submission, new User('validator'), DossierReviewAction::CorrectionRequested, 'À reprendre.');

        // Asking for a correction the cible cannot then hand in is asking for nothing.
        self::assertTrue((new DossierStatusResolver())->acceptsSubmission($document, [$submission], $this->today()));
    }

    public function testNothingIsAcceptedBeforeVisibilityOrAfterValidation(): void
    {
        $resolver = new DossierStatusResolver();

        self::assertFalse($resolver->acceptsSubmission($this->document(visibleFrom: '2026-10-20', dueOn: '2026-11-01'), [], $this->today()));

        $document = $this->document(profile: DossierValidationProfile::Validation);
        $submission = $this->submission($document);
        new DossierReview($submission, new User('validator'), DossierReviewAction::Validated, null);

        self::assertFalse($resolver->acceptsSubmission($document, [$submission], $this->today()));
    }

    public function testACorrectionRequestMustSayWhatIsToBeCorrected(): void
    {
        $document = $this->document(profile: DossierValidationProfile::Validation);

        $this->expectException(\InvalidArgumentException::class);
        new DossierReview($this->submission($document), new User('validator'), DossierReviewAction::CorrectionRequested, '   ');
    }
}
