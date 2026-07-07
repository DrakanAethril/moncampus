<?php

namespace App\Form;

use App\Entity\Period;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PeriodType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'structureNameColumnLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('startDate', DateType::class, [
                'label' => 'structureStartDateColumnLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'structureEndDateColumnLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Period::class,
            // Same reasoning as SchoolYearType::$empty_data: Period's constructor requires a
            // name, a start date and an end date, built here from already-submitted sibling
            // fields, so a missing required field is a validation error, not a TypeError.
            'empty_data' => static function (FormInterface $form): Period {
                return new Period(
                    $form->get('name')->getData() ?? '',
                    $form->get('startDate')->getData() ?? new \DateTimeImmutable(),
                    $form->get('endDate')->getData() ?? new \DateTimeImmutable(),
                );
            },
        ]);
    }
}
