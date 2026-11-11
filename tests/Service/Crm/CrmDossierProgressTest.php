<?php

declare(strict_types=1);

namespace App\Tests\Service\Crm;

use App\Entity\CrmCandidate;
use App\Entity\CrmDossier;
use App\Entity\CrmDossierPiece;
use App\Entity\CrmPiece;
use App\Entity\CrmProgram;
use App\Entity\Program;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceConstraint;
use App\Enum\CrmPieceKind;
use App\Enum\CrmStep;
use App\Service\Crm\CrmDossierProgress;
use PHPUnit\Framework\TestCase;

/**
 * Where a dossier stands, read from its pieces and its formation's settings: the stage, « Complet »,
 * what is still to do, what was entered under another modality.
 */
class CrmDossierProgressTest extends TestCase
{
    private Program $program;
    private CrmProgram $settings;

    protected function setUp(): void
    {
        $this->program = $this->createStub(Program::class);
        $this->settings = new CrmProgram($this->program);
    }

    private static function piece(string $code, CrmPhase $phase, CrmPieceKind $kind = CrmPieceKind::Check, array $modalities = [CrmModality::Initial, CrmModality::Apprenticeship, CrmModality::Other], array $paths = [CrmPath::Enrolment, CrmPath::Reenrolment]): CrmPiece
    {
        return new CrmPiece($code, ucfirst($code), $code, $phase, $kind, $modalities, $paths, 0);
    }

    private function dossier(CrmModality $modality = CrmModality::Initial, CrmPath $path = CrmPath::Enrolment, bool $foreign = false): CrmDossier
    {
        $candidate = new CrmCandidate('DUPONT', 'Léa');
        $candidate->setForeignStudent($foreign);

        return new CrmDossier($candidate, $this->program, $path, $modality, new \DateTimeImmutable('2026-03-01'), null);
    }

    private static function enter(CrmDossier $dossier, CrmPiece $piece, mixed $value = null): void
    {
        $dossier->addEntry(new CrmDossierPiece($dossier, $piece, new \DateTimeImmutable('2026-03-02'), $value, null, 'Claire Martin'));
    }

    public function testAnEmptyDossierStandsAtTheFirstStageThatAsksForSomething(): void
    {
        $pre = self::piece('preins', CrmPhase::PreEnrolment);
        $sent = self::piece('sent', CrmPhase::Enrolment);
        $progress = CrmDossierProgress::of($this->dossier(), $this->settings, [$pre, $sent]);

        self::assertSame(CrmStep::PreEnrolment, $progress->step());
        self::assertSame(['done' => 0, 'total' => 1], $progress->phaseCount(CrmPhase::PreEnrolment));
        self::assertFalse($progress->isDone($pre));
    }

    public function testEachStageDoneMovesTheDossierOnUntilItIsComplete(): void
    {
        $pre = self::piece('preins', CrmPhase::PreEnrolment);
        $sent = self::piece('sent', CrmPhase::Enrolment);
        $doc = self::piece('positioning', CrmPhase::Documents);
        $dossier = $this->dossier();

        self::enter($dossier, $pre);
        self::assertSame(CrmStep::Enrolment, CrmDossierProgress::of($dossier, $this->settings, [$pre, $sent, $doc])->step());

        self::enter($dossier, $sent);
        self::enter($dossier, $doc);
        self::assertSame(CrmStep::Complete, CrmDossierProgress::of($dossier, $this->settings, [$pre, $sent, $doc])->step());
    }

    public function testAStageWithNothingRequiredIsPassedOver(): void
    {
        $pre = self::piece('preins', CrmPhase::PreEnrolment, paths: [CrmPath::Enrolment]);
        $sent = self::piece('sent', CrmPhase::Enrolment);
        $progress = CrmDossierProgress::of($this->dossier(path: CrmPath::Reenrolment), $this->settings, [$pre, $sent]);

        self::assertSame(['done' => 0, 'total' => 0], $progress->phaseCount(CrmPhase::PreEnrolment));
        self::assertSame(CrmStep::Enrolment, $progress->step());
    }

    public function testAnOptionalPieceIsAskedButNeverHoldsBackComplete(): void
    {
        $sent = self::piece('sent', CrmPhase::Enrolment);
        $arrival = self::piece('arrival', CrmPhase::Enrolment);
        $arrival->setDefaultConstraint(CrmPieceConstraint::Optional);
        $dossier = $this->dossier();
        self::enter($dossier, $sent);

        $progress = CrmDossierProgress::of($dossier, $this->settings, [$sent, $arrival]);

        self::assertContains($arrival, $progress->applicable());
        self::assertSame(CrmPieceConstraint::Optional, $progress->constraintOf($arrival));
        self::assertSame(CrmStep::Complete, $progress->step());
    }

