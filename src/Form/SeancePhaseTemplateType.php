<?php

namespace App\Form;

use App\Entity\SeancePhaseTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SeancePhaseTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ordre', NumberType::class, [
                'label' => 'seancePhaseTemplateOrdreFieldLabel',
                'html5' => false,
            ])
            ->add('nom', TextType::class, [
                'label' => 'seancePhaseTemplateNomFieldLabel',
            ])
            ->add('duree', NumberType::class, [
                'label' => 'seancePhaseTemplateDureeFieldLabel',
                'html5' => false,
                'required' => false,
            ])
            ->add('contenu', TextareaType::class, [
                'label' => 'seancePhaseTemplateContenuFieldLabel',
                'required' => false,
            ])
            ->add('objectifs', TextareaType::class, [
                'label' => 'seancePhaseTemplateObjectifsFieldLabel',
                'required' => false,
            ])
            ->add('enseignant', TextareaType::class, [
                'label' => 'seancePhaseTemplateEnseignantFieldLabel',
                'required' => false,
            ])
            ->add('etudiant', TextareaType::class, [
                'label' => 'seancePhaseTemplateEtudiantFieldLabel',
                'required' => false,
            ])
            ->add('moyensSupports', TextareaType::class, [
                'label' => 'seancePhaseTemplateMoyensSupportsFieldLabel',
                'required' => false,
            ])
            ->add('difficultes', TextareaType::class, [
                'label' => 'seancePhaseTemplateDifficultesFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SeancePhaseTemplate::class]);
    }
}
