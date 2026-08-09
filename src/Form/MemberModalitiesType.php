<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Modality;
use App\Entity\Program;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Not tied to a persisted entity (data_class stays the default array) - mirrors MemberOptionsType
// for Modality instead of Option, synced against ProgramStudentModality rows by the controller
// (see Program\SettingsMemberController::studentModalitiesForm()). Students only - modalities aren't
// currently assigned to teachers/referents.
class MemberModalitiesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('modalities', EntityType::class, [
                'class' => Modality::class,
                'choices' => $program->getModalities(),
                'choice_label' => static fn (Modality $modality): string => $modality->getShortName() ?? $modality->getName(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'memberModalitiesFieldLabel',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }
}
