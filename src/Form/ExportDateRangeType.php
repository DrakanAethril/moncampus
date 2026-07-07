<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Shared by the Signature and Invoicing exports - both just need a date range to pull lesson
// sessions from, not tied to any persisted entity.
class ExportDateRangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startDay', DateType::class, [
                'label' => 'exportStartDayFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('endDay', DateType::class, [
                'label' => 'exportEndDayFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'exportGenerateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // GET, not POST: this just re-queries and re-renders the same page with different
        // parameters, it doesn't change any state - and Turbo (enabled site-wide) requires POST
        // form responses to redirect, which a plain "show the results" response can't do.
        $resolver->setDefaults(['data_class' => null, 'method' => 'GET', 'csrf_protection' => false]);
    }
}
