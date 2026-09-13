<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\JobboardSource;
use PHPUnit\Framework\TestCase;

/**
 * A site's domains, and the one thing that makes them work: they are compared against a **host**.
 *
 * A domain stored as it was pasted - `https://www.example.com/jobs` - would never equal
 * `example.com`, and the screen would look perfectly right while resolving nothing. So the
 * normalisation lives in the entity rather than in the form that happens to feed it.
 */
class JobboardSourceTest extends TestCase
{
    public function testWhateverIsPastedComesDownToAHost(): void
    {
        $source = new JobboardSource('example', 'Example', [
            'https://www.example.com/jobs?q=1',
            'EXAMPLE.ORG.',
            'example.net:8080',
            '   ',
        ]);

        $this->assertSame(['example.com', 'example.org', 'example.net'], $source->getDomains());
    }

    public function testTheSameDomainIsNeverStoredTwice(): void
    {
        $source = new JobboardSource('example', 'Example', ['example.com']);
        $source->addDomain('https://www.example.com/');

        $this->assertSame(['example.com'], $source->getDomains());
    }

    public function testASubdomainBelongsToItsDomainButANeighbourDoesNot(): void
    {
        $source = new JobboardSource('example', 'Example', ['example.com']);

        $this->assertTrue($source->covers('example.com'));
        $this->assertTrue($source->covers('candidat.example.com'));
        // The check is a suffix on a label boundary, not a string suffix: `notexample.com` must not
        // pass for being `example.com` with letters in front of it.
        $this->assertFalse($source->covers('notexample.com'));
        $this->assertFalse($source->covers('example.com.evil.net'));
    }

    public function testASourceIsNotBlacklistedUntilItIsPutThere(): void
    {
        $source = new JobboardSource('example', 'Example', ['example.com']);

        $this->assertFalse($source->isBlacklisted());
        $this->assertNull($source->getBlacklistedAt());
    }

    /**
     * Blacklisting twice keeps the first date. It matters because the screen prints « en liste noire
     * depuis le … » and a double click would otherwise rewrite the answer to the only question that
     * row is asked.
     */
    public function testBlacklistingKeepsTheDateOfTheFirstGesture(): void
    {
        $source = new JobboardSource('example', 'Example', ['example.com']);
        $first = new \DateTimeImmutable('2026-09-01 08:00:00');

        $source->blacklist($first);
        $source->blacklist(new \DateTimeImmutable('2026-09-12 17:30:00'));

        $this->assertSame($first, $source->getBlacklistedAt());
    }

    /**
     * Coming back off the list restores nothing but the state: the domains stayed all along, which
     * is what makes the site resolve - and be dropped - while it is out.
     */
    public function testComingOffTheBlacklistLeavesTheDomainsAlone(): void
    {
        $source = new JobboardSource('example', 'Example', ['example.com']);
        $source->blacklist();

        $this->assertTrue($source->covers('emplois.example.com'));

        $source->allow();

        $this->assertFalse($source->isBlacklisted());
        $this->assertSame(['example.com'], $source->getDomains());
    }
}
