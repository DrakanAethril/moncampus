<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\EquipmentMovementKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Retrouvé » / « Réparé » for a quantity type: how many come back as spares. A piece of a
 * unit-tracked type needs no form - its fiche offers the one button its status allows.
 */
class EquipmentResolutionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', EnumType::class, [
                'label' => 'equipmentResolutionKindFieldLabel',
                'class' => EquipmentMovementKind::class,
                'choices' => [EquipmentMovementKind::Found, EquipmentMovementKind::Repaired],
                'choice_label' => static fn (EquipmentMovementKind $kind): string => $kind->labelKey(),
                'expanded' => true,
                'data' => EquipmentMovementKind::Repaired,
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'equipmentQuantityFieldLabel',
                'data' => 1,
                'attr' => ['min' => 1],
                'constraints' => [new Assert\NotNull(), new Assert\Positive()],
            ])
            ->add('note', TextType::class, ['label' => 'equipmentMovementNoteFieldLabel', 'required' => false])
            ->add('submit', SubmitType::class, ['label' => 'submitSaveAction'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
