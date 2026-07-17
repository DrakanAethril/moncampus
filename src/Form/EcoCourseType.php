<?php

namespace App\Form;

use App\Entity\EcoCourse;
use App\Enum\EcoCourseMode;
use App\Enum\EcoMapVisibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

// Screen 1g's "Nouvelle course" panel - reused unchanged for editing a Prepared course (the code
// itself is generated server-side, App\Service\EcoCourseCodeGenerator, never edited here).
class EcoCourseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'ecoCourseNameFieldLabel',
                'constraints' => [new NotBlank()],
            ])
            ->add('mode', EnumType::class, [
                'class' => EcoCourseMode::class,
                'choice_label' => static fn (EcoCourseMode $mode): string => $mode->labelKey(),
                'expanded' => true,
                'label' => 'ecoCourseModeFieldLabel',
            ])
            ->add('mapVisibility', EnumType::class, [
                'class' => EcoMapVisibility::class,
                'choice_label' => static fn (EcoMapVisibility $visibility): string => $visibility->labelKey(),
                'label' => 'ecoCourseMapVisibilityFieldLabel',
            ])
            ->add('teamsEnabled', CheckboxType::class, [
                'label' => 'ecoCourseTeamsEnabledFieldLabel',
                'required' => false,
            ])
            ->add('safetyAlertsEnabled', CheckboxType::class, [
                'label' => 'ecoCourseSafetyAlertsEnabledFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'ecoCourseCreateSubmitLabel',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EcoCourse::class]);
    }
}
