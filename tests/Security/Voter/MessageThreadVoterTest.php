<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\MessageThread;
use App\Entity\MessageThreadRecipient;
use App\Repository\MessageThreadRecipientRepository;
use App\Security\Voter\MessageThreadVoter;

/**
 * Being a recipient opens the thread; replying additionally requires the thread to be a
 * one-to-one, so nobody can answer into a broadcast and reach an audience they never had.
 */
class MessageThreadVoterTest extends VoterTestCase
{
    private function voter(bool $isRecipient, int $recipientCount): MessageThreadVoter
    {
        $repository = $this->createStub(MessageThreadRecipientRepository::class);
        $repository->method('findOneForUserAndThread')
            ->willReturn($isRecipient ? $this->createStub(MessageThreadRecipient::class) : null);
        $repository->method('countRecipients')->willReturn($recipientCount);

        return new MessageThreadVoter($repository);
    }

    public function testOnlyRecipientsOpenAThread(): void
    {
        $thread = $this->createStub(MessageThread::class);

        $this->assertGranted($this->voter(true, 1), $this->user(), $thread, MessageThreadVoter::VIEW);
        $this->assertDenied($this->voter(false, 1), $this->user(), $thread, MessageThreadVoter::VIEW);
    }

    public function testReplyingIsRefusedOnABroadcast(): void
    {
        $thread = $this->createStub(MessageThread::class);

        $this->assertGranted($this->voter(true, 1), $this->user(), $thread, MessageThreadVoter::REPLY);
        $this->assertDenied($this->voter(true, 12), $this->user(), $thread, MessageThreadVoter::REPLY);
    }

    public function testAnonymousIsDenied(): void
    {
        $this->assertDenied($this->voter(true, 1), null, $this->createStub(MessageThread::class), MessageThreadVoter::VIEW);
    }
}
