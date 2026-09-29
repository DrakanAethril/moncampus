<?php

declare(strict_types=1);

namespace App\Tests\Service\Sirene;

use App\Entity\Enterprise;
use App\Enum\SiretEvidence;
use App\Repository\EnterpriseRepository;
use App\Service\Sirene\RankedCandidate;
use App\Service\Sirene\RechercheEntreprisesClient;
use App\Service\Sirene\SiretCandidateFinder;
use App\Tests\Double\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The cascade replayed on the measure of 2026-09-29 (design/validated/siret-entreprises.md, §2):
 * the employers of the CFC file, each with what a person found on reading the candidates, and the
 * État's answers recorded that day (sole traders taken out - they are people).
 *
 * A query the recording does not hold fails the test: the cascade asking something new is a
 * change to look at, not a gap to fill with an empty answer.
 */
class SiretCandidateFinderTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/sirene/cascade-2026-09-29.json';

    private static ?array $recording = null;

    private int $calls = 0;

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function measuredEmployers(): iterable
    {
        foreach (self::recording()['cases'] as $index => $case) {
            yield \sprintf('%02d %s', $index, $case['name']) => [$case['name'], $case['address'], $case['review'], $case['sirets']];
        }
    }

    /** @param list<string> $sirets */
    #[DataProvider('measuredEmployers')]
    public function testTheCascadeFindsWhatThePersonFound(string $name, string $address, string $review, array $sirets): void
    {
        $candidates = $this->finder()->propose(new Enterprise($name, $address));
        $found = array_map(static fn (RankedCandidate $c): string => $c->establishment->siret, $candidates);

        match ($review) {
            // The right establishment first - and it still only is a proposal.
            'ok' => self::assertSame($sirets[0], $found[0] ?? null),
            // The right company is there, the establishment is for a person to choose.
            'choose' => self::assertNotEmpty(array_intersect($sirets, $found)),
            // What failed on 2026-09-29 is never presented as a sure thing.
            'failure' => self::assertNotContains(SiretEvidence::SameAddress, ($candidates[0] ?? null)?->evidence() ?? []),
            default => self::fail('Unknown review: '.$review),
        };
    }

    public function testTheFalsePositiveIsListedWithoutAnAddressBadge(): void
    {
        $candidates = $this->finder()->propose(new Enterprise('ACOM LIMOGES SAS', "2 AVENUE DU PRESIDENT J. KENNEDY\nLES BUREAUX DU GOLF\n87000-LIMOGES"));

        self::assertSame('45239671600030', $candidates[0]->establishment->siret, 'Cabinet Robert, found on the word « ACOM ».');
        self::assertSame([SiretEvidence::SamePostalCode], $candidates[0]->evidence());
    }

    public function testTwoEstablishmentsOfOneCompanyAreToldApart(): void
    {
        $candidates = $this->finder()->propose(new Enterprise('AUDEFI EXPERTISE-COMPTABLE', "16 RUE BERNARD LATHIERE\n87000-LIMOGES"));

        self::assertSame('48931910300037', $candidates[0]->establishment->siret);
        self::assertSame([SiretEvidence::SameAddress], $candidates[0]->evidence());
        self::assertSame('48931910300029', $candidates[1]->establishment->siret);
        self::assertSame([SiretEvidence::SamePostalCode, SiretEvidence::SameCompanyElsewhere], $candidates[1]->evidence());
    }

    public function testEveryEstablishmentAtTheAddressIsOffered(): void
    {
        $candidates = $this->finder()->propose(new Enterprise('LIMOGES METROPOLE', "19, rue Bernard Palissy\n87031-LIMOGES CEDEX 1"));
        $atTheAddress = [];
        foreach ($candidates as $candidate) {
            // A CEDEX postcode is a sorting office's: the département is the place.
            if ('248719312' === $candidate->establishment->siren && \in_array(SiretEvidence::SameAddress, $candidate->evidence(), true)) {
                $atTheAddress[] = $candidate->establishment->siret;
            }
        }

        // The five of the measure, at least - nine on the day of the recording.
        self::assertSame([], array_diff(['24871931200022', '24871931200030', '24871931200048', '24871931200055', '24871931200063'], $atTheAddress));
    }

    public function testTheCascadeStaysWithinTheMeasuredBudget(): void
    {
        $finder = $this->finder();
        foreach (self::recording()['cases'] as $case) {
            $finder->propose(new Enterprise($case['name'], $case['address']));
        }

        // 136 calls for the 47 employers of the measure: about three each.
        self::assertLessThanOrEqual(3 * \count(self::recording()['cases']), $this->calls);
    }

    private function finder(): SiretCandidateFinder
    {
        $answers = self::recording()['answers'];
        $http = new MockHttpClient(function (string $method, string $url) use ($answers): MockResponse {
            ++$this->calls;
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            ksort($query);
            $key = http_build_query($query);

            self::assertArrayHasKey($key, $answers, 'Not in the recording: '.$key);

            return new MockResponse((string) json_encode($answers[$key]));
        }, 'https://recherche-entreprises.api.gouv.fr');

        $enterprises = $this->createStub(EnterpriseRepository::class);
        $enterprises->method('findOthersBySiret')->willReturn([]);

        return new SiretCandidateFinder(new RechercheEntreprisesClient($http, new RecordingLogger()), $enterprises);
    }

    /** @return array{cases: list<array{name: string, address: string, review: string, sirets: list<string>}>, answers: array<string, mixed>} */
    private static function recording(): array
    {
        return self::$recording ??= json_decode((string) file_get_contents(self::FIXTURE), true, flags: \JSON_THROW_ON_ERROR);
    }
}
