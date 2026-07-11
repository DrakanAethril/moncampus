<?php

namespace App\Service;

use App\Entity\Ticket;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Posts a message to the "discord" chatter transport (config/packages/notifier.yaml) whenever a
 * new Ticket is created, from either TicketController (authenticated) or PublicTicketController
 * (anonymous "lost access" form). Every message sent outside prod is prefixed with "[DEV]" so
 * dev/prod traffic stays distinguishable even when both point at the same webhook - see
 * .env.dev.local.example.
 */
class TicketDiscordNotifier
{
    public function __construct(
        private readonly ChatterInterface $chatter,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TicketStatusFormatter $statusFormatter,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'kernel.environment')] private readonly string $environment,
    ) {
    }

    public function notifyNewTicket(Ticket $ticket): void
    {
        $reporterLabel = $ticket->isAnonymous()
            ? \sprintf('%s (anonyme)', $ticket->getReporterName() ?? 'Inconnu')
            : ($ticket->getReporter()->getDisplayName() ?? $ticket->getReporter()->getUsername());

        $url = $this->urlGenerator->generate('app_tickets_show', ['id' => $ticket->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        $text = \sprintf(
            "🎫 Nouveau ticket #%d : %s\nCatégorie : %s\nPriorité : %s\nSignalé par : %s\n%s",
            $ticket->getId(),
            $ticket->getSubject(),
            $ticket->getCategory()?->getName() ?? '—',
            $this->statusFormatter->priorityLabel($ticket->getPriority()),
            $reporterLabel,
            $url,
        );

        if ('prod' !== $this->environment) {
            $text = '[DEV] '.$text;
        }

        // Never let a missing/wrong webhook, or Discord being unreachable, break ticket creation
        // itself - this is a best-effort side notification, not part of the actual ticket flow.
        try {
            $this->chatter->send((new ChatMessage($text))->transport('discord'));
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to send Discord notification for new ticket #{ticketId}: {message}', [
                'ticketId' => $ticket->getId(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
