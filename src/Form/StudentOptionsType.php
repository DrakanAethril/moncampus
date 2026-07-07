<?php

namespace App\Form;

use App\Entity\Option;
use App\Entity\Program;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Not tied to a persisted entity (data_class stays the default array) - this is a one-off
// selector over the program's own options, synced against ProgramStudentOption rows by the
// controller rather than mapped directly onto a single entity's field.
class StudentOptionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            ->add('options', EntityType::class, [
                'class' => Option::class,
                'choices' => $program->getOptions(),
                'choice_label' => 'shortName',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'studentOptionsFieldLabel',
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
