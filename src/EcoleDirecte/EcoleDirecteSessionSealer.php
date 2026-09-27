<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Entity\User;
use Symfony\Component\Clock\ClockInterface;

/**
 * Hands an École Directe connection to the browser instead of keeping it.
 *
 * A connection spans several requests - the identity question, then each read - and something has to
 * carry it from one to the next. The platform promised teachers it would keep nothing of their École
 * Directe access, so that something is not the database and not the PHP session: it is this sealed
 * string, which the page holds in a JavaScript variable and sends back with each call. Closing the
 * tab forgets it.
 *
 * What is sealed is the handshake (cookies, GTK, tokens) and the account description - never the
 * identifiant nor the password, which this class never sees.
 *
 * The seal is XSalsa20-Poly1305 (ext-sodium), keyed by a key derived from APP_SECRET under a label of
 * its own. Deriving rather than adding a variable is deliberate: what is sealed lives ten minutes, so
 * rotating APP_SECRET costs at most a teacher signing in again, and there is no new secret to carry
 * across deployments. It is bound to the MonCampus account that opened it, so a string lifted from
 * one teacher's page opens nothing in another's, and it is **sliding**: each read seals it again with
 * a fresh ten minutes.
 */
class EcoleDirecteSessionSealer
{
    public const int TTL_SECONDS = 600;

    private const string KIND_SESSION = 'session';
    private const string KIND_PENDING = 'pending';

    private readonly string $key;

    public function __construct(
        #[\SensitiveParameter] string $appSecret,
        private readonly ClockInterface $clock,
    ) {
        if ('' === $appSecret) {
            throw new \LogicException('APP_SECRET is empty: École Directe connections cannot be sealed.');
        }

        $this->key = hash_hkdf('sha256', $appSecret, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'moncampus-ecoledirecte-session');
    }

    public function sealSession(EcoleDirecteSession $session, User $owner): string
    {
        return $this->seal(self::KIND_SESSION, [
            'handshake' => $session->handshake->toArray(),
            'account' => $session->account->toArray(),
        ], $owner);
    }

    /** @throws EcoleDirecteSessionExpiredException */
    public function openSession(string $sealed, User $owner): EcoleDirecteSession
    {
        $data = $this->open(self::KIND_SESSION, $sealed, $owner);
        $account = \is_array($data['account'] ?? null) ? EcoleDirecteAccount::fromArray($data['account']) : null;

        if (null === $account || !\is_array($data['handshake'] ?? null)) {
            throw new EcoleDirecteSessionExpiredException();
        }

        return new EcoleDirecteSession(EcoleDirecteHandshake::fromArray($data['handshake']), $account);
    }

    /**
     * The half-way state of a login stopped on the identity question, with the propositions it
     * offered - so that the answer sent back can be checked against them rather than trusted.
     *
     * @param list<string> $choices
     */
    public function sealPending(EcoleDirecteHandshake $handshake, array $choices, User $owner): string
    {
        return $this->seal(self::KIND_PENDING, ['handshake' => $handshake->toArray(), 'choices' => $choices], $owner);
    }

    /**
     * @return array{handshake: EcoleDirecteHandshake, choices: list<string>}
     *
     * @throws EcoleDirecteSessionExpiredException
     */
    public function openPending(string $sealed, User $owner): array
    {
        $data = $this->open(self::KIND_PENDING, $sealed, $owner);

        if (!\is_array($data['handshake'] ?? null) || !\is_array($data['choices'] ?? null)) {
            throw new EcoleDirecteSessionExpiredException();
        }

        return [
            'handshake' => EcoleDirecteHandshake::fromArray($data['handshake']),
            'choices' => array_values(array_filter($data['choices'], 'is_string')),
        ];
    }

    /** @param array<string, mixed> $data */
    private function seal(string $kind, array $data, User $owner): string
    {
        $payload = json_encode([
            'k' => $kind,
            'u' => $owner->getId() ?? throw new \LogicException('An École Directe connection needs a persisted user.'),
            'e' => $this->clock->now()->getTimestamp() + self::TTL_SECONDS,
            'd' => $data,
        ], \JSON_THROW_ON_ERROR);

        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return sodium_bin2base64($nonce.sodium_crypto_secretbox($payload, $nonce, $this->key), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws EcoleDirecteSessionExpiredException
     */
    private function open(string $kind, string $sealed, User $owner): array
    {
        try {
            $raw = sodium_base642bin($sealed, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            throw new EcoleDirecteSessionExpiredException();
        }

        if (\strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new EcoleDirecteSessionExpiredException();
        }

        $payload = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        $decoded = false === $payload ? null : json_decode($payload, true);

        if (!\is_array($decoded)
            || $kind !== ($decoded['k'] ?? null)
            || $owner->getId() !== ($decoded['u'] ?? null)
            || !\is_int($decoded['e'] ?? null)
            || $decoded['e'] < $this->clock->now()->getTimestamp()
            || !\is_array($decoded['d'] ?? null)) {
            throw new EcoleDirecteSessionExpiredException();
        }

        return $decoded['d'];
    }
}
