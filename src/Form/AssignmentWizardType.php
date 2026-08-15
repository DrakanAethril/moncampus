<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Assignment;
use App\Entity\Evaluation;
use App\Entity\GroupBatch;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Enum\QuizMode;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The four-step « Nouveau travail » wizard (design_handoff_creation_travail 2a):
 * Recipients → Type → Instructions → Deadline.
 *
 * A single form, not four: nothing is saved before « Publier le travail », so there is neither a
 * draft to save between two steps nor state to glue back together on the server side. The four steps
 * are permanently present in the DOM and it is assignment_wizard_controller.js that shows only one
 * of them - the only submission to the server is the publication.
 *
 * Corollary: every class-dependent choice (options, group batches, quizzes, evaluations) is loaded
 * for ALL the candidate classes, each carrying its class as an attribute, and the Stimulus
 * controller only displays those of the chosen class. The server rechecks the membership on
 * publication - see AssignmentController::publish().
 */
class AssignmentWizardType extends AbstractType
{
    public const VISIBILITY_NOW = 'now';
    public const VISIBILITY_SCHEDULED = 'scheduled';
    public const VISIBILITY_HIDDEN = 'hidden';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ?Assignment $assignment */
        $assignment = $options['data'] ?? null;

        /** @var list<Program> $programs */
        $programs = $options['programs'];
        $programIds = array_map(static fn (Program $program): int => (int) $program->getId(), $programs);

