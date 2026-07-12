<?php

namespace App\Form;

use App\Entity\InternshipTutorEvaluation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InternshipTutorEvaluationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Fixed rows - the controller always attaches one InternshipTutorEvaluationBehavior
            // per active behavior criteria and one InternshipTutorEvaluationSkill per active
            // skill criterion before building this form (idempotently, across repeat visits),
            // so allow_add/allow_delete stay false.
            ->add('behaviorEvaluations', CollectionType::class, [
                'entry_type' => InternshipTutorEvaluationBehaviorType::class,
                'allow_add' => false,
                'allow_delete' => false,
                'by_reference' => false,
                'label' => false,
            ])
            ->add('skillEvaluations', CollectionType::class, [
                'entry_type' => InternshipTutorEvaluationSkillType::class,
                'entry_options' => ['skillLevelChoices' => $options['skillLevelChoices']],
                'allow_add' => false,
                'allow_delete' => false,
                'by_reference' => false,
                'label' => false,
            ])
            ->add('strengthsText', TextareaType::class, [
                'label' => 'internshipTutorEvaluationStrengthsFieldLabel',
                'required' => false,
            ])
            ->add('weaknessesText', TextareaType::class, [
                'label' => 'internshipTutorEvaluationWeaknessesFieldLabel',
                'required' => false,
            ])
            ->add('goalsText', TextareaType::class, [
                'label' => 'internshipTutorEvaluationGoalsFieldLabel',
                'required' => false,
            ])
            ->add('remarksText', TextareaType::class, [
                'label' => 'internshipTutorEvaluationRemarksFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => InternshipTutorEvaluation::class])
            ->setRequired('skillLevelChoices')
            ->setAllowedTypes('skillLevelChoices', 'iterable')
        ;
    }
}
