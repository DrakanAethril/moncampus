/* Fusion — the two information systems, act by act, and the console of the student's laptop.

   FSWorld.at(act) is the world as the act finds it: the machines of both sites, the accounts of both
   domains, the DNS, the shares, the mailboxes. What the student did in the acts before is in it (from
   act 3 on, Brive is 10.20.20.0/24 behind the tunnel; from act 4, one domain…). The console reads it:
   a dozen read-only commands, enough to look before deciding. Nothing a step checks depends on the
   console: the views of the steps carry what is to be designated. */
(function (root) {
  'use strict';

  function machines(act) {
    var renum = act >= 3;
    // Only Brive is renumbered during the quarter (act 2): Limoges keeps 192.168.1.0/24, and the two
    // sites no longer overlap. Limoges moves to 10.20.10.0/24 after the quarter.
    var L = '192.168.1.', LS = '192.168.1.', B = renum ? '10.20.20.' : '192.168.1.', BS = renum ? '10.20.21.' : '192.168.1.';
    var list = [
      { name: 'box-limoges', site: 'Limoges', ip: '192.168.1.1', role: 'Passerelle, pare-feu, VPN', os: 'pfSense 2.7' },
      { name: 'SRV-DELORME', site: 'Limoges', ip: LS + '10', role: 'AD DS, DNS, DHCP, fichiers', os: 'Windows Server 2019' },
      { name: 'EXCH-DELORME', site: 'Limoges', ip: LS + '11', role: 'Exchange (messagerie)', os: 'Windows Server 2019' },
      { name: 'nas-delorme', site: 'Limoges', ip: LS + '12', role: 'Sauvegardes', os: 'Synology DSM 7' },
      { name: 'copieur-limoges', site: 'Limoges', ip: L + '40', role: 'Copieur, numérisation vers mail', os: '—' },
      { name: 'box-brive', site: 'Brive', ip: (renum ? '10.20.20.' : '192.168.1.') + '1', role: 'Passerelle, pare-feu, VPN', os: 'pfSense 2.7' },
      { name: 'dc-kerbrat', site: 'Brive', ip: BS + '10', role: act >= 4 ? 'Contrôleur de domaine en sommeil (comptes migrés)' : 'Samba 4 AD DC, DNS, DHCP', os: 'Debian 12' },
      { name: 'lic-kerbrat', site: 'Brive', ip: BS + '15', role: 'Serveur de licences de CAO', os: 'Debian 12' },
      { name: 'nas-kerbrat', site: 'Brive', ip: BS + '20', role: 'Partages Projets, Concours', os: 'Synology DSM 7' },
      { name: 'traceur-a0', site: 'Brive', ip: B + '50', role: 'Traceur A0, adresse fixe', os: '—' }
    ];
    if (act <= 2) list.push({ name: 'srv-kerbrat-old', site: 'Brive', ip: '192.168.1.30', role: '(absent de l\'inventaire)', os: 'Synology DSM 6.2' });
    return list;
  }

  var USERS = [
    { sam: 'j.delorme', name: 'Julien Delorme', dom: 'delorme', on: true, last: '2026-10-12', groups: ['Associés', 'Utilisateurs du domaine'] },
    { sam: 's.aubry', name: 'Sandrine Aubry', dom: 'delorme', on: true, last: '2026-10-12', groups: ['Administration', 'Utilisateurs du domaine'] },
    { sam: 'p.morel', name: 'Pauline Morel', dom: 'delorme', on: true, last: '2026-10-09', groups: ['Dessinateurs', 'Utilisateurs du domaine'] },
    { sam: 'k.benali', name: 'Karim Benali', dom: 'delorme', on: true, last: '2026-10-12', groups: ['Dessinateurs', 'Utilisateurs du domaine'] },
    { sam: 'm.kerbrat', name: 'Maëlle Kerbrat', dom: 'kerbrat', on: true, last: '2026-10-12', groups: ['associes', 'Domain Users'] },
    { sam: 't.lacoste', name: 'Thibault Lacoste', dom: 'kerbrat', on: true, last: '2026-10-10', groups: ['dessin', 'Domain Users'] },
    { sam: 'c.nguyen', name: 'Claire Nguyen', dom: 'kerbrat', on: true, last: '2026-10-12', groups: ['dessin', 'concours', 'Domain Users'] },
    { sam: 'b.roux', name: 'Bastien Roux', dom: 'kerbrat', on: true, last: '2025-04-30', groups: ['dessin', 'concours', 'Domain Users'] },
    { sam: 'a.perrin', name: 'Anaïs Perrin', dom: 'kerbrat', on: true, last: '2025-12-19', groups: ['dessin', 'Domain Users'] },
    { sam: 'stagiaire2024', name: 'Stagiaire 2024', dom: 'kerbrat', on: true, last: '2024-08-30', groups: ['Domain Users'] }
  ];

  function at(act) {
    var w = { act: act, machines: machines(act), users: USERS.map(function (u) { var c = Object.assign({}, u); if (act >= 4 && ['b.roux', 'a.perrin', 'stagiaire2024'].indexOf(u.sam) !== -1) c.on = false; return c; }) };
    w.dns = {};
    w.machines.forEach(function (m) { w.dns[m.name.toLowerCase() + '.' + (m.site === 'Limoges' ? 'delorme.local' : 'kerbrat.lan')] = m.ip; });
    w.forwarders = act >= 4;
    w.tunnel = act >= 3;
    return w;
  }

  // ——— The console ———
  function pad(s, n) { s = String(s); while (s.length < n) s += ' '; return s; }
  function console_(act) {
    var w = at(act), here = 'Limoges';
    function host(q) {
      q = String(q || '').toLowerCase();
      return w.machines.filter(function (m) { return m.name.toLowerCase() === q || m.ip === q || (m.name.toLowerCase() + '.' + (m.site === 'Limoges' ? 'delorme.local' : 'kerbrat.lan')) === q; })[0] || null;
    }
    var cmds = [
      { re: /^(aide|help)$/i, out: function () { return ['Commandes du portable :', '  ping HÔTE            · nslookup NOM', '  ipconfig             · arp -a', '  scan (les deux sites)  · Get-ADUser -Filter *', '  Get-ADGroupMember G  · dig MX kerbrat-architectes.fr', '  clear'].join('\n'); } },
      { re: /^ipconfig(\s+\/all)?$/i, out: function () { return ['Carte Ethernet Ethernet :', '   Adresse IPv4. . . . . . : 192.168.1.142', '   Masque de sous-réseau . : 255.255.255.0', '   Passerelle par défaut . : 192.168.1.1', '   Serveur DNS . . . . . . : 192.168.1.10', '   Suffixe DNS . . . . . . : delorme.local'].join('\n'); } },
      { re: /^ping\s+(\S+)$/i, out: function (m) {
        var h = host(m[1]);
        if (!h) return 'La requête Ping n\'a pas pu trouver l\'hôte ' + m[1] + '.';
        if (h.site !== here && !w.tunnel) {
          var twin = w.machines.filter(function (x) { return x.site === here && x.ip === h.ip; })[0];
          return twin ? 'Réponse de ' + h.ip + ' : octets=32 temps<1ms TTL=128\n(c\'est ' + twin.name + ', à Limoges, qui répond : la même adresse existe des deux côtés)' : 'Délai d\'attente de la demande dépassé.';
        }
        return 'Réponse de ' + h.ip + ' : octets=32 temps=' + (h.site === here ? '<1' : '14') + ' ms TTL=' + (h.site === here ? 128 : 62);
      } },
      { re: /^nslookup\s+(\S+)$/i, out: function (m) {
        var q = m[1].toLowerCase(), ip = w.dns[q] || (host(q) && host(q).ip);
        var crossSite = /kerbrat\.lan$/.test(q) && !w.forwarders;
        if (!ip || crossSite) return 'Serveur :   srv-delorme.delorme.local\n\n*** srv-delorme.delorme.local ne parvient pas à trouver ' + m[1] + ' : Non-existent domain';
        return 'Serveur :   srv-delorme.delorme.local\n\nNom :    ' + q + '\nAddress:  ' + ip;
      } },
      { re: /^arp\s+-a$/i, out: function () { return w.machines.filter(function (m) { return m.site === here; }).map(function (m) { return '  ' + pad(m.ip, 16) + pad(m.name, 18) + 'dynamique'; }).join('\n'); } },
      { re: /^scan$/i, out: function () { return [pad('MACHINE', 18) + pad('SITE', 9) + pad('ADRESSE', 15) + 'RÔLE'].concat(w.machines.map(function (m) { return pad(m.name, 18) + pad(m.site, 9) + pad(m.ip, 15) + m.role; })).join('\n'); } },
      { re: /^get-aduser\s+-filter\s+\*$/i, out: function () {
        return [pad('SamAccountName', 16) + pad('Domaine', 9) + pad('Enabled', 9) + 'LastLogonDate'].concat(w.users.filter(function (u) { return act >= 4 || u.dom === 'delorme' || act >= 3; }).map(function (u) { return pad(u.sam, 16) + pad(u.dom, 9) + pad(u.on ? 'True' : 'False', 9) + u.last; })).join('\n');
      } },
      { re: /^get-adgroupmember\s+(.+)$/i, out: function (m) {
        var g = m[1].replace(/^["']|["']$/g, '').toLowerCase(), list = w.users.filter(function (u) { return u.groups.some(function (x) { return x.toLowerCase() === g; }); });
        return list.length ? list.map(function (u) { return u.sam + '  (' + u.name + ')'; }).join('\n') : 'Get-ADGroupMember : groupe introuvable : ' + m[1];
      } },
      { re: /^dig\s+mx\s+kerbrat-architectes\.fr$/i, out: function () { return ';; ANSWER SECTION:\nkerbrat-architectes.fr. 86400 IN MX 10 mail.delorme-architectes.fr.' + '\nkerbrat-architectes.fr. 86400 IN MX 20 mx2.arveo-hebergement.fr.'; } }
    ];
    return {
      prompt: 'alternant@PC-ARPEGE>',
      run: function (line) {
        line = String(line || '').trim();
        for (var i = 0; i < cmds.length; i++) { var m = cmds[i].re.exec(line); if (m) return { out: cmds[i].out(m), err: false }; }
        return { out: '« ' + line.split(/\s+/)[0] + ' » : commande inconnue sur ce portable. Tape « aide ».', err: true };
      }
    };
  }

  root.FSWorld = { at: at, console: console_, users: USERS };
})(typeof globalThis !== 'undefined' ? globalThis : window);
