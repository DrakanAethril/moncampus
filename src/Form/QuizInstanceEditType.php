<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\QuizInstance;
use App\Enum\QuizScoring;
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
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuizInstance::class]);
    }
}
