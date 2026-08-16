<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;

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
            ->add('file', FilePickerType::class, [
                'label' => 'libraryResourceFileFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'policy' => UploadPolicy::documents(),
                // Course material, so the library tab belongs here - it arrives with the library
                // itself (design/validated/file-library.md, lot 4).
                'library' => false,
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
