<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\TeacherDossier;
use App\Entity\User;
use App\Security\Voter\TeacherDossierVoter;

/**
 * The administration enters by its role, the intervenant reads and feeds their own dossier once it
 * is handed to them, and **every other teacher sees nothing** - the pieces are somebody's papers.
 */
class TeacherDossierVoterTest extends VoterTestCase
{
    private const array ALL = [TeacherDossierVoter::MANAGE, TeacherDossierVoter::VIEW, TeacherDossierVoter::SUBMIT, TeacherDossierVoter::REVIEW];

    private User $holder;

    protected function setUp(): void
    {
        $this->holder = $this->user(['ROLE_TEACHER'], 'nadia');
    }

    private function dossier(string $state = 'published', ?User $holder = null): TeacherDossier
    {
        $dossier = TeacherDossier::forTeacher($this->user(['ROLE_STAFF'], 'author'), $holder ?? $this->holder, 'Dossier administratif');

        if ('draft' !== $state) {
            $dossier->publish();
        }

        if ('closed' === $state) {
            $dossier->close();
        }

        return $dossier;
    }

    public function testTheAdministrationDoesEverythingOnAPublishedDossier(): void
    {
        foreach (TeacherDossierVoter::ADMINISTRATION as $role) {
            foreach (self::ALL as $attribute) {
                $this->assertGranted(new TeacherDossierVoter(), $this->user([$role]), $this->dossier(), $attribute, $role.' '.$attribute);
            }
        }
    }

    public function testTheIntervenantReadsAndFeedsTheirOwnDossierAndNothingElse(): void
    {
        $voter = new TeacherDossierVoter();

        $this->assertGranted($voter, $this->holder, $this->dossier(), TeacherDossierVoter::VIEW);
        $this->assertGranted($voter, $this->holder, $this->dossier(), TeacherDossierVoter::SUBMIT);
        $this->assertDenied($voter, $this->holder, $this->dossier(), TeacherDossierVoter::MANAGE);
        $this->assertDenied($voter, $this->holder, $this->dossier(), TeacherDossierVoter::REVIEW);
    }

    public function testADraftIsNotTheIntervenantsYet(): void
    {
        foreach (self::ALL as $attribute) {
            $this->assertDenied(new TeacherDossierVoter(), $this->holder, $this->dossier('draft'), $attribute, $attribute);
        }
    }

    public function testAClosedDossierIsReadAndTakesNothingMore(): void
    {
        $voter = new TeacherDossierVoter();
        $staff = $this->user(['ROLE_STAFF']);

        $this->assertGranted($voter, $this->holder, $this->dossier('closed'), TeacherDossierVoter::VIEW);
        $this->assertDenied($voter, $this->holder, $this->dossier('closed'), TeacherDossierVoter::SUBMIT);
        $this->assertGranted($voter, $staff, $this->dossier('closed'), TeacherDossierVoter::MANAGE);
        $this->assertDenied($voter, $staff, $this->dossier('closed'), TeacherDossierVoter::SUBMIT);
        $this->assertDenied($voter, $staff, $this->dossier('closed'), TeacherDossierVoter::REVIEW);
    }

    public function testNobodyValidatesTheirOwnPapers(): void
    {
        $staffWhoTeaches = $this->user(['ROLE_STAFF', 'ROLE_TEACHER'], 'both');
        $own = $this->dossier('published', $staffWhoTeaches);

        $this->assertDenied(new TeacherDossierVoter(), $staffWhoTeaches, $own, TeacherDossierVoter::REVIEW);
        $this->assertGranted(new TeacherDossierVoter(), $staffWhoTeaches, $own, TeacherDossierVoter::SUBMIT);
        $this->assertGranted(new TeacherDossierVoter(), $staffWhoTeaches, $this->dossier(), TeacherDossierVoter::REVIEW, 'somebody else’s');
    }

    public function testEveryOtherTeacherAndEveryOtherRoleSeesNothing(): void
    {
        foreach (['ROLE_TEACHER', 'ROLE_STUDENT', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'] as $role) {
            foreach (['draft', 'published', 'closed'] as $state) {
                foreach (self::ALL as $attribute) {
                    $this->assertDenied(new TeacherDossierVoter(), $this->user([$role], 'other'), $this->dossier($state), $attribute, $role.' '.$state.' '.$attribute);
                }
            }
        }

        $this->assertDenied(new TeacherDossierVoter(), null, $this->dossier(), TeacherDossierVoter::VIEW);
    }

    public function testAModelIsTheAdministrationsAlone(): void
    {
        $model = TeacherDossier::asTemplate($this->user(['ROLE_STAFF'], 'author'), 'Vacataire');
        $voter = new TeacherDossierVoter();

        $this->assertGranted($voter, $this->user(['ROLE_STAFF']), $model, TeacherDossierVoter::MANAGE);
        $this->assertGranted($voter, $this->user(['ROLE_STAFF']), $model, TeacherDossierVoter::VIEW);
        $this->assertDenied($voter, $this->user(['ROLE_STAFF']), $model, TeacherDossierVoter::SUBMIT, 'nothing is deposited on a model');
        $this->assertDenied($voter, $this->user(['ROLE_STAFF']), $model, TeacherDossierVoter::REVIEW);

        foreach (self::ALL as $attribute) {
            $this->assertDenied($voter, $this->holder, $model, $attribute, $attribute);
        }
    }

    public function testAbstainsOnOtherSubjectsAndAttributes(): void
    {
        $this->assertAbstains(new TeacherDossierVoter(), $this->user(['ROLE_ADMIN']), new \stdClass(), TeacherDossierVoter::VIEW);
        $this->assertAbstains(new TeacherDossierVoter(), $this->user(['ROLE_ADMIN']), $this->dossier(), 'SOMETHING_ELSE');
    }
}
