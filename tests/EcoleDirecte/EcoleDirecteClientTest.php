<?php

declare(strict_types=1);

namespace App\Tests\EcoleDirecte;

use App\EcoleDirecte\EcoleDirecteAccount;
use App\EcoleDirecte\EcoleDirecteClient;
use App\EcoleDirecte\EcoleDirecteException;
use App\EcoleDirecte\EcoleDirecteHandshake;
use App\EcoleDirecte\EcoleDirecteSession;
use App\EcoleDirecte\EcoleDirecteSessionExpiredException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The École Directe client against recorded answers: the GTK dance, the identity question, the
 * refusals, and the one rule that makes it a reading client - nothing but `verbe=get` leaves read().
 */
class EcoleDirecteClientTest extends TestCase
{
    private const string PASSWORD = 'Secret-ED-2026';

    /** @var list<array{method: string, url: string, body: string, headers: list<string>}> */
    private array $sent = [];

    public function testLoginFollowsTheGtkDanceAndOpensTheTeacherAccount(): void
    {
        $client = $this->client([
            $this->gtkAnswer(),
            new MockResponse($this->json(['code' => 200, 'token' => 'body-token', 'message' => '', 'data' => ['accounts' => [$this->teacherAccount()]]]), [
                'response_headers' => ['X-Token' => 'header-token'],
            ]),
        ]);

        $outcome = $client->login('prof.dupont', self::PASSWORD);

        self::assertTrue($outcome->isConnected());
        self::assertNotNull($outcome->session);
        self::assertSame('header-token', $outcome->session->handshake->token, 'the X-Token header wins over the envelope');
        self::assertSame('P', $outcome->session->account->type);
        self::assertTrue($outcome->session->account->isTeacher());
        self::assertSame([['id' => 44, 'code' => 'SIO1', 'label' => 'BTS SIO 1']], $outcome->session->account->classes);
        self::assertSame([['code' => 'INFO', 'label' => 'Informatique']], $outcome->session->account->subjects);

        self::assertStringContainsString('login.awp?gtk=1', $this->sent[0]['url']);
        self::assertContains('X-Gtk: gtk-value', $this->sent[1]['headers']);
        self::assertContains('Cookie: GTK=gtk-value', $this->sent[1]['headers']);
        $data = $this->dataOf($this->sent[1]['body']);
        self::assertSame('prof.dupont', $data['identifiant']);
        self::assertSame(self::PASSWORD, $data['motdepasse']);
    }

    public function testTheIdentityQuestionIsAskedThenAnsweredWithTheProofSentTwice(): void
    {
        $client = $this->client([
            $this->gtkAnswer(),
            new MockResponse($this->json(['code' => 250, 'token' => '', 'message' => '', 'data' => ['totp' => false]]), [
                'response_headers' => ['X-Token' => 'pending-token', '2FA-Token' => 'fa-token'],
            ]),
            new MockResponse($this->json(['code' => 200, 'token' => '', 'message' => '', 'data' => [
                'question' => base64_encode('Quelle est votre année de naissance ?'),
                'propositions' => [base64_encode('1980'), base64_encode('1981')],
            ]])),
        ]);

        $outcome = $client->login('prof.dupont', self::PASSWORD);

        self::assertFalse($outcome->isConnected());
        self::assertNotNull($outcome->pending);
        self::assertSame('Quelle est votre année de naissance ?', $outcome->question);
        self::assertSame([['label' => '1980', 'value' => base64_encode('1980')], ['label' => '1981', 'value' => base64_encode('1981')]], $outcome->choices);
        self::assertSame('pending-token', $outcome->pending->token);
        self::assertSame('fa-token', $outcome->pending->twoFaToken);
        self::assertStringContainsString('doubleauth.awp?verbe=get', $this->sent[2]['url']);
        self::assertSame('data=%7B%7D', $this->sent[2]['body'], 'the question is fetched with an empty object, not an empty list');

        $this->forgetSent();
        $client = $this->client([
            new MockResponse($this->json(['code' => 200, 'token' => '', 'message' => '', 'data' => ['cn' => 'CN64', 'cv' => 'CV64']])),
            $this->gtkAnswer(),
            new MockResponse($this->json(['code' => 200, 'token' => 'final-token', 'message' => '', 'data' => ['accounts' => [$this->teacherAccount()]]])),
        ]);

        $outcome = $client->answerChallenge('prof.dupont', self::PASSWORD, $outcome->pending, base64_encode('1980'));

        self::assertTrue($outcome->isConnected());
        self::assertSame('final-token', $outcome->session?->handshake->token);
        self::assertStringContainsString('doubleauth.awp?verbe=post', $this->sent[0]['url']);
        self::assertContains('X-Token: pending-token', $this->sent[0]['headers']);
        self::assertContains('2FA-Token: fa-token', $this->sent[0]['headers']);
        self::assertSame(['choix' => base64_encode('1980')], $this->dataOf($this->sent[0]['body']));

        $replay = $this->dataOf($this->sent[2]['body']);
        self::assertSame(self::PASSWORD, $replay['motdepasse']);
        self::assertSame('CN64', $replay['cn']);
        self::assertSame('CV64', $replay['cv']);
        self::assertSame([['cn' => 'CN64', 'cv' => 'CV64']], $replay['fa']);
    }

