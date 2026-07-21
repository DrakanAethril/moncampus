<?php

namespace App\Form;

use App\Entity\EvaluationPeriod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Row entry_type for EvaluationPeriodGroupType's 'periods' CollectionType - never used as a
// standalone form. Only collects a plain date; EvaluationPeriod::setStartDate()/setEndDate()
// themselves pin the time to 00:00:00/23:59:59.
class EvaluationPeriodType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'evaluationPeriodNameFieldLabel',
                'empty_data' => '',
            ])
            ->add('startDate', DateType::class, [
                'label' => 'evaluationPeriodStartDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'evaluationPeriodEndDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EvaluationPeriod::class]);
    }
}
