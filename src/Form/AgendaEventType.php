<?php

namespace App\Form;

use App\Entity\AgendaEvent;
use App\Entity\Program;
use App\Enum\MessageAudienceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Same shape/conventions as AnnouncementType - see that class's docblock for the
// audienceType/program/manual-recipients wiring.
class AgendaEventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'agendaEventTitleFieldLabel',
            ])
            ->add('startAt', DateTimeType::class, [
                'label' => 'agendaEventStartAtFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('endAt', DateTimeType::class, [
                'label' => 'agendaEventEndAtFieldLabel',
                'help' => 'agendaEventEndAtFieldHelpText',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('location', TextType::class, [
                'label' => 'agendaEventLocationFieldLabel',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'agendaEventDescriptionFieldLabel',
                'required' => false,
            ])
            ->add('audienceType', EnumType::class, [
                'class' => MessageAudienceType::class,
                'choice_label' => static fn (MessageAudienceType $type): string => $type->labelKey(),
                'expanded' => true,
                'label' => 'messageAudienceTypeFieldLabel',
            ])
            ->add('program', EntityType::class, [
                'class' => Program::class,
                'choices' => $options['programs'],
                'choice_label' => 'shortName',
                'label' => 'messageAudienceProgramFieldLabel',
                'required' => false,
                'placeholder' => 'messageAudienceProgramPlaceholder',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => AgendaEvent::class])
            ->setRequired(['programs'])
            ->setAllowedTypes('programs', 'array')
        ;
    }
}
