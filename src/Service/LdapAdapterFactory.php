<?php

namespace App\Service;

use Symfony\Component\Ldap\Adapter\ExtLdap\Adapter;

/**
 * Builds the LDAP Adapter (config/services.yaml) - a factory rather than a plain argument array
 * so TLS CA-cert verification can be entirely skipped for dev's plain-LDAP openldap container
 * while production connects over LDAPS and verifies the server's certificate against a known CA
 * file - see docs/production.md.
 *
 * TLS verification is configured via the LDAPTLS_CACERT/LDAPTLS_REQCERT process environment
 * variables, not ldap_set_option()'s LDAP_OPT_X_TLS_CACERTFILE/LDAP_OPT_X_TLS_REQUIRE_CERT
 * (Symfony's own documented mechanism, and what this originally used): on this deployment's
 * libldap build, per-connection ldap_set_option() calls for the TLS-cert family silently failed
 * the handshake ("Can't contact LDAP server") even with verification turned off entirely -
 * confirmed empirically, since the exact same host/port/certificate connected fine both over a
 * raw TCP+TLS test and via these LDAPTLS_* env vars. libldap reads them at its own internal
 * initialization, so putenv() must happen before any LDAP operation in this process - hence doing
 * it here, in the one place every LDAP connection in the app is built, rather than closer to the
 * actual connect/bind call.
 */
class LdapAdapterFactory
{
    public static function create(string $host, int $port, string $encryption, string $tlsCaCertPath = ''): Adapter
    {
        // Only set for TLS-verified connections (production): dev's openldap container speaks
        // plain LDAP with no TLS at all.
        if ('' !== $tlsCaCertPath) {
            putenv("LDAPTLS_CACERT=$tlsCaCertPath");
            putenv('LDAPTLS_REQCERT=demand');
        }

        return new Adapter([
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'options' => [
                'protocol_version' => 3,
                'referrals' => false,
            ],
        ]);
    }
}
