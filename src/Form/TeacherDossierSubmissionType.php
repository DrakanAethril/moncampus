<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * What is handed in on an `upload` piece of a dossier intervenant - by the intervenant, or by the
 * administration for them.
 *
 * PDF and pictures only: these are scans of papers.
 *
 * `library` stays false although a teacher has a bibliothèque de fichiers: a file picked there is a
 * *reference*, so deleting it from the library would take the piece out of the dossier - and a
 * teaching library is not where an identity card belongs in the first place.
 */
class TeacherDossierSubmissionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FilePickerType::class, [
            'label' => 'teacherDossierSubmissionFileFieldLabel',
            'mapped' => false,
            'required' => true,
            'policy' => UploadPolicy::administrativePapers(),
            'library' => false,
        ]);
    }
}
