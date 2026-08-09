<?php

declare(strict_types=1);

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
    /**
     * What a student may hand in. Shared with the "Travail à faire" screen, which posts its own
     * bare form (one "Déposer" button per expected production, no field to fill in) and validates
     * the file against this very constraint - one list, so both ways in accept the same thing.
     */
    public static function fileConstraint(): File
    {
        return new File(
            maxSize: FileUploadDefaults::MAX_SIZE,
            mimeTypes: [
                'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain', 'application/zip',
            ],
            mimeTypesMessage: 'assignmentSubmissionInvalidTypeMessage',
        );
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'assignmentSubmissionFileFieldLabel',
                'mapped' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'constraints' => [self::fileConstraint()],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'assignmentSubmissionUploadAction',
            ])
        ;
    }
}
