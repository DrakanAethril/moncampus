<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

// Not entity-backed (no data_class): the uploaded file is handled directly in the controller,
// which builds the S3 key and calls App\Service\FileUploadService itself - this form's only job
// is to validate the incoming file.
class AvatarUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('avatarFile', FileType::class, [
                'label' => 'avatarUploadFieldLabel',
                'mapped' => false,
                'constraints' => [
                    new File(
                        maxSize: '2M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'avatarUploadInvalidTypeMessage',
                    ),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'avatarUploadSubmitAction',
            ])
        ;
    }
}
