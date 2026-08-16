<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use App\Validator\AllowedUpload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\All;
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
                    new All([new AllowedUpload(UploadPolicy::documents())]),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'messageReplyAction',
            ])
        ;
    }
}
