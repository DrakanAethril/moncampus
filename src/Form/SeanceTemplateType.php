<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SeanceTemplate;
use App\Enum\EvaluationNature;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
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
            // Unmapped: the entity holds one nullable nature (null = no evaluation), so the
            // checkbox is purely the affordance that reveals the select. finishView() below ticks
            // it back on for an existing séance, and the controller nulls the nature when it is
            // left unchecked - which is what keeps "cochée sans nature" out of the database.
            ->add('hasEvaluation', CheckboxType::class, [
                'label' => 'seanceTemplateHasEvaluationFieldLabel',
                'required' => false,
                'mapped' => false,
            ])
            ->add('evaluationNature', EnumType::class, [
                'class' => EvaluationNature::class,
                'choice_label' => static fn (EvaluationNature $nature): string => $nature->labelKey(),
                'label' => 'seanceTemplateEvaluationNatureFieldLabel',
                'placeholder' => 'seanceTemplateEvaluationNaturePlaceholder',
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
            ->add('cahierDeTexteDescription', TextareaType::class, [
                'label' => 'seanceTemplateCahierDeTexteDescriptionFieldLabel',
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

    // Pre-ticks the checkbox when the séance being edited already carries a nature. Done in
    // finishView() rather than with a 'data' option because the option is resolved once at build
    // time, before the entity is bound - it would always read the empty new SeanceTemplate.
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $seanceTemplate = $form->getData();

        if ($seanceTemplate instanceof SeanceTemplate && $seanceTemplate->hasEvaluation()) {
            $view['hasEvaluation']->vars['checked'] = true;
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SeanceTemplate::class]);
    }
}
