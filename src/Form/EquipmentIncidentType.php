<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Room;
use App\Enum\EquipmentIncidentCause;
use App\Enum\EquipmentItemStatus;
use App\Enum\EquipmentMovementKind;
use App\Repository\RoomRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Déclarer un problème » - what happened (Disparu / Hors d'usage), why, where and when.
 *
 * For a quantity type (`for_quantity`) it also asks how many, and from which count they came:
 * cables lost from the reserve and cables lost from a room leave different counters.
 *
 * The room is optional, always, and no field names a person or a class: the inventory measures
 * losses, it does not impute them. The date defaults to today and can be moved back, because a loss
 * is often noticed days after it happened - it is what the school year of the report is read on.
 */
class EquipmentIncidentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', EnumType::class, [
                'label' => 'equipmentIncidentKindFieldLabel',
                'class' => EquipmentMovementKind::class,
                'choices' => [EquipmentMovementKind::Missing, EquipmentMovementKind::OutOfOrder],
                'choice_label' => static fn (EquipmentMovementKind $kind): string => $kind->labelKey(),
                'expanded' => true,
                'data' => EquipmentMovementKind::OutOfOrder,
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('cause', EnumType::class, [
                'label' => 'equipmentIncidentCauseFieldLabel',
                'class' => EquipmentIncidentCause::class,
                'choice_label' => static fn (EquipmentIncidentCause $cause): string => $cause->labelKey(),
                'placeholder' => 'equipmentIncidentCausePlaceholder',
                'constraints' => [new Assert\NotNull()],
            ]);

        if ($options['for_quantity']) {
            $builder
                ->add('quantity', IntegerType::class, [
                    'label' => 'equipmentQuantityFieldLabel',
                    'data' => 1,
                    'attr' => ['min' => 1],
                    'constraints' => [new Assert\NotNull(), new Assert\Positive()],
                ])
                ->add('origin', EnumType::class, [
                    'label' => 'equipmentIncidentOriginFieldLabel',
                    'class' => EquipmentItemStatus::class,
                    'choices' => [EquipmentItemStatus::InUse, EquipmentItemStatus::Available],
                    'choice_label' => static fn (EquipmentItemStatus $status): string => $status->labelKey(),
                    'expanded' => true,
                    'data' => EquipmentItemStatus::InUse,
                    'constraints' => [new Assert\NotNull()],
                ]);
        }

        $builder
            ->add('room', EntityType::class, [
                'label' => 'equipmentRoomFieldLabel',
                'class' => Room::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'equipmentNoRoomPlaceholder',
                'query_builder' => static fn (RoomRepository $repository) => $repository->createQueryBuilder('r')
                    ->where('r.inactiveDate IS NULL')
                    ->orderBy('r.name', 'ASC'),
            ])
            ->add('occurredAt', DateType::class, [
                'label' => 'equipmentIncidentDateFieldLabel',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'data' => new \DateTimeImmutable('today'),
                'constraints' => [
                    new Assert\NotNull(),
                    new Assert\LessThanOrEqual('today', message: 'equipmentIncidentDateInFutureMessage'),
                ],
            ])
            ->add('note', TextType::class, ['label' => 'equipmentMovementNoteFieldLabel', 'required' => false])
            ->add('submit', SubmitType::class, ['label' => 'equipmentIncidentDeclareAction'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['for_quantity' => false]);
        $resolver->setAllowedTypes('for_quantity', 'bool');
    }
}
