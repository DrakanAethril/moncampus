<?php

namespace App\Form;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\TopicGroup;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TopicGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'topicGroupNameFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
        ;

        // Only offered when the Program actually has Options - same reasoning as
        // LessonSessionType's own 'options' field. Left empty, the group is common to every
        // Option (see TopicGroup's class docblock).
        if (!$program->getOptions()->isEmpty()) {
            $builder->add('options', EntityType::class, [
                'class' => Option::class,
                'choices' => $program->getOptions(),
                'choice_label' => 'shortName',
                'label' => 'topicGroupOptionsFieldLabel',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'submitCreateAction',
        ]);

        // TopicGroup's constructor requires a name and a Program - built here from the submitted
        // "name" and the "program" form option, captured directly since configureOptions() below
        // has no access to per-request option values.
        $builder->setEmptyData(static function (FormInterface $form) use ($program): TopicGroup {
            return new TopicGroup($form->get('name')->getData() ?? '', $program);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => TopicGroup::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
