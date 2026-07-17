<?php

namespace App\Form;

use App\Entity\Program;
use App\Enum\QuizMode;
use App\Enum\QuizScoring;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

// Screen 1c - not entity-backed (mirrors SequenceInstantiateType): the controller reads this data
// and builds the QuizInstance itself via App\Service\QuizInstantiationService, which is also where
// the difficulty slider position gets turned into actual per-level question counts (never trust
// the client's own recap numbers - see App\Service\QuizDifficultyDistributionResolver).
//
// 'mode' choices are deliberately restricted to Entrainement/Evaluation - App\Enum\QuizMode::Live
// exists in the data model (the strict "entrainement | evaluation | live" spec) but the concours-
// à-plusieurs feature isn't built yet, so it must never be a selectable choice here.
class QuizLaunchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('program', EntityType::class, [
                'class' => Program::class,
                'choices' => $options['programs'],
                'choice_label' => static fn (Program $program): string => sprintf('%s - %s', $program->getDisplayShortName(), $program->getSchoolYear()->getStartDate()?->format('Y') ?? '?'),
                'label' => 'quizLaunchProgramFieldLabel',
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('mode', EnumType::class, [
                'class' => QuizMode::class,
                'choices' => [QuizMode::Entrainement, QuizMode::Evaluation],
                'choice_label' => static fn (QuizMode $mode): string => $mode->labelKey(),
                'expanded' => true,
                'label' => 'quizLaunchModeFieldLabel',
                'data' => QuizMode::Evaluation,
            ])
            ->add('opensAt', DateTimeType::class, [
                'label' => 'quizLaunchOpensAtFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('closesAt', DateTimeType::class, [
                'label' => 'quizLaunchClosesAtFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('scoring', EnumType::class, [
                'class' => QuizScoring::class,
                'choice_label' => static fn (QuizScoring $scoring): string => $scoring->labelKey(),
                'expanded' => true,
                'label' => 'quizLaunchScoringFieldLabel',
                'data' => QuizScoring::Note20,
            ])
            ->add('scoreVisibleImmediately', CheckboxType::class, [
                'label' => 'quizLaunchScoreVisibleImmediatelyFieldLabel',
                'required' => false,
            ])
            ->add('questionCount', IntegerType::class, [
                'label' => 'quizLaunchQuestionCountFieldLabel',
                'constraints' => [new Positive()],
                'data' => $options['defaultQuestionCount'],
            ])
            // Driven client-side by the range slider (assets/controllers/quiz_launch_controller.js) -
            // rendered as a plain hidden field here since the visual track/thumb/zone-label markup
            // is bespoke (screen 1c), not something a native range input's own form_widget covers.
            ->add('difficultySliderPosition', HiddenType::class, [
                'constraints' => [new Range(min: 0, max: 100)],
                'data' => 50,
            ])
            ->add('sameQuestionsForAll', CheckboxType::class, [
                'label' => 'quizLaunchSameQuestionsForAllFieldLabel',
                'required' => false,
                'data' => $options['defaultSameQuestionsForAll'],
            ])
            ->add('questionOrderPerStudent', CheckboxType::class, [
                'label' => 'quizLaunchQuestionOrderPerStudentFieldLabel',
                'required' => false,
                'data' => $options['defaultQuestionOrderPerStudent'],
            ])
            ->add('answerOrderPerStudent', CheckboxType::class, [
                'label' => 'quizLaunchAnswerOrderPerStudentFieldLabel',
                'required' => false,
                'data' => $options['defaultAnswerOrderPerStudent'],
            ])
            ->add('secondsPerQuestion', IntegerType::class, [
                'label' => 'quizLaunchSecondsPerQuestionFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
                'data' => $options['defaultSecondsPerQuestion'],
            ])
            ->add('globalTimeMinutes', IntegerType::class, [
                'label' => 'quizLaunchGlobalTimeMinutesFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'quizLaunchSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['programs', 'defaultQuestionCount', 'defaultSecondsPerQuestion', 'defaultSameQuestionsForAll', 'defaultQuestionOrderPerStudent', 'defaultAnswerOrderPerStudent'])
            ->setAllowedTypes('programs', 'array')
            ->setAllowedTypes('defaultQuestionCount', 'int')
            ->setAllowedTypes('defaultSecondsPerQuestion', 'int')
            ->setAllowedTypes('defaultSameQuestionsForAll', 'bool')
            ->setAllowedTypes('defaultQuestionOrderPerStudent', 'bool')
            ->setAllowedTypes('defaultAnswerOrderPerStudent', 'bool')
        ;
    }
}
