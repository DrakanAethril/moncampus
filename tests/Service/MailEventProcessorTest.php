<?php

namespace App\Tests\Service;

use App\Entity\EmailMessage;
use App\Entity\SuppressedEmailAddress;
use App\Enum\EmailDeliveryStatus;
use App\Enum\EmailDirection;
use App\Enum\EmailSuppressionReason;
use App\Repository\EmailMessageRepository;
use App\Repository\SuppressedEmailAddressRepository;
use App\Service\MailEventProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What one SES event does to a mail we sent (design_handoff_courrier_ecole_infra §6).
 *
 * Worth pinning down here rather than only in the browser: this code only ever runs against a real
 * SQS queue, so the cases that matter - an out-of-order event, an unknown mail, a soft bounce - are
 * exactly the ones nobody would think to reproduce by hand.
 */
class MailEventProcessorTest extends TestCase
{
    /** The shape SES uses: no brackets, no domain. */
    private const string SES_ID = '0113019fd06b7c6d-5ddd5424-453b-4269-b9da-cca2f35f16b9-000000';


    public function testDeliveryEventMarksTheMailDelivered(): void
    {
        $message = $this->message(EmailDeliveryStatus::Sent);
        $processor = $this->processor($message, $persisted);

        self::assertTrue($processor->process($this->sesEvent('Delivery')));
        self::assertSame(EmailDeliveryStatus::Delivered, $message->getDeliveryStatus());
    }

    public function testAnEarlierEventNeverUndoesALaterOne(): void
    {
        // SQS gives no ordering guarantee: a Send arriving after a Delivery must not un-deliver it.
        $message = $this->message(EmailDeliveryStatus::Delivered);
        $processor = $this->processor($message, $persisted);

        self::assertTrue($processor->process($this->sesEvent('Send')));
        self::assertSame(EmailDeliveryStatus::Delivered, $message->getDeliveryStatus());
    }

    public function testAnEventAboutAnUnknownMailIsLeftInTheQueue(): void
    {
        // The event queue can outrun our own commit; acknowledging here would strand that send on
        // "sent" for good.
        $processor = $this->processor(null, $persisted);

        self::assertFalse($processor->process($this->sesEvent('Delivery')));
    }

    public function testOpenEventsAreAcknowledgedAndIgnored(): void
    {
        // Principle #1 of the screens handoff forbids open tracking, whatever SES publishes.
        $message = $this->message(EmailDeliveryStatus::Delivered);
        $processor = $this->processor($message, $persisted);

        self::assertTrue($processor->process($this->sesEvent('Open')));
        self::assertSame(EmailDeliveryStatus::Delivered, $message->getDeliveryStatus());
    }

    public function testPermanentBounceSuppressesTheAddress(): void
    {
        $message = $this->message(EmailDeliveryStatus::Sent);
        $processor = $this->processor($message, $persisted);

        $event = json_encode([
            'eventType' => 'Bounce',
            'mail' => ['messageId' => self::SES_ID],
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'NoEmail',
                'bouncedRecipients' => [['emailAddress' => 'rh@neopixel.fr']],
            ],
        ], \JSON_THROW_ON_ERROR);

        self::assertTrue($processor->process($event));
        self::assertSame(EmailDeliveryStatus::Bounced, $message->getDeliveryStatus());
        self::assertCount(1, $persisted);
        self::assertInstanceOf(SuppressedEmailAddress::class, $persisted[0]);
        self::assertSame('rh@neopixel.fr', $persisted[0]->getAddress());
        self::assertSame(EmailSuppressionReason::HardBounce, $persisted[0]->getReason());
    }

    public function testTransientBounceLeavesTheAddressAlone(): void
    {
        // A full mailbox is a bad day, not a dead address: suppressing it would cost a student a
        // real contact.
        $message = $this->message(EmailDeliveryStatus::Sent);
        $processor = $this->processor($message, $persisted);

        $event = json_encode([
            'eventType' => 'Bounce',
            'mail' => ['messageId' => self::SES_ID],
            'bounce' => [
                'bounceType' => 'Transient',
                'bounceSubType' => 'MailboxFull',
                'bouncedRecipients' => [['emailAddress' => 'rh@neopixel.fr']],
            ],
        ], \JSON_THROW_ON_ERROR);

        self::assertTrue($processor->process($event));
        self::assertSame(EmailDeliveryStatus::Bounced, $message->getDeliveryStatus());
        self::assertSame([], $persisted);
    }

    public function testSnsEnvelopeIsUnwrapped(): void
    {
        // SES publishes through SNS, which wraps the real event in a JSON string.
        $message = $this->message(EmailDeliveryStatus::Sent);
        $processor = $this->processor($message, $persisted);

        $envelope = json_encode([
            'Type' => 'Notification',
            'Message' => $this->sesEvent('Delivery'),
        ], \JSON_THROW_ON_ERROR);

        self::assertTrue($processor->process($envelope));
        self::assertSame(EmailDeliveryStatus::Delivered, $message->getDeliveryStatus());
    }

    /** An SES event names the mail by the identifier SES itself assigned, never by ours. */
    private function sesEvent(string $type): string
    {
        return json_encode([
            'eventType' => $type,
            'mail' => ['messageId' => self::SES_ID],
        ], \JSON_THROW_ON_ERROR);
    }

    private function message(EmailDeliveryStatus $status): EmailMessage
    {
        return (new EmailMessage())
            ->setDirection(EmailDirection::Outbound)
            ->setMessageId('<'.self::SES_ID.'@eu-west-3.amazonses.com>')
            ->setProviderMessageId(self::SES_ID)
            ->setFromAddress('camille.roux@devetu.beaupeyrat.org')
            ->setToAddresses(['rh@neopixel.fr'])
            ->setS3Key('candidatures/croux/mails/abc.eml')
            ->setDeliveryStatus($status);
    }

    /** @param list<object>|null $persisted filled with whatever the processor asked to persist */
    private function processor(?EmailMessage $message, ?array &$persisted): MailEventProcessor
    {
        $persisted = [];

        // Stubs, not mocks: what is asserted is the state the processor leaves behind, never the
        // calls it made to get there.
        $messages = $this->createStub(EmailMessageRepository::class);
        $messages->method('findOneByAnyMessageId')->willReturn($message);

        $suppressions = $this->createStub(SuppressedEmailAddressRepository::class);
        $suppressions->method('isSuppressed')->willReturn(false);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        return new MailEventProcessor($messages, $suppressions, $entityManager, new NullLogger());
    }
}
