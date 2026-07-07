<?php

namespace App\Form;

use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TopicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'topicNameFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('targetCmHours', IntegerType::class, [
                'label' => 'topicTargetCmHoursFieldLabel',
            ])
            ->add('targetTdHours', IntegerType::class, [
                'label' => 'topicTargetTdHoursFieldLabel',
            ])
            ->add('targetTpHours', IntegerType::class, [
                'label' => 'topicTargetTpHoursFieldLabel',
            ])
            // Only the program's own teachers can be picked here, same reasoning as the lesson
            // session form's teacher field.
            ->add('teacher', EntityType::class, [
                'class' => User::class,
                'choices' => $program->getTeachers(),
                'choice_label' => static fn (User $user): string => $user->getDisplayName() ?? $user->getUsername(),
                'label' => 'topicTeacherFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'topicDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('maxSessionLength', IntegerType::class, [
                'label' => 'topicMaxSessionLengthFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        // Topic's constructor requires a name and a Program - built here from the submitted
        // "name" and the "program" form option, captured directly since configureOptions() below
        // has no access to per-request option values.
        $builder->setEmptyData(static function (FormInterface $form) use ($program): Topic {
            return new Topic($form->get('name')->getData() ?? '', $program);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => Topic::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
