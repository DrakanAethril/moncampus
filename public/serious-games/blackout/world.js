/* Black-out — the room as it should be: devices, cables and every configuration file, act by act.

   This is the reference world: each ticket starts from it, at its own act, and applies its bug
   (game/tickets/). A file is either a string or a function of the act, so the network grows as
   the night goes on (the rang C arrives in act 2, the DMZ in act 5…). Every machine is a Debian 13;
   switches are VLAN-aware Linux bridges (ifupdown2), as on the students' Proxmox hosts.

   Device: { id, label, kind, since, x, y, vlan (colour on the map), hidden, ifaces: { name: { mac } },
             services: ['tcp/22', 'udp/53', …], files: [paths] }
   kind: router | switch | server | host | box | internet | ap | ghost
   Cable: ['dev:iface', 'dev:iface', since?] */
(function (root) {
  'use strict';

  function r1Interfaces(act) {
    var s = '# r1 : routeur et pare-feu de la salle (Debian 13, ifupdown2)\n' +
      'auto lo\niface lo inet loopback\n\n' +
      '# Côté lycée : la box\nauto ens18\niface ens18 inet static\n    address 192.168.100.2/24\n    gateway 192.168.100.1\n\n' +
      '# Trunk vers sw-core : une sous-interface par VLAN\nauto ens19\niface ens19 inet manual\n\n' +
      '# VLAN 10 : orgas et serveurs\nauto ens19.10\niface ens19.10 inet static\n    address 10.20.10.254/24\n\n' +
      '# VLAN 20 : joueurs, rangs A et B\nauto ens19.20\niface ens19.20 inet static\n    address 10.20.20.254/24\n\n';
    if (act >= 2) s += '# VLAN 30 : joueurs, rang C\nauto ens19.30\niface ens19.30 inet static\n    address 10.20.30.126/25\n\n';
    s += '# VLAN 40 : stream\nauto ens19.40\niface ens19.40 inet static\n    address 10.20.40.254/24\n';
    if (act >= 3) s += '    up ip route add 10.20.41.0/24 via 10.20.40.2\n';
    s += '\n';
    if (act >= 5) s += '# VLAN 50 : DMZ\nauto ens19.50\niface ens19.50 inet static\n    address 10.20.50.254/24\n\n';
    if (act >= 7) s += '# VLAN 60 : Wi-Fi invités\nauto ens19.60\niface ens19.60 inet static\n    address 10.20.60.254/24\n\n';
    s += '# VLAN 99 : administration des commutateurs\nauto ens19.99\niface ens19.99 inet static\n    address 10.20.99.254/24\n';
    return s;
  }

  function relay(act) {
    var ifs = ['ens19.10', 'ens19.20'];
    if (act >= 2) ifs.push('ens19.30');
    if (act >= 7) ifs.push('ens19.60');
    return '# Defaults for isc-dhcp-relay initscript\n# sourced by /etc/init.d/isc-dhcp-relay\n\n' +
      '# What servers should the DHCP relay forward requests to?\nSERVERS="10.20.10.5"\n\n' +
      '# On what interfaces should the DHCP relay (dhrelay) serve DHCP requests?\nINTERFACES="' + ifs.join(' ') + '"\n\n' +
      '# Additional options that are passed to the DHCP relay daemon?\nOPTIONS=""\n';
  }

  function r1Nftables(act) {
    var s = '#!/usr/sbin/nft -f\nflush ruleset\n\n';
    if (act < 6) {
      s += 'table inet filtre {\n    chain input {\n        type filter hook input priority 0; policy accept;\n    }\n' +
        '    chain forward {\n        type filter hook forward priority 0; policy accept;\n    }\n}\n\n';
    } else {
      s += 'table inet filtre {\n    chain input {\n        type filter hook input priority 0; policy accept;\n    }\n\n' +
        '    chain forward {\n        type filter hook forward priority 0; policy drop;\n' +
        '        ct state established,related accept\n' +
        '        # L\'administration des commutateurs : la régie seulement\n' +
        '        ip saddr 10.20.10.130 oifname "ens19.99" accept\n' +
        '        oifname "ens19.99" drop\n' +
        '        # Les orgas vont partout\n' +
        '        iifname "ens19.10" accept\n' +
        '        # Les joueurs : Internet, le serveur de jeu, le DNS\n' +
        '        iifname { "ens19.20", "ens19.30" } oifname "ens18" accept\n' +
        '        iifname { "ens19.20", "ens19.30" } ip daddr 10.20.10.10 accept\n' +
        '        iifname { "ens19.20", "ens19.30" } ip daddr 10.20.10.6 udp dport 53 accept\n' +
        '        # Le stream : Internet et le serveur de jeu\n' +
        '        iifname "ens19.40" oifname "ens18" accept\n' +
        '        iifname "ens19.40" ip daddr 10.20.10.10 accept\n' +
        '        # Depuis Internet : le site d\'inscription, et rien d\'autre\n' +
        '        iifname "ens18" ip daddr 10.20.50.10 tcp dport 443 accept\n';
      if (act >= 7) s += '        # Les invités : Internet seulement\n        iifname "ens19.60" oifname "ens18" accept\n' +
        '        # L\'arbitre à distance : le serveur de jeu\n        iifname "wg0" ip daddr 10.20.10.10 accept\n';
      s += '        log prefix "refus " drop\n    }\n}\n\n';
    }
    s += 'table ip nat {\n';
    if (act >= 5) s += '    chain prerouting {\n        type nat hook prerouting priority -100;\n        iifname "ens18" tcp dport 443 dnat to 10.20.50.10\n    }\n';
    s += '    chain postrouting {\n        type nat hook postrouting priority 100;\n        oifname "ens18" masquerade\n    }\n}\n';
    return s;
  }

  function switchFile(name, desc, ports, opts) {
    // ports: [[iface, comment, vids, pvid]] ; vids null for an access port (pvid only); opts.off: ports kept out of the bridge
    var s = '# ' + name + ' : ' + desc + ' (pont Linux à VLAN, ifupdown2)\n';
    var off = opts.off || [];
    s += 'auto br0\niface br0\n    bridge-ports ' + ports.filter(function (p) { return off.indexOf(p[0]) === -1; }).map(function (p) { return p[0]; }).join(' ') + '\n' +
      '    bridge-vlan-aware yes\n    bridge-stp ' + (opts.stp === false ? 'off' : 'on') + '\n\n';
    ports.forEach(function (p) {
      s += '# ' + p[1] + '\niface ' + p[0] + '\n';
      if (p[2]) s += '    bridge-vids ' + p[2] + '\n';
      s += '    bridge-pvid ' + p[3] + '\n\n';
    });
    s += '# Administration (VLAN 99)\nauto br0.99\niface br0.99 inet static\n    address ' + opts.admin + '/24\n    gateway 10.20.99.254\n';
    return s;
  }

  function swCore(act) {
    var ports = [
      ['ens18', 'vers r1 (trunk)', '10 20' + (act >= 2 ? ' 30' : '') + ' 40' + (act >= 5 ? ' 50' : '') + (act >= 7 ? ' 60' : '') + ' 99', 1],
      ['ens19', 'vers sw-rang-a (trunk)', '20 99', 1],
      ['ens20', 'vers sw-rang-b (trunk)', '10 20 99', 1]
    ];
    if (act >= 2) ports.push(['ens21', 'vers sw-rang-c (trunk)', '30 99', 1]);
    ports.push(['ens22', 'régie', null, 10], ['ens23', 'srv-dhcp', null, 10], ['ens24', 'srv-dns', null, 10],
      ['ens25', 'srv-jeu', null, 10], ['ens26', 'imprimante des badges', null, 10], ['ens27', 'r-stream', null, 40]);
    if (act >= 5) ports.push(['ens28', 'www (DMZ)', null, 50]);
    if (act >= 7) ports.push(['ens29', 'point d\'accès Wi-Fi (trunk)', '10 60', 1]);
    return switchFile('sw-core', 'cœur de la salle', ports, { admin: '10.20.99.1' });
  }
  function swRow(row, admin, act) {
    var ports = [['ens18', 'vers sw-core (trunk)', row === 'b' ? '10 20 99' : '20 99', 1], ['ens19', 'les PC du rang ' + row.toUpperCase(), null, 20]];
    if (row === 'b') ports.push(['ens31', 'table B13', null, 20], ['ens32', act >= 9 ? 'table B14 (le boîtier en a été retiré)' : 'table B14', null, 20], ['ens33', 'table B15', null, 20]);
    return switchFile('sw-rang-' + row, 'rang ' + row.toUpperCase(), ports, { admin: admin, off: row === 'b' && act >= 9 ? ['ens32'] : [] });
  }
  function swRowC() {
    return switchFile('sw-rang-c', 'rang C', [['ens18', 'vers sw-core (trunk)', '30 99', 1], ['ens19', 'les PC du rang C', null, 30]], { admin: '10.20.99.13' });
  }
  function swRowBNft() {
    return '#!/usr/sbin/nft -f\nflush ruleset\n\n# Seul le lien montant amène des réponses DHCP\ntable bridge filtre {\n    chain forward {\n        type filter hook forward priority 0; policy accept;\n' +
      '        iifname != "ens18" udp sport 67 drop\n    }\n}\n';
  }

  function staticHost(comment, ip, gw, dns) {
    return '# ' + comment + '\nauto lo\niface lo inet loopback\n\nauto ens18\niface ens18 inet static\n    address ' + ip + '\n' +
      (gw ? '    gateway ' + gw + '\n' : '') + (dns ? '    dns-nameservers ' + dns + '\n' : '');
  }
  function dhcpHost(comment) {
    return '# ' + comment + '\nauto lo\niface lo inet loopback\n\nauto ens18\niface ens18 inet dhcp\n';
  }

  function kea(act) {
    var subnets = [
      '        {\n            "id": 10,\n            "subnet": "10.20.10.0/24",\n            "pools": [ { "pool": "10.20.10.100 - 10.20.10.199" } ],\n            "option-data": [ { "name": "routers", "data": "10.20.10.254" } ]' +
        (act >= 4 ? ',\n            "reservations": [\n                { "hw-address": "00:1b:a9:42:0f:20", "ip-address": "10.20.10.20", "hostname": "imprimante-badges" }\n            ]' : '') + '\n        }',
      '        {\n            "id": 20,\n            "subnet": "10.20.20.0/24",\n            "pools": [ { "pool": "10.20.20.100 - 10.20.20.199" } ],\n            "option-data": [ { "name": "routers", "data": "10.20.20.254" } ]\n        }'
    ];
    if (act >= 2) subnets.push('        {\n            "id": 30,\n            "subnet": "10.20.30.0/25",\n            "pools": [ { "pool": "10.20.30.10 - 10.20.30.120" } ],\n            "option-data": [ { "name": "routers", "data": "10.20.30.126" } ]\n        }');
    if (act >= 7) subnets.push('        {\n            "id": 60,\n            "subnet": "10.20.60.0/24",\n            "pools": [ { "pool": "10.20.60.10 - 10.20.60.250" } ],\n            "option-data": [ { "name": "routers", "data": "10.20.60.254" } ]\n        }');
    return '{\n"Dhcp4": {\n    "interfaces-config": { "interfaces": [ "ens18" ] },\n' +
      '    "lease-database": { "type": "memfile", "persist": true, "name": "/var/lib/kea/kea-leases4.csv" },\n' +
      '    "valid-lifetime": 3600,\n    "option-data": [\n        { "name": "domain-name", "data": "tournoi.lan" },\n' +
      '        { "name": "domain-name-servers", "data": "10.20.10.6" }\n    ],\n    "subnet4": [\n' + subnets.join(',\n') + '\n    ]\n}\n}\n';
  }

  var nginx = '# Le site d\'inscription des parents (nginx)\nserver {\n    listen 443 ssl;\n    server_name inscriptions.tournoi.example;\n\n' +
    '    ssl_certificate     /etc/ssl/tournoi/inscriptions.crt;\n    ssl_certificate_key /etc/ssl/tournoi/inscriptions.key;\n\n    root /var/www/inscriptions;\n}\n';
  function cert(cn, serial) {
    return 'Certificate:\n    Data:\n        Serial Number: ' + serial + '\n        Issuer: C = FR, O = Let\'s Encrypt, CN = R11\n        Validity\n' +
      '            Not Before: Oct  1 00:00:00 2026 GMT\n            Not After : Dec 30 23:59:59 2026 GMT\n        Subject: CN = ' + cn + '\n' +
      '        X509v3 extensions:\n            X509v3 Subject Alternative Name:\n                DNS:' + cn + '\n-----BEGIN CERTIFICATE-----\nMIIFazCCBFOgAwIBAgISA' + serial.replace(/:/g, '') + 'vJw0BAQsFADAzMQswCQYDVQQGEwJVUzEW\n-----END CERTIFICATE-----\n';
  }
  var hostapd = '# ap-salle : deux réseaux Wi-Fi sur le même point d\'accès\ninterface=wlan0\nbridge=br0\nssid=LAN-invites\nwpa=2\nwpa_key_mgmt=WPA-PSK\nwpa_passphrase=Bienvenue-LAN-2026\n' +
    '# les invités ne se voient pas entre eux\nap_isolate=1\n\nbss=wlan1\nbridge=br0\nssid=LAN-arbitres\nwpa=2\nwpa_key_mgmt=WPA-EAP\nieee8021x=1\n' +
    '# le serveur RADIUS qui vérifie les comptes\nauth_server_addr=10.20.10.5\nauth_server_port=1812\nauth_server_shared_secret=Rad1us-Arena-2026\n';
  var apIfaces = '# ap-salle : le point d\'accès Wi-Fi (pont Linux à VLAN, ifupdown2)\nauto br0\niface br0\n    bridge-ports ens18 wlan0 wlan1\n    bridge-vlan-aware yes\n    bridge-stp on\n\n' +
    '# vers sw-core (trunk)\niface ens18\n    bridge-vids 10 60\n    bridge-pvid 1\n\n# Wi-Fi invités (LAN-invites)\niface wlan0\n    bridge-pvid 60\n\n# Wi-Fi arbitres (LAN-arbitres)\niface wlan1\n    bridge-pvid 10\n\n' +
    '# Administration (VLAN 10) : c\'est l\'adresse que voit le serveur RADIUS\nauto br0.10\niface br0.10 inet static\n    address 10.20.10.40/24\n    gateway 10.20.10.254\n';
  var radiusClients = '# freeradius : les équipements autorisés à interroger ce serveur\nclient localhost {\n    ipaddr = 127.0.0.1\n    secret = testing123\n}\n\n' +
    'client ap-salle {\n    ipaddr = 10.20.10.40\n    secret = Rad1us-Arena-2026\n}\n';
  var radiusUsers = '# freeradius : les comptes des arbitres\narbitre1    Cleartext-Password := "Sifflet-2026"\narbitre2    Cleartext-Password := "Carton-Jaune"\n';
  var KEYS = { r1: { priv: 'kFq3T2uR9mL8xVb1nC7pZ4sW6yH0jD5gA2eK8tN3oQ=', pub: 'R1pubVg9aXcT4mZq7LwE2bN8sK5yH3jD6fU1oP0rQ=' },
    arbitre: { priv: 'aB7nW3qX9tR2mK8vL5pZ1cY6uH4jD0sF3gE9kN2oQ=', pub: 'ArbPubHw2Lq9Xt7Rn4Mk1Vs8Zc5Yb3Ju6Dg0Fe2Ka=' } };
  function wgR1() {
    return '# r1 : le tunnel WireGuard de l\'arbitre à distance\n[Interface]\nAddress = 10.20.200.1/24\nListenPort = 51820\nPrivateKey = ' + KEYS.r1.priv + '\n\n' +
      '[Peer]\n# l\'arbitre, chez lui (sa clé publique est dans arbitre.pub)\nPublicKey = ' + KEYS.arbitre.pub + '\nAllowedIPs = 10.20.200.2/32\n';
  }
  function wgArbitre() {
    return '# Le poste de l\'arbitre, chez lui\n[Interface]\nAddress = 10.20.200.2/32\nPrivateKey = ' + KEYS.arbitre.priv + '\n\n' +
      '[Peer]\n# r1, au lycée\nPublicKey = ' + KEYS.r1.pub + '\nEndpoint = 203.0.113.2:51820\n# seul le serveur de jeu passe par le tunnel\nAllowedIPs = 10.20.10.10/32\n';
  }
  var sshdMain = '# This is the sshd server system-wide configuration file.  See sshd_config(5)\n\nInclude /etc/ssh/sshd_config.d/*.conf\n\n' +
    '#PermitRootLogin prohibit-password\n#PubkeyAuthentication yes\n#PasswordAuthentication yes\nKbdInteractiveAuthentication no\nUsePAM yes\n\nX11Forwarding yes\nPrintMotd no\nAcceptEnv LANG LC_*\nSubsystem\tsftp\t/usr/lib/openssh/sftp-server\n';

  var zone = '; Zone du tournoi : db.tournoi.lan\n$ORIGIN tournoi.lan.\n$TTL 3600\n' +
    '@          SOA   srv-dns admin ( 2026103101 3600 900 604800 300 )\n' +
    '@          NS    srv-dns\n\n' +
    'srv-dns    A     10.20.10.6\nsrv-dhcp   A     10.20.10.5\nsrv-jeu    A     10.20.10.10\nregie      A     10.20.10.130\n' +
    'imprimante A     10.20.10.20\nwww        A     10.20.50.10\nstream     A     10.20.41.10\n\n' +
    '; Le tableau du tournoi, sur le serveur de jeu\nbracket    CNAME srv-jeu\n';

  var D = [
    { id: 'internet', label: 'Internet', kind: 'internet', x: 400, y: 26, since: 1 },
    { id: 'box', label: 'Box du lycée', kind: 'box', x: 400, y: 92, since: 1, ifaces: { lan: { ip: '192.168.100.1/24' } } },
    { id: 'r1', label: 'r1', kind: 'router', x: 400, y: 168, since: 1,
      ifaces: { ens18: { mac: 'bc:24:11:00:00:01' }, ens19: { mac: 'bc:24:11:00:00:02' } },
      files: ['/etc/network/interfaces', '/etc/sysctl.d/99-routage.conf', '/etc/default/isc-dhcp-relay', '/etc/nftables.conf'] },
    { id: 'sw-core', label: 'sw-core', kind: 'switch', x: 400, y: 262, since: 1, files: ['/etc/network/interfaces'] },
    { id: 'sw-rang-a', label: 'sw-rang-a', kind: 'switch', x: 130, y: 372, since: 1, files: ['/etc/network/interfaces'] },
    { id: 'sw-rang-b', label: 'sw-rang-b', kind: 'switch', x: 300, y: 372, since: 1, files: ['/etc/network/interfaces', '/etc/nftables.conf'] },
    { id: 'sw-rang-c', label: 'sw-rang-c', kind: 'switch', x: 470, y: 372, since: 2, files: ['/etc/network/interfaces'] },
    { id: 'pc-a01', label: 'Rang A', kind: 'host', x: 130, y: 460, since: 1, count: 20, ifaces: { ens18: { mac: 'bc:24:11:a0:01:01' } }, files: ['/etc/network/interfaces'] },
    { id: 'pc-b07', label: 'Rang B', kind: 'host', x: 300, y: 460, since: 1, count: 20, ifaces: { ens18: { mac: 'bc:24:11:b0:07:01' } }, files: ['/etc/network/interfaces'] },
    { id: 'pi-b14', label: 'GHOST-PC', kind: 'ghost', x: 370, y: 460, since: 1, hidden: true, ifaces: { ens18: { mac: 'b8:27:eb:4c:11:9e' } }, services: ['tcp/22'] },
    { id: 'pc-c12', label: 'Rang C', kind: 'host', x: 470, y: 460, since: 2, count: 20, ifaces: { ens18: { mac: 'bc:24:11:c0:12:01' } }, files: ['/etc/network/interfaces'] },
    { id: 'regie', label: 'Régie', kind: 'host', x: 600, y: 196, since: 1, ifaces: { ens18: { mac: 'bc:24:11:10:30:01' } }, files: ['/etc/network/interfaces'] },
    { id: 'srv-dhcp', label: 'srv-dhcp', kind: 'server', x: 720, y: 196, since: 1, ifaces: { ens18: { mac: 'bc:24:11:10:05:01' } }, services: ['udp/67', 'tcp/22'], files: ['/etc/network/interfaces', '/etc/kea/kea-dhcp4.conf'] },
    { id: 'srv-dns', label: 'srv-dns', kind: 'server', x: 720, y: 262, since: 1, ifaces: { ens18: { mac: 'bc:24:11:10:06:01' } }, services: ['udp/53', 'tcp/53', 'tcp/22'], files: ['/etc/network/interfaces', '/etc/bind/db.tournoi.lan'] },
    { id: 'srv-jeu', label: 'srv-jeu', kind: 'server', x: 720, y: 328, since: 1, ifaces: { ens18: { mac: 'bc:24:11:10:10:01' } }, services: ['tcp/22', 'tcp/80', 'tcp/27015', 'udp/27015'], files: ['/etc/network/interfaces'] },
    { id: 'imprimante', label: 'Imprimante', kind: 'host', x: 600, y: 328, since: 1, ifaces: { ens18: { mac: '00:1b:a9:42:0f:20' } }, services: ['tcp/631', 'tcp/9100'], files: ['/etc/network/interfaces'] },
    { id: 'r-stream', label: 'r-stream', kind: 'router', x: 160, y: 262, since: 3, ifaces: { ens18: { mac: 'bc:24:11:40:02:01' }, ens19: { mac: 'bc:24:11:41:01:01' } }, files: ['/etc/network/interfaces'] },
    { id: 'encodeur', label: 'Encodeur', kind: 'host', x: 160, y: 168, since: 3, ifaces: { ens18: { mac: 'bc:24:11:41:10:01' } }, services: ['tcp/22', 'tcp/1935'], files: ['/etc/network/interfaces'] },
    { id: 'www', label: 'www (DMZ)', kind: 'server', x: 560, y: 110, since: 5, ifaces: { ens18: { mac: 'bc:24:11:50:10:01' } }, services: ['tcp/443', 'tcp/22'], files: ['/etc/network/interfaces', '/etc/nginx/sites-enabled/inscriptions'] },
    { id: 'ap-salle', label: 'Wi-Fi', kind: 'switch', ap: true, x: 640, y: 410, since: 7, files: ['/etc/network/interfaces', '/etc/hostapd/hostapd.conf'] },
    { id: 'invite', label: 'Invité', kind: 'host', x: 570, y: 470, since: 7, wifi: { ssid: 'LAN-invites' }, ifaces: { ens18: { mac: 'bc:24:11:60:01:01' } }, files: ['/etc/network/interfaces'] },
    { id: 'invite2', label: 'Invité 2', kind: 'host', x: 665, y: 470, since: 7, wifi: { ssid: 'LAN-invites' }, ifaces: { ens18: { mac: 'bc:24:11:60:02:01' } }, files: ['/etc/network/interfaces'] },
    { id: 'arbitre', label: 'Arbitre', kind: 'host', x: 750, y: 410, since: 7, wifi: { ssid: 'LAN-arbitres', user: 'arbitre1', password: 'Sifflet-2026' }, ifaces: { ens18: { mac: 'bc:24:11:10:41:01' } }, files: ['/etc/network/interfaces'] },
    { id: 'arbitre-distant', label: 'Arbitre (chez lui)', kind: 'remote', x: 620, y: 26, since: 7, files: ['/etc/network/interfaces', '/etc/wireguard/wg0.conf', '/etc/wireguard/arbitre.pub'] }
  ];

  var C = [
    ['box:lan', 'r1:ens18'], ['r1:ens19', 'sw-core:ens18'],
    ['sw-core:ens19', 'sw-rang-a:ens18'], ['sw-core:ens20', 'sw-rang-b:ens18'], ['sw-core:ens21', 'sw-rang-c:ens18', 2],
    ['sw-rang-a:ens19', 'pc-a01:ens18'], ['sw-rang-b:ens19', 'pc-b07:ens18'], ['sw-rang-b:ens32', 'pi-b14:ens18'], ['sw-rang-c:ens19', 'pc-c12:ens18', 2],
    ['sw-core:ens22', 'regie:ens18'], ['sw-core:ens23', 'srv-dhcp:ens18'], ['sw-core:ens24', 'srv-dns:ens18'], ['sw-core:ens25', 'srv-jeu:ens18'],
    ['sw-core:ens26', 'imprimante:ens18'], ['sw-core:ens27', 'r-stream:ens18', 3], ['r-stream:ens19', 'encodeur:ens18', 3],
    ['sw-core:ens28', 'www:ens18', 5], ['sw-core:ens29', 'ap-salle:ens18', 7],
    ['ap-salle:wlan0#1', 'invite:ens18', 7], ['ap-salle:wlan0#2', 'invite2:ens18', 7], ['ap-salle:wlan1#1', 'arbitre:ens18', 7]
  ];

  var F = {
    'r1:/etc/network/interfaces': r1Interfaces,
    'r1:/etc/sysctl.d/99-routage.conf': '# r1 : faire suivre les paquets d\'une interface à l\'autre\nnet.ipv4.ip_forward = 1\n',
    'r1:/etc/default/isc-dhcp-relay': relay,
    'r1:/etc/nftables.conf': r1Nftables,
    'sw-core:/etc/network/interfaces': swCore,
    'sw-rang-a:/etc/network/interfaces': function (act) { return swRow('a', '10.20.99.11', act); },
    'sw-rang-b:/etc/network/interfaces': function (act) { return swRow('b', '10.20.99.12', act); },
    'sw-rang-b:/etc/nftables.conf': function (act) { return act >= 8 ? swRowBNft() : '#!/usr/sbin/nft -f\nflush ruleset\n'; },
    'sw-rang-c:/etc/network/interfaces': swRowC,
    'pc-a01:/etc/network/interfaces': dhcpHost('Les PC du rang A (pc-a01 à pc-a20)'),
    'pc-b07:/etc/network/interfaces': dhcpHost('Les PC du rang B (pc-b01 à pc-b20)'),
    'pc-c12:/etc/network/interfaces': function (act) {
      return act < 4 ? staticHost('Les PC du rang C : adresses fixes en attendant le DHCP', '10.20.30.12/25', '10.20.30.126', '10.20.10.6') : dhcpHost('Les PC du rang C (pc-c01 à pc-c20)');
    },
    'regie:/etc/network/interfaces': staticHost('Le PC de la régie', '10.20.10.130/24', '10.20.10.254', '10.20.10.6'),
    'srv-dhcp:/etc/network/interfaces': staticHost('srv-dhcp : Kea', '10.20.10.5/24', '10.20.10.254', '10.20.10.6'),
    'srv-dhcp:/etc/kea/kea-dhcp4.conf': kea,
    'srv-dns:/etc/network/interfaces': staticHost('srv-dns : bind9', '10.20.10.6/24', '10.20.10.254', '127.0.0.1'),
    'srv-dns:/etc/bind/db.tournoi.lan': zone,
    'srv-jeu:/etc/network/interfaces': staticHost('srv-jeu : le serveur du tournoi', '10.20.10.10/24', '10.20.10.254', '10.20.10.6'),
    'imprimante:/etc/network/interfaces': function (act) {
      return act < 4 ? staticHost('Le boîtier d\'impression des badges (Debian)', '10.20.10.20/24', '10.20.10.254') : dhcpHost('Le boîtier d\'impression des badges (Debian) : son adresse lui vient du DHCP');
    },
    'pi-b14:/etc/network/interfaces': staticHost('', '10.20.20.66/24', '10.20.20.254'),
    'r-stream:/etc/network/interfaces': '# r-stream : le petit routeur de la régie du stream\nauto lo\niface lo inet loopback\n\n' +
      'auto ens18\niface ens18 inet static\n    address 10.20.40.2/24\n    gateway 10.20.40.254\n\n' +
      'auto ens19\niface ens19 inet static\n    address 10.20.41.1/24\n',
    'encodeur:/etc/network/interfaces': staticHost('L\'encodeur du stream', '10.20.41.10/24', '10.20.41.1', '10.20.10.6'),
    'www:/etc/network/interfaces': staticHost('www : le site d\'inscription, dans la DMZ', '10.20.50.10/24', '10.20.50.254', '10.20.10.6'),
    'www:/etc/nginx/sites-enabled/inscriptions': nginx,
    'www:/etc/ssl/tournoi/inscriptions.crt': cert('inscriptions.tournoi.example', '04:a3:91:5c'),
    'www:/etc/ssl/tournoi/www.crt': cert('www.tournoi.example', '03:7f:22:e8'),
    'ap-salle:/etc/network/interfaces': apIfaces,
    'ap-salle:/etc/hostapd/hostapd.conf': hostapd,
    'invite:/etc/network/interfaces': dhcpHost('Le portable d\'un invité (Wi-Fi LAN-invites)'),
    'invite2:/etc/network/interfaces': dhcpHost('Le téléphone d\'un autre invité (Wi-Fi LAN-invites)'),
    'arbitre:/etc/network/interfaces': dhcpHost('La tablette d\'un arbitre (Wi-Fi LAN-arbitres, 802.1X)'),
    'srv-dhcp:/etc/freeradius/3.0/clients.conf': function (act) { return act >= 7 ? radiusClients : undefined; },
    'srv-dhcp:/etc/freeradius/3.0/mods-config/files/authorize': function (act) { return act >= 7 ? radiusUsers : undefined; },
    'r1:/etc/wireguard/wg0.conf': function (act) { return act >= 7 ? wgR1() : undefined; },
    'arbitre-distant:/etc/network/interfaces': '# Le poste de l\'arbitre, chez lui, derrière sa box\nauto lo\niface lo inet loopback\n\nauto wan\niface wan inet static\n    address 198.51.100.77/32\n',
    'arbitre-distant:/etc/wireguard/wg0.conf': wgArbitre,
    'arbitre-distant:/etc/wireguard/arbitre.pub': '# La clé publique de l\'arbitre (wg pubkey < arbitre.key)\n' + KEYS.arbitre.pub + '\n',
    'srv-jeu:/etc/ssh/sshd_config': function (act) { return act >= 8 ? sshdMain : undefined; },
    'srv-jeu:/etc/ssh/sshd_config.d/50-cloud-init.conf': function (act) { return act >= 8 ? '# Écrit par cloud-init à l\'installation du serveur\nPasswordAuthentication no\n' : undefined; },
    'srv-jeu:/etc/ssh/sshd_config.d/60-tournoi.conf': function (act) { return act >= 8 ? '# Durcissement de srv-jeu, après l\'intrusion de 03:05\nPasswordAuthentication no\nPermitRootLogin no\nMaxAuthTries 3\n' : undefined; }
  };

  root.BlackoutWorld = {
    devices: D,
    cables: C,
    files: F,
    // r1 forwards nothing unless /etc/sysctl.d says so; r-stream and the box are trusted routers.
    forwarders: { 'r-stream': true, box: true },
    wgKeys: (function () { var m = {}; Object.keys(KEYS).forEach(function (k) { m[KEYS[k].priv] = KEYS[k].pub; }); return m; })(),
    sshKeys: { 'srv-jeu': ['nadia', 'root'] },
    sshUsers: { 'srv-jeu': ['nadia', 'karim', 'root'] },
    box: { lan: '192.168.100.1', public: '203.0.113.2', forwards: [{ proto: 'tcp', port: 443, to: '192.168.100.2' }, { proto: 'udp', port: 51820, to: '192.168.100.2' }] },
    internet: {
      hosts: {
        '203.0.113.80': { label: 'un site web', services: ['tcp/80', 'tcp/443'] },
        '203.0.113.53': { label: 'le résolveur public', services: ['udp/53'] },
        '198.51.100.40': { label: 'un parent, chez lui', services: [] },
        '198.51.100.77': { label: 'l\'arbitre, chez lui', services: [] },
        '198.51.100.23': { label: 'une machine inconnue', services: ['udp/51820'] }
      },
      dns: { 'site.example.': '203.0.113.80', 'inscriptions.tournoi.example.': '203.0.113.2', 'www.tournoi.example.': '203.0.113.2' }
    },
    vlans: {
      10: { label: 'Orga', color: '#4D8BFF' }, 20: { label: 'Joueurs A-B', color: '#B26BFF' }, 30: { label: 'Joueurs C', color: '#FF9F43' },
      40: { label: 'Stream', color: '#FFD23F' }, 50: { label: 'DMZ', color: '#3DDBB0' }, 60: { label: 'Invités', color: '#8FA3BF' }, 99: { label: 'Admin', color: '#F4F2FF' }
    }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
