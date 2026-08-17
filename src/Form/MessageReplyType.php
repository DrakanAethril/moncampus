<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
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
            ->add('attachments', FilePickerType::class, [
                'label' => 'messageAttachmentsFieldLabel',
                'mapped' => false,
                'multiple' => true,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                // See MessageComposeType's identical field.
                'policy' => UploadPolicy::documents(),
                // Teacher-authored course material: the « Bibliothèque de fichiers » tab is offered
                // here (design/validated/file-library.md, "The component"). A file picked there is a
                // reference - it weighs once, and deleting it from the library removes it from here.
                'library' => true,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'messageReplyAction',
            ])
        ;
    }
}
