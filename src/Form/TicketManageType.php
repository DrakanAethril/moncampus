<?php

namespace App\Form;

use App\Entity\Ticket;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Handler-only panel updating a subset of Ticket's fields (status/priority/assignee) - the rest
// of the entity (subject, description, reporter...) is left untouched by this form, same shape
// as LaptopLoanReturnType only mapping returnCondition/returnStateNotes onto a LaptopLoan.
class TicketManageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('status', ChoiceType::class, [
                'label' => 'ticketStatusFieldLabel',
                'choices' => [
                    'ticketStatusOpenLabel' => Ticket::STATUS_OPEN,
                    'ticketStatusAwaitingInfoLabel' => Ticket::STATUS_AWAITING_INFO,
                    'ticketStatusInProgressLabel' => Ticket::STATUS_IN_PROGRESS,
                    'ticketStatusResolvedLabel' => Ticket::STATUS_RESOLVED,
                    'ticketStatusClosedLabel' => Ticket::STATUS_CLOSED,
                ],
                'placeholder' => false,
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'ticketPriorityFieldLabel',
                'choices' => [
                    'ticketPriorityLowLabel' => Ticket::PRIORITY_LOW,
                    'ticketPriorityMediumLabel' => Ticket::PRIORITY_MEDIUM,
                    'ticketPriorityHighLabel' => Ticket::PRIORITY_HIGH,
                    'ticketPriorityUrgentLabel' => Ticket::PRIORITY_URGENT,
                ],
                'placeholder' => false,
            ])
            ->add('assignee', EntityType::class, [
                'class' => User::class,
                'choices' => $options['assignableUsers'],
                'choice_label' => static fn (User $user): string => $user->getDisplayName() ?? $user->getUsername(),
                'label' => 'ticketAssigneeFieldLabel',
                'required' => false,
                'placeholder' => 'ticketUnassignedPlaceholder',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'ticketManageSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => Ticket::class])
            ->setRequired('assignableUsers')
            ->setAllowedTypes('assignableUsers', 'array')
        ;
    }
}
