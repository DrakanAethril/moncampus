<?php

namespace App\Form;

use App\Entity\Program;
use App\Entity\SignupList;
use App\Enum\MessageAudienceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

// audienceType/programs/includeStudents/includeTeachers/attachments wiring mirrors
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
            ->add('audienceType', EnumType::class, [
                'class' => MessageAudienceType::class,
                'choices' => $allowedAudienceTypes,
                'choice_label' => static fn (MessageAudienceType $type): string => $type->labelKey(),
                'expanded' => true,
                'label' => 'messageAudienceTypeFieldLabel',
            ])
            ->add('programs', EntityType::class, [
                'class' => Program::class,
                'choices' => $programs,
                'choice_label' => 'shortName',
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
            ->add('attachments', FileType::class, [
                'label' => 'messageAttachmentsFieldLabel',
                'mapped' => false,
                'multiple' => true,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'constraints' => [
                    new All([
                        new File(
                            maxSize: FileUploadDefaults::MAX_SIZE,
                            mimeTypes: [
                                'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/plain', 'application/zip',
                            ],
                            mimeTypesMessage: 'messageAttachmentInvalidTypeMessage',
                        ),
                    ]),
                ],
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
