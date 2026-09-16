<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * What a cible hands in on an `upload` document.
 *
 * The « documents » narrowing of the platform upload policy, the same one the assignment submission
 * box uses - a dossier collects rapports, annexes and attestations, which is the same list of types.
 *
 * `library` stays false: this is the student's side, and a student has no bibliothèque de fichiers
 * (design/validated/file-library.md, « The component »).
 */
class DossierSubmissionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FilePickerType::class, [
            'label' => 'dossierSubmissionFileFieldLabel',
            'mapped' => false,
            'required' => true,
            'policy' => UploadPolicy::documents(),
            'library' => false,
        ]);
    }
}
