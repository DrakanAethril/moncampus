<?php

namespace App\Form;

use App\Entity\LessonLog;
use App\Enum\LessonLogVisibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LessonLogType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contenuRealise', TextareaType::class, [
                'label' => 'lessonLogContenuRealiseFieldLabel',
                'required' => false,
            ])
            ->add('travailAvantDescription', TextareaType::class, [
                'label' => 'lessonLogTravailAvantFieldLabel',
                'required' => false,
            ])
            ->add('travailApresDescription', TextareaType::class, [
                'label' => 'lessonLogTravailApresFieldLabel',
                'required' => false,
            ])
            // Visibilité du temps « avant » : dans le formulaire principal, donc enregistrée par le
            // bouton Enregistrer comme le reste. Le gabarit la rend en menu déroulant, pas en select.
            ->add('visibilityBefore', EnumType::class, [
                'class' => LessonLogVisibility::class,
                'choice_label' => static fn (LessonLogVisibility $v): string => $v->labelKey(),
                'label' => false,
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('visibleAtBefore', DateTimeType::class, [
                'label' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            // Visibilité du temps « pendant » : dans le formulaire principal, donc enregistrée par le
            // bouton Enregistrer comme le reste. Le gabarit la rend en menu déroulant, pas en select.
            ->add('visibilityDuring', EnumType::class, [
                'class' => LessonLogVisibility::class,
                'choice_label' => static fn (LessonLogVisibility $v): string => $v->labelKey(),
                'label' => false,
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('visibleAtDuring', DateTimeType::class, [
                'label' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            // Visibilité du temps « après » : dans le formulaire principal, donc enregistrée par le
            // bouton Enregistrer comme le reste. Le gabarit la rend en menu déroulant, pas en select.
            ->add('visibilityAfter', EnumType::class, [
                'class' => LessonLogVisibility::class,
                'choice_label' => static fn (LessonLogVisibility $v): string => $v->labelKey(),
                'label' => false,
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('visibleAtAfter', DateTimeType::class, [
                'label' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LessonLog::class]);
    }
}
