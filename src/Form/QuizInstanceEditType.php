<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Option;
use App\Entity\QuizInstance;
use App\Enum\QuizPenaltyMode;
use App\Enum\QuizScoring;
use App\Enum\QuizSupervisionPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
 * « Note négative sur erreurs » *is* editable here, and it is the one field on this form that
 * rewrites something already written. The penalty is frozen into each answer as it is given
 * (App\Service\QuizAttemptGrader::score()), so on its own, moving it would leave the copies already
 * handed in marked under the old rule and the ones still to come under the new one - the same class
 * sitting two different papers. The answer is not to forbid the gesture but to remove the
 * asymmetry: App\Service\QuizPenaltyRemarker re-marks every copy of the quiz on save, and the
 * controller says how many marks moved.
 *
 * That re-marking re-grades nothing. What each answer earned was decided when the student gave it
 * and stays decided; only the penalty on top of it is recomputed. Which is why this field passes
 * the test the draw settings fail: a different penalty is a different reading of the same copies,
 * a different draw would be different copies.
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
 * « Visibilité » - the option the quiz is narrowed to - *is* editable here, and it passes the same
 * test only because App\Service\QuizAudience says the narrowing decides who may discover and begin
 * a quiz, never who has already begun it: a student holding an attempt keeps the quiz on their hub
 * and keeps their copy. Without that rule this field would take a finished copy away from the
 * student who sat it, and it would belong nowhere near this form.
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
        ;

        // Absent rather than empty for a class with no option: « Tous les étudiants » would then be
        // the only thing to choose, which is not a choice. The list is the *instance's own* class's
        // options, so there is nothing to filter and nothing foreign to refuse - Symfony rejects a
        // value outside `choices` on its own, which is why this field needs no listener of the kind
        // QuizLaunchType carries.
        if ([] !== $options['programOptions']) {
            $builder->add('visibilityOption', EntityType::class, [
                'class' => Option::class,
                'choices' => $options['programOptions'],
                'choice_label' => 'shortName',
                'label' => 'quizLaunchVisibilityOptionFieldLabel',
                'help' => 'quizInstanceEditVisibilityOptionFieldHelp',
                'placeholder' => 'quizLaunchVisibilityOptionAllLabel',
                'required' => false,
            ]);
        }

        $builder
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
            // Editable after the launch like the rest of this form, and usefully so: it is the
            // switch a teacher reaches for once the last group has sat the same subject.
            ->add('correctionVisible', CheckboxType::class, [
                'label' => 'quizLaunchCorrectionVisibleFieldLabel',
                'help' => 'quizLaunchCorrectionVisibleFieldHelp',
                'required' => false,
            ])
            // « Note négative sur erreurs », the four fields of the launch form unchanged - see the
            // class docblock for why they are here at all. Entity-backed unlike over there, so no
            // `data`: they open on what this quiz was launched with.
            ->add('negativeMarking', CheckboxType::class, [
                'label' => 'quizLaunchNegativeMarkingFieldLabel',
                'help' => 'quizLaunchNegativeMarkingFieldHelp',
                'required' => false,
            ])
            // Same guard as « secondes avant une sortie » below, and for the same reason: the
            // column is NOT NULL and this form writes straight into the entity, so a payload
            // arriving without the select - an old cached page, a client that posts a subset -
            // would reach a typed setter as null and raise a TypeError rather than a form error.
            ->add('penaltyMode', EnumType::class, [
                'class' => QuizPenaltyMode::class,
                'choice_label' => static fn (QuizPenaltyMode $penaltyMode): string => $penaltyMode->labelKey(),
                'label' => 'quizLaunchPenaltyModeFieldLabel',
                'empty_data' => QuizPenaltyMode::Fixed->value,
            ])
            // Required here, unlike on the launch form, for the reason « secondes avant une sortie »
            // gives just below: the column is NOT NULL and this form is bound to the entity, so a
            // blank submission would reach the setter as null. Blank means the half point the
            // launch form opens on rather than a 500.
            ->add('penaltyPoints', NumberType::class, [
                'label' => 'quizLaunchPenaltyPointsFieldLabel',
                'help' => 'quizLaunchPenaltyPointsFieldHelp',
                'html5' => false,
                'scale' => 2,
                'empty_data' => '0.5',
                'constraints' => [new Range(min: 0.01, max: 20)],
            ])
            ->add('penaltyPercent', IntegerType::class, [
                'label' => 'quizLaunchPenaltyPercentFieldLabel',
                'help' => 'quizLaunchPenaltyPercentFieldHelp',
                'empty_data' => '50',
                'constraints' => [new Range(min: 1, max: 100)],
            ])
            ->add('negativeScoreAllowed', CheckboxType::class, [
                'label' => 'quizLaunchNegativeScoreAllowedFieldLabel',
                'help' => 'quizLaunchNegativeScoreAllowedFieldHelp',
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
            ->setDefaults(['data_class' => QuizInstance::class, 'supervisionEditable' => false, 'programOptions' => []])
            ->setAllowedTypes('supervisionEditable', 'bool')
            ->setAllowedTypes('programOptions', 'array')
        ;
    }
}
