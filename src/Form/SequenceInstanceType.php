<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SequenceInstance;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Editing the CLASS's copy of a séquence - same fields and labels as SequenceTemplateType, for the
 * reason given on SeanceInstanceType.
 *
 * No `order` field: a template's rank is its place in the teacher's library, while a copy's place
 * in the year is decided by the progression that plans it (ProgressionSequence::$position), not
 * here.
 */
class SequenceInstanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'sequenceTemplateTitreFieldLabel',
            ])
            ->add('capacitesAttendues', TextareaType::class, [
                'label' => 'sequenceTemplateCapacitesAttenduesFieldLabel',
                'required' => false,
            ])
            ->add('preRequis', TextareaType::class, [
                'label' => 'sequenceTemplatePreRequisFieldLabel',
                'required' => false,
            ])
            ->add('objectifs', TextareaType::class, [
                'label' => 'sequenceTemplateObjectifsFieldLabel',
                'required' => false,
            ])
            ->add('transversalites', TextareaType::class, [
                'label' => 'sequenceTemplateTransversalitesFieldLabel',
                'required' => false,
            ])
            ->add('situationProblematique', TextareaType::class, [
                'label' => 'sequenceTemplateSituationProblematiqueFieldLabel',
                'required' => false,
            ])
            ->add('supportsGeneraux', TextareaType::class, [
                'label' => 'sequenceTemplateSupportsGenerauxFieldLabel',
                'required' => false,
            ])
            ->add('differentiation', TextareaType::class, [
                'label' => 'sequenceTemplateDifferentiationFieldLabel',
                'required' => false,
            ])
            ->add('watchPoints', TextareaType::class, [
                'label' => 'sequenceTemplateWatchPointsFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SequenceInstance::class]);
    }
}
