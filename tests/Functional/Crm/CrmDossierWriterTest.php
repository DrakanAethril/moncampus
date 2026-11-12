<?php

declare(strict_types=1);

namespace App\Tests\Functional\Crm;

use App\Entity\CrmCandidate;
use App\Entity\CrmDossier;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\CrmJournalKind;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPieceKind;
use App\Enum\CrmRefund;
use App\Enum\CrmSituation;
use App\Service\Crm\CrmCandidateInput;
use App\Service\Crm\CrmDossierInput;
use App\Service\Crm\CrmDossierWriter;
use App\Service\Crm\CrmRefusal;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The writes of a dossier and the rules they hold, through the one service every door uses.
 */
class CrmDossierWriterTest extends FunctionalTestCase
{
    use CrmFixturesTrait;

    private User $admin;
    private Program $program;
    private CrmDossierWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'crm.admin');
        $this->program = $this->crmFollow($this->createProgram([], [], $this->admin), CrmModality::Apprenticeship);
        $this->writer = static::getContainer()->get(CrmDossierWriter::class);
    }

    private function open(string $last, string $first, ?string $mail = null, ?Program $program = null, ?CrmModality $modality = null): CrmDossier
    {
        $dossier = $this->writer->open(
            CrmCandidateInput::of($last, $first, $mail, null, false, []),
            new CrmDossierInput($program ?? $this->program, null, CrmPath::Enrolment, $modality),
            $this->admin,
        );
        $this->crmEntityManager()->flush();

        return $dossier;
    }

    public function testADossierOpensInTheFormationsDefaultModalityWithItsFirstJournalLine(): void
    {
        $dossier = $this->open('dupont', ' Léa ', 'Lea.Dupont@Exemple.fr');

        self::assertSame('DUPONT', $dossier->getCandidate()->getLastName());
        self::assertSame('Léa', $dossier->getCandidate()->getFirstName());
        self::assertSame('lea.dupont@exemple.fr', $dossier->getCandidate()->getEmail());
        self::assertSame(CrmModality::Apprenticeship, $dossier->getModality());
        self::assertSame(CrmSituation::Active, $dossier->getSituation());
        self::assertCount(1, $dossier->getModalityChanges());
        self::assertSame('Dossier ouvert.', $dossier->getJournal()->first()->getText());
    }

    public function testTheNameIsRequiredAndAMailMustLookLikeOne(): void
    {
        try {
            $this->open('', '', 'pas-un-mail');
            self::fail('refused');
        } catch (CrmRefusal $refusal) {
            self::assertSame(['crmRefusalLastNameMissing', 'crmRefusalFirstNameMissing', 'crmRefusalMailInvalid'], array_map(static fn ($m) => $m->getMessage(), $refusal->reasons));
        }
    }

    public function testAMailServesOneDossierPerFormation(): void
    {
        $this->open('DUPONT', 'Léa', 'lea@exemple.fr');

        try {
            $this->open('MARTIN', 'Hugo', 'LEA@exemple.fr');
            self::fail('A mail already held in the formation is refused.');
        } catch (CrmRefusal $refusal) {
            self::assertSame('crmRefusalMailTaken', $refusal->reasons[0]->getMessage());
        }

        $other = $this->crmFollow($this->createProgram([], [], $this->admin));
        $elsewhere = $this->open('DUPONT', 'Léa', 'lea@exemple.fr', $other);
        self::assertNotNull($elsewhere->getId(), 'The same mail in another formation is accepted');
        self::assertCount(2, $this->crmEntityManager()->getRepository(CrmCandidate::class)->findBy(['email' => 'lea@exemple.fr']), 'and never attached to the first candidate');
    }

    public function testAFormationThatAsksForAnOptionRefusesADossierWithoutOne(): void
    {
        $cda = $this->crmOption($this->program, 'CDA', $this->admin);
        $this->crmOption($this->program, 'AIS', $this->admin);
        static::getContainer()->get(\App\Service\Crm\CrmSettings::class)->settingsFor($this->program)->setTrackedOptionIds([$cda->getId()]);

        $this->expectException(CrmRefusal::class);
        $this->open('DUPONT', 'Léa');
    }

    public function testOnlyAFollowedOptionIsGivenToADossierAndAnUntickedOneStaysWhereItIs(): void
    {
        $settings = static::getContainer()->get(\App\Service\Crm\CrmSettings::class);
        $cda = $this->crmOption($this->program, 'CDA', $this->admin);
        $ais = $this->crmOption($this->program, 'AIS', $this->admin);
        $settings->settingsFor($this->program)->setTrackedOptionIds([$cda->getId(), $ais->getId()]);
        $this->crmEntityManager()->flush();

        $input = fn (\App\Entity\Option $option): CrmDossierInput => new CrmDossierInput($this->program, $option, CrmPath::Enrolment, null);
        $dossier = $this->writer->open(CrmCandidateInput::of('DUPONT', 'Léa', null, null, false, []), $input($ais), $this->admin);
        $this->crmEntityManager()->flush();

        $settings->settingsFor($this->program)->setTrackedOptionIds([$cda->getId()]);
        $this->crmEntityManager()->flush();
        self::assertSame([$cda], $settings->optionsOf($this->program), 'Only the ticked option is offered');
        self::assertSame($ais, $dossier->getOption(), 'Unticking an option moves no dossier');
        self::assertSame([$cda, $ais], $settings->optionLines($this->program, [$settings->progress($dossier)]), 'and its dossiers keep a line');

        $this->writer->correct($dossier, new CrmDossierInput($this->program, $ais, CrmPath::Reenrolment, null), $this->admin);
        self::assertSame($ais, $dossier->getOption(), 'A correction that leaves the option alone is accepted');

        try {
            $this->writer->open(CrmCandidateInput::of('MARTIN', 'Zoé', null, null, false, []), $input($ais), $this->admin);
            self::fail('An option no longer followed is given to no new dossier');
        } catch (CrmRefusal $refusal) {
            self::assertSame('crmRefusalOptionUntracked', $refusal->reasons[0]->getMessage());
        }
    }

    public function testACorrectionMovesTheDossierAndSaysSoInTheJournal(): void
    {
        $dossier = $this->open('DUPONT', 'Léa');
        $other = $this->crmFollow($this->createProgram([], [], $this->admin));
        $jpo = $this->crmOrigin('JPO');

        self::assertTrue($this->writer->correct($dossier, new CrmDossierInput($other, null, CrmPath::Reenrolment, null, [$jpo], 'Bac STMG'), $this->admin));

        self::assertSame($other, $dossier->getProgram());
        self::assertSame(CrmPath::Reenrolment, $dossier->getPath());
        self::assertSame('Bac STMG', $dossier->getPriorSchooling());
        self::assertStringStartsWith('Dossier corrigé. Formation : ', $dossier->getJournal()->last()->getText());
        self::assertFalse($this->writer->correct($dossier, new CrmDossierInput($other, null, CrmPath::Reenrolment, null, [$jpo], 'Bac STMG'), $this->admin), 'Nothing changed, nothing written');
    }

    public function testACorrectionToAFormationWhereTheMailIsTakenIsRefused(): void
    {
        $other = $this->crmFollow($this->createProgram([], [], $this->admin));
        $this->open('MARTIN', 'Hugo', 'shared@exemple.fr', $other);
        $dossier = $this->open('DUPONT', 'Léa', 'shared@exemple.fr');

        $this->expectException(CrmRefusal::class);
        $this->writer->correct($dossier, new CrmDossierInput($other, null, CrmPath::Enrolment, null), $this->admin);
    }

    public function testAModalityChangeKeepsItsHistoryAndEndsTheSfpStatus(): void
    {
        $dossier = $this->open('DUPONT', 'Léa');
        $this->writer->setSfp($dossier, true, $this->admin);

        $this->writer->changeModality($dossier, CrmModality::Initial, new \DateTimeImmutable('2026-10-01'), 'Pas d’entreprise trouvée', $this->admin);

        self::assertSame(CrmModality::Initial, $dossier->getModality());
        self::assertFalse($dossier->isSfp());
        self::assertCount(2, $dossier->getModalityChanges());
        self::assertSame('Modalité : Apprentissage → Formation initiale. Pas d’entreprise trouvée', $dossier->getJournal()->last()->getText());
    }

    public function testAPieceRefusesADateToComeUnlessItAllowsOne(): void
    {
        $dossier = $this->open('DUPONT', 'Léa');
        $complete = $this->crmPiece('file_completed');
        $convocation = $this->crmPiece('convocation', future: true);
        $nextMonth = $this->writer->today()->modify('+1 month');

        $this->writer->enterPiece($dossier, $convocation, null, $nextMonth, null, $this->admin);
        self::assertEquals($nextMonth, $dossier->entryFor($convocation)?->getDoneOn());

        $this->expectException(CrmRefusal::class);
        $this->writer->enterPiece($dossier, $complete, null, $nextMonth, null, $this->admin);
    }

    public function testADateUnknownIsOnlyForWhatTheSpreadsheetGives(): void
    {
        $dossier = $this->open('DUPONT', 'Léa');
        $complete = $this->crmPiece('file_completed');

        $this->writer->enterPiece($dossier, $complete, null, null, null, $this->admin, dateUnknown: true);
        self::assertNotNull($dossier->entryFor($complete)?->getDoneOn(), 'Typed by the team: today');

        $this->writer->enterPiece($dossier, $complete, null, null, null, $this->admin, imported: true, dateUnknown: true);
        $entry = $dossier->entryFor($complete);
        self::assertNull($entry->getDoneOn());
        self::assertTrue($entry->isImported());
    }

    public function testEnteringAndRemovingAPieceWritesItsJournalLines(): void
    {
        $dossier = $this->open('DUPONT', 'Léa');
        $sent = $this->crmPiece('file_sent', kind: CrmPieceKind::Choice, choices: ['Mail', 'Papier']);

        $this->writer->enterPiece($dossier, $sent, 'papier', null, null, $this->admin);
        self::assertSame('Papier', $dossier->entryFor($sent)?->getValue());
        self::assertSame('« '.$sent->getLabel().' » marqué fait : Papier.', $dossier->getJournal()->last()->getText());
        self::assertSame(CrmJournalKind::Entry, $dossier->getJournal()->last()->getKind());

        self::assertTrue($this->writer->clearPiece($dossier, $sent, $this->admin));
        self::assertNull($dossier->entryFor($sent));
    }

    public function testAClosedDossierWithADepositOwesItBack(): void
    {
        $dossier = $this->open('DUPONT', 'Léa');
        $deposit = $this->crmPiece('deposit', kind: CrmPieceKind::Amount);
        $this->writer->enterPiece($dossier, $deposit, 250, null, 'Chèque', $this->admin);

        $this->writer->changeSituation($dossier, CrmSituation::Cancelled, 'À la demande de la famille.', true, $this->admin);
        self::assertSame(CrmRefund::Due, $dossier->getRefund());

        $this->writer->markRefunded($dossier, $this->admin);
        self::assertSame(CrmRefund::Done, $dossier->getRefund());
        self::assertSame('Acompte remboursé.', $dossier->getJournal()->last()->getText());
    }

    public function testDeletingTheLastDossierDeletesTheCandidate(): void
    {
        $dossier = $this->open('DUPONT', 'Léa', 'lea@exemple.fr');
        $this->crmPiece('file_completed');
        $this->writer->addNote($dossier, CrmJournalKind::Call, 'Message laissé.', $this->admin);
        $this->crmEntityManager()->flush();

        $this->writer->delete($dossier);
        $this->crmEntityManager()->flush();

        self::assertSame([], $this->crmEntityManager()->getRepository(CrmCandidate::class)->findBy(['email' => 'lea@exemple.fr']));
    }

    public function testAnEmptyNoteIsRefused(): void
    {
        $this->expectException(CrmRefusal::class);
        $this->writer->addNote($this->open('DUPONT', 'Léa'), CrmJournalKind::Note, '   ', $this->admin);
    }
}
