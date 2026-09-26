<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Room;
use App\Repository\RoomRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The small forms of a type's or a piece's fiche that record one journal line: a delivery, a
 * number of cables put into service or brought back, a mouse put into service.
 *
 * Not bound to an entity - the line is built by App\Service\Equipment\EquipmentLedger from what is
 * read here. `with_quantity` is off for a single piece (it moves one), `with_room` on where a room
 * means something: « Utilisé » says where, and saying it stays optional.
 */
class EquipmentMovementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['with_quantity']) {
            $constraints = [new Assert\NotNull(), new Assert\Positive()];
            $attr = ['min' => 1];

            if (\is_int($options['max_quantity'])) {
                $constraints[] = new Assert\LessThanOrEqual($options['max_quantity'], message: 'equipmentTooManyUnitsMessage');
                $attr['max'] = $options['max_quantity'];
            }

            $builder->add('quantity', IntegerType::class, [
                'label' => 'equipmentQuantityFieldLabel',
                'data' => 1,
                'attr' => $attr,
                'constraints' => $constraints,
            ]);
        }

        if ($options['with_room']) {
            $builder->add('room', EntityType::class, [
                'label' => 'equipmentRoomFieldLabel',
                'class' => Room::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'equipmentNoRoomPlaceholder',
                'query_builder' => static fn (RoomRepository $repository) => $repository->createQueryBuilder('r')
                    ->where('r.inactiveDate IS NULL')
                    ->orderBy('r.name', 'ASC'),
            ]);
        }

        if ($options['with_note']) {
            $builder->add('note', TextType::class, ['label' => 'equipmentMovementNoteFieldLabel', 'required' => false]);
        }

        $builder->add('submit', SubmitType::class, ['label' => $options['submit_label']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'with_quantity' => true,
            'max_quantity' => null,
            'with_room' => false,
            'with_note' => false,
            'submit_label' => 'submitSaveAction',
        ]);
        $resolver->setAllowedTypes('with_quantity', 'bool');
        $resolver->setAllowedTypes('max_quantity', ['null', 'int']);
        $resolver->setAllowedTypes('with_room', 'bool');
        $resolver->setAllowedTypes('with_note', 'bool');
        $resolver->setAllowedTypes('submit_label', 'string');
    }
}
