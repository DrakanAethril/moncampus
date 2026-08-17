<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\DocumentationArticle;
use App\Entity\DocumentationTag;
use App\Entity\Group;
use App\Entity\User;
use App\Enum\DocumentationAudience;
use App\Enum\DocumentationStatus;
use App\Service\UploadPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;

/**
 * The article editor of handoff 2d.
 *
 * Three fields carry a rule rather than a value:
 *
 * - "diffusion" is unmapped: the entity knows a window (two nullable dates), the screen knows a
 *   radio - "Permanente" wipes the two dates on submit, which is the only way a period the author
 *   turned off cannot come back to life at its end date;
 * - "perimeter" is restricted to the groups the author may post on ($perimeterChoices), so a
 *   teacher cannot address the whole campus by editing the form's HTML;
 * - "tags" is a plain hidden field holding the labels: the referential is shared and created on
 *   the fly (App\Service\DocumentationTagResolver), which no entity-backed choice list can do.
 */
class DocumentationArticleType extends AbstractType
{
    public const string DIFFUSION_PERMANENT = 'permanent';
    public const string DIFFUSION_PERIOD = 'period';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Group> $perimeterChoices */
        $perimeterChoices = $options['perimeter_choices'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'documentationTitleFieldLabel',
                'empty_data' => '',
            ])
            ->add('excerpt', TextareaType::class, [
                'label' => 'documentationExcerptFieldLabel',
                'empty_data' => '',
                'attr' => ['rows' => 2],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'documentationBodyFieldLabel',
                'required' => false,
            ])
            ->add('status', EnumType::class, [
                'class' => DocumentationStatus::class,
                'choice_label' => static fn (DocumentationStatus $status): string => $status->labelKey(),
                'label' => 'documentationStatusFieldLabel',
            ])
            ->add('diffusion', ChoiceType::class, [
                'label' => 'documentationDiffusionFieldLabel',
                'mapped' => false,
                'expanded' => true,
                'choices' => [
                    'documentationDiffusionPermanentLabel' => self::DIFFUSION_PERMANENT,
                    'documentationDiffusionPeriodLabel' => self::DIFFUSION_PERIOD,
                ],
                'data' => $options['data'] instanceof DocumentationArticle && $options['data']->isTimeBound()
                    ? self::DIFFUSION_PERIOD
                    : self::DIFFUSION_PERMANENT,
            ])
            ->add('publishStart', DateTimeType::class, [
                'label' => 'documentationPublishStartFieldLabel',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('publishEnd', DateTimeType::class, [
                'label' => 'documentationPublishEndFieldLabel',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('perimeter', EntityType::class, [
                'class' => Group::class,
                'choices' => $perimeterChoices,
                'choice_label' => static fn (Group $group): string => $group->getName(),
                'label' => 'documentationPerimeterFieldLabel',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('audiences', EnumType::class, [
                'class' => DocumentationAudience::class,
                'choice_label' => static fn (DocumentationAudience $audience): string => $audience->labelKey(),
                'label' => 'documentationVisibilityFieldLabel',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('tags', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => $options['data'] instanceof DocumentationArticle
                    ? implode("\n", array_map(
                        static fn (DocumentationTag $tag): string => $tag->getLabel(),
                        $options['data']->getTags()->toArray(),
                    ))
                    : '',
            ])
            ->add('files', FilePickerType::class, [
                'label' => 'documentationAttachmentsFieldLabel',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                // The platform rule, unnarrowed. This field used to declare no type restriction at
                // all - an .exe was accepted - so putting it under the platform rule is a pure
                // tightening. It is deliberately not narrowed to "documents" the way messaging and
                // the lesson log are: a documentation article legitimately attaches a capture, a
                // notebook or a source file, and narrowing it further would be a product decision
                // design/validated/upload-policy.md did not take.
                //
                // The All() wrapper a multiple field needs is FilePickerType's own business now -
                // it builds the constraint from this policy, which is the half that used to be
                // forgotten at every call site.
                'policy' => UploadPolicy::platform(),
                // Teacher-authored course material: the « Bibliothèque de fichiers » tab is offered
                // here (design/validated/file-library.md, "The component"). A file picked there is a
                // reference - it weighs once, and deleting it from the library removes it from here.
                'library' => true,
            ]);

        // "Permanente" must win over whatever the two date inputs still hold - the mock leaves
        // them filled and merely dimmed, so a period turned off would otherwise survive the save.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();

            if (!\is_array($data) || self::DIFFUSION_PERIOD === ($data['diffusion'] ?? null)) {
                return;
            }

            $data['publishStart'] = null;
            $data['publishEnd'] = null;
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DocumentationArticle::class,
            'perimeter_choices' => [],
            // The constructor takes the author, so reflection alone cannot build a fresh article.
            'empty_data' => static function (FormInterface $form): DocumentationArticle {
                $author = $form->getConfig()->getOption('author');

                return new DocumentationArticle($author instanceof User ? $author : throw new \LogicException('A documentation article needs an author.'));
            },
        ]);

        $resolver->setRequired('author');
        $resolver->setAllowedTypes('author', User::class);
        $resolver->setAllowedTypes('perimeter_choices', 'array');
    }
}
