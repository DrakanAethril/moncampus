<?php

namespace App\Form;

use App\Entity\QuizTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Positive;

// Screen 1n - identity fields plus the launch defaults that pre-fill the "Lancer" form (1c).
// Editing these only affects future launches - see QuizTemplate's class docblock.
class QuizTemplateSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'quizTemplateNameFieldLabel',
            ])
            ->add('subject', TextType::class, [
                'label' => 'quizTemplateSubjectFieldLabel',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'quizTemplateDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('defaultQuestionCount', IntegerType::class, [
                'label' => 'quizTemplateDefaultQuestionCountFieldLabel',
                'constraints' => [new Positive()],
            ])
            ->add('defaultSecondsPerQuestion', IntegerType::class, [
                'label' => 'quizTemplateDefaultSecondsPerQuestionFieldLabel',
                'constraints' => [new Positive()],
            ])
            ->add('defaultSameQuestionsForAll', CheckboxType::class, [
                'label' => 'quizTemplateDefaultSameQuestionsForAllFieldLabel',
                'required' => false,
            ])
            ->add('defaultQuestionOrderPerStudent', CheckboxType::class, [
                'label' => 'quizTemplateDefaultQuestionOrderPerStudentFieldLabel',
                'required' => false,
            ])
            ->add('defaultAnswerOrderPerStudent', CheckboxType::class, [
                'label' => 'quizTemplateDefaultAnswerOrderPerStudentFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuizTemplate::class]);
    }
}
