<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\QuizInstance;
use App\Enum\QuizScoring;
use App\Enum\QuizSupervisionPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

/**
 * "Modifier le quiz" on a launched instance - entity-backed, unlike QuizLaunchType which builds one.
 *
 * It deliberately carries only the settings that can change without invalidating what students have
 * already done. Everything about the *draw* (question count, difficulty split, the three fairness
 * toggles, the merged source templates) is absent: those were resolved into actual
 * QuizInstanceQuestion rows at launch time, and re-deciding them afterwards would mean either
 * re-drawing a quiz some of the class has already sat, or showing numbers that no longer describe
 * the questions in the row. A different draw is a new launch.
 *
 * QuizMode is absent for the same reason one level up: entraînement and évaluation do not grant the
 * same number of attempts, so flipping the mode would retroactively change how many tries the
 * students who already played were entitled to.
 *
 * Mode contrôle, on the other hand, *is* editable here, and only on an évaluation (the
 * 'supervisionEditable' option, which the controller reads off the frozen mode). It invalidates
 * nothing: the journal stores raw page events, and the threshold, the policy and the auto-submit
 * count are all read back at display time, so moving one re-reads what was recorded rather than
 * rewriting it. Turning surveillance off leaves every event in place - turning it back on shows the
 * same timelines again.
 *
 * Two consequences are real, and the screen says so rather than the form forbidding them: the
 * absences already recorded count towards a freshly-chosen « rendre après N sorties », so a copy can
 * be handed in the moment that policy is picked; and an attempt already open when surveillance is
 * turned on holds no session key, so its next page is the « repris ailleurs » screen and the student
 * takes the hand back in one click.
 */
class QuizInstanceEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'quizLaunchNameFieldLabel',
                'constraints' => [new NotBlank(), new Length(max: 255)],
                'attr' => ['maxlength' => 255],
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
            ->add('secondsPerQuestion', IntegerType::class, [
                'label' => 'quizLaunchSecondsPerQuestionFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
            ])
            ->add('globalTimeMinutes', IntegerType::class, [
                'label' => 'quizLaunchGlobalTimeMinutesFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
            ])
            ->add('scoring', EnumType::class, [
                'class' => QuizScoring::class,
                'choice_label' => static fn (QuizScoring $scoring): string => $scoring->labelKey(),
                'expanded' => true,
                'label' => 'quizLaunchScoringFieldLabel',
            ])
            ->add('scoreVisibleImmediately', CheckboxType::class, [
                'label' => 'quizLaunchScoreVisibleImmediatelyFieldLabel',
                'required' => false,
            ])
        ;

        // Absent rather than hidden on an entraînement: « le mode contrôle n'existe qu'en
        // Évaluation » is the rule, the mode is frozen at launch, and a field that cannot mean
        // anything here has no business being submitted at all.
        if ($options['supervisionEditable']) {
            $builder
                ->add('supervised', CheckboxType::class, [
                    'label' => 'quizLaunchSupervisedFieldLabel',
                    'required' => false,
                ])
                ->add('supervisionPolicy', EnumType::class, [
                    'class' => QuizSupervisionPolicy::class,
                    'choice_label' => static fn (QuizSupervisionPolicy $policy): string => $policy->labelKey(),
                    'expanded' => true,
                    'label' => 'quizLaunchSupervisionPolicyFieldLabel',
                ])
                // Required, unlike on the launch form: the column is NOT NULL, so a blank
                // submission would reach the setter as null. Blank therefore means the same 8
                // seconds the launch form starts on, rather than a 500.
                ->add('supervisionExitSeconds', IntegerType::class, [
                    'label' => 'quizLaunchSupervisionExitSecondsFieldLabel',
                    'empty_data' => '8',
                    'constraints' => [new Range(min: 1, max: 300)],
                ])
                ->add('supervisionSubmitAt', IntegerType::class, [
                    'label' => 'quizLaunchSupervisionSubmitAtFieldLabel',
                    'required' => false,
                    // Same floor as the launch form: a copy handed in on one stray click would be
                    // the automatic sanction the design refuses.
                    'constraints' => [new Range(min: 3, max: 50)],
                ])
            ;
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'submitSaveAction',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => QuizInstance::class, 'supervisionEditable' => false])
            ->setAllowedTypes('supervisionEditable', 'bool')
        ;
    }
}
