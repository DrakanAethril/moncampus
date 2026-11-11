<?php

declare(strict_types=1);

namespace App\Tests\Functional\Crm;

use App\Entity\CrmDossier;
use App\Entity\CrmOrigin;
use App\Entity\CrmPiece;
use App\Entity\CrmProgram;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\CrmModality;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceKind;
use App\Enum\CrmSituation;
use App\Repository\CrmDossierRepository;
use App\Tests\Functional\FunctionalTestCase;

/**
 * The CRM's screens and dialogs end to end, through the requests the pages make.
 */
class CrmScreensTest extends FunctionalTestCase
{
    use CrmFixturesTrait;

    private User $admin;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'crm.screens');
        $this->program = $this->crmFollow($this->createProgram([], [], $this->admin));
        $this->crmPiece('pre_enrolment', CrmPhase::PreEnrolment, CrmPieceKind::Choice, choices: ['En ligne', 'Papier']);
        $this->crmPiece('file_completed');
        $this->crmPiece('deposit', kind: CrmPieceKind::Amount);
        $this->crmPiece('company', CrmPhase::Documents, CrmPieceKind::Text, [CrmModality::Apprenticeship]);
        $this->crmOrigin('JPO');
        $this->client->loginUser($this->admin);
    }

    private function newDossier(string $last = 'Dupont', string $first = 'Léa', string $mail = 'lea@exemple.fr'): CrmDossier
    {
        $this->client->request('GET', '/crm/new');
        self::assertResponseIsSuccessful();
        $jpo = $this->crmEntityManager()->getRepository(CrmOrigin::class)->findOneBy(['label' => 'JPO']);
        $this->client->request('POST', '/crm/new', [
            '_token' => $this->csrfToken('crm'),
            'lastName' => $last,
            'firstName' => $first,
            'email' => $mail,
            'program' => (string) $this->program->getId(),
            'path' => 'enrolment',
            'modality' => 'apprenticeship',
            'origins' => [(string) $jpo?->getId()],
            'guardians' => [['lastName' => 'Dupont', 'firstName' => 'Anne', 'email' => '', 'phone' => '06 00 00 00 00'], ['lastName' => '', 'firstName' => '', 'email' => '', 'phone' => '']],
        ]);
        self::assertResponseRedirects();

        $dossier = static::getContainer()->get(CrmDossierRepository::class)->findOneBy(['program' => $this->program], ['id' => 'DESC']);
        self::assertInstanceOf(CrmDossier::class, $dossier);

        return $dossier;
    }

    public function testEveryScreenRendersWithAndWithoutDossiers(): void
    {
        $year = (int) $this->program->getSchoolYear()?->getId();
        $paths = ['/crm', '/crm/years/'.$year, '/crm/programs/'.$this->program->getId(), '/crm/years/'.$year.'/history', '/crm/settings', '/crm/settings?tab=programs', '/crm/settings?tab=pieces', '/crm/settings?tab=origins', '/crm/settings/programs/'.$this->program->getId().'/pieces'];
        foreach ($paths as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }

        $dossier = $this->newDossier();
        foreach ([...$paths, '/crm/dossiers/'.$dossier->getId(), '/crm/search?q=dup', '/crm/years/'.$year.'/history?entries=1&q=lea'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
        $this->client->request('GET', '/crm/search?q=dup');
        self::assertStringContainsString('<mark>DUP</mark>ONT', (string) $this->client->getResponse()->getContent());
    }

    public function testEveryDialogRenders(): void
    {
        $dossier = $this->newDossier();
        $id = $dossier->getId();
        $piece = $this->crmEntityManager()->getRepository(CrmPiece::class)->findOneBy(['code' => 'file_completed']);
        $origin = $this->crmEntityManager()->getRepository(CrmOrigin::class)->findOneBy(['label' => 'JPO']);
        $year = $this->program->getSchoolYear()?->getId();

        foreach (['/crm/dossiers/'.$id.'/correct', '/crm/dossiers/'.$id.'/candidate', '/crm/dossiers/'.$id.'/origin', '/crm/dossiers/'.$id.'/situation', '/crm/dossiers/'.$id.'/modality', '/crm/dossiers/'.$id.'/pieces/deposit', '/crm/dossiers/'.$id.'/pieces/pre_enrolment', '/crm/settings/pieces/'.$piece?->getId(), '/crm/settings/origins/'.$origin?->getId(), '/crm/settings/years/'.$year.'/programs', '/crm/settings/copy?to='.$year.'&import=1', '/crm/settings/copy?from='.$this->program->getId(), '/crm/new/check?program='.$this->program->getId().'&mail=lea@exemple.fr'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testANewDossierRefusedComesBackAsTheDialogWithItsReasons(): void
    {
        $this->newDossier();
        $this->client->request('POST', '/crm/new', [
            '_token' => $this->csrfToken('crm'),
            'lastName' => 'Martin',
            'firstName' => 'Hugo',
            'email' => 'LEA@exemple.fr',
            'program' => (string) $this->program->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ne sert qu', (string) $this->client->getResponse()->getContent());
    }

    public function testTheBoardTicksABoxAndADialogEntersAValue(): void
    {
        $dossier = $this->newDossier();
        $back = '/crm/programs/'.$this->program->getId();

        $this->client->request('POST', '/crm/dossiers/'.$dossier->getId().'/pieces/file_completed/tick', ['_token' => $this->csrfToken('crm'), 'back' => $back]);
        self::assertResponseRedirects($back);

        $this->client->request('POST', '/crm/dossiers/'.$dossier->getId().'/pieces/deposit', ['_token' => $this->csrfToken('crm'), 'value' => '250', 'paymentMode' => 'Chèque', 'date' => '']);
        self::assertResponseRedirects('/crm/dossiers/'.$dossier->getId());

        $this->crmEntityManager()->clear();
        $dossier = $this->crmEntityManager()->find(CrmDossier::class, $dossier->getId());
        $codes = array_map(static fn ($e): string => $e->getPiece()->getCode(), $dossier?->getPieces()->toArray() ?? []);
        sort($codes);
        self::assertSame(['deposit', 'file_completed'], $codes);
    }

    public function testSituationModalityJournalAndDeletion(): void
    {
        $dossier = $this->newDossier();
        $id = $dossier->getId();
        $token = $this->csrfToken('crm');

        $this->client->request('POST', '/crm/dossiers/'.$id.'/situation', ['_token' => $token, 'situation' => 'waitlist', 'reason' => 'Formation complète.']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/dossiers/'.$id.'/modality', ['_token' => $token, 'modality' => 'initial', 'date' => '2026-10-01', 'reason' => '']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/dossiers/'.$id.'/journal', ['_token' => $token, 'kind' => 'call', 'text' => 'Message laissé.']);
        self::assertResponseRedirects();

        $this->crmEntityManager()->clear();
        $dossier = $this->crmEntityManager()->find(CrmDossier::class, $id);
        self::assertInstanceOf(CrmDossier::class, $dossier);
        self::assertSame(CrmSituation::Waitlist, $dossier->getSituation());
        self::assertSame(CrmModality::Initial, $dossier->getModality());
        self::assertSame('Message laissé.', $dossier->getJournal()->last()->getText());

        $this->client->request('POST', '/crm/dossiers/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/crm/programs/'.$this->program->getId());
        $this->crmEntityManager()->clear();
        self::assertNull($this->crmEntityManager()->find(CrmDossier::class, $id));
    }

    public function testSettingsWriteThroughTheirForms(): void
    {
        $token = (function (): string {
            $this->client->request('GET', '/crm/settings');

            return $this->csrfToken('crm');
        })();

        $this->client->request('POST', '/crm/settings/programs/'.$this->program->getId(), ['_token' => $token, 'modality' => 'apprenticeship', 'deposit' => '250', 'npec' => '']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/settings/programs/'.$this->program->getId().'/pieces/company', ['_token' => $token, 'constraint' => 'hidden']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/settings/pieces', ['_token' => $token, 'label' => 'Attestation CVEC', 'phase' => 'documents', 'kind' => 'list', 'choices' => "Demandée\nReçue", 'lastValueCompletes' => '1', 'paths' => 'both', 'modalities' => ['initial'], 'defaultConstraint' => 'optional']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/settings/origins', ['_token' => $token, 'label' => 'Formulaire de contact']);
        self::assertResponseRedirects();

        $this->crmEntityManager()->clear();
        $settings = $this->crmEntityManager()->getRepository(CrmProgram::class)->findOneBy(['program' => $this->program->getId()]);
        self::assertInstanceOf(CrmProgram::class, $settings);
        self::assertSame(CrmModality::Apprenticeship, $settings->getDefaultModality());
        self::assertSame(250, $settings->getDepositAmount());
        self::assertSame(['company' => 'hidden'], $settings->getPieceConstraints());
        $cvec = $this->crmEntityManager()->getRepository(CrmPiece::class)->findOneBy(['code' => 'attestation_cvec']);
        self::assertSame(['Demandée', 'Reçue'], $cvec?->getChoices());
        self::assertNotNull($this->crmEntityManager()->getRepository(CrmOrigin::class)->findOneBy(['label' => 'Formulaire de contact']));
    }

    public function testAYearNotOpenedAndAnUnknownPieceAnswer404(): void
    {
        $dossier = $this->newDossier();
        $this->client->request('GET', '/crm/dossiers/'.$dossier->getId().'/pieces/nope');
        self::assertResponseStatusCodeSame(404);

        $other = $this->createProgram([], [], $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'crm.other.author'));
        $this->client->request('GET', '/crm/programs/'.$other->getId());
        self::assertResponseStatusCodeSame(404);
    }
}
