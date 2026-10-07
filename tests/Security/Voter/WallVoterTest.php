<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallComment;
use App\Entity\WallList;
use App\Enum\WallFormat;
use App\Security\Voter\WallVoter;
use App\Service\Wall\WallAccess;

/**
 * The voter maps three kinds of subject onto App\Service\Wall\WallAccess, whose table has its own
 * test (tests/Service/Wall/WallAccessTest.php). What is pinned here is the mapping: each attribute
 * reaches the right rule, and an attribute asked of the wrong kind of subject is left alone - a
 * card answering WALL_MANAGE would be a way round the wall's own answer.
 */
class WallVoterTest extends VoterTestCase
{
    public function testTheOwnerIsGrantedEveryAttributeOfTheirWall(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        [$wall, $card, $comment] = $this->subjects($owner);

        foreach ([WallVoter::VIEW, WallVoter::MANAGE, WallVoter::OWN, WallVoter::ADD_CARD] as $attribute) {
            $this->assertGranted($this->voter(), $owner, $wall, $attribute);
        }
        foreach ([WallVoter::CARD_VIEW, WallVoter::CARD_EDIT, WallVoter::CARD_COMMENT] as $attribute) {
            $this->assertGranted($this->voter(), $owner, $card, $attribute);
        }
        $this->assertGranted($this->voter(), $owner, $comment, WallVoter::COMMENT_DELETE);
    }

    public function testAColleagueSharedWithManagesButDoesNotOwn(): void
    {
        $colleague = $this->user(['ROLE_TEACHER'], 'colleague');
        [$wall] = $this->subjects($this->user(['ROLE_TEACHER'], 'owner'));
        $wall->addMember($colleague);

        $this->assertGranted($this->voter(), $colleague, $wall, WallVoter::MANAGE);
        $this->assertDenied($this->voter(), $colleague, $wall, WallVoter::OWN);
    }

    public function testNobodyElseIsGrantedAnythingNotEvenAnAdministrator(): void
    {
        [$wall, $card, $comment] = $this->subjects($this->user(['ROLE_TEACHER'], 'owner'));

        foreach ([$this->user(['ROLE_TEACHER'], 'colleague'), $this->user(['ROLE_ADMIN'], 'admin'), null] as $other) {
            $this->assertDenied($this->voter(), $other, $wall, WallVoter::VIEW);
            $this->assertDenied($this->voter(), $other, $card, WallVoter::CARD_VIEW);
            $this->assertDenied($this->voter(), $other, $comment, WallVoter::COMMENT_DELETE);
        }
    }

    public function testAnAttributeAskedOfTheWrongSubjectIsNotItsToAnswer(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        [$wall, $card, $comment] = $this->subjects($owner);

        $this->assertAbstains($this->voter(), $owner, $card, WallVoter::MANAGE);
        $this->assertAbstains($this->voter(), $owner, $wall, WallVoter::CARD_EDIT);
        $this->assertAbstains($this->voter(), $owner, $comment, WallVoter::VIEW);
        $this->assertAbstains($this->voter(), $owner, new \stdClass(), WallVoter::VIEW);
    }

    private function voter(): WallVoter
    {
        return new WallVoter(new WallAccess());
    }

    /** @return array{Wall, WallCard, WallComment} */
    private function subjects(\App\Entity\User $owner): array
    {
        $wall = new Wall($owner, 'Mur', WallFormat::Columns);
        $wall->setCommentsEnabled(true);
        $card = new WallCard(new WallList($wall, 'Liste'), 'Carte', $owner);

        return [$wall, $card, new WallComment($card, $owner, 'Commentaire')];
    }
}
