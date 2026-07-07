<?php

namespace App\Form;

use App\Entity\LaptopLoan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LaptopLoanReturnType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('returnCondition', ChoiceType::class, [
                'label' => 'laptopLoanReturnConditionFieldLabel',
                'choices' => [
                    'laptopReturnConditionOkLabel' => 'ok',
                    'laptopReturnConditionDamagedLabel' => 'damaged',
                    'laptopReturnConditionLostLabel' => 'lost',
                ],
                'placeholder' => false,
            ])
            ->add('returnStateNotes', TextareaType::class, [
                'label' => 'laptopLoanReturnStateNotesFieldLabel',
                // Explicit '' (not the default) activates TextareaType's own null->'' safety net
                // for blank submissions on this non-nullable-at-return-time field.
                'empty_data' => '',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'returnLaptopSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LaptopLoan::class,
        ]);
    }
}
