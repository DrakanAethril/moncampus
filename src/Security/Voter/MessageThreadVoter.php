<?php

namespace App\Security\Voter;

use App\Entity\MessageThread;
use App\Entity\User;
use App\Repository\MessageThreadRecipientRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scopes access to a MessageThread: a user can VIEW it only if they have a non-deleted
 * MessageThreadRecipient row on it (sender included, see that entity's docblock) - never a
 * global lookup. REPLY additionally requires the thread to be 1:1-shaped (at most one recipient
 * besides the sender) - an announcement-shaped thread's "reply" action is really "start a new
 * private thread with the sender" (App\Controller\MessageController), not a post into this one.
 */
class MessageThreadVoter extends Voter
{
    public const string VIEW = 'MESSAGE_THREAD_VIEW';
    public const string REPLY = 'MESSAGE_THREAD_REPLY';

    public function __construct(
        private readonly MessageThreadRecipientRepository $recipientRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::REPLY], true) && $subject instanceof MessageThread;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var MessageThread $thread */
        $thread = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $recipient = $this->recipientRepository->findOneForUserAndThread($user, $thread);

        if (null === $recipient) {
            return false;
        }

        if (self::VIEW === $attribute) {
            return true;
        }

        return $this->recipientRepository->countRecipients($thread) <= 1;
    }
}
