<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\HelpSection;
use App\Enum\HelpAudience;
use App\Service\FormValue;
use App\Service\HelpSlug;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A help section as an admin edits it.
 *
 * Audiences are checkboxes rather than a multi-select, per this app's own form convention, and the
 * slug is optional: left empty it is derived from the title (App\Controller\HelpAdminController).
 */
class HelpSectionType extends AbstractType
{
    // The two locales this app runs in (framework.enabled_locales) named in French, since that is
    // what the admin screens are written in. An unknown code falls back to its own uppercase form
    // rather than to nothing, so adding a third locale to the framework config cannot blank a label.
    private const array LOCALE_LABELS = ['fr' => 'Français', 'en' => 'Anglais'];

    /** @param list<string> $locales framework.enabled_locales, in configuration order */
    public function __construct(
        private readonly HelpSlug $slug,
        #[Autowire(param: 'kernel.enabled_locales')]
        private readonly array $locales = ['fr'],
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'helpSectionTitleFieldLabel',
                'empty_data' => '',
            ])
            ->add('slug', TextType::class, [
                'label' => 'helpSlugFieldLabel',
                'help' => 'helpSlugFieldHelpText',
                'required' => false,
                // A field left empty submits null, and the entity's setter takes a string:
                // TextType's own null->'' safety net only kicks in when empty_data is stated.
                // The controller then derives the slug from the title.
                'empty_data' => '',
            ])
            ->add('description', TextType::class, [
                'label' => 'helpSectionDescriptionFieldLabel',
                'help' => 'helpSectionDescriptionFieldHelpText',
                'empty_data' => '',
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'helpLocaleFieldLabel',
                'help' => 'helpLocaleFieldHelpText',
                'choices' => array_combine(
                    array_map(static fn (string $code): string => self::LOCALE_LABELS[$code] ?? strtoupper($code), $this->locales),
                    $this->locales,
                ),
            ])
            ->add('audiences', EnumType::class, [
                'class' => HelpAudience::class,
                'choice_label' => static fn (HelpAudience $audience): string => $audience->labelKey(),
                'label' => 'helpAudiencesFieldLabel',
                'help' => 'helpAudiencesFieldHelpText',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('position', IntegerType::class, [
                'label' => 'helpPositionFieldLabel',
                'help' => 'helpPositionFieldHelpText',
            ])
            ->add('published', CheckboxType::class, [
                'label' => 'helpPublishedFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ]);

        // Before validation, not after: the slug carries NotBlank and a format constraint, so
        // deriving it in the controller would come too late to save a form the author left empty.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $event->setData(HelpSlugNormalizer::normalize($event->getData(), $this->slug));
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => HelpSection::class,
            // HelpSection's constructor takes its slug and title, so a fresh one can't be built by
            // reflection alone - same shape as LessonTypeType. The slug is fixed up right after,
            // once the controller has derived it from the title.
            'empty_data' => static fn (FormInterface $form): HelpSection => new HelpSection(
                FormValue::trimmed($form, 'slug'),
                FormValue::trimmed($form, 'title'),
            ),
        ]);
    }
}
