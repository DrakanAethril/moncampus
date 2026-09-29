<?php

declare(strict_types=1);

namespace App\Tests\Service\Sirene;

use App\Service\Sirene\RechercheEntreprisesClient;
use App\Service\Sirene\SireneUnavailableException;
use App\Tests\Double\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Recherche d'entreprises API as this client reads it, on an answer recorded on 2026-09-29
 * (« AUDEFI » around 87000): only open establishments are candidates, a SIRET lookup finds a closed
 * one too, and anything but a well-formed answer is an outage - out loud when the shape is unknown.
 */
class RechercheEntreprisesClientTest extends TestCase
{
    private const string BASE = 'https://recherche-entreprises.api.gouv.fr';

    public function testASearchKeepsOnlyOpenEstablishmentsAndSendsTheNarrowing(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$sent): MockResponse {
            $sent = $url;

            return new MockResponse($this->fixture('search-audefi-87000.json'));
        }, self::BASE);

        $candidates = (new RechercheEntreprisesClient($http, new RecordingLogger()))->search('AUDEFI', '87000');

        self::assertIsString($sent);
        parse_str((string) parse_url($sent, \PHP_URL_QUERY), $query);
        self::assertSame('AUDEFI', $query['q'] ?? null);
        self::assertSame('87000', $query['code_postal'] ?? null);
        self::assertArrayNotHasKey('departement', $query);
        self::assertSame('matching_etablissements,siege', $query['include'] ?? null);

        $sirets = array_map(static fn ($candidate): string => $candidate->siret, $candidates);
        self::assertContains('48931910300037', $sirets);
        self::assertContains('48931910300029', $sirets);
        self::assertNotContains('48931910300011', $sirets, 'A closed establishment is never a candidate.');

        $lathiere = $candidates[array_search('48931910300037', $sirets, true)];
        self::assertSame('489319103', $lathiere->siren);
        self::assertSame('AUDEFI EXPERTISE COMPTABLE', $lathiere->fullName);
        self::assertSame('16 RUE BERNARD LATHIERE 87000 LIMOGES', $lathiere->address);
        self::assertSame('87000', $lathiere->postalCode);
        self::assertTrue($lathiere->open);
        self::assertFalse($lathiere->headOffice);
        self::assertSame('2021-02-01', $lathiere->createdOn?->format('Y-m-d'));
    }

    public function testALookupBySiretFindsAClosedEstablishmentToo(): void
    {
        $http = new MockHttpClient(new MockResponse($this->fixture('search-audefi-87000.json')), self::BASE);

        $closed = (new RechercheEntreprisesClient($http, new RecordingLogger()))->establishment('489 319 103 00011');

        self::assertNotNull($closed);
        self::assertFalse($closed->open);
        self::assertSame('2010-10-25', $closed->closedOn?->format('Y-m-d'));
    }

    public function testAnUnknownSiretIsNull(): void
    {
        $http = new MockHttpClient(new MockResponse('{"results":[],"total_results":0}'), self::BASE);

        self::assertNull((new RechercheEntreprisesClient($http, new RecordingLogger()))->establishment('48931910300037'));
    }

    public function testThrottlingIsAnOutage(): void
    {
        $http = new MockHttpClient(new MockResponse('{"erreur":"Too many requests"}', ['http_code' => 429]), self::BASE);

        $this->expectException(SireneUnavailableException::class);
        (new RechercheEntreprisesClient($http, new RecordingLogger()))->search('AUDEFI');
    }

    public function testAnUnreachableServiceIsAnOutage(): void
    {
        $this->expectException(SireneUnavailableException::class);
        (new RechercheEntreprisesClient(new MockHttpClient([], self::BASE), new RecordingLogger()))->search('AUDEFI');
    }

    public function testAnAnswerOfAnotherShapeIsRefusedAndReported(): void
    {
        $logger = new RecordingLogger();
        $http = new MockHttpClient(new MockResponse('{"etablissements":[]}'), self::BASE);

        try {
            (new RechercheEntreprisesClient($http, $logger))->search('AUDEFI');
            self::fail('An answer without results must not be read as « nothing found ».');
        } catch (SireneUnavailableException) {
        }

        $errors = array_filter($logger->records, static fn (array $record): bool => 'error' === $record['level'] && str_contains($record['message'], 'unrecognised answer'));
        self::assertCount(1, $errors);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/sirene/'.$name);
    }
}
