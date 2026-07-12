<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

// Not entity-backed, same shape as App\Form\LessonLogAttachmentType's file field - the controller
// builds the AssignmentSubmission/AssignmentSubmissionFile itself from the uploaded file.
class AssignmentSubmissionFileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'assignmentSubmissionFileFieldLabel',
                'mapped' => false,
                'constraints' => [
                    new File(
                        maxSize: '25M',
                        mimeTypes: [
                            'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/plain', 'application/zip',
                        ],
                        mimeTypesMessage: 'assignmentSubmissionInvalidTypeMessage',
                    ),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'assignmentSubmissionUploadAction',
            ])
        ;
    }
}
