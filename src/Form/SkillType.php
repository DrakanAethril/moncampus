<?php

namespace App\Form;

use App\Entity\Program;
use App\Entity\Skill;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SkillType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'skillNameFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('shortName', TextType::class, [
                'label' => 'skillShortNameFieldLabel',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'skillDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('professional', TextareaType::class, [
                'label' => 'skillProfessionalFieldLabel',
                'required' => false,
            ])
            ->add('knowledge', TextareaType::class, [
                'label' => 'skillKnowledgeFieldLabel',
                'required' => false,
            ])
            ->add('performance', TextareaType::class, [
                'label' => 'skillPerformanceFieldLabel',
                'required' => false,
            ])
            ->add('evaluationModality', TextareaType::class, [
                'label' => 'skillEvaluationModalityFieldLabel',
                'required' => false,
            ])
            // Only the program's own teachers can be picked here, same reasoning as the lesson
            // session form's teacher field.
            ->add('teacher', EntityType::class, [
                'class' => User::class,
                'choices' => $program->getTeachers(),
                'choice_label' => static fn (User $user): string => $user->getDisplayName() ?? $user->getUsername(),
                'label' => 'skillTeacherFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('volume', NumberType::class, [
                'label' => 'skillVolumeFieldLabel',
                'required' => false,
            ])
            ->add('period', TextType::class, [
                'label' => 'skillPeriodFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        // Skill's constructor requires a name and a Program - built here from the submitted
        // "name" and the "program" form option, captured directly since configureOptions() below
        // has no access to per-request option values.
        $builder->setEmptyData(static function (FormInterface $form) use ($program): Skill {
            return new Skill($form->get('name')->getData() ?? '', $program);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => Skill::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
