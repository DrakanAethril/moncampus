<?php

namespace App\Form;

use App\Entity\InternshipSkillGroup;
use App\Entity\Program;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InternshipSkillGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('label', TextType::class, [
                'label' => 'internshipSkillGroupLabelFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        // InternshipSkillGroup's constructor requires a label and a Program - built here from
        // the submitted "label" and the "program" form option, same pattern as TopicType.
        $builder->setEmptyData(static function (FormInterface $form) use ($program): InternshipSkillGroup {
            return new InternshipSkillGroup($form->get('label')->getData() ?? '', $program);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => InternshipSkillGroup::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
