<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentLocation;
use App\Repository\EquipmentLocationRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The details of one piece that are not its history: serial number, storage place, notes.
 *
 * The status is absent on purpose - « Utilisé » / « Disponible » is a journal line, recorded by its
 * own button, never a field somebody edits.
 */
class EquipmentItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('serialNumber', TextType::class, ['label' => 'equipmentItemSerialNumberFieldLabel', 'required' => false])
            ->add('location', EntityType::class, [
                'label' => 'equipmentTypeLocationFieldLabel',
                'class' => EquipmentLocation::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'equipmentNoLocationPlaceholder',
                'query_builder' => static fn (EquipmentLocationRepository $repository) => $repository->createQueryBuilder('l')->orderBy('l.name', 'ASC'),
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'equipmentNotesFieldLabel',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('submit', SubmitType::class, ['label' => 'submitSaveAction'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EquipmentItem::class]);
    }
}
