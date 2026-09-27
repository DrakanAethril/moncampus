<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentLocation;
use App\Entity\EquipmentType;
use App\Entity\Room;
use PHPUnit\Framework\TestCase;

/**
 * « Emplacement » is one place taken from two lists - a storage place or a room - and never both.
 */
class EquipmentPlaceTest extends TestCase
{
    public function testSettingARoomClearsTheStoragePlaceAndBack(): void
    {
        $type = (new EquipmentType())->setPlace(new EquipmentLocation('Réserve'));
        $room = new Room('B12');

        $type->setPlace($room);
        self::assertSame($room, $type->getPlace());
        self::assertNull($type->getLocation());
        self::assertSame('B12', $type->getPlaceName());

        $cupboard = new EquipmentLocation('Armoire');
        $type->setPlace($cupboard);
        self::assertSame($cupboard, $type->getPlace());
        self::assertNull($type->getStorageRoom());

        $type->setPlace(null);
        self::assertNull($type->getPlace());
        self::assertNull($type->getPlaceName());
    }

    public function testANewPieceLandsWhereItsTypeIsKept(): void
    {
        $room = new Room('Labo SISR');
        $type = (new EquipmentType())->setPlace($room);

        $item = new EquipmentItem($type, 142);
        self::assertSame($room, $item->getStorageRoom());
        self::assertNull($item->getLocation());
        self::assertNull($item->getRoom(), 'Being put away in a room is not being used in it.');

        $item->setPlace(new EquipmentLocation('Réserve'));
        self::assertNull($item->getStorageRoom());
    }
}
