<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

// Not entity-backed (no data_class): the uploaded file is handled directly in the controller,
// which builds the S3 key and calls App\Service\UploadIntake itself - this form's only job
// is to validate the incoming file.
class AvatarUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('avatarFile', FilePickerType::class, [
                'label' => 'avatarUploadFieldLabel',
                'mapped' => false,
                // Deliberately below App\Form\FileUploadDefaults::MAX_SIZE - a profile picture
                // never needs the platform's general 20M ceiling.
                'help' => 'avatarUploadMaxSizeHelpText',
                'policy' => UploadPolicy::images(),
                'max_size' => '2M',
                // No library tab: a profile picture is not course material
                // (design/validated/file-library.md, "The component").
                'library' => false,
                'required' => true,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'avatarUploadSubmitAction',
            ])
        ;
    }
}
