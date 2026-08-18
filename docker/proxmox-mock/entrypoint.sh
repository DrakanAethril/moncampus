#!/bin/sh
# Generates the self-signed certificate the mock serves, then starts it.
#
# Generated at every start rather than committed: a private key in git is a private key in git,
# even a throwaway one, and regenerating costs a second. The consequence is that the fingerprint
# and the public-key pin change at every `docker compose up` - which is exactly the behaviour the
# "Épingle de clé" TLS mode has to survive, and a good reason to prefer the CA mode.
#
# The CA is its own certificate so that the "AC du cluster" mode has something real to verify
# against: /certs/ca.pem is what an administrator pastes into the host form, and it is printed at
# start-up so it can be copied out of `docker compose logs proxmox-mock`.

set -e

CERTS=/certs
HOSTNAME="${MOCK_HOSTNAME:-proxmox-mock}"

if [ ! -f "$CERTS/ca.pem" ] || [ ! -f "$CERTS/server.pem" ]; then
    mkdir -p "$CERTS"

    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
        -keyout "$CERTS/ca.key" -out "$CERTS/ca.pem" \
        -subj "/O=Proxmox Virtual Environment/CN=proxmox-mock Cluster Manager CA" \
        -addext "basicConstraints=critical,CA:TRUE" 2>/dev/null

    openssl req -newkey rsa:2048 -nodes \
        -keyout "$CERTS/server.key" -out "$CERTS/server.csr" \
        -subj "/O=Proxmox Virtual Environment/CN=$HOSTNAME" 2>/dev/null

    # A SAN is not optional: curl verifies the name against subjectAltName and ignores CN
    # entirely, so a certificate with only a CN fails host verification in the `ca` mode - which
    # would look like a broken TLS implementation rather than a missing extension.
    printf "subjectAltName=DNS:%s,DNS:localhost,IP:127.0.0.1\n" "$HOSTNAME" > "$CERTS/server.ext"

    openssl x509 -req -in "$CERTS/server.csr" -CA "$CERTS/ca.pem" -CAkey "$CERTS/ca.key" \
        -CAcreateserial -out "$CERTS/server.pem" -days 3650 \
        -extfile "$CERTS/server.ext" 2>/dev/null

    rm -f "$CERTS/server.csr" "$CERTS/server.ext"
fi

echo "[proxmox-mock] ---------- cluster CA (paste this into « AC du cluster ») ----------"
cat "$CERTS/ca.pem"
echo "[proxmox-mock] ---------------------------------------------------------------"

exec python3 /srv/server.py
