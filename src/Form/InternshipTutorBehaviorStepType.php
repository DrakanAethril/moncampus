<?php

namespace App\Form;

use App\Entity\InternshipTutorEvaluation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Tuteur wizard step 1 ("Comportement au travail", 28a) / chargé de suivi step 1 (31a, same
 * fields but always editable) - a thin per-step slice of InternshipTutorEvaluationType so
 * submitting one step never touches the other steps' data (see the feature's plan doc, §Phase 5,
 * on why a single shared flat form can't be reused across steps: fields absent from a step's own
 * submitted body would otherwise be wiped by Symfony's default empty-data handling).
 */
class InternshipTutorBehaviorStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('behaviorEvaluations', CollectionType::class, [
            'entry_type' => InternshipTutorEvaluationBehaviorType::class,
            'allow_add' => false,
            'allow_delete' => false,
            'by_reference' => false,
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipTutorEvaluation::class]);
    }
}
