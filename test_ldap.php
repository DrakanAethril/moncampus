<?php
LDAPTLS_CACERT=/etc/moncampus/ldap-ca.pem; 
LDAPTLS_REQCERT=demand;
$conn = ldap_connect("ldaps://172.30.90.1:636");
ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
if (@ldap_bind($conn, getenv("LDAP_SEARCH_DN"), getenv("LDAP_SEARCH_PASSWORD"))) {
    echo "Bind OK via LDAPTLS_CACERT env var.";
} else {
    echo "Bind FAILED: " . ldap_error($conn);
}

$host = "172.30.90.1";
  $port = 636;

  $context = stream_context_create([
      "ssl" => [
          "verify_peer" => false,
          "verify_peer_name" => false,
          "capture_peer_cert" => true,
      ],
  ]);

  $errno = 0;
  $errstr = "";
  $client = @stream_socket_client(
      "ssl://$host:$port",
      $errno,
      $errstr,
      5,
      STREAM_CLIENT_CONNECT,
      $context
  );

  if (!$client) {
      echo "TLS handshake FAILED: [$errno] $errstr\n";
      exit(1);
  }

  echo "TLS handshake OK.\n";
  $params = stream_context_get_params($client);
  if (isset($params["options"]["ssl"]["peer_certificate"])) {
      $cert = openssl_x509_parse($params["options"]["ssl"]["peer_certificate"]);
      echo "Peer certificate subject: " . ($cert["name"] ?? "?") . "\n";
      echo "Peer certificate issuer: " . ($cert["issuer"]["CN"] ?? "?") . "\n";
  }
  fclose($client);

echo '------- STep 2 ----------';
 $host = getenv("LDAP_HOST");
  $port = (int) getenv("LDAP_PORT");
  $fp = @fsockopen($host, $port, $errno, $errstr, 5);
  if ($fp) {
      echo "TCP connect to $host:$port: OK\n";
      fclose($fp);
  } else {
      echo "TCP connect to $host:$port: FAILED ($errno: $errstr)\n";
  }

 echo '----- STEP 3 ----';
 $host = getenv("LDAP_HOST");
  $port = (int) getenv("LDAP_PORT");
  $searchDn = getenv("LDAP_SEARCH_DN");
  $searchPassword = getenv("LDAP_SEARCH_PASSWORD");

  $conn = ldap_connect("ldaps://$host:$port");
  ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
  ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);

  if (@ldap_bind($conn, $searchDn, $searchPassword)) {
      echo "Bind OK with cert verification DISABLED - the TLS handshake itself works, the CA cert file is the problem.\n";
  } else {
      echo "Bind FAILED even with cert verification disabled: " . ldap_error($conn) . "\n";
      echo "-> connectivity/port/protocol problem, not a certificate problem.\n";
  }



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
    //ldap_set_option($conn, LDAP_OPT_X_TLS_PROTOCOL_SSL3, true);
    ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, true);
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