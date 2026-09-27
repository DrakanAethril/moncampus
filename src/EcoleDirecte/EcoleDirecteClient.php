<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * École Directe's private API - the one its own website and mobile app call. Aplim publishes no
 * API, so everything here follows what those clients send, as documented by the community
 * (github.com/EduWireApps/ecoledirecte-api-docs) and observed on teacher accounts.
 *
 * **Two doors, and no third.** read() forces `verbe=get` whatever it is given. send() is the only
 * way anything is written, and it knows two verbs - `put` (replace what a cahier de texte slot says)
 * and `post` (create an evaluation, enter its grades) - never `delete`: the platform does not remove
 * anything from École Directe. Those guarantees live here, in the only class that talks to École
 * Directe, rather than in a screen that could forget them. (The answer to the identity question also
 * carries `verbe=post`; it is part of signing in and has its own method.)
 *
 * **Stateless.** The identifiant and the password are arguments of login() and answerChallenge() and
 * are never kept: not in a property, not in a log line, not in an exception (every parameter that
 * carries them is a SensitiveParameter). What has to outlive a request is the handshake, and it goes
 * back to the caller by value - in worker mode a property would carry one teacher's connection into
 * the next person's request.
 *
 * École Directe changes this protocol without notice (the GTK cookie appeared in March 2025). So an
 * answer this client does not recognise is logged at error level - which reaches Discord in
 * production - and refused, never guessed at.
 */
class EcoleDirecteClient
{
    /**
     * École Directe binds its token to the User-Agent that obtained it, so every call sends the
     * same one.
     */
    private const string USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const int TIMEOUT_SECONDS = 20;

    private const int CODE_OK = 200;
    private const int CODE_IDENTITY_QUESTION = 250;
    private const int CODE_BAD_CREDENTIALS = 505;
    private const array CODES_BLOCKED = [516, 535];
    private const int CODE_UNAVAILABLE = 517;
    private const int CODE_CHARTER = 520;
    private const array CODES_TOKEN_DEAD = [520, 521, 525];

    private const array SEND_VERBS = ['put', 'post'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $ecoleDirecteApiUrl,
        private readonly string $ecoleDirecteTeacherApiUrl,
        private readonly string $ecoleDirecteAppVersion,
    ) {
    }

