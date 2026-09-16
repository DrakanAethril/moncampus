<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Dossier;
use App\Entity\User;
use App\Security\Voter\DossierVoter;
use App\Service\Dossier\DossierTargetResolver;

/**
 * The five doors of a dossier documentaire, and the two asymmetries that matter.
 *
 * **Following a dossier is not a rank.** A co-validateur edits and validates exactly as the créateur
 * does, and somebody who is neither gets nothing - there is no staff bypass, only the administrator's
 * one, which the whole feature is delivered to on the day it ships.
 *
 * **SUBMIT is the one attribute `ROLE_ADMIN` does not open.** An administrator manages every
 * dossier and is a cible of none: being able to deposit in a student's place is not a power, it is
 * a way of making the dépôts unreadable.
 */
class DossierVoterTest extends VoterTestCase
{
    private function voter(bool $isTarget = false): DossierVoter
    {
        $targets = $this->createStub(DossierTargetResolver::class);
        $targets->method('isTarget')->willReturn($isTarget);

        return new DossierVoter($targets);
    }

    private function dossier(User $creator, array $others = [], bool $published = true): Dossier
    {
        $dossier = new Dossier();
        $dossier->setCreatedBy($creator);
        $dossier->addValidator($creator);

        foreach ($others as $other) {
            $dossier->addValidator($other);
        }

        if ($published) {
            $dossier->publish();
        }

        return $dossier;
    }

    public function testCreatorHoldsEveryManagementAttribute(): void
    {
        $creator = $this->user(['ROLE_TEACHER'], 'creator');
        $dossier = $this->dossier($creator);
        $voter = $this->voter();

        foreach ([DossierVoter::VIEW, DossierVoter::EDIT, DossierVoter::VALIDATE, DossierVoter::DELETE] as $attribute) {
            $this->assertGranted($voter, $creator, $dossier, $attribute);
        }
    }

    public function testCoValidatorEditsAndValidatesButDoesNotDelete(): void
    {
        $creator = $this->user(['ROLE_TEACHER'], 'creator');
        $other = $this->user(['ROLE_TEACHER'], 'other');
        $dossier = $this->dossier($creator, [$other]);
        $voter = $this->voter();

        $this->assertGranted($voter, $other, $dossier, DossierVoter::VIEW);
        $this->assertGranted($voter, $other, $dossier, DossierVoter::EDIT);
        $this->assertGranted($voter, $other, $dossier, DossierVoter::VALIDATE);
        // The one gesture that takes the dossier away from the people who follow it.
        $this->assertDenied($voter, $other, $dossier, DossierVoter::DELETE);
    }

    public function testAStrangerGetsNothing(): void
    {
        $creator = $this->user(['ROLE_TEACHER'], 'creator');
        $stranger = $this->user(['ROLE_TEACHER', 'ROLE_STAFF'], 'stranger');
        $dossier = $this->dossier($creator);
        $voter = $this->voter();

        // Staff included: there is no bypass here, only the administrator's.
        foreach ([DossierVoter::VIEW, DossierVoter::EDIT, DossierVoter::VALIDATE, DossierVoter::DELETE] as $attribute) {
            $this->assertDenied($voter, $stranger, $dossier, $attribute);
        }
    }

    public function testAdministratorManagesADossierTheyDoNotFollow(): void
    {
        $creator = $this->user(['ROLE_TEACHER'], 'creator');
        $admin = $this->user(['ROLE_ADMIN'], 'admin');
        $dossier = $this->dossier($creator);
        $voter = $this->voter();

        foreach ([DossierVoter::VIEW, DossierVoter::EDIT, DossierVoter::VALIDATE, DossierVoter::DELETE] as $attribute) {
            $this->assertGranted($voter, $admin, $dossier, $attribute);
        }
    }

    public function testAdministratorIsStillNotACible(): void
    {
        $creator = $this->user(['ROLE_TEACHER'], 'creator');
        $admin = $this->user(['ROLE_ADMIN'], 'admin');

        $this->assertDenied($this->voter(isTarget: false), $admin, $this->dossier($creator), DossierVoter::SUBMIT);
    }

    public function testACibleOfAPublishedDossierMaySubmit(): void
    {
        $creator = $this->user(['ROLE_TEACHER'], 'creator');
        $student = $this->user(['ROLE_STUDENT'], 'student');

        $this->assertGranted($this->voter(isTarget: true), $student, $this->dossier($creator), DossierVoter::SUBMIT);
    }

    public function testADraftIsAddressedToNobody(): void
    {
        $creator = $this->user(['ROLE_TEACHER'], 'creator');
        $student = $this->user(['ROLE_STUDENT'], 'student');

        // isTarget() answers yes and it changes nothing: an unpublished dossier has no cible.
        $this->assertDenied($this->voter(isTarget: true), $student, $this->dossier($creator, published: false), DossierVoter::SUBMIT);
    }

    public function testACibleReadsNothingOfTheManagementSide(): void
    {
        $creator = $this->user(['ROLE_TEACHER'], 'creator');
        $student = $this->user(['ROLE_STUDENT'], 'student');
        $voter = $this->voter(isTarget: true);

        foreach ([DossierVoter::VIEW, DossierVoter::EDIT, DossierVoter::VALIDATE, DossierVoter::DELETE] as $attribute) {
            $this->assertDenied($voter, $student, $this->dossier($creator), $attribute);
        }
    }

    public function testItStaysOutOfEverythingElse(): void
    {
        $creator = $this->user(['ROLE_ADMIN'], 'admin');
        $voter = $this->voter();

        $this->assertAbstains($voter, $creator, $this->dossier($creator), 'SOMETHING_ELSE');
        $this->assertAbstains($voter, $creator, new \stdClass(), DossierVoter::VIEW);
        $this->assertDenied($voter, null, $this->dossier($creator), DossierVoter::VIEW);
    }
}
