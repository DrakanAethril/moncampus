<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Service\EnterpriseContacts;
use PHPUnit\Framework\TestCase;

/**
 * An employer's fiche answers "who do we talk to there", and the answer is read off the alternances
 * rather than stored: a contact is a tutor somebody once named on a contract with this company.
 *
 * The two rules worth pinning are that a tutor stays a contact once their alternance is over - an
 * employer between two contracts would otherwise have no contact at all - and that the ones still
 * running come first, because that is who a call is about today.
 */
class EnterpriseContactsTest extends TestCase
{
    public function testOneRowPerTutorWhateverTheNumberOfAlternances(): void
    {
        $tutor = $this->user('Claude', 'Ferrand');
        $links = [$this->link($tutor), $this->link($tutor), $this->link($this->user('Dominique', 'Alix'))];

        $contacts = (new EnterpriseContacts())->fromLinks($links);

        $this->assertCount(2, $contacts);
        $this->assertSame($tutor, $contacts[0]['tutor']);
        $this->assertCount(2, $contacts[0]['alternances']);
        $this->assertSame(2, $contacts[0]['activeCount']);
    }

    public function testATutorWhoseAlternancesAreAllOverIsStillAContact(): void
    {
        $tutor = $this->user('Claude', 'Ferrand');
        $link = $this->link($tutor);
        $link->setInactiveDate(new \DateTimeImmutable('2026-06-30'));

        $contacts = (new EnterpriseContacts())->fromLinks([$link]);

        $this->assertCount(1, $contacts);
        $this->assertSame(0, $contacts[0]['activeCount']);
    }

    public function testRunningContactsComeFirstThenAlphabeticalOrder(): void
    {
        $over = $this->link($this->user('Alix', 'Aubert'));
        $over->setInactiveDate(new \DateTimeImmutable('2026-06-30'));
        $running = $this->link($this->user('Zoé', 'Zerbib'));

        $contacts = (new EnterpriseContacts())->fromLinks([$over, $running, $this->link($this->user('Bel', 'Bonnet'))]);

        $this->assertSame(
            ['Bel Bonnet', 'Zoé Zerbib', 'Alix Aubert'],
            array_map(static fn (array $contact): string => $contact['tutor']->getDisplayName() ?? '', $contacts),
        );
    }

    public function testALinkWithNoTutorIsNotAContact(): void
    {
        $this->assertSame([], (new EnterpriseContacts())->fromLinks([$this->link(null)]));
    }

    private function link(?User $tutor): InternshipTutorLink
    {
        $link = new InternshipTutorLink($this->createStub(Program::class));
        $link->setTutor($tutor);
        $link->setEnterprise(new Enterprise('Neopixel'));

        return $link;
    }

    private function user(string $firstname, string $lastname): User
    {
        $user = new User(mb_strtolower($firstname.'.'.$lastname));
        $user->setFirstname($firstname);
        $user->setLastname($lastname);

        return $user;
    }
}