        $builder
            // Étape 1 - Destinataires.
            ->add('program', EntityType::class, [
                'class' => Program::class,
                'choices' => $programs,
                'choice_label' => static fn (Program $program): string => $program->getDisplayShortName(),
                'label' => 'assignmentWizardClassFieldLabel',
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
                'required' => true,
            ])
            ->add('audienceType', EnumType::class, [
                'class' => AssignmentAudienceType::class,
                'choices' => AssignmentAudienceType::forWizard(),
                'choice_label' => static fn (AssignmentAudienceType $type): string => $type->labelKey(),
                'label' => 'assignmentWizardAudienceFieldLabel',
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('options', EntityType::class, [
                'class' => Option::class,
                'choices' => $this->optionsOf($programs),
                'choice_label' => static fn (Option $option): string => $option->getName(),
                // An option may serve several programs: the chip carries the list of classes it
                // applies to, and not a single class.
                'choice_attr' => static fn (Option $option): array => ['data-programs' => implode(' ', array_map(
                    static fn (Program $program): string => (string) $program->getId(),
                    $option->getPrograms()->toArray(),
                ))],
                'label' => 'assignmentWizardOptionsFieldLabel',
                'expanded' => true,
                'multiple' => true,
                'required' => false,
            ])
            ->add('groupBatch', EntityType::class, [
                'class' => GroupBatch::class,
                'query_builder' => static fn (\Doctrine\ORM\EntityRepository $repository) => $repository->createQueryBuilder('b')
                    ->where('b.program IN (:programs)')
                    ->andWhere('b.teacher = :teacher')
                    ->setParameter('programs', $programIds ?: [0])
                    ->setParameter('teacher', $options['teacher'])
                    ->orderBy('b.createdAt', 'DESC'),
                'choice_label' => static fn (GroupBatch $batch): string => $batch->getName(),
                'choice_attr' => static fn (GroupBatch $batch): array => [
                    'data-programs' => (string) $batch->getProgram()->getId(),
                    'data-groups' => (string) \count($batch->getGroups()),
                ],
                'label' => 'assignmentWizardGroupBatchFieldLabel',
                'placeholder' => 'assignmentWizardGroupBatchPlaceholder',
                'required' => false,
            ])

            // Step 2 - Work type.
            ->add('nature', EnumType::class, [
                'class' => AssignmentNature::class,
                'choices' => $options['natures'],
                'choice_label' => static fn (AssignmentNature $nature): string => $nature->labelKey(),
                'label' => 'assignmentWizardNatureFieldLabel',
                'expanded' => true,
                'placeholder' => false,
            ])
            // Two chips rather than a checkbox: the mockup lays « Obligatoire » and « Facultatif »
            // side by side, one not being the absence of the other to the reader.
            ->add('mandatory', ChoiceType::class, [
                'label' => 'assignmentWizardMandatoryFieldLabel',
                'choices' => [
                    'assignmentWizardMandatoryLabel' => true,
                    'assignmentWizardOptionalLabel' => false,
                ],
                'choice_value' => static fn (?bool $value): string => null === $value ? '' : ($value ? '1' : '0'),
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('graded', ChoiceType::class, [
                'label' => 'assignmentWizardGradedFieldLabel',
                'choices' => [
                    'assignmentWizardGradedLabel' => true,
                    'assignmentWizardNotGradedLabel' => false,
                ],
                'choice_value' => static fn (?bool $value): string => null === $value ? '' : ($value ? '1' : '0'),
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('gradingVisibleToStudents', CheckboxType::class, [
                'label' => 'assignmentWizardGradingVisibleFieldLabel',
                'required' => false,
            ])

            // Étape 3 - Consigne.
            ->add('title', TextType::class, [
                'label' => 'assignmentWizardTitleFieldLabel',
                'attr' => ['placeholder' => 'assignmentWizardTitlePlaceholder'],
                'empty_data' => '',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'assignmentWizardDescriptionFieldLabel',
                'required' => false,
            ])
            // The supports are not data of the assignment as long as it does not exist: they travel
            // with the form and are only uploaded on publication.
            ->add('attachmentFiles', FileType::class, [
                'label' => 'assignmentWizardAttachmentsFieldLabel',
                'mapped' => false,
                'multiple' => true,
                'required' => false,
                'constraints' => [new Assert\All([new Assert\File(maxSize: '20M', maxSizeMessage: 'assignmentWizardAttachmentTooLargeMessage')])],
            ])
            // The pasted links, one per line: the field stays hidden, it is the template's chips
            // that write it.
            ->add('attachmentLinks', TextareaType::class, [
                'label' => false,
                'mapped' => false,
                'required' => false,
            ])
            ->add('expectedProductions', CollectionType::class, [
                'entry_type' => AssignmentExpectedProductionType::class,
                'label' => 'assignmentWizardProductionsFieldLabel',
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype_name' => '__production__',
                'required' => false,
            ])
            ->add('quizInstance', EntityType::class, [
                'class' => QuizInstance::class,
                'query_builder' => static fn (\Doctrine\ORM\EntityRepository $repository) => $repository->createQueryBuilder('q')
                    ->where('q.program IN (:programs)')
                    // A live contest is run together, at the appointed time: it is not given out to
                    // do for next time.
                    ->andWhere('q.mode != :live')
                    ->setParameter('programs', $programIds ?: [0])
                    ->setParameter('live', QuizMode::Live)
                    ->orderBy('q.creationDate', 'DESC'),
                'choice_label' => static fn (QuizInstance $instance): string => $instance->getName(),
                'choice_attr' => static fn (QuizInstance $instance): array => ['data-programs' => (string) $instance->getProgram()?->getId()],
                'label' => 'assignmentWizardQuizFieldLabel',
                'placeholder' => 'assignmentWizardQuizPlaceholder',
                'required' => false,
            ])
            // The share of correct answers the quiz must reach to count as done. Left empty,
            // concluding the quiz is enough.
            //
            // Deliberately not html5: the mockup writes "70,0 %", and a type=number field would
            // refuse the decimal comma a French keyboard produces. A text field lets Symfony's own
            // localized number parsing read it, and Assert\Range still bounds it to 0-100.
            ->add('minimumScorePercent', NumberType::class, [
                'label' => 'assignmentWizardMinimumScoreFieldLabel',
                'scale' => 1,
                'html5' => false,
                'required' => false,
                'attr' => ['inputmode' => 'decimal', 'placeholder' => '70,0'],
            ])
            ->add('evaluation', EntityType::class, [
                'class' => Evaluation::class,
                'query_builder' => static function (\Doctrine\ORM\EntityRepository $repository) use ($programIds, $options) {
                    $builder = $repository->createQueryBuilder('e')
                        ->innerJoin('e.topic', 't')
                        ->addSelect('t')
                        ->where('t.program IN (:programs)')
                        ->andWhere('e.inactiveDate IS NULL')
                        ->andWhere('t.inactiveDate IS NULL')
                        ->setParameter('programs', $programIds ?: [0])
                        ->orderBy('e.date', 'DESC');

                    // Outside staff, only the evaluations of the teacher's own matières: the
                    // comparison made by the student is made against THEIR grading.
                    if (null !== $options['teacher_topics_only']) {
                        $builder->andWhere('t.teacher = :teacher')->setParameter('teacher', $options['teacher_topics_only']);
                    }

                    return $builder;
                },
                'choice_label' => static fn (Evaluation $evaluation): string => sprintf(
                    '%s — %s · %s',
                    $evaluation->getName(),
                    $evaluation->getTopic()?->getName() ?? '',
                    $evaluation->getDate()?->format('d/m/Y') ?? '',
                ),
                'choice_attr' => static fn (Evaluation $evaluation): array => ['data-programs' => (string) $evaluation->getTopic()?->getProgram()?->getId()],
                'label' => 'assignmentWizardEvaluationFieldLabel',
                'placeholder' => 'assignmentWizardEvaluationPlaceholder',
                'required' => false,
            ])
            ->add('readTrackingEnabled', CheckboxType::class, [
                'label' => 'assignmentWizardReadTrackingFieldLabel',
                'required' => false,
            ])

            // Étape 4 - Échéance.
            ->add('dueDate', DateTimeType::class, [
                'label' => 'assignmentWizardDueDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('lateSubmissionAllowed', CheckboxType::class, [
                'label' => 'assignmentWizardLateSubmissionFieldLabel',
                'required' => false,
            ])
            // Unmapped: the entity carries a date of coming into visibility, the screen three cards.
            // The controller does the translation, the only place that knows « now ».
            ->add('visibility', ChoiceType::class, [
                'label' => 'assignmentWizardVisibilityFieldLabel',
                'mapped' => false,
                'choices' => [
                    'assignmentWizardVisibilityNowLabel' => self::VISIBILITY_NOW,
                    'assignmentWizardVisibilityScheduledLabel' => self::VISIBILITY_SCHEDULED,
                    'assignmentWizardVisibilityHiddenLabel' => self::VISIBILITY_HIDDEN,
                ],
                'expanded' => true,
                'placeholder' => false,
                'data' => $options['visibility'],
            ])
            ->add('visibleAt', DateTimeType::class, [
                'label' => 'assignmentWizardVisibleAtFieldLabel',
                'mapped' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
                'data' => $assignment?->getVisibleAt(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Assignment::class]);
        $resolver->setRequired('programs');
        $resolver->setAllowedTypes('programs', 'array');
        $resolver->setRequired('teacher');
        $resolver->setAllowedTypes('teacher', User::class);
        // The teacher whose matières alone are offered in the evaluation choice; null for staff, who
        // see every matière of the program.
        $resolver->setDefault('teacher_topics_only', null);
        $resolver->setAllowedTypes('teacher_topics_only', [User::class, 'null']);
        // The visibility card ticked on opening: « visible dès l'enregistrement » for a new
        // assignment, the assignment's real state when it is reopened. The controller computes it,
        // the only place that knows « now ».
        $resolver->setDefault('visibility', self::VISIBILITY_NOW);
        $resolver->setAllowedValues('visibility', [self::VISIBILITY_NOW, self::VISIBILITY_SCHEDULED, self::VISIBILITY_HIDDEN]);
        // The natures on offer. The mockup's grid in the general case; an assignment born of an audio
        // recording has only one - listening - and the grid shrinks to that single card rather than
        // offering a choice that is not one.
        $resolver->setDefault('natures', AssignmentNature::forLessonLog());
        $resolver->setAllowedTypes('natures', 'array');
    }

    /**
     * @param list<Program> $programs
     *
     * @return list<Option>
     */
    private function optionsOf(array $programs): array
    {
        $options = [];

        foreach ($programs as $program) {
            foreach ($program->getOptions() as $option) {
                $options[$option->getId()] = $option;
            }
        }

        return array_values($options);
    }
}
