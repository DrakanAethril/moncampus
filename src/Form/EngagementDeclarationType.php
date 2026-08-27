<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\EngagementKind;
use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Declaring an engagement (design's screen 13, left half).
 *
 * The nature is a closed list and its value is not on the form: what a certification is worth is the
 * barème's business, and a field for it would let a student - or a validator - out-give the whole
 * scale in one gesture.
 *
 * The evidence goes through App\Form\FilePickerType with `library: false`: **the form carries no
 * bytes** (the file reaches the bucket on its own request while the student is still typing), and a
 * student has no file library to pick from.
 *
 * Not bound to the entity: App\Entity\EngagementDeclaration takes its student, formation, period and
 * kind in its constructor - the four things a form has no business inventing - so this type carries
 * plain fields and App\Service\Game\EngagementDeclarationService builds the row.
 */
class EngagementDeclarationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', EnumType::class, [
                'class' => EngagementKind::class,
                'label' => 'engagementKindFieldLabel',
                'constraints' => [new NotNull()],
                // A select used for *input* carries a placeholder - the repository's own convention,
                // and here it also stops « Certification » from being filed by inattention.
                'placeholder' => 'engagementKindPlaceholder',
                'choice_label' => static fn (EngagementKind $kind): string => $kind->labelKey(),
            ])
            ->add('description', TextareaType::class, [
                'label' => 'engagementDescriptionFieldLabel',
                'attr' => ['rows' => 4, 'placeholder' => 'engagementDescriptionPlaceholder'],
                'constraints' => [new NotBlank(), new Length(min: 10, max: 4000)],
            ])
            ->add('attachments', FilePickerType::class, [
                'label' => 'engagementAttachmentsFieldLabel',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'help' => 'engagementAttachmentsHelp',
                'policy' => UploadPolicy::documents(),
                'library' => false,
            ])
        ;
    }
}
