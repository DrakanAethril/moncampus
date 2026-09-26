<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A category or a storage place of Gestion > Matériel - both are a name and nothing else.
 */
class EquipmentNameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'equipmentNameFieldLabel'])
            ->add('submit', SubmitType::class, ['label' => $options['submit_label']])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['submit_label' => 'submitSaveAction']);
        $resolver->setAllowedTypes('submit_label', 'string');
    }
}
