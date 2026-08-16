<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\QuizQuestion;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionTimeMode;
use App\Enum\QuestionType;
use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

// Screen 1b, right-hand question editor. Only the fixed fields live here - the answers list is a
// dynamic client-side row set (assets/controllers/quiz_question_editor_controller.js) submitted as
// raw answers[N][label]/answers[N][correct] request fields and resolved manually in
// QuizLibraryController::questionSave(), same reasoning as AssignmentType's manualRecipients or
// SequenceTemplateType's niveau/option/blocs (a Symfony CollectionType would fight the add/remove/
// reorder JS instead of driving it).
class QuizQuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextareaType::class, [
                'label' => 'quizQuestionLabelFieldLabel',
                'constraints' => [new NotBlank()],
            ])
            ->add('type', EnumType::class, [
                'class' => QuestionType::class,
                'choice_label' => static fn (QuestionType $type): string => $type->labelKey(),
                'label' => 'quizQuestionTypeFieldLabel',
            ])
            ->add('difficulty', EnumType::class, [
                'class' => QuestionDifficulty::class,
                'choice_label' => static fn (QuestionDifficulty $difficulty): string => $difficulty->labelKey(),
                'expanded' => true,
                'required' => false,
                // required:false + expanded needs an explicit "no choice" radio to let a value be
                // cleared once set - give it a real translated label instead of Symfony's default
                // "None" placeholder text (see App\Entity\QuizQuestion's docblock: unset === Moyen).
                'placeholder' => 'quizQuestionDifficultyUnsetLabel',
                'label' => 'quizQuestionDifficultyFieldLabel',
            ])
            // Three answers where the quiz has two: a question follows the quiz's own default
            // unless it says otherwise - see App\Enum\QuestionTimeMode.
            ->add('timeMode', EnumType::class, [
                'class' => QuestionTimeMode::class,
                'choice_label' => static fn (QuestionTimeMode $mode): string => $mode->labelKey(),
                'expanded' => true,
                'label' => 'quizQuestionTimeModeFieldLabel',
            ])
            ->add('timeSeconds', IntegerType::class, [
                'label' => 'quizQuestionTimeSecondsFieldLabel',
                'required' => false,
                'constraints' => [new Positive()],
            ])
            // Screen 1m's "Correction : …" callout - never shown during the attempt itself.
            ->add('explanation', TextareaType::class, [
                'label' => 'quizQuestionExplanationFieldLabel',
                'required' => false,
                'help' => 'quizQuestionExplanationFieldHint',
                'attr' => ['rows' => 2],
            ])
            ->add('imageFile', FilePickerType::class, [
                'label' => 'quizQuestionImageFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'policy' => UploadPolicy::images(),
                // Course material, so the library tab belongs here - it arrives with the library
                // itself (design/validated/file-library.md, lot 4).
                'library' => false,
            ])
            ->add('removeImage', CheckboxType::class, [
                'label' => 'quizQuestionRemoveImageFieldLabel',
                'mapped' => false,
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuizQuestion::class]);
    }
}
