<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * Reads the certificate a host presents, without verifying it.
 *
 * That sounds like the thing the TLS section exists to forbid, and it is the opposite: this never
 * authorises a call. It runs only from the host form, purely to *display* the certificate's two
 * digests so an administrator can compare them with what `pvenode cert info` prints on the host
 * before choosing a mode. Nothing here is stored, and no API request is made on this socket.
 *
 * A raw stream rather than the HTTP client on purpose: HttpClient exposes the peer chain only
 * through `capture_peer_cert_chain`, which would mean issuing a real authenticated request against
 * a certificate we have decided not to verify - exactly the shape we do not want lying around.
 */
class ProxmoxCertificateInspector
{
    private const int CONNECT_TIMEOUT_SECONDS = 4;
    private const int HANDSHAKE_TIMEOUT_SECONDS = 4;

    /**
     * @throws ProxmoxUnavailableException when the socket cannot be opened or the certificate cannot be read
     */
    public function inspect(string $hostname, int $port): ProxmoxCertificate
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            // SNI: a Proxmox behind a name-based front end otherwise serves the default
            // certificate, and the digests shown would be somebody else's.
            'peer_name' => $hostname,
            'SNI_enabled' => true,
        ]]);

        // **Plain TCP first, TLS second, and not `ssl://` in one call.** The `$timeout` argument of
        // stream_socket_client() bounds the *connection* only: with an `ssl://` address the
        // handshake happens inside the same call and falls back to `default_socket_timeout`, 60
        // seconds by default - past PHP's own 30-second execution limit, so the process is killed
        // by a fatal MaxExecutionTimeError that no catch block can turn into a message. That is
        // what production answered on 2026-08-19 against a host whose port accepted the connection
        // and then went quiet. Connecting in the clear, arming stream_set_timeout() and only then
        // enabling crypto puts a bound on the half that was unbounded.
        $stream = @stream_socket_client(
            \sprintf('tcp://%s:%d', $hostname, $port),
            $errorCode,
            $errorMessage,
            self::CONNECT_TIMEOUT_SECONDS,
            \STREAM_CLIENT_CONNECT,
            $context,
        );

        $address = \sprintf('%s:%d', $hostname, $port);

        if (false === $stream) {
            throw ProxmoxUnavailableException::unreachable($address, \sprintf('Could not connect to %s%s', $address, '' !== (string) $errorMessage ? ' - '.$errorMessage : ''));
        }

        stream_set_timeout($stream, self::HANDSHAKE_TIMEOUT_SECONDS);

        if (true !== @stream_socket_enable_crypto($stream, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($stream);

            throw ProxmoxUnavailableException::tlsHandshakeFailed($address);
        }

        try {
            // stream_context_get_params() is documented as an array but typed mixed all the way
            // down, so each level is checked rather than cast - the whole point of this class is
            // that it deals with whatever a stranger's TLS endpoint happens to hand back.
            $params = stream_context_get_params($stream);
            $ssl = $params['options']['ssl'] ?? null;
            $certificate = \is_array($ssl) ? ($ssl['peer_certificate'] ?? null) : null;

            if (!$certificate instanceof \OpenSSLCertificate) {
                throw new ProxmoxUnavailableException('The server presented no readable certificate.');
            }

            return $this->describe($certificate);
        } finally {
            fclose($stream);
        }
    }

    private function describe(\OpenSSLCertificate $certificate): ProxmoxCertificate
    {
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        $parsed = openssl_x509_parse($certificate);

        if (false === $fingerprint || false === $parsed) {
            throw new ProxmoxUnavailableException('The certificate could not be parsed.');
        }

        $subject = $this->flatten($parsed['subject'] ?? null);
        $issuer = $this->flatten($parsed['issuer'] ?? null);
        $validTo = $parsed['validTo_time_t'] ?? null;

        return new ProxmoxCertificate(
            strtoupper(implode(':', str_split($fingerprint, 2))),
            $this->publicKeyPin($certificate),
            $subject,
            $issuer,
            \is_int($validTo) ? (new \DateTimeImmutable())->setTimestamp($validTo) : null,
            $subject === $issuer,
        );
    }

    /**
     * base64(sha256(DER of the SubjectPublicKeyInfo)). openssl_pkey_get_details() hands back the
     * public key already in SPKI PEM form, so stripping the armour and base64-decoding it *is* the
     * DER - no ASN.1 work needed.
     */
    private function publicKeyPin(\OpenSSLCertificate $certificate): string
    {
        $publicKey = openssl_pkey_get_public($certificate);

        if (false === $publicKey) {
            throw new ProxmoxUnavailableException('The certificate carries no readable public key.');
        }

        $details = openssl_pkey_get_details($publicKey);
        $pem = \is_array($details) ? ($details['key'] ?? null) : null;

        if (!\is_string($pem)) {
            throw new ProxmoxUnavailableException('The public key could not be exported.');
        }

        $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $pem) ?? '';
        $der = base64_decode($body, true);

        if (false === $der) {
            throw new ProxmoxUnavailableException('The public key could not be decoded.');
        }

        return base64_encode(hash('sha256', $der, true));
    }

    private function flatten(mixed $name): string
    {
        if (!\is_array($name)) {
            return '';
        }

        $parts = [];
        foreach ($name as $key => $value) {
            $parts[] = \sprintf('%s=%s', (string) $key, \is_scalar($value) ? (string) $value : '');
        }

        return implode(', ', $parts);
    }
}
