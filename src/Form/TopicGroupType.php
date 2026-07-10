<?php

namespace App\Form;

use App\Entity\Program;
use App\Entity\TopicGroup;
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
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

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
