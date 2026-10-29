/* Black-out — the network simulator.

   Not a packet-by-packet emulator: a deterministic model of the room, rebuilt from the configuration
   files every time they change, and asked one question: does this flow go through, and if not,
   where does it die? Every answer says why, in the words of the map ("éliminé à r1 : …").

   What it reads (Debian 13 syntax, a faithful subset):
   - /etc/network/interfaces (ifupdown2): auto, iface inet static|dhcp|manual, address, netmask,
     gateway, dns-nameservers, up ip route add …, VLAN sub-interfaces (ens19.30), VLAN-aware bridges
     (bridge-ports, bridge-vlan-aware, bridge-stp, bridge-vids, bridge-pvid);
   - /etc/sysctl.d/*.conf (net.ipv4.ip_forward);
   - /etc/default/isc-dhcp-relay (SERVERS, INTERFACES);
   - /etc/kea/kea-dhcp4.conf (interfaces, subnets, pools, options, reservations);
   - a bind9 zone file ($ORIGIN, $TTL, SOA, NS, A, CNAME; the final dot matters);
   - /etc/nftables.conf (inet/ip/bridge tables, filter and nat chains, first match, policy,
     ct state, iifname/oifname with wildcards and !=, ip saddr/daddr, tcp/udp ports, log,
     dnat, masquerade).

   Net.world(act, options) builds a model; Net.helpers(model) is what a ticket's checks receive.
   Net.run(model, device, command) answers the console. */
