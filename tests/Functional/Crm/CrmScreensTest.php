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
use App\Service\Crm\CrmSettings;
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
        $this->crmPiece('follow_up_check', CrmPhase::FollowUp, CrmPieceKind::Check, [CrmModality::Apprenticeship]);
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

        // The documents come in two groups: the entry ones, which make « Complet », and the follow-up ones.
        $crawler = $this->client->request('GET', '/crm/dossiers/'.$dossier->getId());
        $groups = $crawler->filter('.cm-crm-acc__title')->each(static fn ($n): string => trim($n->text()));
        self::assertContains('Documents d’entrée · Apprentissage', $groups);
        self::assertContains('Documents de suivi · Apprentissage', $groups);
        self::assertSame(['Pré-inscription', 'Envoi du dossier', 'Inscription', 'Pièces D.I.', 'Documents d’entrée', 'Complet'], $crawler->filter('.cm-crm-stepper .cm-crm-step b')->each(static fn ($n): string => trim($n->text())));

        // « Non renseignée »: no piece on the dossier, listed apart on the formation's page.
        $this->client->request('POST', '/crm/dossiers/'.$dossier->getId().'/modality', ['_token' => $this->csrfToken('crm'), 'modality' => 'unspecified', 'date' => '2026-10-01', 'reason' => '']);
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/crm/dossiers/'.$dossier->getId());
        self::assertResponseIsSuccessful();
        self::assertSame([], $crawler->filter('.cm-crm-acc__title')->each(static fn ($n): string => trim($n->text())));
        self::assertStringContainsString('Modalité non renseignée', $crawler->filter('.cm-crm-callout')->text());
        $this->client->request('GET', '/crm/years/'.$year);
        self::assertResponseIsSuccessful();
    }

    public function testAFormationListsItsCandidatesBySurnameAndExportsThemAll(): void
    {
        $lea = $this->newDossier('Dupont', 'Léa', 'lea@exemple.fr');
        $this->newDossier('Émery', 'Paul', 'paul@exemple.fr');
        $zoe = $this->newDossier('Abadie', 'Zoé', 'zoe@exemple.fr');
        // Every piece her pré-inscription asks for, whatever the catalogue holds (the seeded one adds
        // the apprentice's kit to an apprenticeship).
        $settings = static::getContainer()->get(CrmSettings::class);
        $dossier = $this->crmEntityManager()->find(CrmDossier::class, $zoe->getId()) ?? self::fail('dossier');
        foreach ($settings->progress($dossier)->applicableIn(CrmPhase::PreEnrolment) as $piece) {
            $value = match ($piece->getKind()) {
                CrmPieceKind::Check => [],
                CrmPieceKind::Choice, CrmPieceKind::List => ['value' => $piece->lastChoice() ?? ''],
                default => ['value' => '1'],
            };
            $this->client->request('POST', '/crm/dossiers/'.$zoe->getId().'/pieces/'.$piece->getCode(), ['_token' => $this->csrfToken('crm'), 'date' => ''] + $value);
            self::assertResponseRedirects();
        }
        // Her pré-inscription is complete while she still waits as a candidate: the stage says so.
        $warning = fn (int $id): int => $this->client->request('GET', '/crm/dossiers/'.$id)->filter('.cm-crm-acc__body .cm-crm-callout:contains("Pré-inscription complète")')->count();
        self::assertSame(1, $warning((int) $zoe->getId()));
        self::assertSame(0, $warning((int) $lea->getId()));
        $this->client->request('POST', '/crm/dossiers/'.$zoe->getId().'/situation', ['_token' => $this->csrfToken('crm'), 'situation' => 'active', 'reason' => '']);
        self::assertSame(0, $warning((int) $zoe->getId()));
        $this->client->request('POST', '/crm/dossiers/'.$zoe->getId().'/situation', ['_token' => $this->csrfToken('crm'), 'situation' => 'pending', 'reason' => '']);
        $this->client->request('POST', '/crm/dossiers/'.$lea->getId().'/situation', ['_token' => $this->csrfToken('crm'), 'situation' => 'resigned', 'reason' => '']);
        self::assertResponseRedirects();

        $names = fn (string $url): array => $this->client->request('GET', $url)->filter('.cm-crm-list .cm-crm-rowlink')->each(static fn ($n): string => trim($n->text()));
        $program = '/crm/programs/'.$this->program->getId();
        // A new dossier waits as « Candidature en attente »; the list shows one situation at a time,
        // « Candidature en attente » unless another is chosen, in surname order the French way (É
        // among the E).
        self::assertSame(['ABADIE Zoé', 'ÉMERY Paul'], $names($program));
        self::assertSame([], $names($program.'?situation=active'));
        self::assertSame(['DUPONT Léa'], $names($program.'?situation=resigned'));
        $crawler = $this->client->request('GET', $program);
        self::assertSame(['pending', 'active', 'resigned', 'cancelled'], $crawler->filter('#crm-situation-filter option')->each(static fn ($n): string => (string) $n->attr('value')));
        self::assertSame('pending', $crawler->filter('#crm-situation-filter option[selected]')->attr('value'));
        self::assertStringStartsWith('Admis', trim($crawler->filter('#crm-situation-filter option[value=active]')->text()));
        $crawler = $this->client->request('GET', $program.'?situation=pending');
        self::assertSame(['Candidat', 'Modalité', 'Pré-inscription', 'Envoi du dossier', 'Inscription', 'Pièces D.I.', 'Dossier complet'], $crawler->filter('.cm-crm-list thead th')->each(static fn ($n): string => trim($n->text())));
        // Zoé's pré-inscription is done: one green mark, on her row.
        self::assertCount(1, $crawler->filter('.cm-crm-list .cm-crm-mark.is-done'));
        self::assertCount(1, $crawler->filter('.cm-crm-list tbody tr')->first()->filter('.cm-crm-mark.is-done'));

        $this->client->request('GET', $program.'/export');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $lines = array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\n", trim(substr($csv, 3))));
        self::assertSame(['Nom', 'Prénom', 'Modalité', 'Situation', 'Pré-inscription', 'Envoi du dossier', 'Inscription', 'Pièces D.I.', 'Dossier complet', 'Mail', 'Téléphone'], $lines[0]);
        self::assertSame(['ABADIE', 'DUPONT', 'ÉMERY'], array_map(static fn (array $l): ?string => $l[0], \array_slice($lines, 1)));
        self::assertSame(['DUPONT', 'Léa', 'Apprentissage', 'Démission', 'Non', 'Sans objet', 'Non', 'Sans objet', 'Non', 'lea@exemple.fr', ''], $lines[2]);
        self::assertSame('Oui', $lines[1][4]);
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

    public function testADialogEntersABoxAndAValue(): void
    {
        $dossier = $this->newDossier();

        $this->client->request('POST', '/crm/dossiers/'.$dossier->getId().'/pieces/file_completed', ['_token' => $this->csrfToken('crm'), 'date' => '']);
        self::assertResponseRedirects('/crm/dossiers/'.$dossier->getId());

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

        $this->client->request('POST', '/crm/dossiers/'.$id.'/situation', ['_token' => $token, 'situation' => 'active', 'reason' => 'Candidature retenue.']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/dossiers/'.$id.'/modality', ['_token' => $token, 'modality' => 'initial', 'date' => '2026-10-01', 'reason' => '']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/dossiers/'.$id.'/journal', ['_token' => $token, 'kind' => 'call', 'text' => 'Message laissé.']);
        self::assertResponseRedirects();

        $this->crmEntityManager()->clear();
        $dossier = $this->crmEntityManager()->find(CrmDossier::class, $id);
        self::assertInstanceOf(CrmDossier::class, $dossier);
        self::assertSame(CrmSituation::Active, $dossier->getSituation());
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

        $slam = $this->crmOption($this->program, 'SLAM', $this->admin);
        $this->crmOption($this->program, 'SISR', $this->admin);
        // An id that is not an option of the formation is dropped.
        $this->client->request('POST', '/crm/settings/programs/'.$this->program->getId(), ['_token' => $token, 'modality' => 'apprenticeship', 'deposit' => '250', 'npec' => '', 'tracked' => [(string) $slam->getId(), '999999']]);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/settings/programs/'.$this->program->getId().'/pieces/company', ['_token' => $token, 'constraint' => 'hidden']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/settings/pieces', ['_token' => $token, 'label' => 'Attestation CVEC', 'phase' => 'documents', 'kind' => 'list', 'choices' => "Demandée\nReçue", 'lastValueCompletes' => '1', 'paths' => 'both', 'modalities' => ['initial'], 'defaultConstraint' => 'optional']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/settings/origins', ['_token' => $token, 'label' => 'Formulaire de contact']);
        self::assertResponseRedirects();
        // « Complétude » unticked moves a document to the follow-up ones, ticked brings it back.
        $company = $this->crmEntityManager()->getRepository(CrmPiece::class)->findOneBy(['code' => 'company']);
        $npec = $this->crmEntityManager()->getRepository(CrmPiece::class)->findOneBy(['code' => 'follow_up_check']);
        $this->client->request('POST', '/crm/settings/pieces/'.$company?->getId().'/inline', ['_token' => $token, 'modalities' => ['apprenticeship'], 'defaultConstraint' => 'required']);
        self::assertResponseRedirects();
        $this->client->request('POST', '/crm/settings/pieces/'.$npec?->getId().'/inline', ['_token' => $token, 'modalities' => ['apprenticeship'], 'defaultConstraint' => 'required', 'completion' => '1']);
        self::assertResponseRedirects();

        $this->crmEntityManager()->clear();
        $settings = $this->crmEntityManager()->getRepository(CrmProgram::class)->findOneBy(['program' => $this->program->getId()]);
        self::assertInstanceOf(CrmProgram::class, $settings);
        self::assertSame(CrmModality::Apprenticeship, $settings->getDefaultModality());
        self::assertSame(250, $settings->getDepositAmount());
        self::assertSame([$slam->getId()], $settings->getTrackedOptionIds());
        self::assertSame(['company' => 'hidden'], $settings->getPieceConstraints());
        self::assertSame(CrmPhase::FollowUp, $this->crmEntityManager()->getRepository(CrmPiece::class)->findOneBy(['code' => 'company'])?->getPhase());
        self::assertSame(CrmPhase::Documents, $this->crmEntityManager()->getRepository(CrmPiece::class)->findOneBy(['code' => 'follow_up_check'])?->getPhase());
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

    public function testTheHeaderButtonsAndTheirDialogShareOneController(): void
    {
        $dossier = $this->newDossier();
        $year = (int) $this->program->getSchoolYear()?->getId();
        // « Nouveau dossier » and « Corriger » sit in the header, outside `main`: the controller
        // that opens them must hold the header too.
        foreach (['/crm', '/crm/years/'.$year, '/crm/programs/'.$this->program->getId(), '/crm/dossiers/'.$dossier->getId()] as $path) {
            $crawler = $this->client->request('GET', $path);
            $wrapper = $crawler->filter('.page-wrapper[data-controller~="crm-dialog"]');
            self::assertCount(1, $wrapper, $path);
            self::assertCount(1, $wrapper->filter('dialog.cm-crm-dialog'), $path);
            self::assertGreaterThan(0, $wrapper->filter('.page-header [data-action="crm-dialog#open"], .page-body [data-crm-dialog-url-param*="/crm/new"]')->count(), $path);
        }
    }

    public function testAPieceTickedForTheHomeIsListedUnderActionRequiredWhileADossierAwaitsIt(): void
    {
        $piece = $this->crmPiece('file_completed');
        $crawler = $this->client->request('GET', '/crm/settings?tab=pieces');
        self::assertCount(1, $crawler->filter('#piece-file_completed input[name=homeAlert]:not([checked])'), 'unticked by default');
        $this->client->request('POST', '/crm/settings/pieces/'.$piece->getId().'/inline', [
            '_token' => $this->csrfToken('crm'),
            'modalities' => array_map(static fn (CrmModality $m): string => $m->value, $piece->getModalities()),
            'defaultConstraint' => $piece->defaultConstraint()->value,
            'homeAlert' => '1',
        ]);
        self::assertResponseRedirects();
        $this->crmEntityManager()->clear();
        self::assertTrue($this->crmEntityManager()->find(CrmPiece::class, $piece->getId())?->isHomeAlert());

        $staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'crm.home.staff');
        $this->crmLightFor('ROLE_STAFF');
        $dossier = $this->newDossier('Martin', 'Hugo', 'hugo@exemple.fr');
        // Every piece before « file_completed »'s stage, so that its stage is reached.
        $settings = static::getContainer()->get(CrmSettings::class);
        $loaded = $this->crmEntityManager()->find(CrmDossier::class, $dossier->getId()) ?? self::fail('dossier');
        $progress = $settings->progress($loaded);
        foreach ([CrmPhase::PreEnrolment, CrmPhase::FileSending] as $phase) {
            foreach ($progress->applicableIn($phase) as $before) {
                $value = match ($before->getKind()) {
                    CrmPieceKind::Check => [],
                    CrmPieceKind::Choice, CrmPieceKind::List => ['value' => $before->lastChoice() ?? ''],
                    default => ['value' => '1'],
                };
                $this->client->request('POST', '/crm/dossiers/'.$dossier->getId().'/pieces/'.$before->getCode(), ['_token' => $this->csrfToken('crm'), 'date' => ''] + $value);
                self::assertResponseRedirects();
            }
        }

        $this->client->loginUser($staff);
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('.cm-home-crm-actions');
        self::assertCount(1, $card);
        self::assertSame('Action requise', trim($card->filter('.card-title')->text()));
        self::assertContains('Inscription', $card->filter('.cm-home-crm-actions__step')->each(static fn ($n): string => trim($n->text())));
        $row = $card->filter('tbody tr:contains("MARTIN Hugo")');
        self::assertCount(1, $row);
        self::assertSame(['MARTIN Hugo', $this->program->getShortName(), 'File completed'], $row->filter('td')->each(static fn ($n): string => trim($n->text())));

        // Filled in: nothing awaits it any more.
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/crm/dossiers/'.$dossier->getId().'/pieces/file_completed', ['_token' => $this->csrfToken('crm'), 'date' => '']);
        self::assertResponseRedirects();
        $this->client->loginUser($staff);
        self::assertCount(0, $this->client->request('GET', '/')->filter('.cm-home-crm-actions tbody tr:contains("MARTIN Hugo")'));
    }
}
