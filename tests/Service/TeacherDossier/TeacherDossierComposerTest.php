<?php

declare(strict_types=1);

namespace App\Tests\Service\TeacherDossier;

use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierDocument;
use App\Entity\TeacherDossierGroup;
use App\Entity\User;
use App\Enum\DossierDepositType;
use App\Enum\DossierValidationProfile;
use App\Service\TeacherDossier\TeacherDossierComposer;
use PHPUnit\Framework\TestCase;

/**
 * A model is copied, never linked: what is copied is what somebody wrote, and each dossier made
 * from it is its own.
 */
class TeacherDossierComposerTest extends TestCase
{
    private User $staff;

    protected function setUp(): void
    {
        $this->staff = new User('claire');
    }

    private function model(): TeacherDossier
    {
        $model = TeacherDossier::asTemplate($this->staff, 'Vacataire indépendant');
        $model->setDescription('Pièces de moins de six mois.');
        $identity = new TeacherDossierGroup($model, 'Identité', 0);
        $status = new TeacherDossierGroup($model, 'Statut', 1);

        (new TeacherDossierDocument($model))->setName('Pièce d’identité')->setGroup($identity)->setValidationProfile(DossierValidationProfile::Validation);
        (new TeacherDossierDocument($model))->setName('RIB')->setGroup($status);
        (new TeacherDossierDocument($model))->setName('Site professionnel')->setRequired(false)->setDepositType(DossierDepositType::Url);

        return $model;
    }

    public function testAModelHasNoIntervenantAndIsNeverPublished(): void
    {
        $model = $this->model();

        self::assertTrue($model->isTemplate());
        self::assertFalse($model->isReadableByTeacher());

        $this->expectException(\LogicException::class);
        $model->publish();
    }

    public function testAModelKeepsNoDateOnItsPieces(): void
    {
        $model = TeacherDossier::asTemplate($this->staff, 'Modèle');
        $piece = (new TeacherDossierDocument($model))->setName('RIB')->setDueOn(new \DateTimeImmutable('2026-10-31'));

        self::assertNull($piece->getDueOn());
    }

    public function testOneDossierPerIntervenantEachABrouillonWithTheModelsComposition(): void
    {
        $model = $this->model();
        $nadia = new User('nadia');
        $julien = new User('julien');
        $due = new \DateTimeImmutable('2026-10-31');

        $dossiers = (new TeacherDossierComposer())->fromTemplate($model, [$nadia, $julien], $this->staff, 'Dossier administratif 2026-2027', $due);

        self::assertCount(2, $dossiers);
        self::assertSame($nadia, $dossiers[0]->getTeacher());
        self::assertSame($julien, $dossiers[1]->getTeacher());

        foreach ($dossiers as $dossier) {
            self::assertTrue($dossier->isDraft());
            self::assertFalse($dossier->isTemplate());
            self::assertSame($model, $dossier->getSourceTemplate());
            self::assertSame('Dossier administratif 2026-2027', $dossier->getTitle());
            self::assertSame('Pièces de moins de six mois.', $dossier->getDescription());
            self::assertCount(2, $dossier->getGroups());
            self::assertCount(3, $dossier->getDocuments());
            self::assertSame(2, $dossier->requiredDocumentCount());

            foreach ($dossier->getDocuments() as $piece) {
                self::assertEquals($due, $piece->getDueOn(), 'The date given at creation lands on every piece.');
            }
        }
    }

    public function testTheCopyIsFiledUnderItsOwnGroupsNotTheModels(): void
    {
        $model = $this->model();
        [$dossier] = (new TeacherDossierComposer())->fromTemplate($model, [new User('nadia')], $this->staff, 'Dossier', null);

        $grouped = $dossier->groupedDocuments();

        self::assertSame(['Identité', 'Statut', null], array_map(static fn (array $entry): ?string => $entry['group']?->getTitle(), $grouped));
        self::assertSame('Pièce d’identité', $grouped[0]['documents'][0]->getName());
        self::assertSame($dossier, $grouped[0]['group']?->getDossier());
        self::assertTrue($grouped[0]['documents'][0]->needsValidation());
        self::assertSame(DossierDepositType::Url, $grouped[2]['documents'][0]->getDepositType());
        self::assertNull($grouped[0]['documents'][0]->getDueOn(), 'No date given, none invented.');
    }

    public function testChangingTheModelAfterwardsChangesNoDossier(): void
    {
        $model = $this->model();
        [$dossier] = (new TeacherDossierComposer())->fromTemplate($model, [new User('nadia')], $this->staff, 'Dossier', null);

        (new TeacherDossierDocument($model))->setName('Casier judiciaire');

        self::assertCount(4, $model->getDocuments());
        self::assertCount(3, $dossier->getDocuments());
    }

    public function testSavingADossierAsAModelDropsItsIntervenantItsDatesAndItsState(): void
    {
        $dossier = TeacherDossier::forTeacher($this->staff, new User('nadia'), 'Dossier de Nadia');
        (new TeacherDossierDocument($dossier))->setName('RIB')->setDueOn(new \DateTimeImmutable('2026-10-31'));
        $dossier->publish();

        $model = (new TeacherDossierComposer())->toTemplate($dossier, $this->staff, 'Nouveau modèle');

        self::assertTrue($model->isTemplate());
        self::assertTrue($model->isDraft());
        self::assertNull($model->getSourceTemplate());
        self::assertSame('Nouveau modèle', $model->getTitle());
        self::assertCount(1, $model->getDocuments());
        self::assertNull($model->getDocuments()->first()->getDueOn());
    }
}