    public function testAFormationMayMakeAPieceOptionalRequiredOrHidden(): void
    {
        $sent = self::piece('sent', CrmPhase::Enrolment);
        $jury = self::piece('jury', CrmPhase::Documents);
        $this->settings->setPieceConstraint('jury', CrmPieceConstraint::Hidden);
        $dossier = $this->dossier();
        self::enter($dossier, $sent);

        $progress = CrmDossierProgress::of($dossier, $this->settings, [$sent, $jury]);

        self::assertNotContains($jury, $progress->applicable());
        self::assertSame(CrmStep::Complete, $progress->step());

        $this->settings->setPieceConstraint('jury', CrmPieceConstraint::Required);
        self::assertSame(CrmStep::Documents, CrmDossierProgress::of($dossier, $this->settings, [$sent, $jury])->step());
    }

    public function testWithoutSettingsEveryPieceFollowsItsOwnDefault(): void
    {
        $sent = self::piece('sent', CrmPhase::Enrolment);
        $jury = self::piece('jury', CrmPhase::Documents);
        $jury->setDefaultConstraint(CrmPieceConstraint::Hidden);

        $progress = CrmDossierProgress::of($this->dossier(), null, [$sent, $jury]);
        self::assertSame(CrmPieceConstraint::Required, $progress->constraintOf($sent));
        self::assertSame([$sent], $progress->applicable());
    }

    public function testAPieceHiddenByDefaultIsAskedWhereAFormationOpensIt(): void
    {
        $jury = self::piece('jury', CrmPhase::Documents);
        $jury->setDefaultConstraint(CrmPieceConstraint::Hidden);
        $this->settings->setPieceConstraint('jury', CrmPieceConstraint::Required);

        self::assertSame(CrmStep::Documents, CrmDossierProgress::of($this->dossier(), $this->settings, [$jury])->step());
    }

    public function testAPieceOfAnotherModalityIsNotAskedAndWhatWasEnteredUnderItIsKept(): void
    {
        $company = self::piece('company', CrmPhase::Documents, CrmPieceKind::Text, [CrmModality::Apprenticeship]);
        $positioning = self::piece('positioning', CrmPhase::Documents, modalities: [CrmModality::Initial]);
        $dossier = $this->dossier(CrmModality::Apprenticeship);
        self::enter($dossier, $company, 'Garage Lacroix');

        $dossier->setModality(CrmModality::Initial);
        $progress = CrmDossierProgress::of($dossier, $this->settings, [$company, $positioning]);

        self::assertSame([$positioning], $progress->applicable());
        self::assertCount(1, $progress->kept());
        self::assertSame($company, $progress->kept()[0]->getPiece());
    }

    public function testForeignOnlyPiecesAreAskedOfForeignStudentsAlone(): void
    {
        $visa = self::piece(CrmPiece::VISA, CrmPhase::Enrolment);
        $visa->setForeignOnly(true);

        self::assertSame([], CrmDossierProgress::of($this->dossier(), $this->settings, [$visa])->applicable());

        $foreign = CrmDossierProgress::of($this->dossier(foreign: true), $this->settings, [$visa]);
        self::assertSame([$visa], $foreign->applicable());
        self::assertTrue($foreign->visaPending());
    }

    public function testTheVisaIsNoLongerPendingOnceEntered(): void
    {
        $visa = self::piece(CrmPiece::VISA, CrmPhase::Enrolment);
        $visa->setForeignOnly(true);
        $dossier = $this->dossier(foreign: true);
        self::enter($dossier, $visa);

        self::assertFalse(CrmDossierProgress::of($dossier, $this->settings, [$visa])->visaPending());
    }

    public function testAListIsDoneAsSoonAsAValueIsChosen(): void
    {
        $cerfa = self::piece('cerfa', CrmPhase::Documents, CrmPieceKind::List);
        $cerfa->setChoices(['Campus', 'Entreprise', 'OPCO']);
        $dossier = $this->dossier();
        self::enter($dossier, $cerfa, 'Entreprise');

        $progress = CrmDossierProgress::of($dossier, $this->settings, [$cerfa]);

        self::assertTrue($progress->isDone($cerfa));
        self::assertFalse($progress->isInProgress($cerfa));
    }

    public function testAStatusListIsInProgressUntilItsLastValue(): void
    {
        $status = self::piece('internship_status', CrmPhase::Documents, CrmPieceKind::List);
        $status->setChoices(['En recherche', 'Entretien prévu', 'Convention signée']);
        $status->setLastValueCompletes(true);
        $dossier = $this->dossier();
        self::enter($dossier, $status, 'Entretien prévu');

        $progress = CrmDossierProgress::of($dossier, $this->settings, [$status]);
        self::assertFalse($progress->isDone($status));
        self::assertTrue($progress->isInProgress($status));
        self::assertSame(CrmStep::Documents, $progress->step());
    }
}
