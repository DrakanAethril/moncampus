<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CrmDossier;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\CrmModality;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceKind;
use App\Enum\CrmSituation;
use App\Repository\CrmDossierRepository;
use App\Tests\Functional\Crm\CrmFixturesTrait;

/**
 * The CRM through the Claude connector: dossiers added and modified - never the configuration -
 * and the spreadsheet's import, line by line, every refused line coming back with its reference
 * and its reasons.
 */
class ClaudeConnectorCrmToolsTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;
    use CrmFixturesTrait;

    private User $admin;
    private Program $program;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'crm.mcp');
        $this->program = $this->crmFollow($this->createProgram([], [], $this->admin));
        $this->crmPiece('file_completed');
        $this->crmPiece('file_sent', kind: CrmPieceKind::Choice, choices: ['Mail', 'Papier']);
        $this->crmPiece('deposit', kind: CrmPieceKind::Amount);
        $this->crmPiece('company', CrmPhase::Documents, CrmPieceKind::Text, [CrmModality::Apprenticeship]);
        $this->crmOrigin('JPO');
        $this->crmOrigin('Inconnue');
        $this->token = $this->accessTokenFor($this->admin);
    }

    /** @param array<string, mixed> $extra */
    private function row(string $ref, string $last, string $first, array $extra = []): array
    {
        return ['ref' => $ref, 'programId' => $this->program->getId(), 'lastName' => $last, 'firstName' => $first] + $extra;
    }

    private function dossiers(): CrmDossierRepository
    {
        return static::getContainer()->get(CrmDossierRepository::class);
    }

    public function testTheToolsAreListed(): void
    {
        $names = $this->toolNames($this->token);
        foreach (['crm_reference', 'crm_dossier_search', 'crm_dossier_get', 'crm_dossier_create', 'crm_dossier_update', 'crm_pieces_set', 'crm_journal_add', 'crm_import'] as $tool) {
            self::assertContains($tool, $names);
        }
    }

    public function testTheReferenceNamesTheFormationsThePiecesAndTheOrigins(): void
    {
        $result = $this->callTool($this->token, 'crm_reference');

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame([$this->program->getId()], array_column($result['data']['programs'], 'programId'));
        self::assertContains('file_sent', array_column($result['data']['pieces'], 'code'));
        self::assertContains('JPO', $result['data']['origins']);
    }

    public function testTheImportChecksEachLineWholeAndSaysWhyALineIsRefused(): void
    {
        $rows = [
            $this->row('SIO 1, ligne 3', 'Dupont', 'Léa', ['email' => 'lea@exemple.fr', 'modality' => 'apprenticeship', 'origins' => ['jpo'], 'pieces' => [['code' => 'file_completed'], ['code' => 'file_sent', 'value' => 'papier', 'date' => '2026-03-02'], ['code' => 'deposit', 'value' => '250', 'paymentMode' => 'Chèque']], 'notes' => [['date' => '2026-03-05', 'text' => 'Relancée pour le RIB.']]]),
            $this->row('SIO 1, ligne 4', '', 'Sans nom'),
            $this->row('SIO 1, ligne 5', 'Martin', 'Hugo', ['origins' => ['Salon des métiers'], 'pieces' => [['code' => 'file_sent', 'value' => 'Fax'], ['code' => 'nope']]]),
            $this->row('SIO 1, ligne 6', 'Petit', 'Chloé', ['email' => 'shared@exemple.fr']),
            $this->row('SIO 1, ligne 7', 'Roux', 'Tom', ['email' => 'shared@exemple.fr', 'situation' => 'cancelled']),
        ];

        $dry = $this->callTool($this->token, 'crm_import', ['rows' => $rows, 'dryRun' => true]);
        self::assertFalse($dry['isError'], $dry['text']);
        self::assertSame(['SIO 1, ligne 3'], array_column($dry['data']['ready'], 'ref'));
        self::assertSame(['SIO 1, ligne 4', 'SIO 1, ligne 5', 'SIO 1, ligne 6', 'SIO 1, ligne 7'], array_column($dry['data']['errors'], 'ref'));
        self::assertSame([], $this->dossiers()->findBy(['program' => $this->program]), 'A dry run writes nothing');

        $errors = array_column($dry['data']['errors'], 'reasons', 'ref');
        self::assertSame(['Le nom est obligatoire.'], $errors['SIO 1, ligne 4']);
        self::assertCount(3, $errors['SIO 1, ligne 5'], implode(' | ', $errors['SIO 1, ligne 5']));
        self::assertStringContainsString('shared@exemple.fr', $errors['SIO 1, ligne 6'][0]);

        $done = $this->callTool($this->token, 'crm_import', ['rows' => $rows]);
        self::assertFalse($done['isError'], $done['text']);
        self::assertCount(1, $done['data']['ready']);
        /** @var list<array{id: int}> $ready */
        $ready = $done['data']['ready'];
        $dossier = $this->dossiers()->find($ready[0]['id']);
        self::assertInstanceOf(CrmDossier::class, $dossier);
        self::assertSame(CrmModality::Apprenticeship, $dossier->getModality());
        self::assertSame('JPO', $dossier->originText());
        $completed = array_values(array_filter($dossier->getPieces()->toArray(), static fn ($e): bool => 'file_completed' === $e->getPiece()->getCode()))[0];
        self::assertNull($completed->getDoneOn(), 'Done, its date unknown');
        self::assertTrue($completed->isImported());
        $texts = array_map(static fn ($l): string => $l->getText(), $dossier->getJournal()->toArray());
        self::assertContains('Dossier repris du classeur, SIO 1, ligne 3.', $texts);
        self::assertContains('Relancée pour le RIB.', $texts);

        $again = $this->callTool($this->token, 'crm_import', ['rows' => [$rows[0]]]);
        self::assertSame([], $again['data']['ready'], 'Imported again: ignored, never updated');
        self::assertSame(['SIO 1, ligne 3'], array_column($again['data']['ignored'], 'ref'));
    }

    public function testALineWithoutModalityTakesTheFormationsDefaultAndSaysSo(): void
    {
        $this->callTool($this->token, 'crm_import', ['rows' => [$this->row('MCO 1, ligne 2', 'Blanc', 'Zoé')]]);

        $dossier = $this->dossiers()->findOneBy(['program' => $this->program]);
        self::assertInstanceOf(CrmDossier::class, $dossier);
        self::assertSame(CrmModality::Initial, $dossier->getModality());
        self::assertContains('Modalité Formation initiale : celle de la formation par défaut, le classeur ne la disait pas.', array_map(static fn ($l): string => $l->getText(), $dossier->getJournal()->toArray()));
    }

    public function testADossierIsCreatedUpdatedFilledAndRead(): void
    {
        $created = $this->callTool($this->token, 'crm_dossier_create', ['programId' => $this->program->getId(), 'lastName' => 'garcia', 'firstName' => 'Inès', 'email' => 'ines@exemple.fr', 'modality' => 'apprenticeship']);
        self::assertFalse($created['isError'], $created['text']);
        $id = $created['data']['dossierId'];

        $refused = $this->callTool($this->token, 'crm_dossier_create', ['programId' => $this->program->getId(), 'lastName' => 'Autre', 'firstName' => 'Personne', 'email' => 'INES@exemple.fr']);
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('ne sert qu', $refused['text']);

        $updated = $this->callTool($this->token, 'crm_dossier_update', ['dossierId' => $id, 'candidate' => ['phone' => '06 12 34 56 78'], 'situation' => 'waitlist', 'situationReason' => 'Formation complète.', 'modality' => 'initial']);
        self::assertFalse($updated['isError'], $updated['text']);

        $pieces = $this->callTool($this->token, 'crm_pieces_set', ['dossierId' => $id, 'pieces' => [['code' => 'file_completed'], ['code' => 'file_sent', 'value' => 'Fax']]]);
        self::assertTrue($pieces['isError'], 'One piece refused: nothing written');
        $pieces = $this->callTool($this->token, 'crm_pieces_set', ['dossierId' => $id, 'pieces' => [['code' => 'file_completed'], ['code' => 'file_sent', 'value' => 'Mail']]]);
        self::assertFalse($pieces['isError'], $pieces['text']);

        $note = $this->callTool($this->token, 'crm_journal_add', ['dossierId' => $id, 'kind' => 'call', 'text' => 'Message laissé.']);
        self::assertFalse($note['isError'], $note['text']);

        $read = $this->callTool($this->token, 'crm_dossier_get', ['dossierId' => $id]);
        /** @var array{candidate: array{lastName: string, phone: string}, situation: string, modality: string, journal: list<array{text: string}>, pieces: list<array{code: string, entry: mixed}>} $data */
        $data = $read['data'];
        self::assertSame('GARCIA', $data['candidate']['lastName']);
        self::assertSame('06 12 34 56 78', $data['candidate']['phone']);
        self::assertSame(CrmSituation::Waitlist->value, $data['situation']);
        self::assertSame('initial', $data['modality']);
        self::assertSame('Message laissé.', $data['journal'][\count($data['journal']) - 1]['text']);
        $entered = array_values(array_filter($data['pieces'], static fn (array $p): bool => null !== $p['entry']));
        $codes = array_column($entered, 'code');
        sort($codes);
        self::assertSame(['file_completed', 'file_sent'], $codes);

        $search = $this->callTool($this->token, 'crm_dossier_search', ['query' => 'ines gar']);
        self::assertSame([$id], array_column($search['data']['dossiers'], 'dossierId'));
    }
}