    /**
     * Sign in. Either connected, or stopped on the identity question École Directe asks a device it
     * does not know - in which case the caller shows it and comes back through answerChallenge().
     *
     * @throws EcoleDirecteException
     */
    public function login(string $identifiant, #[\SensitiveParameter] string $password): EcoleDirecteLoginOutcome
    {
        $handshake = $this->bootstrap(new EcoleDirecteHandshake());

        [$body, $handshake] = $this->post($this->apiUrl('login.awp', []), [
            'identifiant' => $identifiant,
            'motdepasse' => $password,
            'isReLogin' => false,
            'uuid' => '',
            'fa' => [],
        ], $handshake, true);

        return $this->settleLogin($body, $handshake);
    }

    /**
     * Answer the identity question, then sign in again with the proof it returns.
     *
     * The password has to come back for that second sign-in - École Directe replays the whole login
     * - and it does: the browser still holds what the teacher typed, and sends it again with the
     * answer. The server never had to keep it between the two requests.
     *
     * École Directe's website sends the proof twice, at the top of the login and inside `fa`; sending
     * it only in `fa` gets a correct answer refused as a bad password.
     *
     * @throws EcoleDirecteException
     */
    public function answerChallenge(string $identifiant, #[\SensitiveParameter] string $password, EcoleDirecteHandshake $pending, string $choice): EcoleDirecteLoginOutcome
    {
        [$answer, $handshake] = $this->post($this->apiUrl('connexion/doubleauth.awp', ['verbe' => 'post']), ['choix' => $choice], $pending, false);

        $proof = \is_array($answer['data'] ?? null) ? $answer['data'] : [];
        $cn = $proof['cn'] ?? null;
        $cv = $proof['cv'] ?? null;

        if (self::CODE_OK !== self::code($answer) || !\is_string($cn) || !\is_string($cv) || '' === $cn || '' === $cv) {
            throw new EcoleDirecteException('ecoleDirecteWrongAnswerMessage', self::code($answer), self::message($answer));
        }

        $handshake = $this->bootstrap($handshake);

        [$body, $handshake] = $this->post($this->apiUrl('login.awp', []), [
            'identifiant' => $identifiant,
            'motdepasse' => $password,
            'isReLogin' => false,
            'cn' => $cn,
            'cv' => $cv,
            'uuid' => '',
            'fa' => [['cn' => $cn, 'cv' => $cv]],
        ], $handshake, true);

        return $this->settleLogin($body, $handshake);
    }

    /**
     * One read. The path is relative to the account's API host (`cahierdetexte/loadslots/…awp`),
     * and whatever query it comes with, `verbe` leaves as `get`.
     *
     * @param array<string, string>         $query
     * @param array<string, mixed>|null     $body  a few routes want parameters in the body; none
     *                                             of them can turn a get into anything else
     *
     * @throws EcoleDirecteException
     */
    public function read(EcoleDirecteSession $session, string $path, array $query = [], ?array $body = null): EcoleDirecteResult
    {
        if (!self::isRoutePath($path)) {
            throw new EcoleDirecteException('ecoleDirecteInvalidPathMessage');
        }

        [$answer, $handshake] = $this->post($this->accountUrl($session, $path, [...$query, 'verbe' => 'get']), $body ?? new \stdClass(), $session->handshake, false);
        $code = self::code($answer);

        if (\in_array($code, self::CODES_TOKEN_DEAD, true)) {
            throw new EcoleDirecteSessionExpiredException($code);
        }

        if (self::CODE_OK !== $code) {
            $this->logger->warning('École Directe refused a read with code {code} on {path}.', ['code' => $code, 'path' => $path]);

            throw new EcoleDirecteException('ecoleDirecteReadRefusedMessage', $code, self::message($answer));
        }

        return new EcoleDirecteResult($answer['data'] ?? null, $session->withHandshake($handshake));
    }

    /**
     * One write, on the account's API host. `put` or `post` only - see the class docblock.
     *
     * The answer's code is checked here, so a caller never mistakes a refusal for a success: École
     * Directe answers HTTP 200 with a code 5xx in the body when it refuses.
     *
     * @param array<string, mixed> $body
     *
     * @throws EcoleDirecteException
     */
    public function send(EcoleDirecteSession $session, string $path, string $verbe, array $body): EcoleDirecteResult
    {
        if (!\in_array($verbe, self::SEND_VERBS, true)) {
            throw new \LogicException(\sprintf('École Directe is never sent "%s".', $verbe));
        }

        if (!self::isRoutePath($path)) {
            throw new EcoleDirecteException('ecoleDirecteInvalidPathMessage');
        }

        [$answer, $handshake] = $this->post($this->accountUrl($session, $path, ['verbe' => $verbe]), $body, $session->handshake, false);
        $code = self::code($answer);

        if (\in_array($code, self::CODES_TOKEN_DEAD, true)) {
            throw new EcoleDirecteSessionExpiredException($code);
        }

        if (self::CODE_OK !== $code) {
            $this->logger->warning('École Directe refused a write with code {code} on {path}.', ['code' => $code, 'path' => $path]);

            throw new EcoleDirecteException('ecoleDirecteSendRefusedMessage', $code, self::message($answer));
        }

        return new EcoleDirecteResult($answer['data'] ?? null, $session->withHandshake($handshake));
    }

    /**
     * The GTK dance, on its own: whether École Directe still hands out the cookie its login
     * requires. Needs no account, which is what makes it the diagnostic of app:ecoledirecte:check.
     *
     * @throws EcoleDirecteException
     */
    public function probe(): bool
    {
        $handshake = $this->bootstrap(new EcoleDirecteHandshake());

        return null !== $handshake->gtk && '' !== $handshake->gtk;
    }

    /**
     * A path read() and send() accept: relative, ending in `.awp`, no `..`, no query of its own. The
     * query is theirs to build, so nobody can slip a `verbe` into the path.
     */
    public static function isRoutePath(string $path): bool
    {
        return 1 === preg_match('#^[\pL\pN¤%_\-.][\pL\pN¤%_\-./ ]*\.awp$#u', $path) && !str_contains($path, '..');
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws EcoleDirecteException
     */
    private function settleLogin(array $body, EcoleDirecteHandshake $handshake): EcoleDirecteLoginOutcome
    {
        $code = self::code($body);
        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];

        if (self::CODE_OK === $code) {
            $account = EcoleDirecteAccount::fromLoginData($data);
            if (null === $account || null === $handshake->token || '' === $handshake->token) {
                $this->logger->error('École Directe accepted a login but its answer carries no readable account or token.');

                throw new EcoleDirecteException('ecoleDirecteUnexpectedAnswerMessage', $code);
            }

            return EcoleDirecteLoginOutcome::connected(new EcoleDirecteSession($handshake, $account));
        }

        if (self::CODE_IDENTITY_QUESTION === $code) {
            if (true === ($data['totp'] ?? null)) {
                throw new EcoleDirecteException('ecoleDirecteTotpUnsupportedMessage', $code);
            }

            return $this->fetchQuestion($handshake);
        }

        if (self::CODE_BAD_CREDENTIALS === $code) {
            throw new EcoleDirecteException('ecoleDirecteBadCredentialsMessage', $code);
        }

        if (\in_array($code, self::CODES_BLOCKED, true)) {
            throw new EcoleDirecteException('ecoleDirecteAccountBlockedMessage', $code, self::message($body));
        }

        if (self::CODE_CHARTER === $code) {
            throw new EcoleDirecteException('ecoleDirecteCharterMessage', $code);
        }

        // 517 is École Directe's own "maintenance / version refused": the second is the one that
        // means ECOLEDIRECTE_APP_VERSION has fallen behind, so it is worth an alert either way.
        $this->logger->error('École Directe refused a login with code {code}.', ['code' => $code]);

        throw new EcoleDirecteException(self::CODE_UNAVAILABLE === $code ? 'ecoleDirecteUnavailableMessage' : 'ecoleDirecteUnexpectedAnswerMessage', $code, self::message($body));
    }

    /** @throws EcoleDirecteException */
    private function fetchQuestion(EcoleDirecteHandshake $handshake): EcoleDirecteLoginOutcome
    {
        [$body, $handshake] = $this->post($this->apiUrl('connexion/doubleauth.awp', ['verbe' => 'get']), new \stdClass(), $handshake, false);

        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];
        $question = self::decodeBase64($data['question'] ?? null);

        $choices = [];
        foreach (\is_array($data['propositions'] ?? null) ? $data['propositions'] : [] as $value) {
            if (\is_string($value) && '' !== $value) {
                $choices[] = ['label' => self::decodeBase64($value), 'value' => $value];
            }
        }

        if (self::CODE_OK !== self::code($body) || '' === $question || [] === $choices) {
            $this->logger->error('École Directe asked its identity question but the question could not be read (code {code}).', ['code' => self::code($body)]);

            throw new EcoleDirecteException('ecoleDirecteUnexpectedAnswerMessage', self::code($body), self::message($body));
        }

        return EcoleDirecteLoginOutcome::challenged($handshake, $question, $choices);
    }

    /**
     * `GET login.awp?gtk=1`: École Directe sets the GTK cookie its login echoes back in `X-Gtk`.
     * The cached header value is dropped first so it cannot outlive the cookie it came from.
     *
     * @throws EcoleDirecteException
     */
    private function bootstrap(EcoleDirecteHandshake $handshake): EcoleDirecteHandshake
    {
        $handshake = $handshake->withGtk(null);

        try {
            $response = $this->httpClient->request('GET', $this->apiUrl('login.awp', ['gtk' => '1']), [
                'headers' => $this->headers($handshake, false),
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new EcoleDirecteException('ecoleDirecteUnreachableMessage', null, '', $exception);
        }

        $handshake = $this->absorb($handshake, $headers);

        if (null === $handshake->gtk && isset($handshake->cookies['GTK'])) {
            $handshake = $handshake->withGtk($handshake->cookies['GTK']);
        }

        // Some answers carry it in the body instead of a cookie.
        $body = json_decode($content, true);
        if (null === $handshake->gtk && \is_array($body) && \is_string($body['token'] ?? null) && '' !== $body['token']) {
            $handshake = $handshake->withGtk($body['token']);
        }

        return $handshake;
    }

    /**
     * École Directe's one shape of request: a form post whose single field `data` holds JSON.
     *
     * @param array<string, mixed>|\stdClass $data
     *
     * @return array{0: array<array-key, mixed>, 1: EcoleDirecteHandshake}
     *
     * @throws EcoleDirecteException
     */
    private function post(string $url, #[\SensitiveParameter] array|\stdClass $data, EcoleDirecteHandshake $handshake, bool $withGtk): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [...$this->headers($handshake, $withGtk), 'Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => 'data='.rawurlencode(json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)),
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $headers = $response->getHeaders(false);
            $content = $response->getContent(false);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new EcoleDirecteException('ecoleDirecteUnreachableMessage', null, '', $exception);
        }

        $handshake = $this->absorb($handshake, $headers);
        $body = json_decode($content, true);

        if (!\is_array($body)) {
            $this->logger->error('École Directe answered HTTP {status} without JSON on {path}.', ['status' => $status, 'path' => parse_url($url, \PHP_URL_PATH)]);

            throw new EcoleDirecteException('ecoleDirecteUnexpectedAnswerMessage');
        }

        // The envelope's own token is the fallback when no X-Token header came back.
        if (!isset($headers['x-token']) && \is_string($body['token'] ?? null) && '' !== $body['token']) {
            $handshake = $handshake->withToken($body['token']);
        }

        return [$body, $handshake];
    }

    /** @return array<string, string> */
    private function headers(EcoleDirecteHandshake $handshake, bool $withGtk): array
    {
        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'application/json, text/plain, */*',
        ];

        if ([] !== $handshake->cookies) {
            $headers['Cookie'] = implode('; ', array_map(
                static fn (string $name, string $value): string => $name.'='.$value,
                array_keys($handshake->cookies),
                $handshake->cookies,
            ));
        }

        $gtk = $handshake->gtk ?? $handshake->cookies['GTK'] ?? null;
        if ($withGtk && null !== $gtk) {
            $headers['X-Gtk'] = $gtk;
        }
        if (null !== $handshake->token) {
            $headers['X-Token'] = $handshake->token;
        }
        if (null !== $handshake->twoFaToken) {
            $headers['2FA-Token'] = $handshake->twoFaToken;
        }

        return $headers;
    }

    /**
     * Fold an answer's cookies and tokens into the handshake.
     *
     * @param array<string, list<string>> $headers
     */
    private function absorb(EcoleDirecteHandshake $handshake, array $headers): EcoleDirecteHandshake
    {
        $cookies = [];
        foreach ($headers['set-cookie'] ?? [] as $line) {
            $pair = explode(';', $line, 2)[0];
            $separator = strpos($pair, '=');
            if (false !== $separator && $separator > 0) {
                $cookies[trim(substr($pair, 0, $separator))] = trim(substr($pair, $separator + 1));
            }
        }
        if ([] !== $cookies) {
            $handshake = $handshake->withCookies($cookies);
        }

        if ('' !== ($headers['x-gtk'][0] ?? '')) {
            $handshake = $handshake->withGtk($headers['x-gtk'][0]);
        }
        if ('' !== ($headers['x-token'][0] ?? '')) {
            $handshake = $handshake->withToken($headers['x-token'][0]);
        }
        if ('' !== ($headers['2fa-token'][0] ?? '')) {
            $handshake = $handshake->withTwoFaToken($headers['2fa-token'][0]);
        }

        return $handshake;
    }

    /**
     * A route on the account's own host - teacher accounts are answered by one of their own.
     *
     * @param array<string, string> $query
     */
    private function accountUrl(EcoleDirecteSession $session, string $path, array $query): string
    {
        $base = $session->account->isTeacher() ? $this->ecoleDirecteTeacherApiUrl : $this->ecoleDirecteApiUrl;

        return rtrim($base, '/').'/v3/'.$path.'?'.http_build_query([...$query, 'v' => $this->ecoleDirecteAppVersion]);
    }

    /** @param array<string, string> $query */
    private function apiUrl(string $path, array $query): string
    {
        return rtrim($this->ecoleDirecteApiUrl, '/').'/v3/'.$path.'?'.http_build_query([...$query, 'v' => $this->ecoleDirecteAppVersion]);
    }

    /** @param array<array-key, mixed> $body */
    private static function code(array $body): ?int
    {
        return \is_int($body['code'] ?? null) ? $body['code'] : null;
    }

    /** @param array<array-key, mixed> $body */
    private static function message(array $body): string
    {
        return \is_string($body['message'] ?? null) ? trim($body['message']) : '';
    }

    private static function decodeBase64(mixed $value): string
    {
        if (!\is_string($value) || '' === $value) {
            return '';
        }

        $decoded = base64_decode($value, true);

        return false === $decoded ? '' : trim($decoded);
    }
}
