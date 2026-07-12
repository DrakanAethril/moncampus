<?php

namespace App\Form;

use App\Entity\SequenceTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// niveau/option/blocs are deliberately NOT form fields here: they're free-text tags
// (App\Entity\AbstractLibraryTag), rendered as raw <select> elements in library/sequence_new.html.twig
// (Tom Select, create-or-reuse) and resolved/persisted manually by
// App\Controller\SequenceLibraryController::form() via App\Service\LibraryTagResolver - same
// reasoning as AssignmentType's manualRecipients field.
class SequenceTemplateType extends AbstractType
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
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SequenceTemplate::class]);
    }
}
