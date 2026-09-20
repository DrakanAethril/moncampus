<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\QuizTemplate;
use App\Enum\QuizMode;
use App\Enum\QuizScoring;
use App\Enum\QuizSupervisionPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

// Screen 1c - not entity-backed (mirrors SequenceInstantiateType): the controller reads this data
// and builds the QuizInstance itself via App\Service\QuizInstantiationService, which is also where
// the difficulty slider position gets turned into actual per-level question counts (never trust
// the client's own recap numbers - see App\Service\QuizDifficultyDistributionResolver).
//
// 'mode' choices are deliberately restricted to Entrainement/Evaluation. App\Enum\QuizMode::Live
// exists and the concours-à-plusieurs is built, but a live session is not something this form can
// produce: it needs a room, a host and a projector, and half the fields here (opening window,
// retake policy, scoring) are meaningless for it. It is created from Outils > Concours live
// instead - App\Controller\QuizLiveHostController - so Live must never become a choice here.
class QuizLaunchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Screen 1c reached without a quiz (« Lancer un quiz » on Quiz par classes): the base
        // quiz is the first field of the form instead of the page's own subject. Added first so it
        // reads as step one, and absent altogether from the library's own launch screen, where the
        // quiz is what the page is about - a select there could only disagree with the URL.
        if (null !== $options['baseTemplateChoices']) {
            $builder->add('template', EntityType::class, [
                'class' => QuizTemplate::class,
                'choices' => $options['baseTemplateChoices'],
                'choice_label' => static fn (QuizTemplate $template): string => $template->getName() ?? '',
                'group_by' => static fn (QuizTemplate $template): ?string => $template->getFolder()?->getName(),
                'label' => 'quizLaunchTemplateFieldLabel',
                'placeholder' => 'quizLaunchAdditionalTemplatePlaceholder',
                // The select is not what the teacher reads any more - it sits behind the picker
                // modal (templates/_library_picker.html.twig), hidden. `required` would then be an
                // HTML5 rule on a control the browser cannot focus, which blocks the submit while
                // showing nothing at all; the rule is a constraint instead, so an empty choice is
                // answered by the form itself.
                'required' => false,
                'constraints' => [new NotNull(message: 'quizLaunchTemplateRequiredError')],
            ]);
        }

        $builder
            // Optional: the instance is named after the base template when left blank
            // (App\Service\QuizInstantiationService). A launch merging five séance quizzes is
            // exactly what this is for - "Évaluation de fin de séquence" says more than "Quiz 1".
            ->add('name', TextType::class, [
                'label' => 'quizLaunchNameFieldLabel',
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'attr' => ['placeholder' => $options['baseTemplateName'], 'maxlength' => 255],
            ])
            // The extra templates whose questions join the pool, on top of the one being launched.
            // A collection of single selects rather than one multi-select: the rows carry an order
            // (which the live concours plays literally) and "ajouter un quiz" is a repeated action,
            // not a set to tick - the checkbox-group convention is for genuine option sets.
            ->add('additionalTemplates', CollectionType::class, [
                'entry_type' => EntityType::class,
                'entry_options' => [
                    'class' => QuizTemplate::class,
                    'choices' => $options['additionalTemplateChoices'],
                    'choice_label' => static fn (QuizTemplate $template): string => $template->getName() ?? '',
                    'label' => false,
                    'placeholder' => 'quizLaunchAdditionalTemplatePlaceholder',
                ],
                'label' => false,
                'allow_add' => true,
                'allow_delete' => true,
                'prototype_name' => '__quiz__',
                'required' => false,
            ])
            // `data` is how the class arrives already picked when the screen is opened from a
            // filtered « Quiz par classes » - null everywhere else, which is the placeholder.
            ->add('program', EntityType::class, [
                'class' => Program::class,
                'choices' => $options['programs'],
                'data' => $options['defaultProgram'],
                'choice_label' => static fn (Program $program): string => sprintf('%s - %s', $program->getDisplayShortName(), $program->getSchoolYear()->getStartDate()?->format('Y') ?? '?'),
                'label' => 'quizLaunchProgramFieldLabel',
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            // « Tous les étudiants » is the placeholder rather than a first choice, because it is
            // what null means here - the narrowing is optional and the field carries no default.
            //
            // The list holds every option of every class on offer, not those of the class currently
            // picked: the program is chosen in the same form, so which options are valid changes
            // while the screen is open. quiz_launch_option_controller.js hides the ones that do not
            // belong to the selection (and clears it when the class changes), exactly as the parent
            // picker on Paramètres > Groupes does; the rule itself is enforced below on submit and
            // again in App\Service\QuizInstantiationService, never by the filtering.
            ->add('visibilityOption', EntityType::class, [
                'class' => Option::class,
                'choices' => $options['optionChoices'],
                'choice_label' => 'shortName',
                'choice_attr' => static fn (Option $option): array => ['data-programs' => implode(' ', array_map(
                    static fn (Program $program): string => (string) $program->getId(),
                    $option->getPrograms()->toArray(),
                ))],
                'label' => 'quizLaunchVisibilityOptionFieldLabel',
                'placeholder' => 'quizLaunchVisibilityOptionAllLabel',
                'required' => false,
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
            // Both switches carry `data`, and they have to: this form is built on null (there is
            // no instance yet), so without it a checkbox opens unticked whatever the entity's own
            // default says - and the two would then disagree with the edit form, which is bound to
            // the instance and does show them on.
            ->add('scoreVisibleImmediately', CheckboxType::class, [
                'label' => 'quizLaunchScoreVisibleImmediatelyFieldLabel',
                'required' => false,
                'data' => true,
            ])
            ->add('correctionVisible', CheckboxType::class, [
                'label' => 'quizLaunchCorrectionVisibleFieldLabel',
                'help' => 'quizLaunchCorrectionVisibleFieldHelp',
                'required' => false,
                'data' => true,
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
            // Mode contrôle - rendered only under Évaluation (the template hides the whole block,
            // quiz_supervision_launch_controller.js does the toggling), and forced back to false
            // server-side by the launch controller when the mode is Entraînement. Hidden is not the
            // same as off, and only the second one is a rule.
            ->add('supervised', CheckboxType::class, [
                'label' => 'quizLaunchSupervisedFieldLabel',
                'required' => false,
            ])
            ->add('supervisionPolicy', EnumType::class, [
                'class' => QuizSupervisionPolicy::class,
                'choice_label' => static fn (QuizSupervisionPolicy $policy): string => $policy->labelKey(),
                'expanded' => true,
                'label' => 'quizLaunchSupervisionPolicyFieldLabel',
                'data' => QuizSupervisionPolicy::Warn,
            ])
            ->add('supervisionExitSeconds', IntegerType::class, [
                'label' => 'quizLaunchSupervisionExitSecondsFieldLabel',
                'required' => false,
                'constraints' => [new Range(min: 1, max: 300)],
                'data' => 8,
            ])
            ->add('supervisionSubmitAt', IntegerType::class, [
                'label' => 'quizLaunchSupervisionSubmitAtFieldLabel',
                'required' => false,
                // Never fewer than three: a copy handed in on one stray click would be the very
                // automatic sanction the design refuses.
                'constraints' => [new Range(min: 3, max: 50)],
                'data' => 5,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'quizLaunchSubmitAction',
            ])
        ;

        // The client-side filtering is an assistance, not the rule: an option belonging to another
        // class would address nobody at all, which is a great deal worse than an error message.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            $program = $form->get('program')->getData();
            $option = $form->get('visibilityOption')->getData();

            if ($program instanceof Program && $option instanceof Option && !$program->getOptions()->contains($option)) {
                $form->get('visibilityOption')->addError(new FormError('quizLaunchVisibilityOptionForeignError'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['programs', 'optionChoices', 'baseTemplateName', 'additionalTemplateChoices', 'defaultQuestionCount', 'defaultSecondsPerQuestion', 'defaultSameQuestionsForAll', 'defaultQuestionOrderPerStudent', 'defaultAnswerOrderPerStudent'])
            // Null means « the quiz is already known »: the field is not rendered at all, rather
            // than rendered and locked.
            ->setDefault('baseTemplateChoices', null)
            ->setAllowedTypes('baseTemplateChoices', ['array', 'null'])
            ->setDefault('defaultProgram', null)
            ->setAllowedTypes('defaultProgram', [Program::class, 'null'])
            ->setAllowedTypes('programs', 'array')
            ->setAllowedTypes('optionChoices', 'array')
            ->setAllowedTypes('baseTemplateName', ['string', 'null'])
            ->setAllowedTypes('additionalTemplateChoices', 'array')
            ->setAllowedTypes('defaultQuestionCount', 'int')
            // Null since the quiz itself can be untimed - the launch form then opens blank,
            // which is already how it spells "pas de limite" (the field is not required).
            ->setAllowedTypes('defaultSecondsPerQuestion', ['int', 'null'])
            ->setAllowedTypes('defaultSameQuestionsForAll', 'bool')
            ->setAllowedTypes('defaultQuestionOrderPerStudent', 'bool')
            ->setAllowedTypes('defaultAnswerOrderPerStudent', 'bool')
        ;
    }
}
