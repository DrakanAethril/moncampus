<?php

declare(strict_types=1);

namespace App\Tests\Service\TeacherDossier;

use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierDocument;
use App\Entity\TeacherDossierReview;
use App\Entity\TeacherDossierSubmission;
use App\Entity\User;
use App\Enum\DossierDocumentStatus;
use App\Enum\DossierReviewAction;
use App\Enum\DossierValidationProfile;
use App\Repository\TeacherDossierSubmissionRepository;
use App\Service\Dossier\DossierStatusResolver;
use App\Service\TeacherDossier\TeacherDossierSheets;
use PHPUnit\Framework\TestCase;

/**
 * A dossier intervenant read through the rule it shares with the dossiers documentaires, and the
 * three things it has of its own: a piece is never « à venir », its date limite closes nothing, and
 * the banner counts only what the intervenant themselves still has to do.
 */
class TeacherDossierSheetsTest extends TestCase
{
    private const string TODAY = '2026-10-10';

    private User $staff;
    private User $teacher;
    private TeacherDossier $dossier;
    private int $nextId = 1;
    /** @var list<TeacherDossierSubmission> */
    private array $submissions = [];

    protected function setUp(): void
    {
        $this->staff = $this->withId(new User('claire'));
        $this->teacher = $this->withId(new User('nadia'));
        $this->dossier = $this->withId(TeacherDossier::forTeacher($this->staff, $this->teacher, 'Dossier administratif'));
        $this->dossier->publish();
    }

