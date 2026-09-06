<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\WordCloud;
use App\Enum\WordCloudProjectionMode;
use App\Enum\WordCloudWordLength;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The creation form of a word cloud - **the reusable half of the tool**.
 *
 * The handoff asks for the creation process to be a component rather than a page: « appelable en
 * page dédiée comme ici, mais aussi en modale ou en panneau depuis une séance, le cahier de texte,
 * etc. ». This type plus `templates/word_cloud/_form.html.twig` and the `word-cloud-form` Stimulus
 * controller are that component; the dedicated page is one host among several, and adding another
 * means including the partial and posting to the same route.
 *
 * The class stays a field even though every host knows which class it means: it is what lets the
 * same form be opened from somewhere that is *not* a class screen, and the créa draws it. The
 * options offered alongside it belong to every class on offer, and the browser shows only the ones
 * of the class currently picked - the server re-checks that pairing on submit
 * (App\Controller\WordCloud\ListController), since a select is not a permission.
 */
class WordCloudType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Program> $programs */
        $programs = $options['programs'];
        /** @var list<Option> $availableOptions */
        $availableOptions = $options['availableOptions'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'wordCloudNameFieldLabel',
            ])
            ->add('question', TextType::class, [
                'label' => 'wordCloudQuestionFieldLabel',
            ])
            ->add('program', EntityType::class, [
                'class' => Program::class,
                'choices' => $programs,
                'choice_label' => static fn (Program $program): string => $program->getDisplayShortName(),
                'choice_attr' => static fn (Program $program): array => ['data-program' => (string) $program->getId()],
                'label' => 'wordCloudProgramFieldLabel',
            ])
            // Checkbox group rather than a multiple select, per the repository's form convention;
            // the stylesheet turns each box into one of the créa's chips.
            ->add('options', EntityType::class, [
                'class' => Option::class,
                'choices' => $availableOptions,
                'choice_label' => static fn (Option $option): string => $option->getShortName(),
                'choice_attr' => static fn (Option $option): array => ['data-program' => implode(' ', array_map(
                    static fn (Program $program): string => (string) $program->getId(),
                    $option->getPrograms()->toArray(),
                ))],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'wordCloudOptionsFieldLabel',
            ])
            // Two native controls per bound, which is exactly what the créa draws: a date box and
            // a time box, and no bespoke picker to disagree with the browser's own.
            ->add('opensAt', DateTimeType::class, [
                'label' => 'wordCloudOpensAtFieldLabel',
                'required' => false,
                'date_widget' => 'single_text',
                'time_widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('closesAt', DateTimeType::class, [
                'label' => 'wordCloudClosesAtFieldLabel',
                'required' => false,
                'date_widget' => 'single_text',
                'time_widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            // Driven by the « Ouverture manuelle » chip; a hidden checkbox rather than a fourth
            // visible control, because the three shortcuts are one choice, not three settings.
            ->add('manualOpening', CheckboxType::class, [
                'label' => 'wordCloudManualOpeningFieldLabel',
                'required' => false,
            ])
            // Written by the stepper, and emptied by the « Illimités » chip - null is the unlimited
            // case throughout the tool, never a zero.
            ->add('wordsPerStudent', IntegerType::class, [
                'label' => 'wordCloudWordsPerStudentFieldLabel',
                'required' => false,
            ])
            ->add('wordLength', EnumType::class, [
                'class' => WordCloudWordLength::class,
                'choice_label' => static fn (WordCloudWordLength $length): string => $length->labelKey(),
                'label' => 'wordCloudWordLengthFieldLabel',
            ])
            ->add('projectionMode', EnumType::class, [
                'class' => WordCloudProjectionMode::class,
                'choice_label' => static fn (WordCloudProjectionMode $mode): string => $mode->labelKey(),
                'expanded' => true,
                'label' => 'wordCloudProjectionModeFieldLabel',
            ])
            ->add('groupVariants', CheckboxType::class, [
                'label' => 'wordCloudGroupVariantsFieldLabel',
                'required' => false,
            ])
            ->add('moderation', CheckboxType::class, [
                'label' => 'wordCloudModerationFieldLabel',
                'required' => false,
            ])
            ->add('visibleToStudents', CheckboxType::class, [
                'label' => 'wordCloudVisibleToStudentsFieldLabel',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WordCloud::class,
            'programs' => [],
            'availableOptions' => [],
        ]);
        $resolver->setAllowedTypes('programs', 'array');
        $resolver->setAllowedTypes('availableOptions', 'array');
    }
}
