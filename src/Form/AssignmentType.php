<?php

namespace App\Form;

use App\Entity\Assignment;
use App\Entity\Option;
use App\Entity\Program;
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

// audienceType/options are shown at once (no JS toggling in the form definition itself - see the
// data-controller="assignment-audience" wiring in program/assignment_new.html.twig, which only
// hides/shows the fields visually) - the controller clears whichever of options/manualRecipients
// doesn't match the submitted audienceType before persisting.
//
// manualRecipients is deliberately NOT a form field here: with potentially hundreds of students
// per program, an EntityType/ChoiceType widget would have to render (or, on submit, validate
// against) the full roster - Symfony's choice-list rendering always loads every choice regardless
// of which ones are bound. The select2 ajax widget in the template instead submits a plain
// `manual_recipients[]` array outside this form's namespace, read and resolved to Users directly
// by App\Controller\ProgramAssignmentController::form() via UserRepository::findByIdsForProgram(),
// which only ever touches the submitted ids, never the whole roster.
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
            ->add('options', EntityType::class, [
                'class' => Option::class,
                'choices' => $program->getOptions(),
                'choice_label' => 'shortName',
                'label' => 'assignmentAudienceOptionFieldLabel',
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
