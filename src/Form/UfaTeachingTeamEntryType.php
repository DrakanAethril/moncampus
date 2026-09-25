<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Program;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line of the booklet's « Équipe pédagogique » - see App\Service\TeachingTeam.
 *
 * Both names are free text on purpose: the matière is not a Topic and the teacher not an account,
 * so neither field looks anything up. The options are offered as ids, which is how the line
 * stores them, and only when the formation has any - same reasoning as TopicGroupType's own field.
 * Left empty, the line is for every option.
 */
class UfaTeachingTeamEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('topic', TextType::class, [
                'label' => 'ufaTeachingTeamTopicFieldLabel',
                'empty_data' => '',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('teacher', TextType::class, [
                'label' => 'ufaTeachingTeamTeacherFieldLabel',
                'empty_data' => '',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
        ;

        if (!$program->getOptions()->isEmpty()) {
            $choices = [];
            foreach ($program->getOptions() as $option) {
                $choices[$option->getShortName()] = $option->getId();
            }

            $builder->add('optionIds', ChoiceType::class, [
                'label' => 'ufaTeachingTeamOptionsFieldLabel',
                'help' => 'ufaTeachingTeamOptionsFieldHelp',
                'choices' => $choices,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'submitCreateAction',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => null])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