    /**
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

    private function piece(string $name, bool $required = true, bool $validation = false, ?string $dueOn = null): TeacherDossierDocument
    {
        return $this->withId((new TeacherDossierDocument($this->dossier))
            ->setName($name)
            ->setRequired($required)
            ->setValidationProfile($validation ? DossierValidationProfile::Validation : DossierValidationProfile::Deposit)
            ->setDueOn(null === $dueOn ? null : new \DateTimeImmutable($dueOn)));
    }

    private function deposit(TeacherDossierDocument $piece, int $version = 1, ?User $by = null): TeacherDossierSubmission
    {
        return $this->submissions[] = TeacherDossierSubmission::ofFile($piece, $by ?? $this->teacher, $version, 'k'.$version, 'piece.pdf', 1024);
    }

    private function sheet(): \App\Service\TeacherDossier\TeacherDossierSheet
    {
        $repository = $this->createStub(TeacherDossierSubmissionRepository::class);
        $repository->method('findForDossiers')->willReturn($this->submissions);

        return (new TeacherDossierSheets($repository, new DossierStatusResolver()))->of($this->dossier, new \DateTimeImmutable(self::TODAY));
    }

    private function statusOf(TeacherDossierDocument $piece): DossierDocumentStatus
    {
        return $this->sheet()->lines[(int) $piece->getId()]->status;
    }

    public function testAPieceWithNoDateLimiteIsMissingAndNeverLate(): void
    {
        self::assertSame(DossierDocumentStatus::Missing, $this->statusOf($this->piece('RIB')));
    }

    public function testAPieceIsNeverUpcomingEvenWithADateFarAhead(): void
    {
        self::assertSame(DossierDocumentStatus::Missing, $this->statusOf($this->piece('RIB', dueOn: '2027-06-30')));
    }

    public function testPastItsDateLimiteAPieceReadsLateAndStillAcceptsADepot(): void
    {
        $piece = $this->piece('RIB', dueOn: '2026-10-09');
        $line = $this->sheet()->lines[(int) $piece->getId()];

        self::assertSame(DossierDocumentStatus::Late, $line->status);
        self::assertTrue($line->acceptsSubmission, 'The date limite closes nothing.');
    }

    public function testTheDateLimiteItselfIsNotLate(): void
    {
        self::assertSame(DossierDocumentStatus::Missing, $this->statusOf($this->piece('RIB', dueOn: self::TODAY)));
    }

    public function testAPlainPieceIsSettledByItsDepotAndAValidatedOneByItsReview(): void
    {
        $plain = $this->piece('RIB');
        $read = $this->piece('Pièce d’identité', validation: true);
        $this->deposit($plain);
        $submission = $this->deposit($read);

        self::assertSame(DossierDocumentStatus::Deposited, $this->statusOf($plain));
        self::assertSame(DossierDocumentStatus::AwaitingReview, $this->statusOf($read));

        new TeacherDossierReview($submission, $this->staff, DossierReviewAction::Validated, null);

        $line = $this->sheet()->lines[(int) $read->getId()];
        self::assertSame(DossierDocumentStatus::Validated, $line->status);
        self::assertFalse($line->acceptsSubmission, 'A validated piece is finished.');
    }

    public function testACorrectionIsAnsweredByANewVersionWhichGoesBackToTheAdministration(): void
    {
        $piece = $this->piece('Attestation URSSAF', validation: true);
        new TeacherDossierReview($this->deposit($piece), $this->staff, DossierReviewAction::CorrectionRequested, 'Trop ancienne.');

        self::assertSame(DossierDocumentStatus::ToCorrect, $this->statusOf($piece));

        $this->deposit($piece, 2);
        $line = $this->sheet()->lines[(int) $piece->getId()];

        self::assertSame(DossierDocumentStatus::AwaitingReview, $line->status);
        self::assertCount(1, $line->previousVersions());
        self::assertSame(1, $line->previousVersions()[0]->getVersion());
    }

    public function testTheAvancementCountsThePiecesObligatoiresOnly(): void
    {
        $this->deposit($this->piece('RIB'));
        $this->piece('Pièce d’identité');
        $this->deposit($this->piece('Assurance', required: false));

        $sheet = $this->sheet();

        self::assertSame(1, $sheet->settledRequired);
        self::assertSame(2, $sheet->totalRequired);
        self::assertSame(50, $sheet->percent());
        self::assertFalse($sheet->isComplete());
    }

    public function testADossierWithNoPieceObligatoireIsComplete(): void
    {
        $this->piece('Assurance', required: false);

        self::assertTrue($this->sheet()->isComplete());
        self::assertSame(100, $this->sheet()->percent());
    }

    public function testTheBannerCountsWhatTheIntervenantStillHasToDo(): void
    {
        $this->piece('RIB');                                              // to submit
        $this->piece('Kbis', dueOn: '2026-10-01');                        // late: to submit too
        $toCorrect = $this->piece('Attestation URSSAF', validation: true);
        new TeacherDossierReview($this->deposit($toCorrect), $this->staff, DossierReviewAction::CorrectionRequested, 'Trop ancienne.');
        $this->deposit($this->piece('Pièce d’identité', validation: true)); // waits for the staff: not theirs
        $this->piece('Assurance', required: false);                       // facultative: never counted

        self::assertSame(['toSubmit' => 2, 'toCorrect' => 1], $this->sheet()->awaitedFromTeacher());
        self::assertTrue($this->sheet()->awaitsTeacher());
    }

    public function testAPieceWaitingForTheAdministrationDoesNotLightTheBanner(): void
    {
        $this->deposit($this->piece('Pièce d’identité', validation: true));

        $sheet = $this->sheet();

        self::assertFalse($sheet->isComplete(), 'Not complete: nobody has validated it yet.');
        self::assertFalse($sheet->awaitsTeacher(), 'And yet nothing is expected of the intervenant.');
    }

    public function testADraftAndAClosedDossierAskNothingAndAcceptNothing(): void
    {
        $draft = $this->withId(TeacherDossier::forTeacher($this->staff, $this->teacher, 'Brouillon'));
        $this->dossier = $draft;
        $piece = $this->piece('RIB');

        self::assertFalse($this->sheet()->awaitsTeacher());
        self::assertFalse($this->sheet()->lines[(int) $piece->getId()]->acceptsSubmission);

        $draft->publish();
        self::assertTrue($this->sheet()->awaitsTeacher());

        $draft->close();
        self::assertFalse($this->sheet()->awaitsTeacher());
        self::assertFalse($this->sheet()->lines[(int) $piece->getId()]->acceptsSubmission);

        $draft->reopen();
        self::assertTrue($this->sheet()->lines[(int) $piece->getId()]->acceptsSubmission);
    }

    public function testTheAlertsNameEachKindOfProblemOnce(): void
    {
        $this->piece('Kbis', dueOn: '2026-10-01');
        $this->piece('RIB', dueOn: '2026-10-02');
        $this->deposit($this->piece('Pièce d’identité', validation: true));

        self::assertSame([DossierDocumentStatus::Late, DossierDocumentStatus::AwaitingReview], $this->sheet()->alerts());
    }

    public function testADepotByTheAdministrationSaysSo(): void
    {
        $piece = $this->piece('RIB');

        self::assertFalse($this->deposit($piece)->isOnBehalf());
        self::assertTrue($this->deposit($piece, 2, $this->staff)->isOnBehalf());
    }
}
