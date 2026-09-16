<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier;

use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\DossierReview;
use App\Entity\DossierSubmission;
use App\Entity\User;
use App\Enum\DossierDocumentStatus;
use App\Enum\DossierReviewAction;
use App\Enum\DossierValidationProfile;
use App\Repository\DossierSubmissionRepository;
use App\Service\Dossier\DossierBoardBuilder;
use App\Service\Dossier\DossierStatusResolver;
use App\Service\Dossier\DossierTargetResolver;
use PHPUnit\Framework\TestCase;

/**
 * The board every screen of the feature reads, and the one arithmetic it carries: **the avancement
 * is over the documents obligatoires only**.
 *
 * A facultative piece nobody handed in must not stop a dossier reading as complete, and one that was
 * handed in must not make it read as more complete than it is - both halves are pinned below,
 * because a bar that lies is worse than no bar.
 */
class DossierBoardBuilderTest extends TestCase
{
    private const string TODAY = '2026-10-10';

    private Dossier $dossier;
    private User $lea;
    private User $hugo;
    private int $nextId = 1;
    /** @var list<DossierSubmission> */
    private array $submissions = [];

    protected function setUp(): void
    {
        $this->dossier = new Dossier();
        $this->lea = $this->withId(new User('lea'));
        $this->hugo = $this->withId(new User('hugo'));
    }

    /**
     * The board is keyed on database ids - it only ever runs on a dossier that has been flushed -
     * so the rows it is built from need one.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function withId(object $entity): object
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $this->nextId++);

        return $entity;
    }

    private function document(string $name, bool $required, DossierValidationProfile $profile = DossierValidationProfile::Deposit, string $dueOn = '2026-10-15', string $visibleFrom = '2026-10-01'): DossierDocument
    {
        return $this->withId(new DossierDocument($this->dossier))
            ->setName($name)
            ->setRequired($required)
            ->setValidationProfile($profile)
            ->setVisibleFrom(new \DateTimeImmutable($visibleFrom))
            ->setDueOn(new \DateTimeImmutable($dueOn));
    }

    private function deposit(DossierDocument $document, User $student, int $version = 1): DossierSubmission
    {
        $submission = DossierSubmission::ofFile($document, $student, $version, 'k', 'f.pdf', 1);
        $this->submissions[] = $submission;

        return $submission;
    }

    private function build(): \App\Service\Dossier\DossierBoard
    {
        $targets = $this->createStub(DossierTargetResolver::class);
        $targets->method('resolve')->willReturn([
            ['student' => $this->lea, 'className' => 'SIO-2'],
            ['student' => $this->hugo, 'className' => 'SIO-2'],
        ]);

        $repository = $this->createStub(DossierSubmissionRepository::class);
        $repository->method('findForDossier')->willReturn($this->submissions);

        return (new DossierBoardBuilder($targets, $repository, new DossierStatusResolver()))
            ->build($this->dossier, new \DateTimeImmutable(self::TODAY));
    }

    public function testTheAvancementCountsTheObligatoiresAndNothingElse(): void
    {
        $required = $this->document('Rapport', true);
        $optional = $this->document('Annexes', false);

        $this->deposit($required, $this->lea);
        // Hugo handed in only the facultative one: his bar must read 0/1, not 1/2.
        $this->deposit($optional, $this->hugo);

        $board = $this->build();
        $rows = $board->rows;

        self::assertSame([1, 1], [$rows[0]->settledRequired, $rows[0]->totalRequired]);
        self::assertTrue($rows[0]->isComplete());
        self::assertSame([0, 1], [$rows[1]->settledRequired, $rows[1]->totalRequired]);
        self::assertFalse($rows[1]->isComplete());
        self::assertSame(1, $board->completeCount());
    }

    public function testADepositInValidationIsNotYetAnAvancement(): void
    {
        $document = $this->document('Rapport', true, DossierValidationProfile::Validation);
        $submission = $this->deposit($document, $this->lea);

        self::assertSame(0, $this->build()->rows[0]->settledRequired, 'a dépôt awaiting a validateur settles nothing');

        new DossierReview($submission, new User('validator'), DossierReviewAction::Validated, null);

        self::assertSame(1, $this->build()->rows[0]->settledRequired);
    }

    public function testADossierWithNoObligatoireIsCompleteByConstruction(): void
    {
        $this->document('Annexes', false);

        $row = $this->build()->rows[0];

        self::assertTrue($row->isComplete(), 'there is nothing anybody is required to do');
        self::assertSame(100, $row->percent());
    }

    public function testTheKpisCountCellsAcrossTheWholeDossier(): void
    {
        $rapport = $this->document('Rapport', true, DossierValidationProfile::Validation);
        $attestation = $this->document('Attestation', true, dueOn: '2026-10-01');

        $corrected = $this->deposit($rapport, $this->lea);
        new DossierReview($corrected, new User('validator'), DossierReviewAction::CorrectionRequested, 'À reprendre.');
        $this->deposit($rapport, $this->hugo);
        $this->deposit($attestation, $this->lea);

        $board = $this->build();

        self::assertSame(1, $board->countWithStatus(DossierDocumentStatus::ToCorrect));
        self::assertSame(1, $board->countWithStatus(DossierDocumentStatus::AwaitingReview));
        // Hugo handed in no attestation and its date limite has passed.
        self::assertSame(1, $board->countWithStatus(DossierDocumentStatus::Late));
        self::assertCount(1, $board->validationQueue());
    }

    public function testTheProgressOfOneDocumentIsCountedOverTheCibles(): void
    {
        $document = $this->document('Rapport', true);
        $this->deposit($document, $this->lea);

        self::assertSame(['done' => 1, 'total' => 2, 'percent' => 50], $this->build()->documentProgress($document));
    }

    public function testTheAlertsAnswerWhetherToLookRatherThanHowOften(): void
    {
        $first = $this->document('Rapport', true, dueOn: '2026-10-01');
        $second = $this->document('Attestation', true, dueOn: '2026-10-01');

        // Two late documents, one « En retard » tag: the column asks « faut-il regarder cette
        // cible », and two tags answer it no better than one.
        self::assertSame([DossierDocumentStatus::Late], $this->build()->rows[0]->alerts());
        self::assertSame(2, $this->build()->rows[0]->countWithStatus(DossierDocumentStatus::Late));
        unset($first, $second);
    }
}
