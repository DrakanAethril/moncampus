<?php

namespace App\Form;

use App\Entity\Evaluation;
use App\Enum\EvaluationModality;
use App\Enum\EvaluationNature;
use App\Enum\EvaluationStatus;
use App\Enum\EvaluationType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The evaluation edit/create form (design's Type/Modalité/Statut "cards", rendered here as plain
// expanded radio groups - a simplification, not a house pattern to reuse elsewhere; this app has
// no existing "card radio" component to build on and building one wasn't essential to the feature.
// $hasRubric is unmapped - it only decides whether ProgramGradebookController redirects into the
// rubric editor after saving, never persisted on Evaluation itself (Evaluation::hasRubric() is
// derived from whether any EvaluationRubricSection actually exists).
class EvaluationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'evaluationNameFieldLabel',
                'empty_data' => '',
            ])
            ->add('type', EnumType::class, [
                'class' => EvaluationType::class,
                'choice_label' => static fn (EvaluationType $type): string => $type->labelKey(),
                'expanded' => true,
                'label' => 'evaluationTypeFieldLabel',
            ])
            // Diagnostique/Formative/Sommative - the Progression pédagogique module's own axis
            // (design_handoff_progression README §1), optional here: an evaluation created
            // straight from the Carnet de notes doesn't have to belong to a progression, and
            // every row that predates the module has no nature at all.
            ->add('nature', EnumType::class, [
                'class' => EvaluationNature::class,
                'choice_label' => static fn (EvaluationNature $nature): string => $nature->labelKey(),
                'expanded' => true,
                'required' => false,
                'placeholder' => 'evaluationNatureNoneLabel',
                'label' => 'evaluationNatureFieldLabel',
            ])
            ->add('modality', EnumType::class, [
                'class' => EvaluationModality::class,
                'choice_label' => static fn (EvaluationModality $modality): string => $modality->labelKey(),
                'expanded' => true,
                'label' => 'evaluationModalityFieldLabel',
            ])
            ->add('status', EnumType::class, [
                'class' => EvaluationStatus::class,
                'choice_label' => static fn (EvaluationStatus $status): string => $status->labelKey(),
                'expanded' => true,
                'label' => 'evaluationStatusFieldLabel',
            ])
            ->add('date', DateType::class, [
                'label' => 'evaluationDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('scale', NumberType::class, [
                'label' => 'evaluationScaleFieldLabel',
                'help' => 'evaluationScaleFieldHelpText',
            ])
            ->add('coefficient', NumberType::class, [
                'label' => 'evaluationCoefficientFieldLabel',
            ])
            ->add('countsOutOf20', CheckboxType::class, [
                'label' => 'evaluationCountsOutOf20FieldLabel',
                'help' => 'evaluationCountsOutOf20FieldHelpText',
                'required' => false,
            ])
            ->add('hasScheduledVisibility', CheckboxType::class, [
                'label' => 'evaluationHasScheduledVisibilityFieldLabel',
                'mapped' => false,
                'required' => false,
                'data' => null !== $options['data']?->getVisibleAt(),
            ])
            ->add('visibleAt', DateTimeType::class, [
                'label' => 'evaluationVisibleAtFieldLabel',
                'help' => 'evaluationVisibleAtFieldHelpText',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('hasRubric', CheckboxType::class, [
                'label' => 'evaluationHasRubricFieldLabel',
                'help' => 'evaluationHasRubricFieldHelpText',
                'mapped' => false,
                'required' => false,
                'data' => $options['data']?->hasRubric() ?? false,
            ])
            // Pas de SubmitType : les créas donnent deux boutons d'envoi (« Créer » et « Créer et
            // saisir → »), écrits directement dans le gabarit et distingués côté contrôleur par le
            // nom du bouton cliqué.
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Evaluation::class]);
    }
}
