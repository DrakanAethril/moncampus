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
        $builder->add('skillEvaluations', CollectionType::class, [
            'entry_type' => InternshipTutorEvaluationSkillType::class,
            'entry_options' => ['skillLevelChoices' => $options['skillLevelChoices']],
            'allow_add' => false,
            'allow_delete' => false,
            'by_reference' => false,
            'label' => false,
        ]);
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
