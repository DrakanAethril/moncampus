<?php

namespace App\Form;

use App\Entity\LaptopLoan;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The laptop and borrower are set on the entity by the controller before this form is built,
// not editable form fields here - see LaptopController::lendForm(). The borrower is instead
// picked via a plain ajax tom-select field embedded directly in lend.html.twig (resolved from a
// top-level "borrower" POST field, same convention as AssignmentType's manual_recipients).
class LaptopLoanLendType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dueAt', DateType::class, [
                'label' => 'laptopLoanDueAtFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('lentStateNotes', TextareaType::class, [
                'label' => 'laptopLoanLentStateNotesFieldLabel',
                // Explicit '' (not the default) activates TextareaType's own null->'' safety net
                // for blank submissions on this non-nullable property.
                'empty_data' => '',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'lendLaptopSubmitAction',
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