    public function testAWrongPasswordIsRefusedWithItsOwnMessage(): void
    {
        $client = $this->client([
            $this->gtkAnswer(),
            new MockResponse($this->json(['code' => 505, 'token' => '', 'message' => 'Identifiant et/ou mot de passe invalide !', 'data' => []])),
        ]);

        try {
            $client->login('prof.dupont', 'wrong');
            self::fail('A 505 must be refused.');
        } catch (EcoleDirecteException $exception) {
            self::assertSame('ecoleDirecteBadCredentialsMessage', $exception->getMessage());
            self::assertSame(505, $exception->apiCode);
        }
    }

    public function testTheAuthenticatorAppIsRefusedRatherThanGuessedAt(): void
    {
        $client = $this->client([
            $this->gtkAnswer(),
            new MockResponse($this->json(['code' => 250, 'token' => 't', 'message' => '', 'data' => ['totp' => true]])),
        ]);

        $this->expectExceptionObject(new EcoleDirecteException('ecoleDirecteTotpUnsupportedMessage'));
        $client->login('prof.dupont', self::PASSWORD);
    }

    public function testAnAnswerThatIsNotJsonIsRefused(): void
    {
        $client = $this->client([$this->gtkAnswer(), new MockResponse('<html>maintenance</html>')]);

        $this->expectExceptionObject(new EcoleDirecteException('ecoleDirecteUnexpectedAnswerMessage'));
        $client->login('prof.dupont', self::PASSWORD);
    }

    public function testNoCredentialLeaksIntoTheExceptionOfAFailedLogin(): void
    {
        $client = $this->client([$this->gtkAnswer(), new MockResponse('', ['error' => 'Connection refused'])]);

        try {
            $client->login('prof.dupont', self::PASSWORD);
            self::fail('A transport failure must be refused.');
        } catch (EcoleDirecteException $exception) {
            self::assertSame('ecoleDirecteUnreachableMessage', $exception->getMessage());
            for ($cause = $exception; null !== $cause; $cause = $cause->getPrevious()) {
                self::assertStringNotContainsString(self::PASSWORD, $cause->getMessage());
                self::assertStringNotContainsString(self::PASSWORD, $cause->getTraceAsString());
            }
        }
    }

