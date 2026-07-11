<?php

namespace App\Service;

use App\Entity\Ticket;
use App\Entity\TicketComment;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Posts a message to the "discord" chatter transport (config/packages/notifier.yaml) whenever a
 * Ticket is created or gets a new reply, from either TicketController (authenticated) or
 * PublicTicketController (anonymous "lost access" form). Every message sent outside prod is
 * prefixed with "[DEV]" so dev/prod traffic stays distinguishable even when both point at the
 * same webhook - see .env.dev.local.example.
 */
class TicketDiscordNotifier
{
    // Discord messages cap at 2000 chars total; this just keeps a single quoted comment from
    // dominating the message on its own.
    private const int COMMENT_BODY_PREVIEW_LENGTH = 300;

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

        $this->send($text, $ticket->getId());
    }

    // Only meant for human replies (TicketController::addComment()) - status/priority/assignee
    // changes are logged as system comments in the same thread (see TicketComment's own
    // docblock) but are a much noisier, different kind of event, not "someone replied".
    public function notifyNewComment(TicketComment $comment): void
    {
        if ($comment->isSystemGenerated()) {
            return;
        }

        $ticket = $comment->getTicket();
        $authorLabel = $comment->getAuthor()->getDisplayName() ?? $comment->getAuthor()->getUsername();
        $body = mb_strimwidth($comment->getBody(), 0, self::COMMENT_BODY_PREVIEW_LENGTH, '…');
        $url = $this->urlGenerator->generate('app_tickets_show', ['id' => $ticket->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        $text = \sprintf(
            "💬 Nouveau commentaire sur le ticket #%d : %s\nAuteur : %s%s\n> %s\n%s",
            $ticket->getId(),
            $ticket->getSubject(),
            $authorLabel,
            $comment->isInternal() ? ' (interne)' : '',
            str_replace("\n", "\n> ", $body),
            $url,
        );

        $this->send($text, $ticket->getId());
    }

    private function send(string $text, ?int $ticketId): void
    {
        if ('prod' !== $this->environment) {
            $text = '[DEV] '.$text;
        }

        // Never let a missing/wrong webhook, or Discord being unreachable, break the ticket
        // action itself - this is a best-effort side notification, not part of the main flow.
        try {
            $this->chatter->send((new ChatMessage($text))->transport('discord'));
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to send Discord notification for ticket #{ticketId}: {message}', [
                'ticketId' => $ticketId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
