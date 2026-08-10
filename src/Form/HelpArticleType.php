<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\HelpArticle;
use App\Entity\HelpSection;
use App\Enum\HelpArticleKind;
use App\Enum\HelpAudience;
use App\Service\FormValue;
use App\Service\HelpSlug;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An article, a frequently-asked question or a glossary term as an admin edits it - one form for
 * the three kinds, because they are one entity (App\Entity\HelpArticle).
 *
 * $summary is what every kind needs and $body only concerns a full article, which is why the body
 * field is not required: a FAQ answer and a glossary definition live entirely in the summary.
 */
class HelpArticleType extends AbstractType
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
            ->add('section', EntityType::class, [
                'class' => HelpSection::class,
                // The language is part of the label: sections come in one row per language, and
                // picking the wrong row is otherwise invisible until the article shows up under a
                // French heading.
                'choice_label' => static fn (HelpSection $section): string => sprintf('%s (%s)', $section->getTitle(), strtoupper($section->getLocale())),
                'label' => 'helpArticleSectionFieldLabel',
                'placeholder' => 'helpArticleSectionPlaceholder',
            ])
            ->add('kind', EnumType::class, [
                'class' => HelpArticleKind::class,
                'choice_label' => static fn (HelpArticleKind $kind): string => $kind->labelKey(),
                'label' => 'helpArticleKindFieldLabel',
                'expanded' => true,
            ])
            ->add('title', TextType::class, [
                'label' => 'helpArticleTitleFieldLabel',
                'help' => 'helpArticleTitleFieldHelpText',
                'empty_data' => '',
            ])
            ->add('slug', TextType::class, [
                'label' => 'helpSlugFieldLabel',
                'help' => 'helpSlugFieldHelpText',
                'required' => false,
                // Same as HelpSectionType's: without empty_data, an empty optional field submits
                // null into a setter that takes a string.
                'empty_data' => '',
            ])
            ->add('summary', TextareaType::class, [
                'label' => 'helpArticleSummaryFieldLabel',
                'help' => 'helpArticleSummaryFieldHelpText',
                'empty_data' => '',
                'attr' => ['rows' => 3],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'helpArticleBodyFieldLabel',
                'help' => 'helpArticleBodyFieldHelpText',
                'required' => false,
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

        // See HelpSectionType's identical listener: the slug has to be usable before validation.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $event->setData(HelpSlugNormalizer::normalize($event->getData(), $this->slug));
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => HelpArticle::class,
            // Same reason as HelpSectionType's: the constructor takes the section, the slug and the
            // title, so reflection alone cannot build a fresh one.
            'empty_data' => static function (FormInterface $form): HelpArticle {
                $section = $form->get('section')->getData();

                return new HelpArticle(
                    $section instanceof HelpSection ? $section : null,
                    FormValue::trimmed($form, 'slug'),
                    FormValue::trimmed($form, 'title'),
                );
            },
        ]);
    }
}
