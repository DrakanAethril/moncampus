<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Skill;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The whole body of a competency's fiche in the training referential.
 *
 * Two fields it deliberately does NOT carry:
 *
 * - $order, which is set by dragging the row on the list
 *   (SettingsSkillGroupController::reorderSkills()) rather than typed into a form;
 * - $teacher, picked through an ajax tom-select embedded in the template and resolved in the
 *   controller, same convention as SkillGroupType's own teacher field.
 */
class SkillType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'skillLabelFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('code', TextType::class, [
                'label' => 'skillCodeFieldLabel',
                'help' => 'skillCodeFieldHelp',
                'required' => false,
            ])
            // NumberType (not IntegerType) so half-hours are accepted, 'html5' => false so a
            // native number input's step="1" doesn't reject them - same reasoning as TopicType.
            ->add('volumeHours', NumberType::class, [
                'label' => 'skillVolumeHoursFieldLabel',
                'scale' => 2,
                'html5' => false,
                'required' => false,
            ])
            ->add('teachingPeriodLabel', TextType::class, [
                'label' => 'skillTeachingPeriodFieldLabel',
                'help' => 'skillTeachingPeriodFieldHelp',
                'required' => false,
            ])
            ->add('occupationDescription', TextareaType::class, [
                'label' => 'skillOccupationDescriptionFieldLabel',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            // The three bullet lists, authored in HugeRTE (wired in the template).
            ->add('knowledgeHtml', TextareaType::class, [
                'label' => 'skillKnowledgeFieldLabel',
                'required' => false,
            ])
            ->add('activitiesHtml', TextareaType::class, [
                'label' => 'skillActivitiesFieldLabel',
                'required' => false,
            ])
            ->add('performanceCriteriaHtml', TextareaType::class, [
                'label' => 'skillPerformanceCriteriaFieldLabel',
                'required' => false,
            ])
            // Plain inputs, not rich text: in the referential these three are one short sentence
            // each, identical across every fiche. The columns still hold HTML, so an admin who
            // ever needs more than a sentence is not blocked by the storage.
            ->add('diagnosticAssessmentHtml', TextType::class, [
                'label' => 'skillDiagnosticAssessmentFieldLabel',
                'required' => false,
            ])
            ->add('summativeAssessmentHtml', TextType::class, [
                'label' => 'skillSummativeAssessmentFieldLabel',
                'required' => false,
            ])
            ->add('certifyingAssessmentHtml', TextType::class, [
                'label' => 'skillCertifyingAssessmentFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Skill::class]);
    }
}
