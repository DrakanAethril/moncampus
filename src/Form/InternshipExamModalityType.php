<?php

namespace App\Form;

use App\Entity\InternshipProgramInfo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The "Mod. Examen" tab's program-wide default text - InternshipProgramInfo's per-Option
// overrides (InternshipOptionExamModality) are a separate, hand-rolled form in
// program/internship/_exam_modalities_content.html.twig.
class InternshipExamModalityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('examModalityText', TextareaType::class, [
                'label' => 'internshipProgramInfoExamModalityFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipProgramInfo::class]);
    }
}
