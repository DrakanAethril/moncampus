<?php

namespace App\Form;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\Program;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Same shape as App\Form\PeriodType (the Period form), minus the "type"/"periodGroup" fields
// this entity deliberately has no equivalent of - see InternshipEvaluationPeriod's docblock.
class InternshipEvaluationPeriodType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'structureNameColumnLabel',
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

        // InternshipEvaluationPeriod's constructor requires a Program - built here from the
        // "program" form option (fixed by the URL's {id}, not a form field itself), same
        // reasoning as PeriodType's own "periodGroup" option / ProgramReportType's "program".
        $builder->setEmptyData(static fn (): InternshipEvaluationPeriod => new InternshipEvaluationPeriod($program));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => InternshipEvaluationPeriod::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
