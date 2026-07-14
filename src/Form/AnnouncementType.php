<?php

namespace App\Form;

use App\Entity\Announcement;
use App\Entity\Program;
use App\Enum\MessageAudienceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// audienceType/program are shown at once, no server-side conditional rendering - same
// "message-audience" Stimulus controller as MessageComposeType toggles which one is meaningful
// (templates/announcement/announcement_new.html.twig). Manual recipients are a plain
// `recipients[]` field outside this form's namespace for the same reason as
// MessageComposeType's - see that class's docblock.
class AnnouncementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'announcementTitleFieldLabel',
            ])
            ->add('body', TextareaType::class, [
                'label' => 'announcementBodyFieldLabel',
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
            ->add('expiresAt', DateType::class, [
                'label' => 'announcementExpiresAtFieldLabel',
                'help' => 'announcementExpiresAtFieldHelpText',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => Announcement::class])
            ->setRequired(['programs'])
            ->setAllowedTypes('programs', 'array')
        ;
    }
}
