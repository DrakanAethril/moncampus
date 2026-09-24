<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\InternshipTutorEvaluation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Tuteur wizard step 2 ("Compétences", 28b) / chargé de suivi step 2 (31a, always editable). */
class InternshipTutorSkillsStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Unmapped, fed the rows the booklet would print rather than the evaluation's whole
        // collection (see App\Service\BookletSkillGroups). Each entry is the stored row itself, so
        // an answer still lands on it; nothing is written back to the collection.
        $builder->add('skillEvaluations', CollectionType::class, [
            'entry_type' => InternshipTutorEvaluationSkillType::class,
            'entry_options' => ['skillLevelChoices' => $options['skillLevelChoices']],
            'allow_add' => false,
            'allow_delete' => false,
            'mapped' => false,
            'data' => $options['skillEvaluations'],
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => InternshipTutorEvaluation::class])
            ->setRequired(['skillLevelChoices', 'skillEvaluations'])
            ->setAllowedTypes('skillLevelChoices', 'iterable')
            ->setAllowedTypes('skillEvaluations', 'array')
        ;
    }
}
