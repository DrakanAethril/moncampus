<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JobboardSource;
use App\Entity\JobboardToken;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\JobboardBatchRepository;
use App\Repository\JobboardOfferRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The door the veille writes through. Three properties are pinned here, and each one is a promise
 * made to an agent that will retry after a dropped connection:
 *
 * - **no key, no entry** - and a revoked key answers exactly like an unknown one;
 * - **a batch belongs to the key that opened it**, anything else being a 404 rather than a 403;
 * - **the same request twice changes nothing**, the tally included. That is what the
 *   Idempotency-Key buys, and it is the only reason the batch report can be trusted.
 */
class JobboardIngestApiTest extends FunctionalTestCase
{
    private const string VERIFIER = 'f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0';

    private ?User $author = null;

    public function testACallWithoutAKeyIsRefused(): void
    {
        $this->track();
        $this->client->request('POST', '/api/jobboard/batches');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testARevokedKeyAnswersLikeAnUnknownOne(): void
    {
        $token = $this->token();
        $token->revoke();
        $this->manager()->flush();

        $this->call('POST', '/api/jobboard/batches');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testABatchIsOpenedInTheFiliereOfItsKey(): void
    {
        $token = $this->token();

        $this->call('POST', '/api/jobboard/batches');

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($token->getTrack()->getName(), $this->payload()['filiere'] ?? null);
    }

    public function testOffersAreCreatedThenReviewed(): void
    {
        $this->token();
        $batch = $this->openBatch();

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer()], 'key-1');
        $this->assertSame(1, $this->payload()['created'] ?? null);

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer()], 'key-2');
        $this->assertSame(1, $this->payload()['reviewed'] ?? null);
        $this->assertSame(0, $this->payload()['created'] ?? null);
    }

    /**
     * The retry after a dropped connection. Without the stored answer the data would still be
     * right - premiere_vue does not move - but the batch would count the offer twice, and a tally
     * nobody can trust is a veille nobody reads.
     */
    public function testTheSameRequestReplayedChangesNothing(): void
    {
        $this->token();
        $batch = $this->openBatch();

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer()], 'key-1');
        $first = $this->payload();

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer()], 'key-1');
        // Canonicalised rather than identical: the stored answer comes back through a MySQL JSON
        // column, which does not promise to keep an object's keys in the order they were written.
        // What must be identical is the answer, not its spelling.
        $this->assertEqualsCanonicalizing($first, $this->payload());

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/close');
        $this->assertSame(1, $this->payload()['created'] ?? null);
        $this->assertSame(0, $this->payload()['reviewed'] ?? null);
    }

    public function testTheSameKeyWithAnotherBodyIsRefused(): void
    {
        $this->token();
        $batch = $this->openBatch();

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer()], 'key-1');
        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer(['poste' => 'Autre chose'])], 'key-1');

        $this->assertResponseStatusCodeSame(409);
    }

    public function testAMalformedOfferDoesNotFailTheBatch(): void
    {
        $this->token();
        $batch = $this->openBatch();

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [
            $this->offer(),
            $this->offer(['source_ref' => '2', 'pays' => 'Allemagne']),
        ], 'key-1');

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->payload()['created'] ?? null);
        $this->assertSame(1, $this->payload()['rejected'] ?? null);
    }

    /**
     * The refusal that cost real offers, gone. A site nobody declared used to answer
     * `unknown_source` line by line, and nothing here keeps what it refuses - so a whole pass on a
     * new board was lost until somebody shipped a deploy.
     */
    public function testAnOfferFromAnUnknownSiteIsFiledRatherThanRefused(): void
    {
        $this->token();
        $batch = $this->openBatch();

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [
            $this->offer([
                'source' => 'Welcome to the Jungle',
                'source_ref' => 'WTTJ-1',
                'url' => 'https://www.welcometothejungle.com/fr/companies/x/jobs/y',
            ]),
        ], 'key-1');

        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->payload()['created'] ?? null);

        $source = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(JobboardSource::class)
            ->findOneBy(['slug' => 'welcometothejungle']);

        $this->assertInstanceOf(JobboardSource::class, $source);
        $this->assertSame(['welcometothejungle.com'], $source->getDomains());
        $this->assertTrue($source->isDiscoveredByAgent());
    }

    /**
     * And the same rule read from the other end: the URL decides, so a batch whose `source` is
     * misspelt no longer forks the site into a second row.
     */
    public function testAMisspeltSiteNameIsFiledUnderTheSiteItsUrlPointsAt(): void
    {
        // HelloWork must already be there for the question to mean anything: on an empty table
        // there is nothing for the host to point at, and the misspelling would simply be the name
        // of a new site - which is the *other* half of the rule, tested just above.
        $this->jobboardSource();
        $this->token();
        $batch = $this->openBatch();

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer(['source' => 'hellowrk'])], 'key-1');

        $this->assertResponseIsSuccessful();

        $offers = $this->payload()['offers'] ?? [];
        $this->assertIsArray($offers);
        $this->assertIsArray($offers[0] ?? null);
        $this->assertSame('hellowork', $offers[0]['source'] ?? null);
    }

    public function testABatchOpenedByAnotherKeyDoesNotExist(): void
    {
        $first = $this->token();
        $batch = $this->openBatch();
        $other = $this->token('other-selector', 'Veille MCO');

        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer()], 'key-1', $other);

        // 404 and not 403: another filière's batch does not exist for this caller.
        $this->assertResponseStatusCodeSame(404);
        $this->assertNotSame($first->getTrack()->getId(), $other->getTrack()->getId());
    }

    public function testCursorsAreReadAndWritten(): void
    {
        $this->token();

        $this->call('PUT', '/api/jobboard/cursors', null, null, null, [
            'source' => 'hellowork',
            'search' => 'technicien-33',
            'last_ref' => '83313525',
            'last_published_at' => '2026-09-12',
        ]);
        $this->assertResponseIsSuccessful();

        $this->call('GET', '/api/jobboard/cursors');
        $cursors = $this->payload()['cursors'] ?? [];

        $this->assertIsArray($cursors);
        $this->assertCount(1, $cursors);

        $first = $cursors[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('83313525', $first['last_ref'] ?? null);
    }

    public function testAnOfferIsClosedRatherThanDeleted(): void
    {
        $this->token();
        $batch = $this->openBatch();
        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer()], 'key-1');

        $this->call('POST', '/api/jobboard/offers/close', null, null, null, [
            'offers' => [['source' => 'hellowork', 'source_ref' => '83313525']],
        ]);

        $this->assertSame(1, $this->payload()['closed'] ?? null);

        $offers = static::getContainer()->get(JobboardOfferRepository::class);
        $stored = $offers->findOneBy(['sourceRef' => '83313525']);

        // Nothing is ever erased: the row stays, dated.
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isClosed());
    }

    public function testAClosureIsFiledOnTheDaysPass(): void
    {
        $this->token();
        $batch = $this->openBatch();
        $this->call('POST', '/api/jobboard/batches/'.$batch.'/offers', [$this->offer()], 'key-1');
        $this->call('POST', '/api/jobboard/batches/'.$batch.'/close');

        // Step 6 of the agent's sheet, after the closure - which is exactly why the pass is found
        // by day rather than held open for it.
        $this->call('POST', '/api/jobboard/offers/close', null, null, null, [
            'offers' => [['source' => 'hellowork', 'source_ref' => '83313525']],
        ]);

        $this->assertSame(1, $this->tallyOf($batch));

        // Closing an offer that is already closed counts nothing: the history would otherwise read
        // a retry as a second departure.
        $this->call('POST', '/api/jobboard/offers/close', null, null, null, [
            'offers' => [['source' => 'hellowork', 'source_ref' => '83313525']],
        ]);

        $this->assertSame(1, $this->tallyOf($batch));
    }

    private function tallyOf(int $batchId): int
    {
        $batches = static::getContainer()->get(JobboardBatchRepository::class);
        $stored = $batches->find($batchId);
        $this->assertNotNull($stored);
        $this->manager()->refresh($stored);

        return $stored->getClosedCount();
    }

    private function openBatch(): int
    {
        $this->call('POST', '/api/jobboard/batches');
        $id = $this->payload()['batch_id'] ?? null;
        $this->assertIsInt($id);

        return $id;
    }

    /**
     * @param list<array<string, mixed>>|null $offers
     * @param array<string, mixed>|null       $body
     */
    private function call(
        string $method,
        string $path,
        ?array $offers = null,
        ?string $idempotencyKey = null,
        ?JobboardToken $token = null,
        ?array $body = null,
    ): void {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->secret($token ?? $this->currentToken)];

        if (null !== $idempotencyKey) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $content = null;
        if (null !== $offers) {
            $content = json_encode(['offers' => $offers], \JSON_THROW_ON_ERROR);
        } elseif (null !== $body) {
            $content = json_encode($body, \JSON_THROW_ON_ERROR);
        }

        $this->client->request($method, $path, [], [], $headers, $content);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private JobboardToken $currentToken;

    private function token(string $selector = 'selector0001', string $label = 'Veille SIO'): JobboardToken
    {
        $token = new JobboardToken($label, $this->track($label), $selector, hash('sha256', self::VERIFIER), null);
        $this->manager()->persist($token);
        $this->manager()->flush();

        return $this->currentToken = $token;
    }

    private function secret(JobboardToken $token): string
    {
        return JobboardToken::PREFIX.'_'.$token->getSelector().'_'.self::VERIFIER;
    }

    private function track(string $name = 'Veille SIO'): Track
    {
        $this->author ??= $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'jobboard.api.author');

        $section = new Section('Enseignement '.$name);
        $section->setCreatedBy($this->author);
        $this->manager()->persist($section);

        $track = new Track('Filière '.$name, $section);
        $track->setCreatedBy($this->author);
        $this->manager()->persist($track);
        $this->manager()->flush();

        return $track;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function offer(array $overrides = []): array
    {
        return array_merge([
            'source' => 'hellowork',
            'source_ref' => '83313525',
            'url' => 'https://www.hellowork.com/fr-fr/emplois/83313525.html',
            'poste' => 'Technicien informatique',
            'entreprise' => 'Astek',
            'categorie' => 'sisr',
            'contrat' => 'cdi',
            'pays' => 'France',
            'niveau' => 'Bac+2',
            'niveau_source' => 'annonce',
            'acces_bts' => 'accessible',
            'teletravail' => 'non_precise',
            'date_publication' => '2026-09-01',
            'date_publication_approx' => false,
        ], $overrides);
    }

    private function manager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
