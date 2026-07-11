<?php

namespace App\Form;

use App\Entity\Option;
use App\Entity\SkillGroup;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Used both at the Centre de formation level (SettingsInternshipController, no Program - a null
// $skillGroup->getProgram()) and, when a Program opts into Program::$customSkillCriteriaEnabled,
// at the Program level (ProgramSettingsController) - which Options are offered in the "options"
// field is the only thing that differs between the two, so the caller passes the exact choice
// list explicitly (a Program's own Options, or every active Option) rather than this type trying
// to derive it from a nullable Program itself.
class SkillGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'internshipSkillGroupLabelFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('options', EntityType::class, [
                'class' => Option::class,
                'choices' => $options['optionChoices'],
                'choice_label' => 'name',
                'label' => 'internshipSkillGroupOptionsFieldLabel',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('visibleInBooklet', CheckboxType::class, [
                'label' => 'internshipSkillGroupVisibleInBookletFieldLabel',
                'required' => false,
            ])
            ->add('visibleInProgram', CheckboxType::class, [
                'label' => 'internshipSkillGroupVisibleInProgramFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => SkillGroup::class])
            ->setRequired('optionChoices')
            ->setAllowedTypes('optionChoices', 'iterable')
        ;
    }
}
