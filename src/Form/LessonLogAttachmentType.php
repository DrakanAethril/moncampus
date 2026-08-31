<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;

// Not entity-backed, same reasoning as App\Form\AvatarUploadType: the controller decides
// whether $file or $url was actually filled in (exactly one is expected) and builds the
// LessonLogAttachment itself, since a single form can't map cleanly onto the entity's XOR shape.
class LessonLogAttachmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'lessonLogAttachmentLabelFieldLabel',
            ])
            ->add('file', FilePickerType::class, [
                'label' => 'lessonLogAttachmentFileFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'policy' => UploadPolicy::documents(),
                // Teacher-authored course material: the « Bibliothèque de fichiers » tab is offered
                // here (design/validated/file-library.md, "The component"). A file picked there is a
                // reference - it weighs once, and deleting it from the library removes it from here.
                'library' => true,
                // The « Lien externe » tab draws the `url` field below, in the picker's own tab strip
                // rather than as a row under it: a file and a link are one question, and this form
                // refuses both at once.
                'external_link' => 'url',
            ])
            ->add('url', UrlType::class, [
                // No label: the tab that shows this field names it, and a title inside the pane
                // would say it twice.
                'label' => false,
                'mapped' => false,
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'lessonLogAttachmentAddAction',
            ])
        ;
    }
}
