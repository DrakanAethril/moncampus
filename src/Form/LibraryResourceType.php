<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;

// Not entity-backed, same reasoning as App\Form\LessonLogAttachmentType: the controller decides
// whether $file or $url was actually filled in (exactly one is expected) and which of
// sequenceTemplate/seanceTemplate/seancePhaseTemplate to attach to, since a single form can't map
// cleanly onto either of those XOR shapes. blocs/niveau/option are also handled outside this form
// entirely (free-text tags, resolved manually - see App\Form\SequenceTemplateType's docblock).
class LibraryResourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'libraryResourceLabelFieldLabel',
            ])
            ->add('file', FileType::class, [
                'label' => 'libraryResourceFileFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'constraints' => [
                    new File(
                        maxSize: FileUploadDefaults::MAX_SIZE,
                        mimeTypes: [
                            'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
                            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/plain', 'application/zip',
                        ],
                        mimeTypesMessage: 'libraryResourceInvalidTypeMessage',
                    ),
                ],
            ])
            ->add('url', UrlType::class, [
                'label' => 'libraryResourceUrlFieldLabel',
                'mapped' => false,
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'libraryResourceAddAction',
            ])
        ;
    }
}
