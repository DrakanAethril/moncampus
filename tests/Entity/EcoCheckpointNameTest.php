<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use PHPUnit\Framework\TestCase;

/**
 * The parcours map shows a flag by its name once somebody named it, by its letter or number while
 * it still bears the name it was born with.
 */
class EcoCheckpointNameTest extends TestCase
{
    public function testTheNamesAFlagIsBornWithAreNotItsOwn(): void
    {
        self::assertFalse($this->checkpoint(EcoCheckpointType::Start, 0, 'Départ')->hasOwnName());
        self::assertFalse($this->checkpoint(EcoCheckpointType::Checkpoint, 3, 'Balise 3')->hasOwnName());
        self::assertFalse($this->checkpoint(EcoCheckpointType::Finish, 13, 'Arrivée')->hasOwnName());
    }

    public function testARenamedFlagHasItsOwnName(): void
    {
        self::assertTrue($this->checkpoint(EcoCheckpointType::Start, 0, 'Parking du lac')->hasOwnName());
        self::assertTrue($this->checkpoint(EcoCheckpointType::Checkpoint, 3, 'Passerelle')->hasOwnName());
        self::assertTrue($this->checkpoint(EcoCheckpointType::Finish, 13, 'Gymnase')->hasOwnName());
    }

    public function testAnotherBaliseNumberIsAName(): void
    {
        // « Balise 7 » on the third flag says something its number does not.
        self::assertTrue($this->checkpoint(EcoCheckpointType::Checkpoint, 3, 'Balise 7')->hasOwnName());
    }

    private function checkpoint(EcoCheckpointType $type, int $position, string $name): EcoCheckpoint
    {
        $checkpoint = new EcoCheckpoint(new EcoParcours(new User('eco.teacher')));
        $checkpoint->setType($type);
        $checkpoint->setPosition($position);
        $checkpoint->setName($name);

        return $checkpoint;
    }
}
