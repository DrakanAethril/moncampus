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
                // Teacher-authored course material: the « Bibliothèque de fichiers » tab is offered
                // here (design/validated/file-library.md, "The component"). A file picked there is a
                // reference - it weighs once, and deleting it from the library removes it from here.
                'library' => true,
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
