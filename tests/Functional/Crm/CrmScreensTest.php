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
        self::assertSame(3, $crawler->filter('.cm-crm-stepper .cm-crm-step')->count() - 1);

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
        $this->client->request('POST', '/crm/dossiers/'.$lea->getId().'/situation', ['_token' => $this->csrfToken('crm'), 'situation' => 'resigned', 'reason' => '']);
        self::assertResponseRedirects();

        $names = fn (string $url): array => $this->client->request('GET', $url)->filter('.cm-crm-list .cm-crm-rowlink')->each(static fn ($n): string => trim($n->text()));
        $program = '/crm/programs/'.$this->program->getId();
        // Surname order, the French way (É among the E), the resigned left out until asked for.
        self::assertSame(['ABADIE Zoé', 'ÉMERY Paul'], $names($program));
        self::assertSame(['ABADIE Zoé', 'DUPONT Léa', 'ÉMERY Paul'], $names($program.'?closed=1'));
        $crawler = $this->client->request('GET', $program);
        self::assertSame(['Candidat', 'Modalité', 'Pré-inscription', 'Inscription', 'Dossier complet'], $crawler->filter('.cm-crm-list thead th')->each(static fn ($n): string => trim($n->text())));
        // Zoé's pré-inscription is done: one green mark, on her row.
        self::assertCount(1, $crawler->filter('.cm-crm-list .cm-crm-mark.is-done'));
        self::assertCount(1, $crawler->filter('.cm-crm-list tbody tr')->first()->filter('.cm-crm-mark.is-done'));

        $this->client->request('GET', $program.'/export');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $lines = array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\n", trim(substr($csv, 3))));
        self::assertSame(['Nom', 'Prénom', 'Modalité', 'Situation', 'Pré-inscription', 'Inscription', 'Dossier complet', 'Mail', 'Téléphone'], $lines[0]);
        self::assertSame(['ABADIE', 'DUPONT', 'ÉMERY'], array_map(static fn (array $l): ?string => $l[0], \array_slice($lines, 1)));
        self::assertSame(['DUPONT', 'Léa', 'Apprentissage', 'Démission', 'Non', 'Non', 'Non', 'lea@exemple.fr', ''], $lines[2]);
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
}
