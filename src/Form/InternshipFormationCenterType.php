<?php

namespace App\Form;

use App\Entity\InternshipFormationCenter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// One flat form for the InternshipFormationCenter singleton - the template groups the fields
// visually into "Directeur"/"Directrice du Campus"/etc. cards, but the form itself has no
// nested sub-forms since the entity has no embeddable value objects (see the entity's docblock).
class InternshipFormationCenterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('generalInfo', TextareaType::class, [
                'label' => 'internshipFormationCenterGeneralInfoFieldLabel',
                'required' => false,
            ])
            ->add('directorFirstName', TextType::class, ['label' => 'internshipFormationCenterFirstNameFieldLabel', 'required' => false])
            ->add('directorLastName', TextType::class, ['label' => 'internshipFormationCenterLastNameFieldLabel', 'required' => false])
            ->add('directorEmail', TextType::class, ['label' => 'internshipFormationCenterEmailFieldLabel', 'required' => false])
            ->add('directorPhone', TelType::class, ['label' => 'internshipFormationCenterPhoneFieldLabel', 'required' => false])
            ->add('campusDirectorFirstName', TextType::class, ['label' => 'internshipFormationCenterFirstNameFieldLabel', 'required' => false])
            ->add('campusDirectorLastName', TextType::class, ['label' => 'internshipFormationCenterLastNameFieldLabel', 'required' => false])
            ->add('campusDirectorEmail', TextType::class, ['label' => 'internshipFormationCenterEmailFieldLabel', 'required' => false])
            ->add('campusDirectorPhone', TelType::class, ['label' => 'internshipFormationCenterPhoneFieldLabel', 'required' => false])
            ->add('alternanceManagerFirstName', TextType::class, ['label' => 'internshipFormationCenterFirstNameFieldLabel', 'required' => false])
            ->add('alternanceManagerLastName', TextType::class, ['label' => 'internshipFormationCenterLastNameFieldLabel', 'required' => false])
            ->add('alternanceManagerEmail', TextType::class, ['label' => 'internshipFormationCenterEmailFieldLabel', 'required' => false])
            ->add('alternanceManagerPhone', TelType::class, ['label' => 'internshipFormationCenterPhoneFieldLabel', 'required' => false])
            ->add('handicapReferentFirstName', TextType::class, ['label' => 'internshipFormationCenterFirstNameFieldLabel', 'required' => false])
            ->add('handicapReferentLastName', TextType::class, ['label' => 'internshipFormationCenterLastNameFieldLabel', 'required' => false])
            ->add('handicapReferentEmail', TextType::class, ['label' => 'internshipFormationCenterEmailFieldLabel', 'required' => false])
            ->add('handicapReferentPhone', TelType::class, ['label' => 'internshipFormationCenterPhoneFieldLabel', 'required' => false])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipFormationCenter::class]);
    }
}
