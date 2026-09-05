<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\ClassRoster;
use PHPUnit\Framework\TestCase;

/**
 * The one claim worth pinning is that a document is not ordered like the screen it was printed
 * from: the two class lists sort on the display name, which starts with the first name, and a roll
 * somebody scans for a surname has to sort on the surname instead.
 */
class ClassRosterTest extends TestCase
{
    private ClassRoster $roster;

    protected function setUp(): void
    {
        $this->roster = new ClassRoster();
    }

    public function testTheRollIsOrderedOnTheSurnameNotOnTheDisplayedName(): void
    {
        // Displayed, these read "Zoé Aubert", "Anne Zambelli" - the screen's own order is the
        // reverse of this one.
        $ordered = $this->roster->ordered([$this->user('Anne', 'Zambelli'), $this->user('Zoé', 'Aubert')]);

        self::assertSame(['Aubert', 'Zambelli'], array_map($this->roster->surname(...), $ordered));
    }

    public function testAnAccentedSurnameSortsWhereAReaderExpectsItRatherThanAfterZ(): void
    {
        $ordered = $this->roster->ordered([
            $this->user('Luc', 'Fabre'),
            $this->user('Marie', 'Élodie'),
            $this->user('Paul', 'Dubois'),
        ]);

        self::assertSame(['Dubois', 'Élodie', 'Fabre'], array_map($this->roster->surname(...), $ordered));
    }

    public function testTwoPeopleOfTheSameFamilyAreSeparatedByTheirFirstName(): void
    {
        $ordered = $this->roster->ordered([$this->user('Théo', 'Martin'), $this->user('Alice', 'Martin')]);

        self::assertSame(['Alice', 'Théo'], array_map($this->roster->given(...), $ordered));
    }

    public function testAPrintedLineLeadsWithTheSurnameInCaps(): void
    {
        self::assertSame('DUPONT Martin', $this->roster->documentName($this->user('Martin', 'Dupont')));
    }

    public function testAnAccountLDAPNeverNamedStillOccupiesItsOwnLine(): void
    {
        $user = new User('jdoe');

        self::assertSame('jdoe', $this->roster->documentName($user));
        self::assertSame('jdoe', $this->roster->surname($user));
        self::assertSame('', $this->roster->given($user));
    }

    private function user(string $firstname, string $lastname): User
    {
        $user = new User(strtolower($firstname.'.'.$lastname));
        $user->setFirstname($firstname);
        $user->setLastname($lastname);

        return $user;
    }
}
