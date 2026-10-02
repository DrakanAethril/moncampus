<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\ClassBoard;
use App\Security\Voter\ClassBoardVoter;

/**
 * A board is its owner's and nobody else's - an administrator included, the rule of « un travail
 * n'est qu'à son auteur ». The colleague of the same class is the case that would go unnoticed.
 */
class ClassBoardVoterTest extends VoterTestCase
{
    public function testTheOwnerOpensChangesAndDeletes(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $board = new ClassBoard($owner, 'Mon tableau');

        foreach ([ClassBoardVoter::VIEW, ClassBoardVoter::EDIT, ClassBoardVoter::DELETE] as $attribute) {
            $this->assertGranted(new ClassBoardVoter(), $owner, $board, $attribute);
        }
    }

    public function testNobodyElseDoesNotEvenAnAdministrator(): void
    {
        $board = new ClassBoard($this->user(['ROLE_TEACHER'], 'owner'), 'Mon tableau');

        foreach ([$this->user(['ROLE_TEACHER'], 'colleague'), $this->user(['ROLE_ADMIN'], 'admin')] as $other) {
            foreach ([ClassBoardVoter::VIEW, ClassBoardVoter::EDIT, ClassBoardVoter::DELETE] as $attribute) {
                $this->assertDenied(new ClassBoardVoter(), $other, $board, $attribute);
            }
        }
    }

    public function testAnAnonymousVisitorIsRefused(): void
    {
        $board = new ClassBoard($this->user(['ROLE_TEACHER'], 'owner'), 'Mon tableau');

        $this->assertDenied(new ClassBoardVoter(), null, $board, ClassBoardVoter::VIEW);
    }

    public function testItStaysOutOfOtherSubjects(): void
    {
        $this->assertAbstains(new ClassBoardVoter(), $this->user(['ROLE_TEACHER']), new \stdClass(), ClassBoardVoter::VIEW);
    }
}