(function (root) {
  'use strict';
  var W = root.BlackoutWorld;

  // ——— Addresses ———
  function ipn(s) {
    var p = String(s == null ? '' : s).trim().split('.');
    if (p.length !== 4) return null;
    var n = 0;
    for (var i = 0; i < 4; i++) {
      if (!/^\d{1,3}$/.test(p[i]) || +p[i] > 255) return null;
      n = n * 256 + (+p[i]);
    }
    return n;
  }
  function ips(n) { return [Math.floor(n / 16777216) % 256, Math.floor(n / 65536) % 256, Math.floor(n / 256) % 256, n % 256].join('.'); }
  function cidr(s) {
    var m = /^\s*([\d.]+)(?:\/(\d{1,2}))?\s*$/.exec(String(s || ''));
    if (!m) return null;
    var ip = ipn(m[1]), len = m[2] === undefined ? 32 : +m[2];
    if (ip === null || len > 32) return null;
    return { ip: ip, len: len };
  }
  function size(len) { return Math.pow(2, 32 - len); }
  function netOf(ip, len) { return len === 0 ? 0 : ip - (ip % size(len)); }
  function inSub(ip, net, len) { return netOf(ip, len) === netOf(net, len); }
  function maskLen(mask) {
    var n = ipn(mask);
    if (n === null) return null;
    for (var len = 0; len <= 32; len++) if (n === 4294967296 - size(len)) return len;
    return null;
  }
  function isPrivate(n) { return inSub(n, ipn('10.0.0.0'), 8) || inSub(n, ipn('172.16.0.0'), 12) || inSub(n, ipn('192.168.0.0'), 16) || inSub(n, ipn('127.0.0.0'), 8); }

  // ——— Files ———
  function fileText(path, act) { var f = W.files[path]; return typeof f === 'function' ? f(act) : f; }
  function deviceAt(d, act) { return (d.since || 1) <= act; }
  /** The reference files of an act, keyed 'device:/path'. */
  function referenceFiles(act) {
    var out = {};
    W.devices.forEach(function (d) {
      if (!deviceAt(d, act)) return;
      Object.keys(W.files).forEach(function (k) { if (k.indexOf(d.id + ':') === 0) { var t = fileText(k, act); if (t !== undefined) out[k] = t; } });
    });
    return out;
  }
  /** Applies find/replace patches (a ticket's bug); reverse undoes them. Throws when a patch does not apply. */
  function patch(files, patches, reverse) {
    (patches || []).forEach(function (p) {
      var from = reverse ? p.replace : p.find, to = reverse ? p.find : p.replace;
      if (files[p.file] === undefined) throw new Error('Fichier inconnu : ' + p.file);
      if (files[p.file].indexOf(from) === -1) throw new Error('Motif introuvable dans ' + p.file + ' : ' + from.slice(0, 60));
      files[p.file] = files[p.file].split(from).join(to);
    });
    return files;
  }

  // ——— Parsers ———
  var IF_KEYS = ['address', 'netmask', 'gateway', 'dns-nameservers', 'dns-search', 'bridge-ports', 'bridge-vlan-aware', 'bridge-stp',
    'bridge-vids', 'bridge-pvid', 'vlan-raw-device', 'mtu', 'up', 'post-up', 'pre-up', 'down', 'post-down', 'hwaddress'];
  function parseInterfaces(text, file, errors) {
    var res = { auto: {}, ifaces: {}, order: [] }, cur = null;
    String(text || '').split('\n').forEach(function (raw, i) {
      var line = raw.replace(/#.*$/, '').trim();
      if (!line) return;
      var w = line.split(/\s+/);
      if (w[0] === 'auto' || w[0] === 'allow-hotplug') { w.slice(1).forEach(function (n) { res.auto[n] = true; }); cur = null; return; }
      if (w[0] === 'source' || w[0] === 'source-directory') { cur = null; return; }
      if (w[0] === 'iface') {
        if (!w[1]) { errors.push({ file: file, line: i + 1, message: 'iface sans nom d\'interface' }); return; }
        cur = { name: w[1], family: w[2] || 'inet', method: w[3] || 'manual', opts: {}, up: [], line: i + 1 };
        if (w[2] && w[2] !== 'inet') errors.push({ file: file, line: i + 1, message: 'famille inconnue « ' + w[2] + ' » (inet attendu)' });
        if (w[3] && ['static', 'dhcp', 'manual', 'loopback'].indexOf(w[3]) === -1) errors.push({ file: file, line: i + 1, message: 'méthode inconnue « ' + w[3] + ' »' });
        res.ifaces[w[1]] = cur; res.order.push(w[1]);
        return;
      }
      if (!cur) { errors.push({ file: file, line: i + 1, message: 'ligne hors de toute interface : « ' + line + ' »' }); return; }
      if (IF_KEYS.indexOf(w[0]) === -1) { errors.push({ file: file, line: i + 1, message: 'option inconnue « ' + w[0] + ' »' }); return; }
      var v = w.slice(1).join(' ');
      if (/^(up|post-up)$/.test(w[0])) cur.up.push({ cmd: v, line: i + 1 });
      else cur.opts[w[0]] = v;
    });
    return res;
  }

  function parseKeyValues(text) {
    var out = {};
    String(text || '').split('\n').forEach(function (raw) {
      var line = raw.replace(/#.*$/, '').trim();
      var m = /^([A-Za-z_][\w.]*)\s*=\s*(.*)$/.exec(line);
      if (m) out[m[1]] = m[2].replace(/^"(.*)"$/, '$1').trim();
    });
    return out;
  }

  function parseKea(text, file, errors) {
    var stripped = String(text || '').split('\n').map(function (l) { return /^\s*(#|\/\/)/.test(l) ? '' : l; }).join('\n');
    var j;
    try { j = JSON.parse(stripped); } catch (e) {
      var m = /position (\d+)/.exec(e.message), line = null;
      if (m) line = stripped.slice(0, +m[1]).split('\n').length;
      errors.push({ file: file, line: line, message: 'JSON invalide : une virgule, un guillemet ou une accolade de trop ou de moins' });
      return null;
    }
    var d = j && j.Dhcp4;
    if (!d) { errors.push({ file: file, line: 1, message: 'il manque l\'objet « Dhcp4 »' }); return null; }
    function opts(list) {
      var o = {};
      (list || []).forEach(function (x) { if (x && x.name) o[x.name] = String(x.data || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean); });
      return o;
    }
    var kea = { interfaces: (d['interfaces-config'] && d['interfaces-config'].interfaces) || [], options: opts(d['option-data']), subnets: [] };
    (d.subnet4 || []).forEach(function (s) {
      var c = cidr(s.subnet);
      if (!c) { errors.push({ file: file, line: null, message: 'sous-réseau illisible : « ' + s.subnet + ' »' }); return; }
      var sub = { id: s.id, net: c.ip, len: c.len, text: s.subnet, pools: [], options: opts(s['option-data']), reservations: [] };
      (s.pools || []).forEach(function (p) {
        var m = /^\s*([\d.]+)\s*-\s*([\d.]+)\s*$/.exec(p.pool || '');
        var a = m && ipn(m[1]), b = m && ipn(m[2]);
        if (a === null || b === null || a === undefined) { errors.push({ file: file, line: null, message: 'plage illisible : « ' + p.pool + ' »' }); return; }
        if (!inSub(a, c.ip, c.len) || !inSub(b, c.ip, c.len)) errors.push({ file: file, line: null, message: 'la plage ' + p.pool + ' sort du sous-réseau ' + s.subnet });
        sub.pools.push([a, b]);
      });
      (s.reservations || []).forEach(function (r) {
        sub.reservations.push({ mac: String(r['hw-address'] || '').toLowerCase(), ip: ipn(r['ip-address']), hostname: r.hostname || '' });
      });
      kea.subnets.push(sub);
    });
    return kea;
  }

  function parseZone(text, file, errors) {
    var z = { origin: null, records: {} }, last = null;
    var src = String(text || '').replace(/\(([^)]*)\)/g, function (m, inner) { return inner.replace(/\n/g, ' '); });
    src.split('\n').forEach(function (raw, i) {
      var line = raw.replace(/;.*$/, '');
      if (!line.trim()) return;
      var w = line.trim().split(/\s+/);
      if (w[0] === '$ORIGIN') { z.origin = w[1]; return; }
      if (w[0] === '$TTL') return;
      var name;
      if (/^\s/.test(line)) name = last; else { name = w.shift(); }
      if (/^\d+$/.test(w[0])) w.shift();
      if (w[0] === 'IN') w.shift();
      var type = (w.shift() || '').toUpperCase();
      if (['SOA', 'NS', 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'PTR'].indexOf(type) === -1) { errors.push({ file: file, line: i + 1, message: 'type d\'enregistrement inconnu « ' + type + ' »' }); return; }
      var fq = fqdn(name, z.origin);
      last = name;
      var data = w.join(' ');
      if (type === 'A' && ipn(data) === null) errors.push({ file: file, line: i + 1, message: 'adresse illisible « ' + data + ' »' });
      var rec = { type: type, data: data, line: i + 1, target: (type === 'CNAME' || type === 'NS') ? fqdn(w[0], z.origin) : null };
      (z.records[fq] = z.records[fq] || []).push(rec);
    });
    if (!z.origin) errors.push({ file: file, line: 1, message: 'il manque $ORIGIN' });
    return z;
  }
  function fqdn(name, origin) {
    if (!name || name === '@') return origin || '.';
    if (/\.$/.test(name)) return name.toLowerCase();
    return (name + '.' + (origin || '')).toLowerCase();
  }

  function parseWg(text, file, errors) {
    var wg = { iface: {}, peers: [] }, cur = null, KEYSI = ['Address', 'ListenPort', 'PrivateKey', 'DNS', 'MTU'], KEYSP = ['PublicKey', 'AllowedIPs', 'Endpoint', 'PersistentKeepalive', 'PresharedKey'];
    String(text || '').split('\n').forEach(function (raw, i) {
      var line = raw.replace(/#.*$/, '').trim();
      if (!line) return;
      if (line === '[Interface]') { cur = wg.iface; return; }
      if (line === '[Peer]') { cur = {}; wg.peers.push(cur); return; }
      var m = /^([A-Za-z]+)\s*=\s*(.+)$/.exec(line);
      if (!m || !cur) { errors.push({ file: file, line: i + 1, message: 'ligne illisible : « ' + line + ' »' }); return; }
      var allowed = cur === wg.iface ? KEYSI : KEYSP;
      if (allowed.indexOf(m[1]) === -1) { errors.push({ file: file, line: i + 1, message: 'clé inconnue « ' + m[1] + ' » dans la section ' + (cur === wg.iface ? '[Interface]' : '[Peer]') }); return; }
      cur[m[1]] = m[2].trim();
    });
    wg.allowed = function (peer) { return String(peer.AllowedIPs || '').split(',').map(function (x) { return cidr(x.trim()); }).filter(Boolean); };
    return wg;
  }
  function parseHostapd(text) {
    var bss = [], cur = null;
    String(text || '').split('\n').forEach(function (raw) {
      var line = raw.replace(/#.*$/, '').trim();
      var m = /^([a-z_0-9]+)=(.*)$/.exec(line);
      if (!m) return;
      if (m[1] === 'interface' || m[1] === 'bss') { cur = { iface: m[2].trim(), opts: {} }; bss.push(cur); return; }
      if (cur) cur.opts[m[1]] = m[2].trim();
    });
    return bss;
  }
  function parseRadiusClients(text) {
    var out = [], cur = null;
    String(text || '').split('\n').forEach(function (raw) {
      var line = raw.replace(/#.*$/, '').trim();
      var m = /^client\s+(\S+)\s*\{/.exec(line);
      if (m) { cur = { name: m[1] }; out.push(cur); return; }
      if (line === '}') { cur = null; return; }
      var kv = /^(\w+)\s*=\s*(.+)$/.exec(line);
      if (kv && cur) cur[kv[1]] = kv[2].replace(/^"(.*)"$/, '$1').trim();
    });
    return out;
  }
  function parseRadiusUsers(text) {
    var out = {};
    String(text || '').split('\n').forEach(function (raw) {
      var m = /^(\S+)\s+Cleartext-Password\s*:=\s*"([^"]*)"/.exec(raw.trim());
      if (m) out[m[1]] = m[2];
    });
    return out;
  }
  function parseNginx(text, file, errors) {
    var servers = [], cur = null;
    String(text || '').split('\n').forEach(function (raw, i) {
      var line = raw.replace(/#.*$/, '').trim();
      if (!line) return;
      if (/^server\s*\{$/.test(line)) { cur = { names: [], cert: null, line: i + 1 }; servers.push(cur); return; }
      if (line === '}') { cur = null; return; }
      if (!/;$/.test(line)) { errors.push({ file: file, line: i + 1, message: 'point-virgule manquant en fin de ligne' }); return; }
      var w = line.replace(/;$/, '').split(/\s+/);
      if (!cur) return;
      if (w[0] === 'server_name') cur.names = w.slice(1);
      else if (w[0] === 'ssl_certificate') cur.cert = w[1];
    });
    return servers;
  }
  function certNames(text) {
    var names = [], m, re = /DNS:([^\s,]+)/g;
    while ((m = re.exec(String(text || '')))) names.push(m[1].toLowerCase());
    var cn = /Subject:.*CN\s*=\s*([^\s,]+)/.exec(String(text || ''));
    if (cn && names.indexOf(cn[1].toLowerCase()) === -1) names.push(cn[1].toLowerCase());
    return names;
  }
  var SSHD_KEYS = ['include', 'permitrootlogin', 'pubkeyauthentication', 'passwordauthentication', 'kbdinteractiveauthentication', 'challengeresponseauthentication', 'usepam', 'x11forwarding', 'printmotd', 'acceptenv', 'subsystem', 'allowgroups', 'allowusers', 'maxauthtries', 'port', 'permitemptypasswords', 'logingracetime', 'clientaliveinterval', 'authorizedkeysfile', 'banner'];
  function sshdConfig(M, dev) {
    var main = dev + ':/etc/ssh/sshd_config';
    if (M.files[main] === undefined) return null;
    var values = {}, errors = [];
    function read(path) {
      String(M.files[path] || '').split('\n').forEach(function (raw, i) {
        var line = raw.replace(/#.*$/, '').trim();
        if (!line) return;
        var w = line.split(/\s+/), k = w[0].toLowerCase();
        if (SSHD_KEYS.indexOf(k) === -1) { errors.push({ file: path, line: i + 1, message: 'Bad configuration option: ' + w[0] }); return; }
        if (k === 'include') {
          var pat = w[1], dir = pat.slice(0, pat.lastIndexOf('/') + 1), suffix = pat.slice(pat.lastIndexOf('*') + 1);
          Object.keys(M.files).filter(function (f) { return f.indexOf(dev + ':' + dir) === 0 && f.slice(-suffix.length) === suffix && f.slice((dev + ':' + dir).length).indexOf('/') === -1; }).sort().forEach(read);
          return;
        }
        if (values[k] === undefined) values[k] = { value: w.slice(1).join(' '), file: path, line: i + 1 };
      });
    }
    read(main);
    return { values: values, errors: errors, get: function (k, def) { return values[k] ? values[k].value.toLowerCase() : def; } };
  }

  // nftables: a tokenizer and a small parser of the subset described above.
  function nftTokens(text) {
    var toks = [], line = 1, i = 0, s = String(text || '');
    while (i < s.length) {
      var c = s[i];
      if (c === '\n') { toks.push({ t: '\n', line: line }); line++; i++; continue; }
      if (c === ' ' || c === '\t' || c === '\r') { i++; continue; }
      if (c === '#') { while (i < s.length && s[i] !== '\n') i++; continue; }
      if (c === '"') { var j = s.indexOf('"', i + 1); if (j === -1) j = s.length; toks.push({ t: s.slice(i, j + 1), line: line }); i = j + 1; continue; }
      if ('{};,'.indexOf(c) !== -1) { toks.push({ t: c, line: line }); i++; continue; }
      var k = i;
      while (k < s.length && !/[\s{};,"#]/.test(s[k])) k++;
      toks.push({ t: s.slice(i, k), line: line });
      i = k;
    }
    return toks;
  }
  function unq(t) { return t.replace(/^"(.*)"$/, '$1'); }
  function parseNft(text, file, errors) {
    var toks = nftTokens(text), i = 0, tables = [];
    function err(tok, msg) { errors.push({ file: file, line: tok ? tok.line : null, message: msg }); }
    function skipNl() { while (i < toks.length && (toks[i].t === '\n' || toks[i].t === ';')) i++; }
    function expect(t) { if (!toks[i] || toks[i].t !== t) { err(toks[i] || toks[i - 1], '« ' + t + ' » attendu' + (toks[i] ? ' avant « ' + toks[i].t + ' »' : ' en fin de fichier')); return false; } i++; return true; }
    function statement() {
      var out = [], depth = 0;
      while (i < toks.length) {
        var t = toks[i].t;
        if (depth === 0 && (t === '\n' || t === ';' || t === '}')) break;
        if (t === '{') depth++;
        if (t === '}') depth--;
        if (t !== '\n') out.push(toks[i]);
        i++;
      }
      return out;
    }
    while (i < toks.length) {
      skipNl();
      if (i >= toks.length) break;
      var tok = toks[i];
      if (tok.t === 'flush') { statement(); continue; }
      if (tok.t === 'define') { statement(); continue; }
      if (tok.t !== 'table') { err(tok, 'instruction inattendue « ' + tok.t + ' »'); statement(); continue; }
      i++;
      var family = toks[i] && toks[i].t, name = toks[i + 1] && toks[i + 1].t;
      if (['inet', 'ip', 'bridge', 'ip6', 'arp', 'netdev'].indexOf(family) === -1) { err(toks[i], 'famille de table inconnue « ' + family + ' »'); }
      i += 2;
      if (!expect('{')) break;
      var table = { family: family, name: name, chains: [] };
      tables.push(table);
      for (;;) {
        skipNl();
        if (i >= toks.length) { err(toks[i - 1], 'accolade fermante manquante pour la table ' + name); break; }
        if (toks[i].t === '}') { i++; break; }
        if (toks[i].t !== 'chain') { err(toks[i], 'instruction inattendue dans la table : « ' + toks[i].t + ' »'); statement(); continue; }
        i++;
        var chain = { name: toks[i] && toks[i].t, hook: null, type: null, policy: 'accept', rules: [], line: toks[i] && toks[i].line };
        i++;
        if (!expect('{')) break;
        table.chains.push(chain);
        for (;;) {
          skipNl();
          if (i >= toks.length) { err(toks[i - 1], 'accolade fermante manquante pour la chaîne ' + chain.name); break; }
          if (toks[i].t === '}') { i++; break; }
          var st = statement();
          if (!st.length) { i++; continue; }
          if (st[0].t === 'type') {
            chain.type = st[1] && st[1].t;
            for (var k = 2; k < st.length; k++) { if (st[k].t === 'hook') chain.hook = st[k + 1] && st[k + 1].t; }
            continue;
          }
          if (st[0].t === 'policy') { chain.policy = st[1] && st[1].t; if (['accept', 'drop'].indexOf(chain.policy) === -1) err(st[0], 'politique inconnue « ' + chain.policy + ' »'); continue; }
          var rule = parseRule(st, err);
          if (rule) chain.rules.push(rule);
        }
      }
    }
    return tables;
  }
  function parseRule(st, err) {
    var r = { line: st[0].line, text: st.map(function (x) { return x.t; }).join(' ').replace(/ , /g, ', ').replace(/\{ /g, '{ ').replace(/ \}/g, ' }'), m: [], verdict: null, log: null, nat: null }, i = 0;
    function value() {
      var neg = false;
      if (st[i] && st[i].t === '!=') { neg = true; i++; }
      var vals = [];
      if (st[i] && st[i].t === '{') {
        i++;
        while (st[i] && st[i].t !== '}') { if (st[i].t !== ',') vals.push(unq(st[i].t)); i++; }
        i++;
      } else if (st[i]) {
        vals = unq(st[i].t).split(','); i++;
        while (st[i] && st[i].t === ',' && st[i + 1]) { vals.push(unq(st[i + 1].t)); i += 2; }
      }
      return { neg: neg, vals: vals };
    }
    while (i < st.length) {
      var t = st[i].t;
      if (t === 'iifname' || t === 'oifname') { i++; r.m.push({ k: t, v: value() }); continue; }
      if (t === 'ip' && st[i + 1] && (st[i + 1].t === 'saddr' || st[i + 1].t === 'daddr')) { var f = st[i + 1].t; i += 2; r.m.push({ k: f, v: value() }); continue; }
      if (t === 'ip' && st[i + 1] && st[i + 1].t === 'protocol') { i += 2; r.m.push({ k: 'proto', v: value() }); continue; }
      if (t === 'meta' && st[i + 1] && st[i + 1].t === 'l4proto') { i += 2; r.m.push({ k: 'proto', v: value() }); continue; }
      if ((t === 'tcp' || t === 'udp') && st[i + 1] && (st[i + 1].t === 'dport' || st[i + 1].t === 'sport')) {
        var fld = st[i + 1].t; i += 2;
        r.m.push({ k: 'proto', v: { neg: false, vals: [t] } });
        r.m.push({ k: fld, v: value() });
        continue;
      }
      if (t === 'icmp' && st[i + 1] && st[i + 1].t === 'type') { i += 2; value(); r.m.push({ k: 'proto', v: { neg: false, vals: ['icmp'] } }); continue; }
      if (t === 'ct' && st[i + 1] && st[i + 1].t === 'state') { i += 2; r.m.push({ k: 'ct', v: value() }); continue; }
      if (t === 'counter') { i++; continue; }
      if (t === 'log') { i++; r.log = ''; if (st[i] && st[i].t === 'prefix') { r.log = unq(st[i + 1] ? st[i + 1].t : ''); i += 2; } continue; }
      if (t === 'accept' || t === 'drop' || t === 'reject') { r.verdict = t; i++; continue; }
      if (t === 'masquerade') { r.nat = { kind: 'masquerade' }; i++; continue; }
      if ((t === 'dnat' || t === 'snat') && st[i + 1] && st[i + 1].t === 'to') {
        var to = st[i + 2] ? st[i + 2].t : '';
        var mm = /^([\d.]+)(?::(\d+))?$/.exec(to);
        if (!mm || ipn(mm[1]) === null) { err(st[i + 2] || st[i], 'adresse de ' + t + ' illisible « ' + to + ' »'); return null; }
        r.nat = { kind: t, ip: ipn(mm[1]), port: mm[2] ? +mm[2] : null };
        i += 3; continue;
      }
      err(st[i], 'mot inconnu dans la règle : « ' + t + ' »');
      return null;
    }
    if (!r.verdict && !r.nat && r.log === null) { err(st[0], 'règle sans décision (accept, drop, dnat, masquerade…)'); return null; }
    return r;
  }

  // ——— The model ———
  function world(act, o) {
    o = o || {};
    var files = o.files || patch(referenceFiles(act), o.bug || []);
    var M = { act: act, files: files, devices: {}, ends: {}, portEnds: {}, errors: [], flags: Object.assign({ ghostDhcp: act === 8 }, o.flags || {}), leases: {}, storm: null, cache: {}, wg: {}, wifi: {} };
    W.devices.forEach(function (d) {
      if (!deviceAt(d, act)) return;
      M.devices[d.id] = { id: d.id, def: d, kind: d.kind, l3: [], routes: [], forward: !!W.forwarders[d.id], services: d.services || [] };
    });
    W.cables.concat(o.cables || []).forEach(function (c) {
      if ((c[2] || 1) > act) return;
      var a = c[0].split(':'), b = c[1].split(':');
      if (!M.devices[a[0]] || !M.devices[b[0]]) return;
      M.ends[c[0]] = { dev: b[0], port: b[1] };
      M.ends[c[1]] = { dev: a[0], port: a[1] };
      [c[0], c[1]].forEach(function (end) { var base = end.split('#')[0]; (M.portEnds[base] = M.portEnds[base] || []).push(end); });
    });
    Object.keys(M.devices).forEach(function (id) { buildDevice(M, M.devices[id]); });
    detectStorm(M);
    wifiAuth(M);
    // The DHCP clients, in a stable order: the pool hands out the same address every time.
    M.poolCursor = {};
    Object.keys(M.devices).forEach(function (id) {
      M.devices[id].l3.forEach(function (ifc) { if (ifc.method === 'dhcp' && ifc.up) { M.leases[id + ':' + ifc.name] = dhcp(M, id, ifc.name); } });
    });
    wireguard(M);
    return M;
  }

  // Wi-Fi: a 802.1X network lets a device in only once RADIUS has accepted its account.
  function radiusCheck(M, apId, bss, user, password) {
    var ap = M.devices[apId];
    var srvIp = ipn(bss.opts.auth_server_addr), secret = bss.opts.auth_server_shared_secret || '';
    var srv = ownerOf(M, srvIp);
    if (!srv || !M.devices[srv].radius) return { ok: false, detail: 'aucun serveur RADIUS en ' + (bss.opts.auth_server_addr || '?') };
    var apIp = primary(M, apId);
    var f = flow(M, apId, srvIp, 'udp', 1812);
    if (!f.ok && !f.refused) return { ok: false, detail: 'le point d\'accès ne joint pas le serveur RADIUS : ' + f.detail };
    var r = M.devices[srv].radius;
    var client = r.clients.filter(function (c) { return apIp && ipn(c.ipaddr) === apIp.ip; })[0];
    if (!client) return { ok: false, detail: 'freeradius ignore le point d\'accès : aucun client déclaré pour ' + (apIp ? ips(apIp.ip) : '?') };
    if (client.secret !== secret) return { ok: false, detail: 'freeradius rejette le point d\'accès : le secret partagé ne correspond pas (Message-Authenticator invalide)' };
    if (r.users[user] === undefined || r.users[user] !== password) return { ok: false, detail: 'Access-Reject : identifiant ou mot de passe inconnu' };
    return { ok: true, detail: 'Access-Accept' };
  }
  function wifiAuth(M) {
    Object.keys(M.devices).forEach(function (id) {
      var d = M.devices[id], w = d.def.wifi;
      if (!w) return;
      var end = M.ends[id + ':ens18'], ap = end && M.devices[end.dev];
      var bss = ap && ap.hostapd && ap.hostapd.filter(function (b) { return b.iface === end.port.split('#')[0]; })[0];
      var res = { ok: true, detail: 'associé à ' + w.ssid };
      if (!bss) res = { ok: false, detail: 'aucun point d\'accès' };
      else if (/EAP/.test(bss.opts.wpa_key_mgmt || '')) res = radiusCheck(M, end.dev, bss, w.user || '', w.password || '');
      M.wifi[id] = res;
      if (!res.ok) d.l3.forEach(function (ifc) { ifc.up = false; });
    });
  }
  // WireGuard: the tunnel comes up when each side holds the other's public key and the endpoint answers.
  function wireguard(M) {
    var pairs = [['arbitre-distant', 'r1']];
    pairs.forEach(function (p) {
      var a = M.devices[p[0]], b = M.devices[p[1]];
      if (!a || !b || !a.wgConf || !b.wgConf) return;
      var pubA = W.wgKeys[a.wgConf.iface.PrivateKey], pubB = W.wgKeys[b.wgConf.iface.PrivateKey];
      var peerOfA = a.wgConf.peers.filter(function (x) { return x.PublicKey === pubB; })[0];
      var peerOfB = b.wgConf.peers.filter(function (x) { return x.PublicKey === pubA; })[0];
      var why = null;
      if (!peerOfA) why = p[0] + ' ne connaît pas la clé publique de ' + p[1];
      else if (!peerOfB) why = p[1] + ' ne connaît pas la clé publique de ' + p[0] + ' : la poignée de main est ignorée';
      else {
        var ep = /^([\d.]+):(\d+)$/.exec(peerOfA.Endpoint || '');
        if (!ep) why = 'pas d\'Endpoint pour joindre ' + p[1];
        else { var f = flow(M, p[0], ipn(ep[1]), 'udp', +ep[2]); if (!f.ok) why = 'l\'Endpoint ne répond pas : ' + f.detail; }
      }
      M.wgState = { up: !why, why: why };
      if (why) return;
      M.wg[p[0]] = { peer: p[1], allowed: a.wgConf.allowed(peerOfA) };
      M.wg[p[1]] = { peer: p[0], allowed: b.wgConf.allowed(peerOfB) };
      [a, b].forEach(function (d) { d.l3.forEach(function (ifc) { if (ifc.name === 'wg0') ifc.up = true; }); });
      M.cache = {};
    });
  }

  function buildDevice(M, d) {
    var path = d.id + ':/etc/network/interfaces';
    if (d.kind === 'box') { d.l3.push({ name: 'lan', raw: 'lan', ip: ipn('192.168.100.1'), len: 24, up: true, method: 'static', dns: [], routes: [], gw: null }); d.forward = true; return; }
    if (d.kind === 'internet') return;
    var p = parseInterfaces(M.files[path], path, M.errors);
    if (d.kind === 'remote') d.forward = false;
    d.ifcfg = p;
    if (d.kind === 'switch') {
      var br = p.ifaces.br0 || { opts: {} };
      d.bridge = {
        ports: (br.opts['bridge-ports'] || '').split(/\s+/).filter(Boolean),
        stp: /^(on|yes)$/.test(br.opts['bridge-stp'] || 'off'),
        aware: /^yes$/.test(br.opts['bridge-vlan-aware'] || 'no'),
        vids: nums(br.opts['bridge-vids']), pvid: br.opts['bridge-pvid'] ? +br.opts['bridge-pvid'] : 1
      };
      d.ports = {};
      d.bridge.ports.forEach(function (pn) {
        var pc = p.ifaces[pn] || { opts: {} };
        var vids = pc.opts['bridge-vids'] !== undefined ? nums(pc.opts['bridge-vids']) : d.bridge.vids.slice();
        var pvid = pc.opts['bridge-pvid'] !== undefined ? +pc.opts['bridge-pvid'] : d.bridge.pvid;
        d.ports[pn] = { vids: vids, pvid: pvid };
      });
    }
    p.order.forEach(function (name) {
      var c = p.ifaces[name];
      if (name === 'lo' || name === 'br0') return;
      if (d.kind === 'switch' && d.bridge.ports.indexOf(name) !== -1) return;
      var ifc = { name: name, method: c.method, up: !!p.auto[name], ip: null, len: null, gw: null, dns: [], routes: [] };
      var dot = name.indexOf('.');
      if (dot !== -1) { ifc.raw = name.slice(0, dot); ifc.vid = +name.slice(dot + 1); ifc.up = ifc.up && (d.kind === 'switch' ? ifc.raw === 'br0' : !!p.auto[ifc.raw]); }
      else ifc.raw = name;
      if (c.method === 'static') {
        var a = cidr(c.opts.address);
        if (a && c.opts.netmask && a.len === 32) { var ml = maskLen(c.opts.netmask); if (ml !== null) a.len = ml; }
        if (!a) M.errors.push({ file: path, line: c.line, message: 'adresse illisible pour ' + name + ' : « ' + (c.opts.address || '') + ' »' });
        else { ifc.ip = a.ip; ifc.len = a.len; }
      }
      if (c.opts.gateway) ifc.gw = ipn(c.opts.gateway);
      if (c.opts['dns-nameservers']) ifc.dns = c.opts['dns-nameservers'].split(/\s+/).map(ipn).filter(function (x) { return x !== null; });
      c.up.forEach(function (u) {
        var m = /^ip route add (\S+) via (\S+)/.exec(u.cmd);
        if (m && cidr(m[1]) && ipn(m[2]) !== null) ifc.routes.push({ to: cidr(m[1]), via: ipn(m[2]) });
        else M.errors.push({ file: path, line: u.line, message: 'commande non reconnue : « ' + u.cmd + ' »' });
      });
      d.l3.push(ifc);
    });
    // r1: forwarding, relay, firewall
    var sys = M.files[d.id + ':/etc/sysctl.d/99-routage.conf'];
    if (sys !== undefined) d.forward = /^\s*net\.ipv4\.ip_forward\s*=\s*1\s*$/m.test(sys.replace(/#.*$/gm, ''));
    var rel = M.files[d.id + ':/etc/default/isc-dhcp-relay'];
    if (rel !== undefined) { var kv = parseKeyValues(rel); d.relay = { servers: (kv.SERVERS || '').split(/\s+/).map(ipn).filter(function (x) { return x !== null; }), ifaces: (kv.INTERFACES || '').split(/\s+/).filter(Boolean) }; }
    var nft = M.files[d.id + ':/etc/nftables.conf'];
    if (nft !== undefined) d.nft = parseNft(nft, d.id + ':/etc/nftables.conf', M.errors);
    var kea = M.files[d.id + ':/etc/kea/kea-dhcp4.conf'];
    if (kea !== undefined) d.kea = parseKea(kea, d.id + ':/etc/kea/kea-dhcp4.conf', M.errors);
    var zonePath = d.id + ':/etc/bind/db.tournoi.lan';
    if (M.files[zonePath] !== undefined) d.zone = parseZone(M.files[zonePath], zonePath, M.errors);
    var wgPath = d.id + ':/etc/wireguard/wg0.conf';
    if (M.files[wgPath] !== undefined) {
      d.wgConf = parseWg(M.files[wgPath], wgPath, M.errors);
      var wa = cidr(d.wgConf.iface.Address);
      d.l3.push({ name: 'wg0', raw: 'wg0', ip: wa ? wa.ip : null, len: wa ? wa.len : null, up: false, method: 'static', gw: null, dns: [], routes: [], wg: true });
      if (d.wgConf.iface.ListenPort) d.services = d.services.concat(['udp/' + d.wgConf.iface.ListenPort]);
    }
    var hp = d.id + ':/etc/hostapd/hostapd.conf';
    if (M.files[hp] !== undefined) d.hostapd = parseHostapd(M.files[hp]);
    var rc = d.id + ':/etc/freeradius/3.0/clients.conf';
    if (M.files[rc] !== undefined) { d.radius = { clients: parseRadiusClients(M.files[rc]), users: parseRadiusUsers(M.files[d.id + ':/etc/freeradius/3.0/mods-config/files/authorize']) }; d.services = d.services.concat(['udp/1812']); }
    var ng = d.id + ':/etc/nginx/sites-enabled/inscriptions';
    if (M.files[ng] !== undefined) d.nginx = parseNginx(M.files[ng], ng, M.errors);
  }
  function nums(s) { return String(s || '').split(/[\s,]+/).filter(Boolean).map(Number).filter(function (n) { return n > 0 && n < 4095; }); }

  // A cycle among switches, with STP off somewhere on it, floods everything.
  function detectStorm(M) {
    var parent = {};
    function find(x) { while (parent[x] !== x) x = parent[x] = parent[parent[x]]; return x; }
    Object.keys(M.devices).forEach(function (id) { if (M.devices[id].kind === 'switch') parent[id] = id; });
    var seen = {};
    Object.keys(M.ends).forEach(function (a) {
      var b = M.ends[a], ka = a.split(':'), key = [a, b.dev + ':' + b.port].sort().join('|');
      if (seen[key]) return; seen[key] = true;
      var da = M.devices[ka[0]], db = M.devices[b.dev];
      if (!da || !db || da.kind !== 'switch' || db.kind !== 'switch') return;
      if (!da.ports[ka[1]] || !db.ports[b.port]) return;
      var ra = find(da.id), rb = find(db.id);
      if (ra === rb) {
        var culprit = !da.bridge.stp ? da.id : (!db.bridge.stp ? db.id : null);
        if (culprit && !M.storm) M.storm = { at: culprit, ports: [ka[1], b.port] };
      } else parent[ra] = rb;
    });
  }

  // ——— Layer 2: who hears whom ———
  function ingress(port, tag) {
    if (tag === null) return port.pvid > 0 ? port.pvid : null;
    return (port.vids.indexOf(tag) !== -1 || port.pvid === tag) ? tag : null;
  }
  function egress(port, vlan) {
    if (port.pvid === vlan) return { tag: null };
    if (port.vids.indexOf(vlan) !== -1) return { tag: vlan };
    return null;
  }
  function iface(M, dev, name) { var d = M.devices[dev]; if (!d) return null; for (var i = 0; i < d.l3.length; i++) if (d.l3[i].name === name) return d.l3[i]; return null; }
  function addrOf(M, dev, ifc) {
    if (ifc.ip !== null && ifc.ip !== undefined) return { ip: ifc.ip, len: ifc.len };
    var l = M.leases[dev + ':' + ifc.name];
    return l && l.ok ? { ip: l.ip, len: l.len } : null;
  }

  /** The L3 interfaces that share the broadcast domain of dev:ifname, and the VLAN it is in. */
  function domain(M, dev, name) {
    var key = dev + ':' + name;
    if (M.cache[key]) return M.cache[key];
    var res = { members: [], vlan: null, switches: [], storm: false };
    M.cache[key] = res;
    var ifc = iface(M, dev, name);
    if (!ifc || !ifc.up) return res;
    res.members.push({ dev: dev, iface: name });
    var d = M.devices[dev], visited = {};
    function visit(sw, vlan) {
      var k = sw + '|' + vlan;
      if (visited[k]) return;
      visited[k] = true;
      if (res.switches.indexOf(sw) === -1) res.switches.push(sw);
      if (res.vlan === null) res.vlan = vlan;
      if (M.storm) res.storm = true;
      var s = M.devices[sw];
      s.l3.forEach(function (x) { if (x.vid === vlan && x.up) res.members.push({ dev: sw, iface: x.name }); });
      s.bridge.ports.forEach(function (pn) {
        var e = egress(s.ports[pn], vlan);
        if (e) (M.portEnds[sw + ':' + pn] || []).forEach(function (end) { send(end, e.tag); });
      });
    }
    function send(end, tag) {
      var peer = M.ends[end];
      if (!peer) return;
      var p = M.devices[peer.dev];
      if (!p) return;
      if (p.kind === 'switch') {
        var port = p.ports[peer.port.split('#')[0]];
        if (!port) return;
        var v = ingress(port, tag);
        if (v !== null) visit(peer.dev, v);
        return;
      }
      var target = tag === null ? peer.port : peer.port + '.' + tag;
      var t = iface(M, peer.dev, target);
      if (t && t.up && !(peer.dev === dev && target === name)) res.members.push({ dev: peer.dev, iface: target });
    }
    if (d.kind === 'switch') { if (ifc.vid) visit(dev, ifc.vid); }
    else send(dev + ':' + ifc.raw, ifc.vid ? ifc.vid : null);
    if (res.vlan === null && ifc.vid) res.vlan = ifc.vid;
    return res;
  }

  // ——— Layer 3 ———
  function owns(M, dev, ip) {
    var d = M.devices[dev];
    if (!d) return null;
    if (d.kind === 'internet') return isPrivate(ip) || ip === ipn(W.box.public) || remoteOwner(M, ip) ? null : { name: 'wan' };
    for (var i = 0; i < d.l3.length; i++) { var a = d.l3[i].up && addrOf(M, dev, d.l3[i]); if (a && a.ip === ip) return d.l3[i]; }
    return null;
  }
  function remoteOwner(M, ip) {
    var found = null;
    Object.keys(M.devices).forEach(function (id) {
      var d = M.devices[id];
      if (d.kind !== 'remote') return;
      d.l3.forEach(function (ifc) { if (ifc.up && ifc.ip === ip && ifc.name !== 'wg0') found = id; });
    });
    return found;
  }
  function routes(M, dev) {
    var d = M.devices[dev], out = [];
    if (d.kind === 'box') return [{ net: ipn('192.168.100.0'), len: 24, dev: 'lan', via: null }, { net: 0, len: 0, dev: 'wan', via: null, internet: true }];
    if (d.kind === 'internet') return [];
    var connected = [];
    d.l3.forEach(function (ifc) {
      if (!ifc.up) return;
      var a = addrOf(M, dev, ifc);
      if (!a) return;
      connected.push({ net: netOf(a.ip, a.len), len: a.len, dev: ifc.name, via: null, src: a.ip });
    });
    out = connected.slice();
    if (M.wg[dev]) M.wg[dev].allowed.forEach(function (c) { out.push({ net: netOf(c.ip, c.len), len: c.len, dev: 'wg0', via: null }); });
    if (d.kind === 'remote') out.push({ net: 0, len: 0, dev: 'wan', via: null, internet: true });
    function onLink(gw) { for (var i = 0; i < connected.length; i++) if (inSub(gw, connected[i].net, connected[i].len)) return connected[i]; return null; }
    d.l3.forEach(function (ifc) {
      if (!ifc.up) return;
      ifc.routes.forEach(function (r) { var c = onLink(r.via); if (c) out.push({ net: netOf(r.to.ip, r.to.len), len: r.to.len, dev: c.dev, via: r.via }); });
      var gw = ifc.gw;
      var lease = M.leases[dev + ':' + ifc.name];
      if (gw === null && lease && lease.ok && lease.routers.length) gw = lease.routers[0];
      if (gw !== null && gw !== undefined) { var c2 = onLink(gw); if (c2) out.push({ net: 0, len: 0, dev: c2.dev, via: gw }); }
    });
    return out;
  }
  function lookup(M, dev, ip) {
    var best = null;
    routes(M, dev).forEach(function (r) { if (inSub(ip, r.net, r.len) && (!best || r.len > best.len)) best = r; });
    return best;
  }
  function primary(M, dev) {
    var d = M.devices[dev];
    if (!d) return null;
    if (d.kind === 'box') return { name: 'lan', ip: ipn('192.168.100.1') };
    for (var i = 0; i < d.l3.length; i++) { var a = d.l3[i].up && addrOf(M, dev, d.l3[i]); if (a) return { name: d.l3[i].name, ip: a.ip, len: a.len }; }
    return null;
  }

  // nftables evaluation
  function matchName(v, name) {
    var hit = v.vals.some(function (p) { return /\*$/.test(p) ? (name || '').indexOf(p.slice(0, -1)) === 0 : p === name; });
    return v.neg ? !hit : hit;
  }
  function matchAddr(v, ip) {
    var hit = v.vals.some(function (p) { var c = cidr(p); return c && inSub(ip, c.ip, c.len); });
    return v.neg ? !hit : hit;
  }
  function matchPort(v, port) {
    if (port === null || port === undefined) return false;
    var hit = v.vals.some(function (p) { var m = /^(\d+)-(\d+)$/.exec(p); return m ? port >= +m[1] && port <= +m[2] : +p === port; });
    return v.neg ? !hit : hit;
  }
  function ruleMatches(r, pkt) {
    return r.m.every(function (m) {
      switch (m.k) {
        case 'iifname': return matchName(m.v, pkt.iif);
        case 'oifname': return matchName(m.v, pkt.oif);
        case 'saddr': return matchAddr(m.v, pkt.src);
        case 'daddr': return matchAddr(m.v, pkt.dst);
        case 'proto': { var h = m.v.vals.indexOf(pkt.proto) !== -1; return m.v.neg ? !h : h; }
        case 'dport': return matchPort(m.v, pkt.dport);
        case 'sport': return matchPort(m.v, pkt.sport);
        case 'ct': { var states = pkt.ct === 'est' ? ['established'] : ['new']; var h2 = m.v.vals.some(function (s) { return states.indexOf(s) !== -1; }); return m.v.neg ? !h2 : h2; }
        default: return false;
      }
    });
  }
  function chainsOf(d, hook, families) {
    var out = [];
    (d.nft || []).forEach(function (t) { if (families.indexOf(t.family) === -1) return; t.chains.forEach(function (c) { if (c.hook === hook) out.push(c); }); });
    return out;
  }
  function filter(M, dev, hook, pkt, families) {
    var d = M.devices[dev], logs = [];
    var chains = chainsOf(d, hook, families || ['inet', 'ip']).filter(function (c) { return c.type === 'filter'; });
    for (var ci = 0; ci < chains.length; ci++) {
      var c = chains[ci], decided = null;
      for (var ri = 0; ri < c.rules.length; ri++) {
        var r = c.rules[ri];
        if (!ruleMatches(r, pkt)) continue;
        if (r.log !== null) logs.push({ rule: r, prefix: r.log });
        if (r.verdict) { decided = { verdict: r.verdict, rule: r }; break; }
      }
      if (!decided) decided = { verdict: c.policy, rule: null, chain: c };
      if (decided.verdict !== 'accept') { decided.logs = logs; return decided; }
    }
    return { verdict: 'accept', logs: logs };
  }
  function natRule(M, dev, hook, pkt) {
    var d = M.devices[dev];
    var chains = chainsOf(d, hook, ['inet', 'ip']).filter(function (c) { return c.type === 'nat'; });
    for (var ci = 0; ci < chains.length; ci++) {
      var rs = chains[ci].rules;
      for (var ri = 0; ri < rs.length; ri++) if (rs[ri].nat && ruleMatches(rs[ri], pkt)) return rs[ri];
    }
    return null;
  }

  function isolated(M, dev, ifname) {
    var ifc = iface(M, dev, ifname);
    var end = M.ends[dev + ':' + (ifc ? ifc.raw : ifname)];
    if (!end) return null;
    var ap = M.devices[end.dev];
    if (!ap || !ap.hostapd) return null;
    var base = end.port.split('#')[0];
    var bss = ap.hostapd.filter(function (b) { return b.iface === base; })[0];
    return bss && bss.opts.ap_isolate === '1' ? end.dev + ':' + base : null;
  }

  /** One direction of a flow, hop by hop. pkt: { src, dst, proto, dport, sport, ct }. */
  function walk(M, from, pkt, ctx) {
    ctx = ctx || { nat: [] };
    var hops = [{ dev: from }], cur = from, inIf = null, first = true;
    function die(dev, reason, rule) { return { ok: false, hops: hops, died: { dev: dev, reason: reason, rule: rule || null }, pkt: pkt, ctx: ctx }; }
    for (var guard = 0; guard < 24; guard++) {
      var d = M.devices[cur];
      // Translations met on the way back: the device that translated undoes it.
      if (pkt.ct === 'est') {
        ctx.nat.forEach(function (n) {
          if (n.dev !== cur) return;
          if (n.kind === 'snat' && pkt.dst === n.to) pkt.dst = n.from;
          if (n.kind === 'dnat' && pkt.src === n.to) { pkt.src = n.from; if (n.port) pkt.sport = n.fromPort; }
        });
      }
      if (d.kind === 'internet') {
        if (pkt.dst === ipn(W.box.public)) { cur = 'box'; inIf = 'wan'; hops.push({ dev: 'box', iface: 'wan' }); first = false; continue; }
        var ro = remoteOwner(M, pkt.dst);
        if (ro) { cur = ro; inIf = 'wan'; hops.push({ dev: ro, iface: 'wan' }); first = false; continue; }
        if (isPrivate(pkt.dst)) return die('internet', 'une adresse privée (' + ips(pkt.dst) + ') ne circule pas sur Internet');
        if (!W.internet.hosts[ips(pkt.dst)] && pkt.ct !== 'est') return die('internet', 'personne ne répond à ' + ips(pkt.dst));
        return { ok: true, hops: hops, delivered: { dev: 'internet', iface: 'wan' }, pkt: pkt, ctx: ctx };
      }
      // DNAT on the way in, before deciding whether the packet is for this device
      if (!first && d.nft && pkt.ct === 'new') {
        var dn = natRule(M, cur, 'prerouting', { iif: inIf, src: pkt.src, dst: pkt.dst, proto: pkt.proto, dport: pkt.dport, sport: pkt.sport, ct: 'new' });
        if (dn && dn.nat.kind === 'dnat') { ctx.nat.push({ dev: cur, kind: 'dnat', from: pkt.dst, to: dn.nat.ip, fromPort: pkt.dport, port: dn.nat.port }); pkt.dst = dn.nat.ip; if (dn.nat.port) pkt.dport = dn.nat.port; }
      }
      var own = owns(M, cur, pkt.dst);
      if (own) {
        if (!first && d.nft && pkt.ct === 'new') {
          var vin = filter(M, cur, 'input', { iif: inIf, oif: null, src: pkt.src, dst: pkt.dst, proto: pkt.proto, dport: pkt.dport, sport: pkt.sport, ct: pkt.ct });
          if (vin.verdict !== 'accept') return die(cur, 'refusé par le pare-feu de ' + cur + (vin.rule ? ' (ligne ' + vin.rule.line + ')' : ' (politique par défaut)'), vin.rule);
        }
        return { ok: true, hops: hops, delivered: { dev: cur, iface: own.name }, pkt: pkt, ctx: ctx };
      }
      if (d.kind === 'box') {
        if (inIf === 'wan' && pkt.ct === 'new') {
          var fw = W.box.forwards.filter(function (f) { return f.proto === pkt.proto && f.port === pkt.dport; })[0];
          if (!fw || pkt.dst !== ipn(W.box.public)) return die('box', 'la box ne fait suivre que le 443 et le 51820 : rien n\'est redirigé pour ' + pkt.proto + '/' + pkt.dport);
          ctx.nat.push({ dev: 'box', kind: 'dnat', from: pkt.dst, to: ipn(fw.to) });
          pkt.dst = ipn(fw.to);
        } else if (!isPrivate(pkt.dst)) {
          if (!inSub(pkt.src, ipn('192.168.100.0'), 24) && pkt.src !== ipn(W.box.public)) {
            return die('box', 'la box ne connaît pas ' + ips(pkt.src) + ' : sans NAT sur r1, la réponse ne pourrait jamais revenir');
          }
          if (pkt.ct === 'new') { ctx.nat.push({ dev: 'box', kind: 'snat', from: pkt.src, to: ipn(W.box.public) }); pkt.src = ipn(W.box.public); }
          cur = 'internet'; hops.push({ dev: 'internet' }); first = false; inIf = 'wan'; continue;
        }
      }
      if (!first && !d.forward) return die(cur, cur === 'r1' ? 'r1 ne fait pas suivre les paquets (net.ipv4.ip_forward vaut 0)' : cur + ' ne fait pas suivre les paquets : ce n\'est pas un routeur');
      var rt = lookup(M, cur, pkt.dst);
      if (!rt) return die(cur, cur + ' n\'a aucune route vers ' + ips(pkt.dst));
      if (rt.internet && d.kind === 'remote' && isPrivate(pkt.dst) && d.wgConf) return die(cur, 'le tunnel WireGuard n\'est pas établi' + (M.wgState && M.wgState.why ? ' : ' + M.wgState.why : '') + ' ; sans lui, ' + ips(pkt.dst) + ' part sur Internet, où une adresse privée ne circule pas');
      if (rt.internet) { cur = 'internet'; hops.push({ dev: 'internet' }); first = false; inIf = 'wan'; continue; }
      if (!first && d.nft) {
        var v = filter(M, cur, 'forward', { iif: inIf, oif: rt.dev, src: pkt.src, dst: pkt.dst, proto: pkt.proto, dport: pkt.dport, sport: pkt.sport, ct: pkt.ct });
        if (v.verdict !== 'accept') return die(cur, 'refusé par le pare-feu de ' + cur + (v.rule ? ' (ligne ' + v.rule.line + ' : ' + v.rule.text + ')' : ' (politique par défaut : drop)'), v.rule);
      }
      if (d.nft && pkt.ct === 'new') {
        var sn = natRule(M, cur, 'postrouting', { iif: inIf, oif: rt.dev, src: pkt.src, dst: pkt.dst, proto: pkt.proto, dport: pkt.dport, sport: pkt.sport, ct: 'new' });
        if (sn && (sn.nat.kind === 'masquerade' || sn.nat.kind === 'snat')) {
          var out = iface(M, cur, rt.dev), oa = out && addrOf(M, cur, out);
          var to = sn.nat.kind === 'snat' ? sn.nat.ip : (oa ? oa.ip : pkt.src);
          ctx.nat.push({ dev: cur, kind: 'snat', from: pkt.src, to: to });
          pkt.src = to;
        }
      }
      if (rt.dev === 'wg0') {
        var tun = M.wg[cur];
        if (!tun) return die(cur, 'le tunnel WireGuard n\'est pas établi' + (M.wgState && M.wgState.why ? ' : ' + M.wgState.why : ''));
        var back = M.wg[tun.peer];
        if (!back.allowed.some(function (c) { return inSub(pkt.src, c.ip, c.len); })) return die(tun.peer, tun.peer + ' rejette le paquet : sa source ' + ips(pkt.src) + ' n\'est pas dans les AllowedIPs du pair');
        hops.push({ dev: tun.peer, iface: 'wg0' });
        cur = tun.peer; inIf = 'wg0'; first = false;
        continue;
      }
      var nh = rt.via !== null ? rt.via : pkt.dst;
      var dom = domain(M, cur, rt.dev);
      if (dom.storm) return die(M.storm.at, 'tempête de diffusion : une boucle sur ' + M.storm.at + ', sans STP, sature les commutateurs');
      var tgt = null;
      for (var i = 0; i < dom.members.length; i++) {
        var mm = dom.members[i];
        if (mm.dev === cur && mm.iface === rt.dev) continue;
        var mi = iface(M, mm.dev, mm.iface) || (M.devices[mm.dev].kind === 'box' ? { ip: ipn('192.168.100.1'), name: 'lan', up: true } : null);
        var ma = mi && (M.devices[mm.dev].kind === 'box' ? { ip: ipn('192.168.100.1') } : addrOf(M, mm.dev, mi));
        if (ma && ma.ip === nh) { tgt = mm; break; }
      }
      if (tgt) {
        var i1 = isolated(M, cur, rt.dev), i2 = isolated(M, tgt.dev, tgt.iface);
        if (i1 && i2 && i1 === i2) return die(cur, 'ap_isolate : sur ce Wi-Fi, les clients ne se voient pas entre eux');
      }
      if (!tgt) {
        return die(cur, rt.via !== null ? 'la passerelle ' + ips(nh) + ' ne répond pas dans le réseau de ' + cur + ' (' + rt.dev + ')' : ips(nh) + ' ne répond pas : personne n\'a cette adresse dans le réseau de ' + cur + ' (' + rt.dev + ')');
      }
      hops.push({ dev: tgt.dev, iface: tgt.iface });
      cur = tgt.dev; inIf = tgt.iface; first = false;
    }
    return die(cur, 'boucle de routage : le paquet tourne en rond (TTL épuisé)');
  }

  /** A full exchange: there and back. */
  function flow(M, from, dst, proto, port, opts) {
    opts = opts || {};
    if (M.storm) return { ok: false, detail: 'tempête de diffusion : une boucle sur ' + M.storm.at + ', sans STP, sature les commutateurs', there: { hops: [{ dev: from }], died: { dev: M.storm.at, reason: 'tempête de diffusion' } } };
    var src = opts.src !== undefined ? opts.src : null, srcDev = from;
    if (from === 'internet') { if (src === null) return { ok: false, detail: 'source inconnue' }; }
    else {
      var p = primary(M, from);
      if (!p) { var ls = M.leases[from + ':ens18']; return { ok: false, detail: from + ' n\'a pas d\'adresse IP' + (ls && !ls.ok ? ' (' + ls.detail + ')' : ''), there: { hops: [{ dev: from }], died: { dev: from, reason: 'pas d\'adresse' } } }; }
      if (src === null) {
        var rt0 = lookup(M, from, dst), out0 = rt0 && iface(M, from, rt0.dev), a0 = out0 && addrOf(M, from, out0);
        src = a0 ? a0.ip : p.ip;
      }
    }
    var pkt = { src: src, dst: dst, proto: proto, dport: port || null, sport: proto === 'icmp' ? null : 40000 + (dst % 1000), ct: 'new' };
    var ctx = { nat: [] };
    var there = walk(M, srcDev, pkt, ctx);
    if (!there.ok) return { ok: false, detail: there.died.reason, there: there };
    var at = there.delivered.dev;
    if (proto !== 'icmp') {
      var svc = at === 'internet' ? (W.internet.hosts[ips(there.pkt.dst)] || {}).services || [] : M.devices[at].services;
      if (svc.indexOf(proto + '/' + there.pkt.dport) === -1) return { ok: false, detail: ips(there.pkt.dst) + ' refuse la connexion : rien n\'écoute sur ' + proto + '/' + there.pkt.dport, there: there, refused: true };
    }
    var back = { src: there.pkt.dst, dst: there.pkt.src, proto: proto, dport: there.pkt.sport, sport: there.pkt.dport, ct: 'est' };
    var reply = walk(M, at, back, ctx);
    if (!reply.ok) return { ok: false, detail: 'la réponse ne revient pas : ' + reply.died.reason, there: there, back: reply };
    if (reply.delivered.dev !== from) return { ok: false, detail: 'la réponse arrive à ' + reply.delivered.dev + ', pas à ' + from, there: there, back: reply };
    return { ok: true, detail: 'ok', there: there, back: reply };
  }

  // ——— DHCP ———
  function dhcp(M, dev, name) {
    var dom = domain(M, dev, name), d = M.devices[dev];
    var mac = ((d.def.ifaces || {})[name] || {}).mac || '';
    if (M.storm && dom.switches.length) return { ok: false, detail: 'tempête de diffusion : aucune réponse' };
    var offers = [];
    // A rogue server answers first, unless the switch drops it.
    dom.members.forEach(function (m) {
      var md = M.devices[m.dev];
      if (md.kind !== 'ghost' || !M.flags.ghostDhcp) return;
      var swEnd = M.ends[m.dev + ':' + m.iface.split('.')[0]];
      if (swEnd && M.devices[swEnd.dev].nft) {
        var v = filter(M, swEnd.dev, 'forward', { iif: swEnd.port, oif: null, src: ipn('10.20.20.66'), dst: ipn('255.255.255.255'), proto: 'udp', sport: 67, dport: 68, ct: 'new' }, ['bridge']);
        if (v.verdict !== 'accept') return;
      }
      offers.push({ ok: true, ip: ipn('10.20.20.141'), len: 24, routers: [ipn('10.20.20.66')], dns: [ipn('10.20.20.66')], server: ipn('10.20.20.66'), via: 'rogue', detail: 'offre de 10.20.20.66' });
    });
    var kea = null, keaDev = null;
    Object.keys(M.devices).forEach(function (id) { if (M.devices[id].kea) { kea = M.devices[id].kea; keaDev = id; } });
    var reasons = [];
    dom.members.forEach(function (m) {
      if (m.dev === keaDev && kea) {
        if (kea.interfaces.indexOf(m.iface) === -1 && kea.interfaces.indexOf('*') === -1) { reasons.push('Kea n\'écoute pas sur ' + m.iface); return; }
        var own = addrOf(M, m.dev, iface(M, m.dev, m.iface));
        var o = keaOffer(M, kea, own ? own.ip : null, mac);
        if (o.ok) { o.via = 'direct'; o.server = own.ip; offers.push(o); } else reasons.push(o.detail);
      }
      var md = M.devices[m.dev];
      if (md.relay && m.dev !== dev) {
        if (md.relay.ifaces.indexOf(m.iface) === -1) { reasons.push('le relais de ' + m.dev + ' n\'écoute pas sur ' + m.iface); return; }
        var gi = addrOf(M, m.dev, iface(M, m.dev, m.iface));
        if (!gi) return;
        var srv = md.relay.servers[0];
        if (srv === undefined) { reasons.push('le relais n\'a pas de serveur (SERVERS)'); return; }
        var up = lookup(M, m.dev, srv);
        if (!up) { reasons.push(m.dev + ' n\'a pas de route vers le serveur DHCP'); return; }
        if (up.via !== null && isPrivate(srv)) { reasons.push('le relais de ' + m.dev + ' ne joint pas le serveur DHCP ' + ips(srv) + ' : aucune de ses interfaces actives n\'est dans son réseau'); return; }
        if (md.relay.ifaces.indexOf(up.dev) === -1) { reasons.push('le relais n\'écoute pas sur ' + up.dev + ', l\'interface qui mène au serveur'); return; }
        var f = flow(M, m.dev, srv, 'udp', 67, { src: gi.ip });
        if (!f.ok) { reasons.push('le relais n\'atteint pas le serveur : ' + f.detail); return; }
        var sd = f.there.delivered.dev;
        if (!M.devices[sd].kea) { reasons.push(ips(srv) + ' n\'est pas un serveur DHCP'); return; }
        var o2 = keaOffer(M, M.devices[sd].kea, gi.ip, mac);
        if (o2.ok) { o2.via = 'relay'; o2.server = srv; offers.push(o2); } else reasons.push(o2.detail);
      }
    });
    if (!offers.length) return { ok: false, detail: reasons.length ? reasons[0] : 'aucun serveur DHCP ni relais dans ce réseau', reasons: reasons };
    var o = offers[0];
    o.dns = o.dns.length ? o.dns : [];
    return o;
  }
  function keaOffer(M, kea, giaddr, mac) {
    if (giaddr === null) return { ok: false, detail: 'serveur sans adresse' };
    var sub = kea.subnets.filter(function (s) { return inSub(giaddr, s.net, s.len); })[0];
    if (!sub) return { ok: false, detail: 'Kea n\'a aucun sous-réseau pour ' + ips(giaddr) + ' (DHCP4_SUBNET_SELECTION_FAILED)' };
    var routers = (sub.options.routers || kea.options.routers || []).map(ipn).filter(function (x) { return x !== null; });
    var dns = (sub.options['domain-name-servers'] || kea.options['domain-name-servers'] || []).map(ipn).filter(function (x) { return x !== null; });
    var res = sub.reservations.filter(function (r) { return r.mac === mac.toLowerCase(); })[0];
    if (res && res.ip !== null) return { ok: true, ip: res.ip, len: sub.len, routers: routers, dns: dns, reserved: true, subnet: sub.text };
    if (!sub.pools.length) return { ok: false, detail: 'le sous-réseau ' + sub.text + ' n\'a pas de plage' };
    var key = sub.text, cursor = M.poolCursor[key] || 0;
    var pool = sub.pools[0], ip = pool[0] + cursor;
    if (ip > pool[1]) return { ok: false, detail: 'plus d\'adresse libre dans la plage (no free leases)' };
    M.poolCursor[key] = cursor + 1;
    return { ok: true, ip: ip, len: sub.len, routers: routers, dns: dns, subnet: sub.text };
  }

  // ——— DNS ———
  function resolverOf(M, dev) {
    var d = M.devices[dev];
    for (var i = 0; i < d.l3.length; i++) {
      var ifc = d.l3[i], l = M.leases[dev + ':' + ifc.name];
      if (l && l.ok && l.dns.length) return l.dns[0];
      if (ifc.dns.length) return ifc.dns[0];
    }
    return null;
  }
  function resolve(M, dev, name) {
    var q = /\.$/.test(name) ? name.toLowerCase() : name.toLowerCase() + '.';
    var r = resolverOf(M, dev);
    if (r === null) return { ok: false, detail: dev + ' ne connaît aucun serveur DNS' };
    if (r === ipn('127.0.0.1')) r = primary(M, dev).ip;
    var f = dev === ownerOf(M, r) ? { ok: true, there: { delivered: { dev: dev } } } : flow(M, dev, r, 'udp', 53);
    if (!f.ok) return { ok: false, detail: f.refused ? ips(r) + ' ne répond pas sur le port 53 : ce n\'est pas un serveur DNS' : 'le serveur DNS ' + ips(r) + ' ne répond pas : ' + f.detail };
    var srv = M.devices[f.there.delivered.dev];
    var chain = [];
    for (var hop = 0; hop < 8; hop++) {
      if (srv.zone && srv.zone.origin && (q === srv.zone.origin || q.slice(-srv.zone.origin.length - 1) === '.' + srv.zone.origin)) {
        var recs = srv.zone.records[q] || [];
        var a = recs.filter(function (x) { return x.type === 'A'; })[0], c = recs.filter(function (x) { return x.type === 'CNAME'; })[0];
        if (a) return { ok: true, ip: ipn(a.data), chain: chain.concat([q + ' A ' + a.data]), server: r };
        if (c) { chain.push(q + ' CNAME ' + c.target); q = c.target; continue; }
        return { ok: false, detail: 'NXDOMAIN : ' + q + ' n\'existe pas dans la zone ' + srv.zone.origin, chain: chain, server: r, nx: true };
      }
      if (W.internet.dns[q]) {
        var up = flow(M, srv.id, ipn('203.0.113.53'), 'udp', 53);
        if (!up.ok) return { ok: false, detail: 'le serveur DNS ne joint pas Internet : ' + up.detail, server: r };
        return { ok: true, ip: ipn(W.internet.dns[q]), chain: chain.concat([q + ' A ' + W.internet.dns[q]]), server: r };
      }
      return { ok: false, detail: 'NXDOMAIN : ' + q + ' n\'existe pas', chain: chain, server: r, nx: true };
    }
    return { ok: false, detail: 'trop d\'alias en chaîne' };
  }
  function ownerOf(M, ip) {
    var found = null;
    Object.keys(M.devices).forEach(function (id) { if (!found && M.devices[id].kind !== 'internet' && owns(M, id, ip)) found = id; });
    return found;
  }

  // ——— HTTPS and SSH ———
  function https(M, from, host, opts) {
    opts = opts || {};
    var q = host.toLowerCase().replace(/\.$/, '') + '.';
    var ip = from === 'internet' ? (W.internet.dns[q] ? ipn(W.internet.dns[q]) : null) : null;
    if (ip === null && from !== 'internet') { var r = resolve(M, from, host); if (!r.ok) return { ok: false, detail: r.detail }; ip = r.ip; }
    if (ip === null) return { ok: false, detail: host + ' : nom inconnu' };
    var f = flow(M, from, ip, 'tcp', 443, { src: opts.src !== undefined ? ipn(opts.src) : undefined });
    if (!f.ok) return { ok: false, detail: f.detail, trace: f };
    var srv = M.devices[f.there.delivered.dev];
    if (!srv.nginx || !srv.nginx.length) return { ok: false, detail: 'aucun site HTTPS sur ' + srv.id };
    var name = host.toLowerCase().replace(/\.$/, '');
    var block = srv.nginx.filter(function (b) { return b.names.indexOf(name) !== -1; })[0] || srv.nginx[0];
    var certText = M.files[srv.id + ':' + block.cert];
    if (certText === undefined) return { ok: false, detail: 'nginx ne démarre pas : certificat introuvable (' + block.cert + ')' };
    var names = certNames(certText);
    var match = names.some(function (n) { return n === name || (n.indexOf('*.') === 0 && name.slice(name.indexOf('.')) === n.slice(1)); });
    if (!match) return { ok: false, detail: 'alerte de sécurité : le certificat présenté est au nom de ' + names.join(', ') + ', pas de ' + name, cert: names };
    return { ok: true, detail: 'certificat valide pour ' + name, cert: names };
  }
  function ssh(M, from, to, user, method) {
    var t = target(M, to);
    var f = flow(M, from, t, 'tcp', 22);
    if (!f.ok) return { ok: false, detail: f.detail };
    var dev = f.there.delivered.dev, cfg = sshdConfig(M, dev);
    if (!cfg) return { ok: false, detail: 'pas de configuration sshd sur ' + dev };
    if (cfg.errors.length) return { ok: false, detail: 'sshd ne démarre pas : ' + cfg.errors[0].message + ' (' + cfg.errors[0].file.split(':')[1] + ', ligne ' + cfg.errors[0].line + ')' };
    if ((W.sshUsers[dev] || []).indexOf(user) === -1) return { ok: false, detail: 'Permission denied : utilisateur inconnu' };
    var root = cfg.get('permitrootlogin', 'prohibit-password');
    if (user === 'root' && (root === 'no' || (method === 'password' && root !== 'yes'))) return { ok: false, detail: 'Permission denied : PermitRootLogin ' + root };
    if (method === 'password') {
      var pw = cfg.get('passwordauthentication', 'yes');
      if (pw !== 'yes') return { ok: false, detail: 'Permission denied (publickey) : mot de passe refusé' };
      var v = cfg.values.passwordauthentication;
      return { ok: true, detail: 'mot de passe accepté (PasswordAuthentication yes, ' + (v ? v.file.split(':')[1] + ' ligne ' + v.line : 'valeur par défaut') + ')' };
    }
    if (cfg.get('pubkeyauthentication', 'yes') !== 'yes') return { ok: false, detail: 'Permission denied : PubkeyAuthentication no' };
    if ((W.sshKeys[dev] || []).indexOf(user) === -1) return { ok: false, detail: 'Permission denied (publickey) : aucune clé autorisée pour ' + user };
    return { ok: true, detail: 'clé acceptée' };
  }

  // ——— What the checks see ———
  function target(M, x) {
    if (typeof x === 'number') return x;
    var n = ipn(x);
    if (n !== null) return n;
    var p = primary(M, x);
    return p ? p.ip : null;
  }
  function helpers(M) {
    var h = {
      M: M,
      ip: ipn, ipstr: ips,
      errors: function () { return M.errors; },
      storm: function () { return !!M.storm; },
      addr: function (dev) { var p = primary(M, dev); return p ? ips(p.ip) : null; },
      lease: function (dev) { return M.leases[dev + ':ens18'] || null; },
      ping: function (from, to) {
        var t = target(M, to);
        if (t === null) return { ok: false, detail: to + ' n\'a pas d\'adresse' };
        var r = flow(M, from, t, 'icmp');
        M.lastTrace = r;
        return r;
      },
      tcp: function (from, to, port, opts) { var t = target(M, to); if (t === null) return { ok: false, detail: to + ' n\'a pas d\'adresse' }; var r = flow(M, from, t, 'tcp', port, opts); M.lastTrace = r; return r; },
      udp: function (from, to, port, opts) { var t = target(M, to); if (t === null) return { ok: false, detail: to + ' n\'a pas d\'adresse' }; var r = flow(M, from, t, 'udp', port, opts); M.lastTrace = r; return r; },
      resolve: function (dev, name) { return resolve(M, dev, name); },
      inNet: function (ipStr, net) { var c = cidr(net), n = ipn(ipStr); return !!(c && n !== null && inSub(n, c.ip, c.len)); },
      port: function (sw, p) { var d = M.devices[sw]; return !!(d && d.ports && d.ports[p]); },
      https: function (from, host, opts) { var r = https(M, from, host, opts); if (r.trace) M.lastTrace = r.trace; return r; },
      ssh: function (from, to, user, method) { return ssh(M, from, to, user, method); },
      wifi: function (dev) { return M.wifi[dev] || { ok: false, detail: dev + ' n\'est pas un client Wi-Fi' }; },
      radius: function (user, password) {
        var ap = M.devices['ap-salle'], bss = ap && ap.hostapd && ap.hostapd.filter(function (b) { return /EAP/.test(b.opts.wpa_key_mgmt || ''); })[0];
        return bss ? radiusCheck(M, 'ap-salle', bss, user, password) : { ok: false, detail: 'pas de Wi-Fi 802.1X' };
      },
      routeDev: function (dev, ip) { var r = lookup(M, dev, ipn(ip)); return r ? r.dev : null; },
      tunnel: function () { return M.wgState || { up: false, why: 'pas de tunnel' }; },
      vlanOf: function (dev) { var p = primary(M, dev); if (!p) return null; return domain(M, dev, p.name).vlan; }
    };
    return h;
  }

  /** Runs a ticket's checks on a model: [{label, ok, detail}]. A configuration that does not even
   *  load fails every check, with the error that stops it. */
  function check(M, checks) {
    var h = helpers(M);
    var errs = M.errors;
    return checks.map(function (c) {
      if (errs.length) {
        var e = errs[0];
        return { label: c.label, ok: false, detail: shortFile(e.file) + (e.line ? ', ligne ' + e.line : '') + ' : ' + e.message };
      }
      var r;
      try { r = c.test(h); } catch (ex) { r = { ok: false, detail: 'erreur du test : ' + ex.message }; }
      if (typeof r === 'boolean') r = { ok: r };
      return { label: c.label, ok: !!r.ok, detail: r.ok ? '' : (r.detail || '') };
    });
  }
  function shortFile(f) { var i = f.indexOf(':'); return f.slice(0, i) + ' · ' + f.slice(i + 1); }

  // ——— The console ———
  function run(M, dev, line) {
    var w = String(line || '').trim().split(/\s+/), cmd = w[0], out = [];
    var d = M.devices[dev];
    if (!cmd) return [];
    function nameToIp(x) {
      var n = ipn(x);
      if (n !== null) return { ip: n };
      var r = resolve(M, dev, /\./.test(x) ? x : x + '.tournoi.lan');
      if (!r.ok) return { err: x + ' : Échec temporaire dans la résolution du nom' + (r.detail ? ' (' + r.detail + ')' : '') };
      return { ip: r.ip };
    }
    if (cmd === 'help' || cmd === 'aide') {
      return ['Commandes : ping <hôte>, traceroute <hôte>, ip a, ip r, dhclient -v ens18, dig <nom>, nc -zv <hôte> <port>, curl -I https://<nom>, ssh <utilisateur>@<hôte>, wg, clear'];
    }
    if (cmd === 'ip' && /^(a|addr|address)$/.test(w[1] || '')) {
      out.push('1: lo: <LOOPBACK,UP> mtu 65536', '    inet 127.0.0.1/8 scope host lo');
      (d.l3 || []).forEach(function (ifc, i) {
        var a = addrOf(M, dev, ifc), mac = (((d.def.ifaces || {})[ifc.raw] || {}).mac) || '';
        out.push((i + 2) + ': ' + ifc.name + ': <BROADCAST,MULTICAST' + (ifc.up ? ',UP' : '') + '> state ' + (ifc.up ? 'UP' : 'DOWN'));
        if (mac) out.push('    link/ether ' + mac);
        if (a && ifc.up) out.push('    inet ' + ips(a.ip) + '/' + a.len + (ifc.method === 'dhcp' ? ' dynamic' : '') + ' scope global ' + ifc.name);
      });
      return out;
    }
    if (cmd === 'ip' && /^(r|route)$/.test(w[1] || '')) {
      routes(M, dev).slice().sort(function (a, b) { return a.len - b.len; }).forEach(function (r) {
        out.push((r.len === 0 ? 'default' : ips(r.net) + '/' + r.len) + (r.via !== null && r.via !== undefined ? ' via ' + ips(r.via) : '') + ' dev ' + r.dev + (r.via === null && r.src ? ' proto kernel scope link src ' + ips(r.src) : ''));
      });
      if (!out.length) out.push('(table de routage vide)');
      return out;
    }
    if (cmd === 'ping' || cmd === 'traceroute') {
      if (!w[1]) return ['usage : ' + cmd + ' <hôte>'];
      var t = nameToIp(w[1]);
      if (t.err) return [cmd + ': ' + t.err];
      var r = flow(M, dev, t.ip, 'icmp');
      M.lastTrace = r;
      if (cmd === 'ping') {
        out.push('PING ' + w[1] + ' (' + ips(t.ip) + ') 56(84) octets de données.');
        if (r.ok) { for (var k = 1; k <= 3; k++) out.push('64 octets de ' + ips(t.ip) + ' : icmp_seq=' + k + ' ttl=' + (64 - (r.there.hops.length - 1)) + ' temps=' + (0.4 + k / 10).toFixed(1) + ' ms'); out.push('--- 3 paquets transmis, 3 reçus, 0 % de perte ---'); }
        else { out.push('--- 3 paquets transmis, 0 reçu, 100 % de perte ---'); out.push('✗ ' + r.detail); }
      } else {
        out.push('traceroute vers ' + w[1] + ' (' + ips(t.ip) + '), 30 sauts max');
        var hs = r.there ? r.there.hops.slice(1) : [];
        hs.forEach(function (hp, i) { var p = hp.dev === 'internet' ? null : primary(M, hp.dev); out.push(' ' + (i + 1) + '  ' + hp.dev + (p ? ' (' + ips(p.ip) + ')' : '') + '  0.' + (3 + i) + ' ms'); });
        if (!r.ok) { out.push(' ' + (hs.length + 1) + '  * * *'); out.push('✗ ' + r.detail); }
      }
      return out;
    }
    if (cmd === 'dhclient') {
      var l = M.leases[dev + ':ens18'];
      if (!iface(M, dev, 'ens18') || iface(M, dev, 'ens18').method !== 'dhcp') return ['ens18 n\'est pas en DHCP sur ' + dev + ' : son adresse est fixe (/etc/network/interfaces).'];
      out.push('DHCPDISCOVER on ens18 to 255.255.255.255 port 67 interval 3');
      if (l && l.ok) {
        out.push('DHCPOFFER of ' + ips(l.ip) + ' from ' + ips(l.server));
        out.push('DHCPREQUEST for ' + ips(l.ip) + ' on ens18 to 255.255.255.255 port 67');
        out.push('DHCPACK of ' + ips(l.ip) + ' from ' + ips(l.server));
        out.push('bound to ' + ips(l.ip) + (l.routers.length ? ' — passerelle ' + ips(l.routers[0]) : '') + (l.dns.length ? ', DNS ' + ips(l.dns[0]) : ''));
      } else {
        out.push('DHCPDISCOVER on ens18 to 255.255.255.255 port 67 interval 7', 'No DHCPOFFERS received.');
        if (l) out.push('✗ ' + l.detail);
      }
      return out;
    }
    if (cmd === 'dig') {
      if (!w[1]) return ['usage : dig <nom>'];
      var res = resolve(M, dev, w[1]);
      out.push('; <<>> DiG 9.20 <<>> ' + w[1]);
      if (res.ok) { out.push(';; status: NOERROR', ';; ANSWER SECTION:'); res.chain.forEach(function (c) { out.push(c); }); out.push(';; SERVER: ' + ips(res.server) + '#53'); }
      else out.push(';; ' + (res.nx ? 'status: NXDOMAIN' : 'connection timed out; no servers could be reached'), '✗ ' + res.detail);
      return out;
    }
    if (cmd === 'nc') {
      var host = w[w.length - 2], port = +w[w.length - 1];
      if (!host || !port) return ['usage : nc -zv <hôte> <port>'];
      var tt = nameToIp(host);
      if (tt.err) return ['nc: ' + tt.err];
      var rr = flow(M, dev, tt.ip, 'tcp', port);
      M.lastTrace = rr;
      return rr.ok ? ['Connection to ' + host + ' (' + ips(tt.ip) + ') ' + port + ' port [tcp] succeeded!'] : ['nc: connect to ' + host + ' (' + ips(tt.ip) + ') port ' + port + ' (tcp) failed', '✗ ' + rr.detail];
    }
    if (cmd === 'curl') {
      var url = w.filter(function (x) { return /^https?:\/\//.test(x); })[0];
      if (!url) return ['usage : curl -I https://<nom>'];
      var hn = url.replace(/^https?:\/\//, '').split('/')[0];
      var hr = https(M, dev, hn);
      if (hr.trace) M.lastTrace = hr.trace;
      return hr.ok ? ['HTTP/2 200', 'server: nginx', '* ' + hr.detail] : ['curl: (60) ' + hr.detail, '✗ ' + hr.detail];
    }
    if (cmd === 'ssh') {
      var ua = /^([^@]+)@(.+)$/.exec(w[w.length - 1] || '');
      if (!ua) return ['usage : ssh <utilisateur>@<hôte>'];
      var tgtIp = nameToIp(ua[2]);
      if (tgtIp.err) return ['ssh: ' + tgtIp.err];
      var k = ssh(M, dev, ips(tgtIp.ip), ua[1], 'key'), pw = ssh(M, dev, ips(tgtIp.ip), ua[1], 'password');
      return ['clé : ' + (k.ok ? 'acceptée' : k.detail), 'mot de passe : ' + (pw.ok ? 'accepté' : pw.detail)];
    }
    if (cmd === 'wg') {
      if (!d.wgConf) return ['wg : pas d\'interface WireGuard sur ' + dev];
      var st = M.wgState || { up: false };
      return ['interface: wg0', '  public key: ' + (W.wgKeys[d.wgConf.iface.PrivateKey] || '(clé privée illisible)'), '', 'peer: ' + ((d.wgConf.peers[0] || {}).PublicKey || '?'), '  allowed ips: ' + ((d.wgConf.peers[0] || {}).AllowedIPs || ''), st.up ? '  latest handshake: 3 seconds ago' : '  (aucune poignée de main)' + (st.why ? ' : ' + st.why : '')];
    }
    return [cmd + ' : commande inconnue dans ce simulateur. Tapez « aide ».'];
  }

  // ——— A ticket's world ———
  function ticketFiles(def) { return patch(referenceFiles(def.act), def.bug || []); }
  function fixedFiles(def) { var f = ticketFiles(def); return def.fix ? patch(f, def.fix) : patch(f, def.bug || [], true); }
  function ticketWorld(def, files) { return world(def.act, { files: files, cables: def.cables, flags: def.flags }); }
  function checkTicket(def, files) { return check(ticketWorld(def, files), def.checks); }

  root.BlackoutNet = {
    world: world, referenceFiles: referenceFiles, patch: patch, check: check, helpers: helpers, run: run,
    ticketFiles: ticketFiles, fixedFiles: fixedFiles, ticketWorld: ticketWorld, checkTicket: checkTicket,
    domain: domain, primary: primary, ip: ipn, ipstr: ips, parseNft: parseNft
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
