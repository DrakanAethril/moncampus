<?php

declare(strict_types=1);

namespace App\Tests\EcoleDirecte;

use App\EcoleDirecte\EcoleDirecteAccount;
use App\EcoleDirecte\EcoleDirecteClient;
use App\EcoleDirecte\EcoleDirecteException;
use App\EcoleDirecte\EcoleDirecteHandshake;
use App\EcoleDirecte\EcoleDirecteLessonLogReader;
use App\EcoleDirecte\EcoleDirecteSession;
use App\Service\HtmlPlainText;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A teacher's École Directe cahier de texte, read into plain rows: base64 HTML decoded then
 * stripped, slots in time order, and only for a teacher account over a bounded span.
 */
class EcoleDirecteLessonLogReaderTest extends TestCase
{
    private ?string $url = null;

    public function testSlotsAreReadInTimeOrderAsPlainText(): void
    {
        $reader = $this->reader([
            [
                'idCours' => 2, 'date' => '2026-09-22', 'start_date' => '2026-09-22 14:00', 'end_date' => '2026-09-22 16:00',
                'entityLibelle' => 'BTS SIO 1', 'matiereLibelle' => 'Informatique', 'salle' => 'B12',
                'travailAFaire' => true,
            ],
            [
                'idCours' => 1, 'date' => '2026-09-22', 'start_date' => '2026-09-22 08:00', 'end_date' => '2026-09-22 10:00',
                'entityLibelle' => 'BTS SIO 1', 'matiereLibelle' => 'Informatique', 'interrogation' => 1,
                'seance' => ['contenu' => base64_encode('<p>Les r&eacute;seaux <script>alert(1)</script>locaux</p>')],
                'aFaire' => ['contenu' => base64_encode('<p>Exercice 3</p>')],
            ],
        ]);

        $read = $reader->read($this->session('P'), new \DateTimeImmutable('2026-09-21'), new \DateTimeImmutable('2026-09-27'));

        self::assertStringContainsString('/v3/cahierdetexte/loadslots/2026-09-21/2026-09-27.awp?', (string) $this->url);
        [$first, $second] = $read['slots'];

        self::assertSame('08:00', $first->start);
        self::assertSame('10:00', $first->end);
        self::assertSame('2026-09-22', $first->date?->format('Y-m-d'));
        self::assertStringContainsString('Les réseaux', $first->content);
        self::assertStringNotContainsString('<', $first->content);
        self::assertSame('Exercice 3', $first->homework);
        self::assertTrue($first->test);

        self::assertSame('14:00', $second->start);
        self::assertSame('B12', $second->room);
        self::assertSame('', $second->homework);
        self::assertTrue($second->hasHomework, 'a slot flagged without its text still says something was written');
        self::assertFalse($second->hasContent);
    }

    public function testOnlyATeacherAccountHasACahierDeTexte(): void
    {
        $this->expectExceptionObject(new EcoleDirecteException('ecoleDirecteNotTeacherMessage'));
        $this->reader([])->read($this->session('E'), new \DateTimeImmutable('2026-09-21'), new \DateTimeImmutable('2026-09-27'));
    }

    public function testASpanPastAMonthIsRefused(): void
    {
        $this->expectExceptionObject(new EcoleDirecteException('ecoleDirecteInvalidSpanMessage'));
        $this->reader([])->read($this->session('P'), new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-12-01'));
    }

    /** @param list<array<string, mixed>> $slots */
    private function reader(array $slots): EcoleDirecteLessonLogReader
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($slots): MockResponse {
            $this->url = $url;

            return new MockResponse(json_encode(['code' => 200, 'token' => '', 'message' => '', 'data' => $slots], \JSON_THROW_ON_ERROR));
        });

        return new EcoleDirecteLessonLogReader(
            new EcoleDirecteClient($http, new NullLogger(), 'https://api.example', 'https://apip.example', '4.101.4'),
            new HtmlPlainText(),
        );
    }

    private function session(string $type): EcoleDirecteSession
    {
        return new EcoleDirecteSession(new EcoleDirecteHandshake([], null, 'tok'), new EcoleDirecteAccount(1, $type, 'Jeanne', 'DUPONT'));
    }
}
