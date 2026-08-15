<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AssignmentExpectedProduction;
use App\Enum\AssignmentProductionFormat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row of « Productions attendues » (2a, Consigne step): name, format, deadline.
 *
 * $dueMode is not data but a screen toggle - « Échéance du travail » or « Date et heure précises… ».
 * It lives in the form rather than in the template so it is carried into the prototype of the rows
 * added on the fly, and it is what decides, on submission, whether the date entered counts or whether
 * the row follows the assignment.
 */
class AssignmentExpectedProductionType extends AbstractType
{
    public const DUE_MODE_WORK = 'work';
    public const DUE_MODE_CUSTOM = 'custom';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ?AssignmentExpectedProduction $production */
        $production = $builder->getData();

        $builder
            ->add('name', TextType::class, [
                'label' => false,
                'empty_data' => '',
                'attr' => ['placeholder' => 'assignmentProductionNamePlaceholder'],
            ])
            ->add('format', EnumType::class, [
                'class' => AssignmentProductionFormat::class,
                'choice_label' => static fn (AssignmentProductionFormat $format): string => $format->labelKey(),
                'label' => false,
                'placeholder' => false,
            ])
            ->add('dueMode', ChoiceType::class, [
                'label' => false,
                'mapped' => false,
                'choices' => [
                    'assignmentProductionDueWorkLabel' => self::DUE_MODE_WORK,
                    'assignmentProductionDueCustomLabel' => self::DUE_MODE_CUSTOM,
                ],
                'placeholder' => false,
                'data' => $production?->hasOwnDueDate() ? self::DUE_MODE_CUSTOM : self::DUE_MODE_WORK,
            ])
            ->add('dueDate', DateTimeType::class, [
                'label' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            // The rank as the row reads on screen, rewritten by the Stimulus controller on every
            // add/remove - without which a row removed in the middle would bring the following ones
            // up out of order.
            ->add('position', HiddenType::class)
        ;

        // « Échéance du travail » means « no deadline of its own »: the value left in the hidden field
        // must not survive going back.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $production = $event->getData();

            if ($production instanceof AssignmentExpectedProduction && self::DUE_MODE_CUSTOM !== $event->getForm()->get('dueMode')->getData()) {
                $production->setDueDate(null);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AssignmentExpectedProduction::class]);
    }
}
