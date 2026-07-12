<?php

namespace App\Form;

use App\Entity\Assignment;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// audienceType/option/manualRecipients are all shown at once (no JS toggling, same static-form
// choice already made for LessonLogAttachmentType) - the controller clears whichever of
// option/manualRecipients doesn't match the submitted audienceType before persisting.
class AssignmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'assignmentTitleFieldLabel',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'assignmentDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'assignmentDueDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('audienceType', EnumType::class, [
                'class' => AssignmentAudienceType::class,
                'choice_label' => static fn (AssignmentAudienceType $type): string => $type->labelKey(),
                'expanded' => true,
                'label' => 'assignmentAudienceTypeFieldLabel',
            ])
            ->add('option', EntityType::class, [
                'class' => Option::class,
                'choices' => $program->getOptions(),
                'choice_label' => 'shortName',
                'label' => 'assignmentAudienceOptionFieldLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('manualRecipients', EntityType::class, [
                'class' => User::class,
                'choices' => $program->getStudents(),
                'choice_label' => static fn (User $user): string => $user->getDisplayName() ?? $user->getUsername(),
                'label' => 'assignmentAudienceManualFieldLabel',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;

        $builder->setEmptyData(static fn (): Assignment => new Assignment($program));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => Assignment::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
