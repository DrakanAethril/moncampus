<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\QuizTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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
            // Two answers, not one number: unlimited is a real setting here, and null seconds is
            // how it is stored. The radio is unmapped - it only decides whether the count below is
            // read at all, and the PRE_SUBMIT listener is what turns "illimité" into a null.
            ->add('defaultTimeMode', ChoiceType::class, [
                'label' => 'quizTemplateDefaultTimeModeFieldLabel',
                'mapped' => false,
                'expanded' => true,
                'choices' => [
                    'quizTimeModeFixedLabel' => 'fixed',
                    'quizTimeModeUnlimitedLabel' => 'unlimited',
                ],
            ])
            ->add('defaultSecondsPerQuestion', IntegerType::class, [
                'label' => 'quizTemplateDefaultSecondsPerQuestionFieldLabel',
                'required' => false,
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
        ;

        // POST_SET_DATA, not PRE_: the child fields only exist once the parent has been populated,
        // and an unmapped radio has no entity property to read itself from.
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $template = $event->getData();
            $unlimited = $template instanceof QuizTemplate && null === $template->getDefaultSecondsPerQuestion();
            $event->getForm()->get('defaultTimeMode')->setData($unlimited ? 'unlimited' : 'fixed');
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (\is_array($data) && 'unlimited' === ($data['defaultTimeMode'] ?? null)) {
                // Blanked rather than left as submitted: the count stays visible in the DOM while
                // the radio is on "illimité", and a leftover value would silently reinstate a limit.
                $data['defaultSecondsPerQuestion'] = '';
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuizTemplate::class]);
    }
}
