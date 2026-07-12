<?php

namespace App\Form;

use App\Entity\InternshipSkillLevel;
use App\Entity\InternshipTutorEvaluationSkill;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Entry type for the InternshipTutorEvaluationType's 'skillEvaluations' CollectionType. Unlike
// InternshipTutorEvaluationBehaviorType, every row shares the same choice list - but that list is
// Program-scoped (InternshipSkillLevelRepository::findAllActiveForProgramOrGlobal()), so the
// caller resolves it once and passes it in via entry_options, same pattern as
// SkillGroupType::$optionChoices, rather than this type deriving it itself.
class InternshipTutorEvaluationSkillType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('skillLevel', EntityType::class, [
            'class' => InternshipSkillLevel::class,
            'choices' => $options['skillLevelChoices'],
            'choice_label' => 'label',
            'label' => false,
            'required' => false,
            'placeholder' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => InternshipTutorEvaluationSkill::class])
            ->setRequired('skillLevelChoices')
            ->setAllowedTypes('skillLevelChoices', 'iterable')
        ;
    }
}
