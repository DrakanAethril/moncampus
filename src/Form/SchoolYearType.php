<?php

namespace App\Form;

use App\Entity\SchoolYear;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SchoolYearType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
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
            'data_class' => SchoolYear::class,
            // SchoolYear's constructor requires a start and end date, so a fresh entity can't
            // be built via plain reflection - construct it here once those fields have
            // actually been submitted.
            'empty_data' => static function (FormInterface $form): SchoolYear {
                return new SchoolYear(
                    $form->get('startDate')->getData() ?? new \DateTimeImmutable(),
                    $form->get('endDate')->getData() ?? new \DateTimeImmutable(),
                );
            },
        ]);
    }
}
