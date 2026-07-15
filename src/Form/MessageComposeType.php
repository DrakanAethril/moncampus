<?php

namespace App\Form;

use App\Entity\MessageThread;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

// audienceType/programs/includeStudents/includeTeachers are shown at once (no JS toggling in the
// form definition itself - see data-controller="message-audience" wiring in
// messages/compose.html.twig, mirroring AssignmentType's assignment-audience controller) - only
// the fields matching the submitted audienceType are meaningful, App\Controller\MessageController
// clears/ignores the rest.
//
// Manual recipients are deliberately NOT a form field here, same reasoning as AssignmentType's
// manualRecipients: with potentially hundreds of active users, an EntityType/ChoiceType would
// have to render every choice regardless of which ones get picked. The select2/tom-select ajax
// widget in the template submits a plain `recipients[]` array outside this form's namespace,
// resolved server-side by App\Service\MessagingAccessChecker::resolveManualRecipients(), which
// only ever touches the submitted ids and re-validates each against the permission matrix.
//
// When the lockedRecipient option is set (the "reply privately to an announcement's sender" flow -
// see MessageController), audienceType/programs are omitted from the form entirely: the controller
// sets audienceType=Manual and the single recipient itself before handling the request, so
// there's no picker to hide/show and nothing here to override that.
class MessageComposeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, [
                'label' => 'messageSubjectFieldLabel',
            ])
            // Not mapped: there's no MessageThread::$body - the body belongs to the thread's
            // first Message, built by the controller from this field's raw value, same as
            // "attachments" below.
            ->add('body', TextareaType::class, [
                'label' => 'messageBodyFieldLabel',
                'mapped' => false,
                'constraints' => [new NotBlank()],
            ])
        ;

        if (null === $options['lockedRecipient']) {
            /** @var list<MessageAudienceType> $allowedAudienceTypes */
            $allowedAudienceTypes = $options['allowedAudienceTypes'];
            /** @var list<Program> $programs */
            $programs = $options['programs'];

            $builder
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
            ;

            AudienceFormValidation::addProgramAudienceValidation($builder);
        }

        $builder
            ->add('attachments', FileType::class, [
                'label' => 'messageAttachmentsFieldLabel',
                'mapped' => false,
                'multiple' => true,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                // 'multiple' => true means the submitted value is an array of UploadedFiles - a
                // bare File constraint would validate the array itself (and fail with a generic
                // "should be of type string" type error), so it has to be wrapped in All to be
                // applied to each file individually.
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
                'label' => 'messageSendAction',
            ])
        ;

        $builder->setEmptyData(static fn (): MessageThread => new MessageThread($options['sender']));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => MessageThread::class, 'lockedRecipient' => null])
            ->setRequired(['sender', 'allowedAudienceTypes', 'programs'])
            ->setAllowedTypes('sender', User::class)
            ->setAllowedTypes('allowedAudienceTypes', 'array')
            ->setAllowedTypes('programs', 'array')
            ->setAllowedTypes('lockedRecipient', ['null', User::class])
        ;
    }
}
