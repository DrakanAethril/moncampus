<?php

namespace App\Form;

use App\Entity\InternshipTutorEvaluation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Tuteur wizard step 3 ("Points forts et objectifs", 28c). */
class InternshipTutorStrengthsStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('strengthsText', TextareaType::class, ['label' => 'internshipTutorEvaluationStrengthsFieldLabel', 'required' => false])
            ->add('weaknessesText', TextareaType::class, ['label' => 'internshipTutorEvaluationWeaknessesFieldLabel', 'required' => false])
            ->add('goalsText', TextareaType::class, ['label' => 'internshipTutorEvaluationGoalsFieldLabel', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipTutorEvaluation::class]);
    }
}
