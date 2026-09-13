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
}
