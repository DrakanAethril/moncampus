<?php

declare(strict_types=1);

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
        // Classes that already hold a copy stay in the list, disabled and labelled as such, rather
        // than being removed from it: a choice that is absent still validates as "invalid choice",
        // so a stale form submitted after somebody else instantiated the same pair would answer with
        // Symfony's generic message instead of the real reason. Keeping them as choices lets the
        // controller's own database check be the one that speaks.
        $unavailableIds = $options['unavailable_program_ids'];
        $unavailableSuffix = $options['unavailable_suffix'];

        $builder
            ->add('program', EntityType::class, [
                'class' => Program::class,
                'choices' => $options['programs'],
                'choice_label' => static fn (Program $program): string => sprintf(
                    '%s - %s%s',
                    $program->getDisplayShortName(),
                    $program->getSchoolYear()->getStartDate()?->format('Y') ?? '?',
                    \in_array($program->getId(), $unavailableIds, true) ? $unavailableSuffix : '',
                ),
                'choice_attr' => static fn (Program $program): array => \in_array($program->getId(), $unavailableIds, true) ? ['disabled' => 'disabled'] : [],
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
            // Empty for the standalone-séance form, which has no once-per-class rule.
            ->setDefault('unavailable_program_ids', [])
            ->setAllowedTypes('unavailable_program_ids', 'int[]')
            ->setDefault('unavailable_suffix', '')
            ->setAllowedTypes('unavailable_suffix', 'string')
        ;
    }
}
