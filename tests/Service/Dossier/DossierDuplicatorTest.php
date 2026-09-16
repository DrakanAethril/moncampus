<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier;

use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\DossierGroup;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\DossierDepositType;
use App\Enum\DossierState;
use App\Enum\DossierValidationProfile;
use App\Service\Dossier\DossierDuplicator;
use PHPUnit\Framework\TestCase;

/**
 * « Dupliquer » — what a copy carries and, above all, what it does not.
 *
 * The rule is the whole test: a duplicate carries what somebody **wrote** and none of what
 * **happened**. A copy addressed to last year's class would be a dossier asked of students who have
 * left; the dates move by a year for the same reason.
 */
class DossierDuplicatorTest extends TestCase
{
    private function source(User $creator, User $coValidator, Program $program): Dossier
    {
        $dossier = new Dossier();
        $dossier->setCreatedBy($creator);
        $dossier->addValidator($creator);
        $dossier->addValidator($coValidator);
        $dossier->setTitle('Dossier de soutenance');
        $dossier->setDescription('Déposez les pièces.');
        $dossier->setStartsOn(new \DateTimeImmutable('2026-09-01'));
        $dossier->setEndsOn(new \DateTimeImmutable('2026-11-10'));
        $dossier->addTargetProgram($program);
        $dossier->addTargetStudent(new User('student'));
        $dossier->publish();

        $group = new DossierGroup($dossier, 'Rapport écrit', 0);

        (new DossierDocument($dossier))
            ->setName('Rapport de stage')
            ->setGroup($group)
            ->setRequired(true)
            ->setDepositType(DossierDepositType::Upload)
            ->setVisibleFrom(new \DateTimeImmutable('2026-09-01'))
            ->setDueOn(new \DateTimeImmutable('2026-10-15'))
            ->setLateAllowed(true)
            ->setValidationProfile(DossierValidationProfile::Validation);

        (new DossierDocument($dossier))
            ->setName('Autorisation de diffusion')
            ->setRequired(false)
            ->setDepositType(DossierDepositType::Url)
            ->setVisibleFrom(new \DateTimeImmutable('2026-10-15'))
            ->setDueOn(new \DateTimeImmutable('2026-11-10'))
            ->setValidationProfile(DossierValidationProfile::Deposit);

        return $dossier;
    }

    public function testACopyIsADraftOfTheSameComposition(): void
    {
        $creator = new User('creator');
        $copy = (new DossierDuplicator())->duplicate($this->source($creator, new User('other'), $this->createStub(Program::class)), $creator);

        self::assertSame(DossierState::Draft, $copy->getState());
        self::assertSame('Dossier de soutenance', $copy->getTitle());
        self::assertSame('Déposez les pièces.', $copy->getDescription());
        self::assertCount(1, $copy->getGroups());
        self::assertCount(2, $copy->getDocuments());
    }

    public function testEveryDateMovesOnByAYear(): void
    {
        $creator = new User('creator');
        $copy = (new DossierDuplicator())->duplicate($this->source($creator, new User('other'), $this->createStub(Program::class)), $creator);

        self::assertSame('2027-09-01', $copy->getStartsOn()?->format('Y-m-d'));
        self::assertSame('2027-11-10', $copy->getEndsOn()?->format('Y-m-d'));

        $documents = array_values($copy->getDocuments()->toArray());
        self::assertSame('2027-09-01', $documents[0]->getVisibleFrom()?->format('Y-m-d'));
        self::assertSame('2027-10-15', $documents[0]->getDueOn()?->format('Y-m-d'));
    }

    public function testTheRulesOfEachDocumentSurviveIntact(): void
    {
        $creator = new User('creator');
        $copy = (new DossierDuplicator())->duplicate($this->source($creator, new User('other'), $this->createStub(Program::class)), $creator);
        $documents = array_values($copy->getDocuments()->toArray());

        self::assertTrue($documents[0]->isRequired());
        self::assertTrue($documents[0]->isLateAllowed());
        self::assertSame(DossierValidationProfile::Validation, $documents[0]->getValidationProfile());
        self::assertSame('Rapport écrit', $documents[0]->getGroup()?->getTitle());

        self::assertFalse($documents[1]->isRequired());
        self::assertSame(DossierDepositType::Url, $documents[1]->getDepositType());
        // « Hors groupe » is copied as « hors groupe », not quietly filed somewhere.
        self::assertNull($documents[1]->getGroup());
    }

    public function testTheGroupsAreNewRowsRatherThanTheOriginals(): void
    {
        $creator = new User('creator');
        $source = $this->source($creator, new User('other'), $this->createStub(Program::class));
        $copy = (new DossierDuplicator())->duplicate($source, $creator);

        $sourceGroup = $source->getGroups()->first();
        $copyGroup = $copy->getGroups()->first();

        self::assertNotSame($sourceGroup, $copyGroup);
        // Editing the copy's group must not rename the original's.
        self::assertSame($copy, $copyGroup->getDossier());
    }

    public function testNoCibleAndNoValidatorButTheOneDuplicating(): void
    {
        $creator = new User('creator');
        $duplicator = new User('someone-else');
        $copy = (new DossierDuplicator())->duplicate($this->source($creator, new User('other'), $this->createStub(Program::class)), $duplicator);

        self::assertCount(0, $copy->getTargetPrograms());
        self::assertCount(0, $copy->getTargetStudents());
        self::assertCount(1, $copy->getValidators());
        self::assertTrue($copy->isValidator($duplicator));
        self::assertTrue($copy->isCreator($duplicator));
    }
}
