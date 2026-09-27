<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EquipmentLocation;
use App\Entity\Room;
use App\Repository\EquipmentLocationRepository;
use App\Repository\RoomRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Emplacement » of Gestion > Matériel: one select over two lists - the inventory's own storage
 * places, then the platform's rooms - bound to the entity's `place` (setPlace() keeps one side).
 *
 * Only active rooms are offered, except the one already chosen (`current`): a room closed since
 * would otherwise vanish from the list, and saving the form would clear it without a word.
 */
class EquipmentPlaceType extends AbstractType
{
    public function __construct(
        private readonly EquipmentLocationRepository $locations,
        private readonly RoomRepository $rooms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => 'equipmentTypeLocationFieldLabel',
            'required' => false,
            'placeholder' => 'equipmentNoLocationPlaceholder',
            'property_path' => 'place',
            'current' => null,
            'choice_translation_domain' => false,
            'choice_label' => static fn (EquipmentLocation|Room $place): string => $place->getName(),
            'choice_value' => static fn (EquipmentLocation|Room|null $place): string => match (true) {
                $place instanceof EquipmentLocation => 'location-'.$place->getId(),
                $place instanceof Room => 'room-'.$place->getId(),
                default => '',
            },
            'group_by' => fn (EquipmentLocation|Room $place): string => $this->translator->trans(
                $place instanceof Room ? 'equipmentPlaceRoomsGroupLabel' : 'equipmentLocationsCardTitle',
            ),
        ]);
        $resolver->setAllowedTypes('current', ['null', EquipmentLocation::class, Room::class]);
        $resolver->setDefault('choices', fn (Options $options): array => $this->choices($options['current']));
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    /** @return list<EquipmentLocation|Room> */
    private function choices(EquipmentLocation|Room|null $current): array
    {
        $rooms = $this->rooms->createQueryBuilder('r')
            ->where('r.inactiveDate IS NULL')
            ->orderBy('r.name', 'ASC');
        if ($current instanceof Room) {
            $rooms->orWhere('r = :current')->setParameter('current', $current);
        }
        /** @var list<Room> $roomList */
        $roomList = $rooms->getQuery()->getResult();

        return [...$this->locations->findAllOrdered(), ...$roomList];
    }
}
