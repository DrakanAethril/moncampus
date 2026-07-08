<?php

namespace App\Form;

use App\Entity\TicketComment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Reply form for a ticket's thread. The visibility field only exists for handlers - reporters
// always post publicly, forced by the controller rather than by a hidden/disabled form field, so
// there's nothing for a reporter to tamper with client-side.
class TicketCommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', TextareaType::class, [
            'label' => 'ticketCommentBodyFieldLabel',
            // Explicit '' (not the default) activates TextareaType's own null->'' safety net for
            // blank submissions on this non-nullable property.
            'empty_data' => '',
        ]);

        if ($options['allowInternalVisibility']) {
            $builder->add('visibility', ChoiceType::class, [
                'label' => 'ticketCommentVisibilityFieldLabel',
                'choices' => [
                    'ticketVisibilityPublicLabel' => TicketComment::VISIBILITY_PUBLIC,
                    'ticketVisibilityInternalLabel' => TicketComment::VISIBILITY_INTERNAL,
                ],
                'placeholder' => false,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'ticketCommentSubmitAction',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => TicketComment::class,
                'allowInternalVisibility' => false,
            ])
            ->setAllowedTypes('allowInternalVisibility', 'bool')
        ;
    }
}
