<?php

namespace App\Form;

use App\Entity\InternshipSkillCriterion;
use App\Entity\InternshipSkillGroup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InternshipSkillCriterionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var InternshipSkillGroup $skillGroup */
        $skillGroup = $options['skillGroup'];

        $builder
            ->add('label', TextType::class, [
                'label' => 'internshipSkillCriterionLabelFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        // InternshipSkillCriterion's constructor requires a label and a skill group - built
        // here from the submitted "label" and the "skillGroup" form option, same pattern as
        // TopicType/InternshipSkillGroupType.
        $builder->setEmptyData(static function (FormInterface $form) use ($skillGroup): InternshipSkillCriterion {
            return new InternshipSkillCriterion($form->get('label')->getData() ?? '', $skillGroup);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => InternshipSkillCriterion::class])
            ->setRequired('skillGroup')
            ->setAllowedTypes('skillGroup', InternshipSkillGroup::class)
        ;
    }
}
