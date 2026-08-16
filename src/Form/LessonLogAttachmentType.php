<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use App\Validator\AllowedUpload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
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
            ->add('file', FileType::class, [
                'label' => 'lessonLogAttachmentFileFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'constraints' => [
                    new AllowedUpload(UploadPolicy::documents()),
                ],
            ])
            ->add('url', UrlType::class, [
                'label' => 'lessonLogAttachmentUrlFieldLabel',
                'mapped' => false,
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'lessonLogAttachmentAddAction',
            ])
        ;
    }
}
