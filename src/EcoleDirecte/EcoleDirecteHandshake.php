<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * What École Directe expects to see again on the next call: its cookies (the GTK among them), the
 * GTK header value, the X-Token and the 2FA-Token.
 *
 * **Never the identifiant nor the password.** Those are only ever arguments of
 * App\EcoleDirecte\EcoleDirecteClient's login methods and die with the request; this object is what
 * outlives it, sealed and handed to the browser (App\EcoleDirecte\EcoleDirecteSessionSealer).
 *
 * Immutable, and passed by value from call to call rather than kept in the client: in FrankenPHP's
 * worker mode a service that remembered it would hand one teacher's connection to the next request.
 */
final readonly class EcoleDirecteHandshake
{
    /**
     * @param array<string, string> $cookies
     */
    public function __construct(
        public array $cookies = [],
        public ?string $gtk = null,
        public ?string $token = null,
        public ?string $twoFaToken = null,
    ) {
    }

    /** @param array<string, string> $cookies */
    public function withCookies(array $cookies): self
    {
        return new self([...$this->cookies, ...$cookies], $this->gtk, $this->token, $this->twoFaToken);
    }

    public function withGtk(?string $gtk): self
    {
        return new self($this->cookies, $gtk, $this->token, $this->twoFaToken);
    }

    public function withToken(?string $token): self
    {
        return new self($this->cookies, $this->gtk, $token, $this->twoFaToken);
    }

    public function withTwoFaToken(?string $twoFaToken): self
    {
        return new self($this->cookies, $this->gtk, $this->token, $twoFaToken);
    }

    /**
     * @return array{cookies: array<string, string>, gtk: ?string, token: ?string, twoFaToken: ?string}
     */
    public function toArray(): array
    {
        return ['cookies' => $this->cookies, 'gtk' => $this->gtk, 'token' => $this->token, 'twoFaToken' => $this->twoFaToken];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $cookies = [];
        if (\is_array($data['cookies'] ?? null)) {
            foreach ($data['cookies'] as $name => $value) {
                if (\is_string($name) && \is_string($value)) {
                    $cookies[$name] = $value;
                }
            }
        }

        return new self(
            $cookies,
            \is_string($data['gtk'] ?? null) ? $data['gtk'] : null,
            \is_string($data['token'] ?? null) ? $data['token'] : null,
            \is_string($data['twoFaToken'] ?? null) ? $data['twoFaToken'] : null,
        );
    }
}
