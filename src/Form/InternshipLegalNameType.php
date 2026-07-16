<?php

namespace App\Form;

use App\Entity\InternshipProgramInfo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The "Dénomination" tab's main field - InternshipProgramInfo's per-Option legal name overrides
// (InternshipOptionLegalName) are hand-rolled raw inputs in the same <form> in
// program/internship/_denomination_content.html.twig (see ProgramInternshipController::
// denominationTab()), same convention as the exam modality tab's per-Option overrides. No 'help'
// option here - the priority-rule explanation lives in a .cm-note callout instead (8a pattern).
class InternshipLegalNameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('legalName', TextType::class, [
                'label' => 'internshipProgramInfoLegalNameFieldLabel',
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
