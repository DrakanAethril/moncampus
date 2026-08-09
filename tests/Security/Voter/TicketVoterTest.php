<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Ticket;
use App\Entity\User;
use App\Security\Voter\TicketVoter;

/**
 * A reporter may read their own ticket but never manage it; every handler role may do both.
 */
class TicketVoterTest extends VoterTestCase
{
    private function ticket(?User $reporter): Ticket
    {
        $ticket = $this->createStub(Ticket::class);
        $ticket->method('getReporter')->willReturn($reporter);

        return $ticket;
    }

    public function testReporterReadsOwnTicketButCannotManageIt(): void
    {
        $reporter = $this->user(['ROLE_USER', 'ROLE_STUDENT']);
        $ticket = $this->ticket($reporter);

        $this->assertGranted(new TicketVoter(), $reporter, $ticket, TicketVoter::VIEW);
        $this->assertDenied(new TicketVoter(), $reporter, $ticket, TicketVoter::MANAGE);
    }

    public function testSomeoneElsesTicketStaysHidden(): void
    {
        $ticket = $this->ticket($this->user(['ROLE_USER'], 'reporter'));

        $this->assertDenied(new TicketVoter(), $this->user(['ROLE_USER'], 'nosy'), $ticket, TicketVoter::VIEW);
    }

    /** Every handler role, not just ROLE_ADMIN - the queue is shared. */
    public function testEveryHandlerRoleManages(): void
    {
        $ticket = $this->ticket($this->user(['ROLE_USER'], 'reporter'));

        foreach (TicketVoter::HANDLER_ROLES as $role) {
            $handler = $this->user(['ROLE_USER', $role], 'handler');
            $this->assertGranted(new TicketVoter(), $handler, $ticket, TicketVoter::MANAGE, $role.' should manage tickets');
            $this->assertGranted(new TicketVoter(), $handler, $ticket, TicketVoter::VIEW, $role.' should view tickets');
        }
    }

    public function testAnonymousIsDenied(): void
    {
        $this->assertDenied(new TicketVoter(), null, $this->ticket(null), TicketVoter::VIEW);
    }
}
