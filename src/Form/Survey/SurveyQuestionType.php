<?php

declare(strict_types=1);

namespace App\Form\Survey;

use App\Entity\SurveyQuestion;
use App\Enum\SurveyQuestionType as QuestionKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One line of the editor. The proposed answers are not a form collection: they are read straight
 * off the request by the controller, the way QuizLibraryController::applyAnswers() does - a survey
 * answer is a label and a rank, and a CollectionType would buy nothing but the locked-row traps
 * this repository has already paid for once (student mail aliases).
 *
 * isScale, minChoices and maxChoices are shown or hidden by the Stimulus controller according to
 * the type, and never emptied when out of scope: same convention as AudienceTargetable's
 * $programs - the fields out of scope are simply not read.
 */
class SurveyQuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'class' => QuestionKind::class,
                'choices' => QuestionKind::forEditor(),
                'choice_label' => static fn (QuestionKind $type): string => $type->labelKey(),
                'label' => 'surveyQuestionTypeFieldLabel',
            ])
            ->add('label', TextareaType::class, [
                'label' => 'surveyQuestionLabelFieldLabel',
                'attr' => ['rows' => 2],
            ])
            ->add('helpText', TextType::class, [
                'label' => 'surveyQuestionHelpTextFieldLabel',
                'help' => 'surveyQuestionHelpTextFieldHelpText',
                'required' => false,
            ])
            ->add('required', CheckboxType::class, [
                'label' => 'surveyQuestionRequiredFieldLabel',
                'required' => false,
            ])
            // « Ces réponses forment une échelle » - unchecked by default, deliberately: a list of
            // tools has an arbitrary order and an average of 1,7 would mean nothing on it. It is
            // the author who declares that their list is a scale (surveys.md §12.A).
            ->add('isScale', CheckboxType::class, [
                'label' => 'surveyQuestionIsScaleFieldLabel',
                'help' => 'surveyQuestionIsScaleFieldHelpText',
                'required' => false,
            ])
            ->add('minChoices', IntegerType::class, [
                'label' => 'surveyQuestionMinChoicesFieldLabel',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            ->add('maxChoices', IntegerType::class, [
                'label' => 'surveyQuestionMaxChoicesFieldLabel',
                'required' => false,
                'attr' => ['min' => 1],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SurveyQuestion::class]);
    }
}
