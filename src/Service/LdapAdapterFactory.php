<?php

namespace App\Service;

use Symfony\Component\Ldap\Adapter\ExtLdap\Adapter;

/**
 * Builds the LDAP Adapter (config/services.yaml) - a factory rather than a plain argument array
 * so the TLS CA-cert verification options can be entirely omitted for dev's plain-LDAP
 * openldap container (compose.override.yaml) while production connects over LDAPS and verifies
 * the server's certificate against a known CA file - see docs/production.md.
 */
class LdapAdapterFactory
{
    public static function create(string $host, int $port, string $encryption, string $tlsCaCertPath = ''): Adapter
    {
        $options = [
            'protocol_version' => 3,
            'referrals' => false,
        ];

        // Only set for TLS-verified connections (production): dev's openldap container speaks
        // plain LDAP with no TLS at all, and setting an empty CA-cert path would make ext-ldap
        // reject the connection outright rather than just skip verification.
        if ('' !== $tlsCaCertPath) {
            $options['x_tls_cacertfile'] = $tlsCaCertPath;
            $options['x_tls_require_cert'] = \LDAP_OPT_X_TLS_DEMAND;
        }

        return new Adapter([
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'options' => $options,
        ]);
    }
}
