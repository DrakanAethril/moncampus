<?php

declare(strict_types=1);

namespace App\Tests\Service\Eco;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Service\Eco\EcoCheckpointRenamer;
use PHPUnit\Framework\TestCase;

/**
 * Renaming a flag from screen 1e: the name the runner reads and the printed page shows, never the
 * flag's role - and a blank or missing name never leaves a flag unnamed.
 */
class EcoCheckpointRenamerTest extends TestCase
{
    public function testEveryFlagMayBeRenamedTheStartAndFinishIncluded(): void
    {
        [$parcours, $start, $flag, $finish] = $this->parcours();

        (new EcoCheckpointRenamer())->apply($parcours, ['1' => 'Le grand chêne', '2' => '  Passerelle   du  ruisseau ', '3' => 'Retour au gymnase'], new User('eco.teacher'));

        self::assertSame('Le grand chêne', $start->getName());
        self::assertSame('Passerelle du ruisseau', $flag->getName());
        self::assertSame('Retour au gymnase', $finish->getName());
        self::assertSame(EcoCheckpointType::Finish, $finish->getType());
        self::assertNotNull($parcours->getLastUpdatedDate());
    }

    public function testABlankMissingOrForeignNameChangesNothing(): void
    {
        [$parcours, $start, $flag, $finish] = $this->parcours();

        (new EcoCheckpointRenamer())->apply($parcours, ['1' => '   ', '2' => ['not a name'], '99' => 'Ailleurs'], new User('eco.teacher'));

        self::assertSame('Départ', $start->getName());
        self::assertSame('Balise 1', $flag->getName());
        self::assertSame('Arrivée', $finish->getName());
        // Nothing renamed, nothing stamped.
        self::assertNull($parcours->getLastUpdatedDate());
    }

    public function testATooLongNameIsCutToWhatThePrintedPageHolds(): void
    {
        [$parcours, , $flag] = $this->parcours();

        (new EcoCheckpointRenamer())->apply($parcours, ['2' => str_repeat('é', EcoCheckpoint::NAME_MAX_LENGTH + 10)], new User('eco.teacher'));

        self::assertSame(str_repeat('é', EcoCheckpoint::NAME_MAX_LENGTH), $flag->getName());
    }

    /** @return array{EcoParcours, EcoCheckpoint, EcoCheckpoint, EcoCheckpoint} */
    private function parcours(): array
    {
        $parcours = new EcoParcours(new User('eco.teacher'));
        $checkpoints = [];
        foreach ([[EcoCheckpointType::Start, 'Départ'], [EcoCheckpointType::Checkpoint, 'Balise 1'], [EcoCheckpointType::Finish, 'Arrivée']] as $index => [$type, $name]) {
            $checkpoint = (new EcoCheckpoint($parcours))->setType($type)->setPosition($index)->setName($name);
            (new \ReflectionProperty(EcoCheckpoint::class, 'id'))->setValue($checkpoint, $index + 1);
            $parcours->addCheckpoint($checkpoint);
            $checkpoints[] = $checkpoint;
        }

        return [$parcours, ...$checkpoints];
    }
}
