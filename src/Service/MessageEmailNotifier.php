<?php

namespace App\Service;

use App\Entity\Message;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forwards a copy of a just-sent internal Message to each recipient's $contactEmail, for those who
 * opted into User::$emailCopyOfMessagesEnabled and have a *verified* contact email - see
 * App\Controller\MessageController::compose()/reply(), the only two callers. Never sends to the
 * message's own author, whether or not $recipients happens to include them.
 */
class MessageEmailNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @param iterable<User> $recipients */
    public function notify(Message $message, iterable $recipients): void
    {
        $sender = $message->getAuthor();
        $subject = $message->getThread()->getSubject();

        foreach ($recipients as $recipient) {
            if ($recipient === $sender || !$recipient->isEmailCopyOfMessagesEnabled() || !$recipient->isContactEmailVerified()) {
                continue;
            }

            $this->mailer->send((new TemplatedEmail())
                ->to($recipient->getContactEmail())
                ->subject(\sprintf('%s : %s', $this->translator->trans('messageCopyEmailSubjectPrefix'), $subject))
                ->htmlTemplate('emails/message_copy.html.twig')
                ->context([
                    'sender' => $sender,
                    'subject' => $subject,
                    'body' => $message->getBody(),
                ]));
        }
    }
}
