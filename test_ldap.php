<?php
$host = getenv('LDAP_HOST');
$port = (int) getenv('LDAP_PORT');
$encryption = getenv('LDAP_ENCRYPTION');
$caCert = getenv('LDAP_TLS_CA_CERT_PATH');
$baseDn = getenv('LDAP_BASE_DN');
$searchDn = getenv('LDAP_SEARCH_DN');
$searchPassword = getenv('LDAP_SEARCH_PASSWORD');
$username = 'stharaud';

$conn = ldap_connect(('ssl' === $encryption ? 'ldaps' : 'ldap') . "://$host:$port");
ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);

if ($caCert) {
    ldap_set_option($conn, LDAP_OPT_X_TLS_CACERTFILE, $caCert);
    //ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CE, true);
}

if ('tls' === $encryption && !ldap_start_tls($conn)) {
    exit("StartTLS failed: " . ldap_error($conn));
}

var_dump($conn);
var_dump($caCert);

echo "Binding as service account ($searchDn)...\n";
if (!@ldap_bind($conn, $searchDn, $searchPassword)) {
    exit("Service account bind FAILED: " . ldap_error($conn) . "\n");
}
echo "Service account bind OK.\n\n";

foreach (['sAMAccountName', 'uid', 'userPrincipalName'] as $attr) {
    $filter = "($attr=$username)";
    $result = @ldap_search($conn, $baseDn, $filter);
    if (false === $result) {
        printf("%-20s search FAILED: %s\n", $filter, ldap_error($conn));
        continue;
    }
    $entries = ldap_get_entries($conn, $result);
    printf("%-20s -> %d match(es)\n", $filter, $entries['count']);
    if ($entries['count'] > 0) {
        echo "  dn: " . $entries[0]['dn'] . "\n";
        foreach (['uid', 'samaccountname', 'userprinemberof'] as $show) {
            if (isset($entries[0][$show])) {
                var_dump($entries[0][$show]);
            }
        }
    }
    echo "\n";
}