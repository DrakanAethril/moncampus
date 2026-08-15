<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
            // Every Topic must belong to exactly one group - only the program's own active
            // groups are valid choices, same reasoning as the teacher field below.
            ->add('topicGroup', EntityType::class, [
                'class' => TopicGroup::class,
                'choices' => $program->getTopicGroups()->filter(static fn (TopicGroup $topicGroup): bool => null === $topicGroup->getInactiveDate()),
                'choice_label' => static fn (TopicGroup $topicGroup): string => $topicGroup->getName(),
                'label' => 'topicTopicGroupFieldLabel',
                'placeholder' => 'topicTopicGroupPlaceholder',
            ])
            // Decimal (2 for a matière that weighs double, 0.5 for a minor one) - the same non-html5
            // NumberType as the hour volumes below, for the same reason.
            ->add('coefficient', NumberType::class, [
                'label' => 'topicCoefficientFieldLabel',
                'help' => 'topicCoefficientFieldHelp',
                'scale' => 2,
                'html5' => false,
            ])
            // NumberType (not IntegerType) so decimal volumes (e.g. 1.5 for 1h30) are accepted -
            // 'html5' => false for the same reason as LessonSessionType's length field: a native
            // type="number" input's default step="1" rejects fractional values, and locale-comma
            // decimals don't parse through it either.
            ->add('targetCmHours', NumberType::class, [
                'label' => 'topicTargetCmHoursFieldLabel',
                'scale' => 2,
                'html5' => false,
            ])
            ->add('targetTdHours', NumberType::class, [
                'label' => 'topicTargetTdHoursFieldLabel',
                'scale' => 2,
                'html5' => false,
            ])
            ->add('targetTpHours', NumberType::class, [
                'label' => 'topicTargetTpHoursFieldLabel',
                'scale' => 2,
                'html5' => false,
            ])
            // Not a form field: "teacher" is picked via an ajax tom-select field embedded
            // directly in topic_new.html.twig (resolved from a top-level "teacher" POST field by
            // ProgramTimetableSettingsController), same convention as LessonSessionType's teacher
            // field - only the program's own teachers are eligible.
            ->add('description', TextareaType::class, [
                'label' => 'topicDescriptionFieldLabel',
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
            return new Topic($form->get('name')->getData() ?? '', $program, $form->get('topicGroup')->getData());
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
