<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use App\Validator\AllowedUpload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

// Not entity-backed, same shape as App\Form\LessonLogAttachmentType's file field - the controller
// builds the AssignmentSubmission/AssignmentSubmissionFile itself from the uploaded file.
class AssignmentSubmissionFileType extends AbstractType
{
    /**
     * What a student may hand in. Shared with the "Travail à faire" screen, which posts its own
     * bare form (one "Déposer" button per expected production, no field to fill in) and validates
     * the file against this very constraint - one rule, so both ways in accept the same thing.
     *
     * The "documents" narrowing of the platform upload policy: the same twelve types this field
     * used to enumerate itself, now declared once (design/validated/upload-policy.md).
     */
    public static function fileConstraint(): AllowedUpload
    {
        return new AllowedUpload(UploadPolicy::documents());
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FilePickerType::class, [
                'label' => 'assignmentSubmissionFileFieldLabel',
                'mapped' => false,
                'required' => true,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'policy' => UploadPolicy::documents(),
                // A student has no library, and this is the student side of the assignment
                // (design/validated/file-library.md, "The component").
                'library' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'assignmentSubmissionUploadAction',
            ])
        ;
    }
}
