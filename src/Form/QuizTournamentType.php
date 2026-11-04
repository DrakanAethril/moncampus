<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\QuizTemplate;
use App\Entity\QuizTournament;
use App\Enum\QuizTournamentPalette;
use App\Service\UploadPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Creating or editing a quiz tournament (design/validated/quiz-tournament.md §3), Paramètres ›
 * Pédagogique › Tournois. The data is an array (App\Service\QuizTournament\QuizTournamentEditor::formData()),
 * written onto the tournament by QuizTournamentEditor once the rules that span several fields hold -
 * the end after the start, at least one source, a reserve that allows a full duel.
 *
 * `locked` - the tournament has had its first duel: only the name, the end and the targeting are
 * still fields. Everything else is no longer rendered at all rather than rendered and disabled, so a
 * forged POST cannot move it either.
 *
 * The sources are a collection of rows rather than a multiple select: each row is a library picker
 * (templates/_library_picker_field.html.twig over the administrator's own quiz library), like the
 * merge rows of « Lancer un quiz ».
 */
class QuizTournamentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locked = true === $options['locked'];
        $dateOptions = [
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'required' => true,
            'constraints' => [new NotNull()],
        ];

        $builder
            ->add('name', TextType::class, [
                'label' => 'quizTournamentNameFieldLabel',
                'constraints' => [new NotBlank(), new Length(max: 255)],
                'attr' => ['maxlength' => 255, 'placeholder' => 'quizTournamentNamePlaceholder'],
            ]);

        if (!$locked) {
            $builder->add('startsAt', DateTimeType::class, $dateOptions + ['label' => 'quizTournamentStartsAtFieldLabel']);
        }

        $builder
            ->add('endsAt', DateTimeType::class, $dateOptions + ['label' => 'quizTournamentEndsAtFieldLabel'])
            ->add('targets', StructureTargetsType::class, [
                'label' => 'quizTournamentTargetsFieldLabel',
                'help' => 'quizTournamentTargetsFieldHelp',
                'targetable' => $options['tournament'],
            ]);

        if ($locked) {
            return;
        }

        /** @var list<QuizTemplate> $sourceChoices */
        $sourceChoices = $options['sourceChoices'];

        $builder
            ->add('sources', CollectionType::class, [
                'entry_type' => EntityType::class,
                'entry_options' => [
                    'class' => QuizTemplate::class,
                    'choices' => $sourceChoices,
                    'choice_label' => static fn (QuizTemplate $quiz): string => $quiz->getName() ?? '',
                    'placeholder' => 'quizTournamentSourcePlaceholder',
                    // Behind the picker modal, the select cannot take focus: `required` would block
                    // the submit while showing nothing (templates/_library_picker_field.html.twig).
                    'required' => false,
                    'label' => false,
                ],
                'label' => 'quizTournamentSourcesFieldLabel',
                'allow_add' => true,
                'allow_delete' => true,
                'prototype_name' => '__quiz__',
                'required' => false,
                'error_bubbling' => false,
            ])
            ->add('questionsPerDuel', IntegerType::class, [
                'label' => 'quizTournamentQuestionsPerDuelFieldLabel',
                'constraints' => [new NotNull(), new Range(min: 5, max: 50)],
                'attr' => ['min' => 5, 'max' => 50],
            ])
            ->add('maxTrueFalsePerDuel', IntegerType::class, [
                'label' => 'quizTournamentMaxTrueFalseFieldLabel',
                'constraints' => [new NotNull(), new Range(min: 0, max: 50)],
                'attr' => ['min' => 0, 'max' => 50],
            ])
            ->add('secondsPerQuestion', IntegerType::class, [
                'label' => 'quizTournamentSecondsPerQuestionFieldLabel',
                'constraints' => [new NotNull(), new Range(min: 5, max: 120)],
                'attr' => ['min' => 5, 'max' => 120],
            ])
            ->add('secondsPerMultiQuestion', IntegerType::class, [
                'label' => 'quizTournamentSecondsPerMultiQuestionFieldLabel',
                'constraints' => [new NotNull(), new Range(min: 5, max: 180)],
                'attr' => ['min' => 5, 'max' => 180],
            ])
            ->add('palette', EnumType::class, [
                'class' => QuizTournamentPalette::class,
                'label' => 'quizTournamentPaletteFieldLabel',
                'choice_label' => static fn (QuizTournamentPalette $palette): string => $palette->labelKey(),
                'expanded' => true,
                // No palette is preselected (§3): the swatches are a choice, not a default.
                'placeholder' => false,
                'required' => true,
                'constraints' => [new NotNull(message: 'quizTournamentPaletteRequiredError')],
            ])
            ->add('logo', FilePickerType::class, [
                'label' => 'quizTournamentLogoFieldLabel',
                'help' => 'quizTournamentLogoFieldHelp',
                'policy' => UploadPolicy::images(),
                'max_size' => '2M',
                'library' => false,
                'required' => false,
            ]);

        if (true === $options['hasLogo']) {
            $builder->add('removeLogo', CheckboxType::class, [
                'label' => 'quizTournamentRemoveLogoFieldLabel',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => null,
                'locked' => false,
                'hasLogo' => false,
                'tournament' => null,
                'sourceChoices' => [],
            ])
            ->setAllowedTypes('locked', 'bool')
            ->setAllowedTypes('hasLogo', 'bool')
            ->setAllowedTypes('tournament', ['null', QuizTournament::class])
            ->setAllowedTypes('sourceChoices', 'array');
    }
}
