<?php

namespace App\Form;

use App\Entity\QuizQuestion;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

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
            ->add('imageFile', FileType::class, [
                'label' => 'quizQuestionImageFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'constraints' => [
                    new File(
                        maxSize: FileUploadDefaults::MAX_SIZE,
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'quizQuestionImageInvalidTypeMessage',
                    ),
                ],
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
