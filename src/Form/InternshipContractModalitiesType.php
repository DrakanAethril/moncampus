<?php

namespace App\Form;

use App\Entity\InternshipProgramInfo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InternshipContractModalitiesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('termsConditionsProText', TextareaType::class, [
                'label' => 'internshipProgramInfoTermsConditionsProFieldLabel',
                'required' => false,
            ])
            ->add('termsConditionsApprentissageText', TextareaType::class, [
                'label' => 'internshipProgramInfoTermsConditionsApprentissageFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipProgramInfo::class]);
    }
}
