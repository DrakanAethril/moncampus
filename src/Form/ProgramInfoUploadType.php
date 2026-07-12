<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

// Not entity-backed, same reasoning as App\Form\AvatarUploadType: the uploaded file itself isn't
// what gets persisted (the resulting S3 key string is), so the controller handles that manually.
// Shared by both the Livret Alternant cover-page and calendar upload actions (fieldLabel varies
// per instantiation) since the accepted file types/size limit are identical for both slots.
class ProgramInfoUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => $options['fieldLabel'],
                'mapped' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'constraints' => [
                    new File(
                        maxSize: FileUploadDefaults::MAX_SIZE,
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                        mimeTypesMessage: 'programInfoUploadInvalidTypeMessage',
                    ),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'programInfoUploadSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('fieldLabel');
        $resolver->setAllowedTypes('fieldLabel', 'string');
    }
}
