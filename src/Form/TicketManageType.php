<?php

namespace App\Form;

use App\Entity\Ticket;
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
            // Not a form field: "assignee" is picked via an ajax tom-select field embedded
            // directly in ticket/show.html.twig (resolved from a top-level "assignee" POST field
            // by TicketController::manageTicket()), same convention as LessonSessionType's
            // teacher field - only active users matching TicketVoter::HANDLER_ROLES are eligible.
            ->add('submit', SubmitType::class, [
                'label' => 'ticketManageSubmitAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Ticket::class]);
    }
}
