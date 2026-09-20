<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailMessage;
use App\Enum\EmailDirection;
use App\Enum\SchoolMailApplicationEvidence;
use App\Repository\EmailMessageRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Files an incoming mail under the démarche it is about when In-Reply-To could not - because the
 * mail *quotes* one of the student's sends rather than answering it.
 *
 * The case this was written for is the delivery failure notice. A company's mail server that
 * refuses a send after having accepted it writes back to the student's alias, and what comes back
 * is a fresh message: no In-Reply-To, so App\Service\InboundMailProcessor's normal rule (handoff
 * principle #5) leaves it outside every démarche - while the notice names, in full, both the
 * address that failed and the mail it carried. The screen then shows the student a failure filed
 * nowhere, next to the démarche it belongs to.
 *
 * Two readings, in order of certainty, and **neither is a guess**:
 *
 * 1. **The quoted Message-ID.** A notice embeds the original message, headers included, and the
 *    Message-ID it quotes is the one SES rewrote - exactly what App\Service\SchoolMailSender stored.
 *    One identifier, one send, no ambiguity.
 * 2. **The quoted address.** Failing that, every address the mail names is looked up among the
 *    recipients of that student's own sends. The démarche is taken **only if all the matches agree
 *    on one**: a student who wrote to the same address under two démarches gets no answer here,
 *    which is the honest one.
 *
 * What is deliberately *not* a reading: the sender, the date, a time window. That fallback is named
 * in the infra handoff and refused in InboundMailProcessor::linkToApplication for a reason - filing
 * on a coincidence puts a mail under a company it may have nothing to do with, and screen 5a exists
 * precisely so "we do not know" stays an answer the platform can give.
 *
 * Addresses on the student domain are dropped before anything is matched: a notice quotes the
 * student's own alias as loudly as it quotes the recipient, and the alias is on every démarche.
 */
class SchoolMailApplicationRecovery
{
    /** Deliberately loose on the local part: a real address is the thing being read, not validated. */
    private const string ADDRESS_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9](?:[A-Z0-9.\-]*[A-Z0-9])?\.[A-Z]{2,}/i';

    /** The bracketed form, which is how a quoted header carries a Message-ID. */
    private const string MESSAGE_ID_PATTERN = '/<[^<>\s@]+@[^<>\s]+>/';

    public function __construct(
        private readonly EmailMessageRepository $messageRepository,
        private readonly HtmlPlainText $plainText,
        #[Autowire('%env(MAIL_STUDENT_DOMAIN)%')]
        private readonly string $studentMailDomain,
    ) {
    }

    /**
     * @return ?SchoolMailApplicationMatch null whenever the mail says nothing, or says two things -
     *                                     the caller then leaves it where it is
     */
    public function recover(EmailMessage $message): ?SchoolMailApplicationMatch
    {
        $student = $message->getStudent();

        if (null === $student
            || EmailDirection::Inbound !== $message->getDirection()
            || null !== $message->getJobApplication()
        ) {
            return null;
        }

        $sends = $this->messageRepository->findSendsWithApplicationForStudent($student);

        if ([] === $sends) {
            return null;
        }

        $quoted = $this->quotedText($message);

        return $this->byMessageId($quoted, $sends) ?? $this->byAddress($quoted, $sends);
    }

    /**
     * @param list<EmailMessage> $sends
     */
    private function byMessageId(string $quoted, array $sends): ?SchoolMailApplicationMatch
    {
        if (!preg_match_all(self::MESSAGE_ID_PATTERN, $quoted, $matches)) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            foreach ($sends as $send) {
                if (!$this->sendAnswersTo($send, $candidate)) {
                    continue;
                }

                $application = $send->getJobApplication();

                if (null === $application) {
                    continue;
                }

                return new SchoolMailApplicationMatch(
                    $application,
                    $send,
                    $candidate,
                    SchoolMailApplicationEvidence::MessageId,
                );
            }
        }

        return null;
    }

    /**
     * The same two names a send answers to as in EmailMessageRepository::findOneByAnyMessageId: the
     * header the recipient saw, and SES's bare identifier inside it. Matching is done here rather
     * than through the repository because the sends are already loaded, and because a notice can
     * quote a dozen identifiers - one query each would be a dozen round trips for nothing.
     */
    private function sendAnswersTo(EmailMessage $send, string $messageId): bool
    {
        if ($send->getMessageId() === $messageId) {
            return true;
        }

        $provider = $send->getProviderMessageId();

        if (null === $provider || '' === $provider) {
            return false;
        }

        $bare = trim($messageId, '<>');
        $bare = str_contains($bare, '@') ? substr($bare, 0, (int) strpos($bare, '@')) : $bare;

        return $provider === $bare;
    }

    /**
     * @param list<EmailMessage> $sends
     */
    private function byAddress(string $quoted, array $sends): ?SchoolMailApplicationMatch
    {
        if (!preg_match_all(self::ADDRESS_PATTERN, $quoted, $matches)) {
            return null;
        }

        $suffix = '@'.mb_strtolower($this->studentMailDomain);
        $found = null;

        foreach ($matches[0] as $address) {
            $lowered = mb_strtolower($address);

            if ('' !== $this->studentMailDomain && str_ends_with($lowered, $suffix)) {
                continue;
            }

            foreach ($sends as $send) {
                if (!$this->sendWasAddressedTo($send, $lowered)) {
                    continue;
                }

                $application = $send->getJobApplication();

                if (null === $application) {
                    continue;
                }

                // A second démarche in the same mail is what makes this unanswerable: the mail is
                // about one of them and nothing here says which. Compared by identity rather than
                // by id - Doctrine's identity map makes the two the same thing, and a démarche
                // created in the same unit of work has no id yet.
                if (null !== $found && $found->application !== $application) {
                    return null;
                }

                $found ??= new SchoolMailApplicationMatch(
                    $application,
                    $send,
                    $address,
                    SchoolMailApplicationEvidence::Address,
                );
            }
        }

        return $found;
    }

    private function sendWasAddressedTo(EmailMessage $send, string $loweredAddress): bool
    {
        foreach ([...$send->getToAddresses(), ...$send->getCcAddresses()] as $recipient) {
            if (mb_strtolower($recipient) === $loweredAddress) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything the mail says, as one block of text: subject and both bodies.
     *
     * The HTML part goes through App\Service\HtmlPlainText rather than being read raw - a notice
     * rendered as HTML writes the failing address inside a `<a href="mailto:…">`, where the raw
     * markup would have it twice and the attribute quoting would swallow neither cleanly.
     */
    private function quotedText(EmailMessage $message): string
    {
        return implode("\n", array_filter([
            $message->getSubject(),
            $message->getTextBody(),
            $this->plainText->linesFromHtml($message->getHtmlBody()),
        ], static fn (?string $part): bool => null !== $part && '' !== $part));
    }
}
