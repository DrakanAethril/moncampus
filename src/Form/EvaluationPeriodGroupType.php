<?php

namespace App\Form;

use App\Entity\EvaluationPeriodGroup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EvaluationPeriodGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'evaluationPeriodGroupNameFieldLabel',
                'help' => 'evaluationPeriodGroupNameFieldHelpText',
                'empty_data' => '',
            ])
            // allow_add/allow_delete + by_reference:false route through
            // EvaluationPeriodGroup::addPeriod()/removePeriod() (matched by property-accessor
            // convention on the singular of the field name), which keeps each EvaluationPeriod's
            // inverse side wired - the entity's cascade:['persist']/orphanRemoval then handles
            // insert/delete on flush without any manual diffing here. Row add/remove itself is
            // driven by assets/controllers/evaluation_period_group_form_controller.js using the
            // 'periods' field's rendered data-prototype attribute (form_widget default for a
            // CollectionType with allow_add - see EvaluationPeriodType, which stays too simple to
            // need a QuizQuestionType-style raw-array/JS-owned approach).
            ->add('periods', CollectionType::class, [
                'entry_type' => EvaluationPeriodType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'label' => false,
                'entry_options' => ['label' => false],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EvaluationPeriodGroup::class]);
    }
}
