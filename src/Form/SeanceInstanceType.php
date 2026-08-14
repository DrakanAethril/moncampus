<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SeanceInstance;
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

/**
 * Editing the CLASS's copy of a séance, not the library template it came from.
 *
 * Deliberately the same fields and the same labels as SeanceTemplateType: a teacher rewriting what
 * this class is actually taught is filling in the same séance, and giving the copy a different
 * vocabulary from the model would only suggest the two are different things. What tells them apart
 * is the banner the edit screen carries, not the form.
 *
 * The template's isOptional/optionalNote have no counterpart here, and that is the entity's own
 * doing: "cette séance est optionnelle" is a statement about a model that several classes will
 * instantiate differently. Once copied for one class, the séance is either in its progression or
 * removed from it.
 */
class SeanceInstanceType extends AbstractType
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
            // Unmapped, exactly as on the template form: the entity holds one nullable nature, and
            // the checkbox is only the affordance that reveals the select.
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
            ->add('materials', TextareaType::class, [
                'label' => 'seanceTemplateMaterialsFieldLabel',
                'required' => false,
            ])
            ->add('watchPoints', TextareaType::class, [
                'label' => 'seanceTemplateWatchPointsFieldLabel',
                'required' => false,
            ])
            ->add('cahierDeTexteDescription', TextareaType::class, [
                'label' => 'seanceTemplateCahierDeTexteDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    // Same reasoning as SeanceTemplateType::finishView(): a 'data' option is resolved before the
    // entity is bound, so it would always read an empty séance.
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $seanceInstance = $form->getData();

        if ($seanceInstance instanceof SeanceInstance && null !== $seanceInstance->getEvaluationNature()) {
            $view['hasEvaluation']->vars['checked'] = true;
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SeanceInstance::class]);
    }
}
