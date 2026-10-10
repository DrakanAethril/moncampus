<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierDocument;
use App\Entity\TeacherDossierSubmission;
use App\Entity\User;
use App\Enum\DossierDepositType;
use App\Enum\DossierDocumentStatus;
use App\Enum\DossierValidationProfile;
use App\Repository\TeacherDossierRepository;
use App\Service\TeacherDossier\TeacherDossierSheets;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Dossiers intervenants » end to end (design/validated/dossiers-intervenants.md): the
 * administration composes a model and hands it to several teachers, the intervenant deposits, the
 * administration answers - and everybody else is answered that the dossier does not exist.
 *
 * The pieces here are `url` ones: what is exercised is who may write what and what it does to the
 * status, and a link goes through the very same door as a file
 * (App\Service\TeacherDossier\TeacherDossierDeposits) without needing a bucket.
 */
class TeacherDossierTest extends FunctionalTestCase
{
    private const string BANNER = 'Des pièces sont attendues pour votre dossier administratif';

    private User $staff;
    private User $holder;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'dossier.staff');
        $this->holder = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'dossier.holder');
        $this->other = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'dossier.other');
    }

    public function testTheAdministrationComposesAModelAndHandsItToSeveralTeachers(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('GET', '/ufa/teacher-dossiers');
        self::assertResponseIsSuccessful();
        $token = $this->csrfToken('teacher_dossier');

        $this->client->request('POST', '/ufa/teacher-dossiers/templates/new', ['_token' => $token, 'title' => 'Vacataire indépendant']);
        self::assertResponseRedirects();
        $model = $this->dossiers()->findTemplates()[0];
        $base = '/ufa/teacher-dossiers/'.$model->getId();

        $this->client->request('POST', $base.'/groups', ['_token' => $token, 'title' => 'Identité']);
        $model = $this->reload($model);
        $group = $model->getGroups()->first() ?: throw new \LogicException('The group was not created.');

        $this->client->request('POST', $base.'/documents/save', [
            '_token' => $token, 'name' => 'Pièce d’identité', 'group_id' => $group->getId(), 'required' => '1',
            'deposit_type' => 'upload', 'validation_profile' => 'validation', 'due_on' => '2026-12-31',
        ]);
        $this->client->request('POST', $base.'/documents/save', ['_token' => $token, 'name' => 'Assurance', 'deposit_type' => 'url', 'validation_profile' => 'depot']);
        $model = $this->reload($model);

        self::assertCount(2, $model->getDocuments());
        self::assertSame(1, $model->requiredDocumentCount());
        self::assertNull($model->getDocuments()->first()->getDueOn(), 'A model keeps no date, even when one is posted.');

        foreach ([$base.'/settings', $base.'/documents', '/ufa/teacher-dossiers/templates', '/ufa/teacher-dossiers/new?template='.$model->getId()] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }

        // A model has no third step: there is nobody to publish it to.
        $this->client->request('GET', $base.'/summary');
        self::assertResponseRedirects($base.'/documents');

        $this->client->request('POST', '/ufa/teacher-dossiers/new', [
            '_token' => $token, 'mode' => 'template', 'template_id' => $model->getId(), 'title' => 'Dossier administratif 2026-2027',
            'teachers' => [$this->holder->getId(), $this->other->getId(), $this->staff->getId()], 'due_on' => '2026-10-31', 'intent' => 'publish',
        ]);
        self::assertResponseRedirects('/ufa/teacher-dossiers?filter=incomplete');

        $created = $this->dossiers()->findDossiers();
        self::assertCount(2, $created, 'One dossier per teacher named - and the staff member, who is not a teacher, was dropped.');

        foreach ($created as $dossier) {
            self::assertTrue($dossier->isPublished());
            self::assertSame('Dossier administratif 2026-2027', $dossier->getTitle());
            self::assertSame($model->getId(), $dossier->getSourceTemplate()?->getId());
            self::assertCount(2, $dossier->getDocuments());
            self::assertSame('2026-10-31', $dossier->getDocuments()->first()->getDueOn()?->format('Y-m-d'));
        }

        self::assertSame([$model->getId() => 2], $this->dossiers()->countByTemplate());

        $crawler = $this->client->request('GET', '/ufa/teacher-dossiers');
        self::assertCount(2, $crawler->filter('.cm-td-list__row'), 'Both are incomplete, which is what the list opens on.');
        self::assertCount(0, $this->client->request('GET', '/ufa/teacher-dossiers?filter=complete')->filter('.cm-td-list__row'));
        self::assertCount(1, $this->client->request('GET', '/ufa/teacher-dossiers?filter=all&q=holder')->filter('.cm-td-list__row'));

        // Deleting the model leaves the dossiers made from it exactly as they are.
        $this->client->request('POST', $base.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/ufa/teacher-dossiers/templates');
        $this->em()->clear();
        self::assertCount(2, $this->dossiers()->findDossiers());
        self::assertSame([], $this->dossiers()->findTemplates());
    }

    public function testABlankDossierIsForOneTeacherAndIsNotPublishedEmpty(): void
    {
        $this->client->loginUser($this->staff);
        $this->client->request('GET', '/ufa/teacher-dossiers/new');
        $token = $this->csrfToken('teacher_dossier');

        $this->client->request('POST', '/ufa/teacher-dossiers/new', ['_token' => $token, 'mode' => 'blank', 'title' => 'Jury', 'teachers' => [$this->holder->getId(), $this->other->getId()]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->dossiers()->count([]), 'Two teachers and no model: refused, nothing written.');

        $this->client->request('POST', '/ufa/teacher-dossiers/new', ['_token' => $token, 'mode' => 'blank', 'title' => 'Jury', 'teachers' => [$this->holder->getId()], 'intent' => 'publish']);
        $dossier = $this->dossiers()->findDossiers()[0];
        self::assertResponseRedirects('/ufa/teacher-dossiers/'.$dossier->getId().'/documents?piece=new');
        self::assertTrue($dossier->isDraft(), 'A blank dossier has nothing to hand over yet, whatever was asked.');

        $this->client->request('POST', '/ufa/teacher-dossiers/'.$dossier->getId().'/publish', ['_token' => $token]);
        $dossier = $this->reload($dossier);
        self::assertTrue($dossier->isDraft(), 'A dossier with no piece is not published.');

        // A brouillon opens on its editor, and its intervenant does not see it yet.
        $this->client->request('GET', '/ufa/teacher-dossiers/'.$dossier->getId());
        self::assertResponseRedirects('/ufa/teacher-dossiers/'.$dossier->getId().'/documents');
        $this->assertScreens($this->holder, ['/my-administrative-dossiers/'.$dossier->getId() => 404]);
        self::assertStringNotContainsString(self::BANNER, (string) $this->client->request('GET', '/')->html());
    }

    public function testTheIntervenantDepositsAndTheAdministrationAnswers(): void
    {
        $dossier = $this->publishedDossier();
        $piece = $this->piece($dossier, 'Profil professionnel', validation: true);
        $mine = '/my-administrative-dossiers/'.$dossier->getId();
        $fiche = '/ufa/teacher-dossiers/'.$dossier->getId();

        // The intervenant is told on their home, and the profile menu's entry opens the one dossier.
        $this->client->loginUser($this->holder);
        $home = (string) $this->client->request('GET', '/')->html();
        self::assertStringContainsString(self::BANNER, $home);
        self::assertStringContainsString('/my-administrative-dossiers', $home);
        $this->client->request('GET', '/my-administrative-dossiers');
        self::assertResponseRedirects($mine);
        $this->client->request('GET', $mine.'?piece='.$piece->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('POST', $mine.'/documents/'.$piece->getId(), ['_token' => $this->csrfToken('teacher_dossier_submission'), 'url' => 'pas une adresse']);
        self::assertSame(DossierDocumentStatus::Missing, $this->statusOf($dossier, $piece), 'An invalid link is not a dépôt.');

        $this->client->request('POST', $mine.'/documents/'.$piece->getId(), ['_token' => $this->csrfToken('teacher_dossier_submission'), 'url' => 'https://example.org/profil']);
        self::assertResponseRedirects($mine.'?piece='.$piece->getId());
        self::assertSame(DossierDocumentStatus::AwaitingReview, $this->statusOf($dossier, $piece));
        self::assertStringNotContainsString(self::BANNER, (string) $this->client->request('GET', '/')->html(), 'Waiting for the administration is not waiting for the intervenant.');

        // The administration asks for a correction - which must say what to correct.
        $this->client->loginUser($this->staff);
        $this->client->request('GET', $fiche.'?piece='.$piece->getId());
        self::assertResponseIsSuccessful();
        $token = $this->csrfToken('teacher_dossier');
        $submission = $this->sheets()->of($dossier)->lines[(int) $piece->getId()]->latest ?? throw new \LogicException('No dépôt.');
        $review = $fiche.'/submissions/'.$submission->getId().'/review';

        $this->client->request('POST', $review, ['_token' => $token, 'action' => 'demande_correction', 'comment' => '  ']);
        self::assertSame(DossierDocumentStatus::AwaitingReview, $this->statusOf($dossier, $piece));
        $this->client->request('POST', $review, ['_token' => $token, 'action' => 'demande_correction', 'comment' => 'Le lien ne s’ouvre pas.']);
        self::assertSame(DossierDocumentStatus::ToCorrect, $this->statusOf($dossier, $piece));

        // Back to the intervenant: the banner again, the comment, a second version.
        $this->client->loginUser($this->holder);
        self::assertStringContainsString(self::BANNER, (string) $this->client->request('GET', '/')->html());
        self::assertStringContainsString('Le lien ne s’ouvre pas.', (string) $this->client->request('GET', $mine.'?piece='.$piece->getId())->html());
        $this->client->request('POST', $mine.'/documents/'.$piece->getId(), ['_token' => $this->csrfToken('teacher_dossier_submission'), 'url' => 'https://example.org/profil-v2']);
        self::assertSame(DossierDocumentStatus::AwaitingReview, $this->statusOf($dossier, $piece));

        // The version already replaced is no longer answered; the latest is validated.
        $this->client->loginUser($this->staff);
        $this->client->request('GET', $fiche);
        $token = $this->csrfToken('teacher_dossier');
        $this->client->request('POST', $review, ['_token' => $token, 'action' => 'validation']);
        self::assertSame(DossierDocumentStatus::AwaitingReview, $this->statusOf($dossier, $piece), 'v1 was replaced by v2.');

        $latest = $this->sheets()->of($dossier)->lines[(int) $piece->getId()]->latest ?? throw new \LogicException('No dépôt.');
        self::assertSame(2, $latest->getVersion());
        $this->client->request('POST', $fiche.'/submissions/'.$latest->getId().'/review', ['_token' => $token, 'action' => 'validation']);

        $sheet = $this->sheets()->of($dossier);
        self::assertSame(DossierDocumentStatus::Validated, $sheet->lines[(int) $piece->getId()]->status);
        self::assertTrue($sheet->isComplete());
        self::assertCount(1, $this->client->request('GET', '/ufa/teacher-dossiers?filter=complete')->filter('.cm-td-list__row'));

        // Complete: no banner any more, and the dossier is still there to be read.
        $this->client->loginUser($this->holder);
        $home = (string) $this->client->request('GET', '/')->html();
        self::assertStringNotContainsString(self::BANNER, $home);
        self::assertStringContainsString('/my-administrative-dossiers', $home);
    }

    public function testTheAdministrationDepositsForTheIntervenantAndThePieceThenStays(): void
    {
        $dossier = $this->publishedDossier();
        $piece = $this->piece($dossier, 'Site de l’entreprise');
        $fiche = '/ufa/teacher-dossiers/'.$dossier->getId();

        $this->client->loginUser($this->staff);
        $this->client->request('GET', $fiche);
        $token = $this->csrfToken('teacher_dossier');
        $this->client->request('POST', $fiche.'/documents/'.$piece->getId().'/deposit', ['_token' => $token, 'url' => 'https://example.org/']);
        self::assertResponseRedirects($fiche.'?piece='.$piece->getId());

        $line = $this->sheets()->of($dossier)->lines[(int) $piece->getId()];
        self::assertSame(DossierDocumentStatus::Deposited, $line->status);
        self::assertTrue($line->latest?->isOnBehalf());
        self::assertSame($this->staff->getId(), $line->latest->getSubmittedBy()?->getId());

        // A piece somebody deposited on is no longer deleted - it is made facultative instead.
        $this->client->request('POST', $fiche.'/documents/'.$piece->getId().'/delete', ['_token' => $token]);
        $dossier = $this->reload($dossier);
        self::assertCount(1, $dossier->getDocuments());

        // One with no dépôt goes, and the editor stays open after publication to add another.
        $empty = $this->piece($dossier, 'Diplôme');
        $this->client->request('POST', $fiche.'/documents/'.$empty->getId().'/delete', ['_token' => $token]);
        $dossier = $this->reload($dossier);
        self::assertCount(1, $dossier->getDocuments());
        self::assertSame('Site de l’entreprise', $dossier->getDocuments()->first()->getName());
    }

    public function testADossierBelongsToTheAdministrationAndToItsIntervenantAlone(): void
    {
        $dossier = $this->publishedDossier();
        $piece = $this->piece($dossier, 'RIB', type: DossierDepositType::Upload);
        $submission = TeacherDossierSubmission::ofFile($piece, $this->holder, 1, 'teacher-dossiers/1/0123456789abcdef0123456789abcdef.pdf', 'rib.pdf', 2048);
        $this->em()->persist($submission);
        $this->em()->flush();

        $mine = '/my-administrative-dossiers/'.$dossier->getId();
        $fiche = '/ufa/teacher-dossiers/'.$dossier->getId();
        $file = '/teacher-dossiers/files/'.$submission->getId();

        // Another teacher: the dossier does not exist, and neither do its files.
        $this->assertScreens($this->other, [$mine => 404, $file => 404, $file.'/raw' => 404, $file.'/download' => 404, $fiche => 403, '/my-administrative-dossiers' => 200]);
        self::assertStringNotContainsString(self::BANNER, (string) $this->client->request('GET', '/')->html());

        $this->client->request('POST', $mine.'/documents/'.$piece->getId(), ['url' => 'https://example.org/']);
        self::assertResponseStatusCodeSame(404);

        // Its intervenant reads it, and opens the file through the framed screen.
        $this->assertScreens($this->holder, [$mine => 200, $file => 200, $fiche => 403, '/ufa/teacher-dossiers' => 403]);

        // The administration reads every dossier from the UFA screens - and « mes dossiers » stays
        // « mes »: somebody else's is not answered there, even to those who may read it.
        $this->assertScreens($this->staff, [$fiche => 200, $file => 200, $mine => 404]);

        // A student has neither side.
        $this->assertScreens($this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'dossier.student'), [$mine => 404, $file => 404, $fiche => 403]);
    }

    public function testNobodyValidatesTheirOwnPapers(): void
    {
        $both = $this->createUser(['ROLE_USER', 'ROLE_STAFF', 'ROLE_TEACHER'], 'dossier.both');
        $dossier = $this->publishedDossier($both);
        $piece = $this->piece($dossier, 'Profil', validation: true);
        $submission = TeacherDossierSubmission::ofUrl($piece, $both, 1, 'https://example.org/');
        $this->em()->persist($submission);
        $this->em()->flush();
        $review = '/ufa/teacher-dossiers/'.$dossier->getId().'/submissions/'.$submission->getId().'/review';

        $this->client->loginUser($both);
        $this->client->request('GET', '/ufa/teacher-dossiers/'.$dossier->getId().'?piece='.$piece->getId());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $review, ['_token' => $this->csrfToken('teacher_dossier'), 'action' => 'validation']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(DossierDocumentStatus::AwaitingReview, $this->statusOf($dossier, $piece));

        $this->client->loginUser($this->staff);
        $this->client->request('GET', '/ufa/teacher-dossiers');
        $this->client->request('POST', $review, ['_token' => $this->csrfToken('teacher_dossier'), 'action' => 'validation']);
        self::assertSame(DossierDocumentStatus::Validated, $this->statusOf($dossier, $piece));
    }

    public function testAClosedDossierIsReadTakesNothingAndAsksNothing(): void
    {
        $dossier = $this->publishedDossier();
        $piece = $this->piece($dossier, 'Profil');
        $mine = '/my-administrative-dossiers/'.$dossier->getId();
        $fiche = '/ufa/teacher-dossiers/'.$dossier->getId();

        $this->client->loginUser($this->staff);
        $this->client->request('GET', $fiche);
        $token = $this->csrfToken('teacher_dossier');
        $this->client->request('POST', $fiche.'/close', ['_token' => $token]);
        $dossier = $this->reload($dossier);
        self::assertTrue($dossier->isClosed());

        $this->client->request('POST', $fiche.'/documents/'.$piece->getId().'/deposit', ['_token' => $token, 'url' => 'https://example.org/']);
        self::assertResponseStatusCodeSame(404, 'Even the administration deposits nothing on a closed dossier.');

        $this->client->loginUser($this->holder);
        $home = (string) $this->client->request('GET', '/')->html();
        self::assertStringNotContainsString(self::BANNER, $home);
        self::assertStringContainsString('/my-administrative-dossiers', $home, 'Closed, it is still theirs to read.');
        $this->client->request('GET', $mine);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $mine.'/documents/'.$piece->getId(), ['_token' => $this->csrfToken('teacher_dossier_submission'), 'url' => 'https://example.org/']);
        self::assertResponseRedirects();
        self::assertSame(DossierDocumentStatus::Missing, $this->statusOf($dossier, $piece), 'Refused with a sentence, not written.');

        $this->client->loginUser($this->staff);
        $this->client->request('GET', $fiche);
        $this->client->request('POST', $fiche.'/reopen', ['_token' => $this->csrfToken('teacher_dossier')]);
        $dossier = $this->reload($dossier);
        self::assertTrue($dossier->isPublished());

        $this->client->request('POST', $fiche.'/delete', ['_token' => $this->csrfToken('teacher_dossier')]);
        self::assertResponseRedirects('/ufa/teacher-dossiers');
        self::assertSame([], $this->dossiers()->findDossiers());
    }

    public function testSwitchedOffNoneOfItExists(): void
    {
        $dossier = $this->publishedDossier();
        $this->piece($dossier, 'RIB');

        $this->em()
            ->createQuery('UPDATE App\Entity\FeatureRoleSetting s SET s.enabled = false WHERE s.feature = :feature')
            ->setParameter('feature', 'teacher_dossiers')
            ->execute();

        $this->assertScreens($this->staff, ['/ufa/teacher-dossiers' => 404, '/ufa/teacher-dossiers/'.$dossier->getId() => 404, '/ufa/teacher-dossiers/templates' => 404]);
        $this->assertScreens($this->holder, ['/my-administrative-dossiers' => 404, '/my-administrative-dossiers/'.$dossier->getId() => 404]);

        // The home page still renders, with neither the banner nor the menu's entry.
        $home = (string) $this->client->request('GET', '/')->html();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::BANNER, $home);
        self::assertStringNotContainsString('/my-administrative-dossiers', $home);
    }

    // ---------------------------------------------------------------------------------------

    private function publishedDossier(?User $teacher = null): TeacherDossier
    {
        $dossier = TeacherDossier::forTeacher($this->staff, $teacher ?? $this->holder, 'Dossier administratif');
        $this->em()->persist($dossier);
        $this->em()->flush();
        $dossier->publish();
        $this->em()->flush();

        return $dossier;
    }

    private function piece(TeacherDossier $dossier, string $name, bool $validation = false, DossierDepositType $type = DossierDepositType::Url): TeacherDossierDocument
    {
        $piece = (new TeacherDossierDocument($dossier))
            ->setName($name)
            ->setDepositType($type)
            ->setValidationProfile($validation ? DossierValidationProfile::Validation : DossierValidationProfile::Deposit);
        $this->em()->persist($piece);
        $this->em()->flush();

        return $piece;
    }

    /** The dossier as the database has it now - the requests in between ran on their own unit of work. */
    private function reload(TeacherDossier $dossier): TeacherDossier
    {
        $this->em()->clear();

        return $this->dossiers()->find($dossier->getId()) ?? throw new \LogicException('The dossier is gone.');
    }

    private function statusOf(TeacherDossier $dossier, TeacherDossierDocument $piece): DossierDocumentStatus
    {
        return $this->sheets()->of($this->reload($dossier))->lines[(int) $piece->getId()]->status;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function dossiers(): TeacherDossierRepository
    {
        return static::getContainer()->get(TeacherDossierRepository::class);
    }

    private function sheets(): TeacherDossierSheets
    {
        return static::getContainer()->get(TeacherDossierSheets::class);
    }
}
