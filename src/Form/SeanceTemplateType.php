<?php

namespace App\Form;

use App\Entity\SeanceTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SeanceTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ordre', NumberType::class, [
                'label' => 'seanceTemplateOrdreFieldLabel',
                'html5' => false,
            ])
            ->add('titre', TextType::class, [
                'label' => 'seanceTemplateTitreFieldLabel',
            ])
            ->add('duree', NumberType::class, [
                'label' => 'seanceTemplateDureeFieldLabel',
                'html5' => false,
                'required' => false,
            ])
            ->add('objectifs', TextareaType::class, [
                'label' => 'seanceTemplateObjectifsFieldLabel',
                'required' => false,
            ])
            ->add('avantDescription', TextareaType::class, [
                'label' => 'seanceTemplateAvantDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('apresDescription', TextareaType::class, [
                'label' => 'seanceTemplateApresDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('isOptional', CheckboxType::class, [
                'label' => 'seanceTemplateIsOptionalFieldLabel',
                'required' => false,
            ])
            ->add('optionalNote', TextareaType::class, [
                'label' => 'seanceTemplateOptionalNoteFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SeanceTemplate::class]);
    }
}
