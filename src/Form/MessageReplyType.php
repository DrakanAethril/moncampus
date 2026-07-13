<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

// Not entity-backed (no data_class) - same reasoning as AssignmentSubmissionFileType: the
// controller builds the Message/MessageAttachment rows itself from these fields' raw values.
// Posting into an existing 1:1-shaped MessageThread, so there's no audience picker here at all -
// see MessageComposeType for that.
class MessageReplyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('body', TextareaType::class, [
                'label' => 'messageBodyFieldLabel',
                'mapped' => false,
                'constraints' => [new NotBlank()],
            ])
            ->add('attachments', FileType::class, [
                'label' => 'messageAttachmentsFieldLabel',
                'mapped' => false,
                'multiple' => true,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                // See MessageComposeType's identical field for why this needs All(): 'multiple'
                // => true submits an array of files, and a bare File constraint would validate
                // the array itself instead of each file.
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
                'label' => 'messageReplyAction',
            ])
        ;
    }
}
