<?php

namespace App\Service;

use App\Entity\Ticket;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formats a Ticket's status/priority for display, and is also used to word the system-generated
 * TicketComment entries logged on status changes (see TicketController::manageTicket()) - same
 * idea as App\Service\LaptopStatusFormatter/QueueStateFormatter.
 */
class TicketStatusFormatter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            Ticket::STATUS_OPEN => $this->translator->trans('ticketStatusOpenLabel'),
            Ticket::STATUS_AWAITING_INFO => $this->translator->trans('ticketStatusAwaitingInfoLabel'),
            Ticket::STATUS_IN_PROGRESS => $this->translator->trans('ticketStatusInProgressLabel'),
            Ticket::STATUS_RESOLVED => $this->translator->trans('ticketStatusResolvedLabel'),
            Ticket::STATUS_CLOSED => $this->translator->trans('ticketStatusClosedLabel'),
            default => $status,
        };
    }

    public function statusCssClass(string $status): string
    {
        return match ($status) {
            Ticket::STATUS_OPEN => 'bg-blue-lt',
            Ticket::STATUS_AWAITING_INFO => 'bg-orange-lt',
            Ticket::STATUS_IN_PROGRESS => 'bg-azure-lt',
            Ticket::STATUS_RESOLVED => 'bg-green-lt',
            Ticket::STATUS_CLOSED => 'bg-secondary-lt',
            default => 'bg-secondary-lt',
        };
    }

    public function priorityLabel(string $priority): string
    {
        return match ($priority) {
            Ticket::PRIORITY_LOW => $this->translator->trans('ticketPriorityLowLabel'),
            Ticket::PRIORITY_MEDIUM => $this->translator->trans('ticketPriorityMediumLabel'),
            Ticket::PRIORITY_HIGH => $this->translator->trans('ticketPriorityHighLabel'),
            Ticket::PRIORITY_URGENT => $this->translator->trans('ticketPriorityUrgentLabel'),
            default => $priority,
        };
    }

    public function priorityCssClass(string $priority): string
    {
        return match ($priority) {
            Ticket::PRIORITY_LOW => 'bg-secondary-lt',
            Ticket::PRIORITY_MEDIUM => 'bg-blue-lt',
            Ticket::PRIORITY_HIGH => 'bg-orange-lt',
            Ticket::PRIORITY_URGENT => 'bg-red-lt',
            default => 'bg-secondary-lt',
        };
    }
}
