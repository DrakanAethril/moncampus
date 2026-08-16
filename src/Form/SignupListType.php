<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Program;
use App\Entity\SignupList;
use App\Enum\MessageAudienceType;
use App\Service\UploadPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// audienceTypes/programs/includeStudents/includeTeachers/attachments wiring mirrors
// MessageComposeType/AnnouncementType/AgendaEventType exactly - see those classes' docblocks.
// Unlike MessageComposeType, description IS mapped normally (data_class-backed, no mapped: false
// trick needed - SignupList::$description is a real property, there's no separate "first message"
// entity the way Message::$body lives apart from MessageThread).
class SignupListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<MessageAudienceType> $allowedAudienceTypes */
        $allowedAudienceTypes = $options['allowedAudienceTypes'];
        /** @var list<Program> $programs */
        $programs = $options['programs'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'signupListTitleFieldLabel',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'signupListDescriptionFieldLabel',
            ])
            ->add('registrationDeadline', DateTimeType::class, [
                'label' => 'signupListRegistrationDeadlineFieldLabel',
                'help' => 'signupListRegistrationDeadlineFieldHelpText',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('publicRoster', CheckboxType::class, [
                'label' => 'signupListPublicRosterFieldLabel',
                'help' => 'signupListPublicRosterFieldHelpText',
                'required' => false,
            ])
            ->add('audienceTypes', EnumType::class, [
                'class' => MessageAudienceType::class,
                'choices' => $allowedAudienceTypes,
                'choice_label' => static fn (MessageAudienceType $type): string => $type->labelKey(),
                // Checkboxes, not radios: audiences are cumulative - see
                // App\Entity\AudienceTargetable.
                'multiple' => true,
                'expanded' => true,
                'label' => 'messageAudienceTypeFieldLabel',
            ])
            ->add('programs', EntityType::class, [
                'class' => Program::class,
                'choices' => $programs,
                'choice_label' => static fn (Program $program): string => $program->getDisplayShortName(),
                'label' => 'messageAudienceProgramsFieldLabel',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('includeStudents', CheckboxType::class, [
                'label' => 'messageAudienceRoleStudentsLabel',
                'required' => false,
            ])
            ->add('includeTeachers', CheckboxType::class, [
                'label' => 'messageAudienceRoleTeachersLabel',
                'required' => false,
            ])
            ->add('attachments', FilePickerType::class, [
                'label' => 'messageAttachmentsFieldLabel',
                'mapped' => false,
                'multiple' => true,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'policy' => UploadPolicy::documents(),
                'library' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;

        AudienceFormValidation::addProgramAudienceValidation($builder);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => SignupList::class])
            ->setRequired(['allowedAudienceTypes', 'programs'])
            ->setAllowedTypes('allowedAudienceTypes', 'array')
            ->setAllowedTypes('programs', 'array')
        ;
    }
}
