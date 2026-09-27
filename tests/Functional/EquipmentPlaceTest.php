<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentLocation;
use App\Entity\EquipmentType;
use App\Entity\Room;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The « Emplacement » field of Gestion > Matériel offers the platform's rooms next to the storage
 * places, through the real form - the choice values are what a stale select would get wrong.
 */
class EquipmentPlaceTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'equipment.place.admin');
    }

    public function testATypeCanBeKeptInARoomAndItsPiecesFollow(): void
    {
        $room = (new Room('Salle B12 matériel'))->setCreatedBy($this->admin);
        $location = new EquipmentLocation('Réserve matériel');
        $this->entityManager->persist($room);
        $this->entityManager->persist($location);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/equipment/types/new');
        $groups = $crawler->filter('#equipment_type_place optgroup')->each(static fn ($group): string => (string) $group->attr('label'));
        self::assertSame(['Emplacements de rangement', 'Salles'], $groups);

        $this->client->submitForm('equipment_type_submit', [
            'equipment_type[name]' => 'Clavier de salle',
            'equipment_type[unitTracked]' => true,
            'equipment_type[quantity]' => 2,
            'equipment_type[place]' => 'room-'.$room->getId(),
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $type = $this->entityManager->getRepository(EquipmentType::class)->findOneBy(['name' => 'Clavier de salle']);
        self::assertInstanceOf(EquipmentType::class, $type);
        self::assertSame($room->getId(), $type->getStorageRoom()?->getId());
        self::assertNull($type->getLocation());

        $items = $this->entityManager->getRepository(EquipmentItem::class)->findBy(['type' => $type]);
        self::assertCount(2, $items);
        foreach ($items as $item) {
            self::assertSame($room->getId(), $item->getStorageRoom()?->getId());
        }

        $this->client->request('GET', '/equipment/types/'.$type->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Salle B12 matériel');

        $this->client->request('GET', '/equipment/items/'.$items[0]->getId().'/edit');
        $this->client->submitForm('equipment_item_submit', [
            'equipment_item[place]' => 'location-'.$location->getId(),
        ]);
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->entityManager->clear();
        $item = $this->entityManager->getRepository(EquipmentItem::class)->find($items[0]->getId());
        self::assertInstanceOf(EquipmentItem::class, $item);
        self::assertSame($location->getId(), $item->getLocation()?->getId());
        self::assertNull($item->getStorageRoom(), 'Choosing a storage place leaves the room.');
    }

    /** A room closed since it was chosen stays offered, or saving the form would clear it. */
    public function testAClosedRoomStaysOfferedToTheTypeKeptThere(): void
    {
        $closed = (new Room('Salle fermée matériel'))->setCreatedBy($this->admin)->setInactiveDate(new \DateTimeImmutable('-1 day'));
        $type = (new EquipmentType())->setName('Câble HDMI de salle')->setPlace($closed)->setCreatedBy($this->admin);
        $this->entityManager->persist($closed);
        $this->entityManager->persist($type);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/equipment/types/'.$type->getId().'/edit');
        self::assertSame('room-'.$closed->getId(), $crawler->filter('#equipment_type_place option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', '/equipment/types/new');
        self::assertCount(0, $crawler->filter('#equipment_type_place option[value="room-'.$closed->getId().'"]'), 'A closed room is not offered to a new type.');
    }
}