    public function testAReadForcesVerbeGetGoesToTheTeacherHostAndPicksUpTheFreshToken(): void
    {
        $client = $this->client([
            new MockResponse($this->json(['code' => 200, 'token' => '', 'message' => '', 'data' => ['x' => 1]]), [
                'response_headers' => ['X-Token' => 'rotated'],
            ]),
        ]);

        $result = $client->read($this->teacherSession(), 'cahierdetexte/loadslots/2026-09-21/2026-09-27.awp', ['verbe' => 'put', 'v' => 'old']);

        self::assertSame(['x' => 1], $result->data);
        self::assertSame('rotated', $result->session->handshake->token);
        self::assertStringStartsWith('https://apip.example/v3/cahierdetexte/loadslots/2026-09-21/2026-09-27.awp?', $this->sent[0]['url']);
        self::assertStringContainsString('verbe=get', $this->sent[0]['url']);
        self::assertStringNotContainsString('verbe=put', $this->sent[0]['url']);
        self::assertStringContainsString('v=4.101.4', $this->sent[0]['url']);
        self::assertContains('X-Token: live-token', $this->sent[0]['headers']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePaths(): iterable
    {
        yield 'a query of its own' => ['cahierdetexte.awp?verbe=post'];
        yield 'climbing out' => ['../v3/login.awp'];
        yield 'absolute' => ['/v3/login.awp'];
        yield 'not a route' => ['cahierdetexte'];
        yield 'another host' => ['https://evil.example/x.awp'];
    }

    #[DataProvider('unreadablePaths')]
    public function testAPathThatCouldBeMoreThanAReadIsRefusedBeforeAnyCall(string $path): void
    {
        $client = $this->client([]);

        try {
            $client->read($this->teacherSession(), $path);
            self::fail('The path must be refused.');
        } catch (EcoleDirecteException $exception) {
            self::assertSame('ecoleDirecteInvalidPathMessage', $exception->getMessage());
        }
        self::assertSame([], $this->sent);
    }

    public function testADeadTokenIsTheExpiredSession(): void
    {
        $client = $this->client([new MockResponse($this->json(['code' => 525, 'token' => '', 'message' => 'Token expiré', 'data' => []]))]);

        $this->expectException(EcoleDirecteSessionExpiredException::class);
        $client->read($this->teacherSession(), 'salles.awp');
    }

    public function testTheProbeNeedsNothingButTheGtkCookie(): void
    {
        self::assertTrue($this->client([$this->gtkAnswer()])->probe());
        self::assertFalse($this->client([new MockResponse('')])->probe());
    }

    private function forgetSent(): void
    {
        $this->sent = [];
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): EcoleDirecteClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $headers = [];
            foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $header) {
                if (\is_string($header)) {
                    $headers[] = $header;
                }
            }
            $this->sent[] = ['method' => $method, 'url' => $url, 'body' => \is_string($options['body'] ?? null) ? $options['body'] : '', 'headers' => $headers];

            return array_shift($responses) ?? throw new \LogicException('No answer recorded for '.$url);
        });

        return new EcoleDirecteClient($http, new NullLogger(), 'https://api.example', 'https://apip.example', '4.101.4');
    }

    private function gtkAnswer(): MockResponse
    {
        return new MockResponse('', ['response_headers' => ['Set-Cookie' => 'GTK=gtk-value; path=/; secure']]);
    }

    /** @return array<string, mixed> */
    private function teacherAccount(): array
    {
        return [
            'id' => 1234,
            'typeCompte' => 'P',
            'main' => true,
            'prenom' => 'Jeanne',
            'nom' => 'DUPONT',
            'nomEtablissement' => 'Institution',
            'anneeScolaireCourante' => '2026-2027',
            'profile' => [
                'classes' => [['id' => 44, 'code' => 'SIO1', 'libelle' => 'BTS SIO 1']],
                'matieres' => [['code' => 'INFO', 'libelle' => 'Informatique']],
            ],
        ];
    }

    private function teacherSession(): EcoleDirecteSession
    {
        return new EcoleDirecteSession(
            new EcoleDirecteHandshake(['GTK' => 'g'], 'g', 'live-token'),
            new EcoleDirecteAccount(1234, 'P', 'Jeanne', 'DUPONT'),
        );
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR);
    }

    /** @return array<array-key, mixed> */
    private function dataOf(string $body): array
    {
        self::assertStringStartsWith('data=', $body);
        $decoded = json_decode(rawurldecode(substr($body, 5)), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
