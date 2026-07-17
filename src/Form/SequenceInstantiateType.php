<?php

namespace App\Form;

use App\Entity\Program;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Not entity-backed - picks the target Program to instantiate a SequenceTemplate/SeanceTemplate
// against. The controller builds the actual SequenceInstance/SeanceInstance itself.
class SequenceInstantiateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('program', EntityType::class, [
                'class' => Program::class,
                'choices' => $options['programs'],
                'choice_label' => static fn (Program $program): string => sprintf('%s - %s', $program->getDisplayShortName(), $program->getSchoolYear()->getStartDate()?->format('Y') ?? '?'),
                'label' => 'sequenceInstantiateProgramFieldLabel',
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'sequenceInstantiateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('programs')
            ->setAllowedTypes('programs', 'array')
        ;
    }
}
