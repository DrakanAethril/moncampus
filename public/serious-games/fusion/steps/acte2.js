/* Acte 2 — Le même réseau deux fois (semaines du 26 octobre au 13 novembre 2026). Le plan d'adressage,
   le tunnel IPsec, le vieux NAS et son journal, la nuit de la renumérotation de Brive et la
   décommission de srv-kerbrat-old. */
(function (S) {
  'use strict';
  var N = globalThis.FSNet;

  // Minutes counted from Saturday 0 h.
  var SAM = 0, DIM = 1440, LUN = 2880, JEU = -2 * 1440, VEN = -1440, DAY = 1440;

  // ——— E09 : le plan d'adressage (formulaire) ———
  var ROWS = ['Limoges · postes', 'Limoges · serveurs', 'Brive · postes', 'Brive · serveurs'];
  var BLOC = N.cidr('10.20.0.0/16');
  function nets(a) { return ROWS.map(function (_, i) { var r = (a.plan || [])[i] || {}; return N.cidr(r.reseau); }); }
  function complete(a) { return nets(a).every(function (c) { return c && c.first >= BLOC.first && c.last <= BLOC.last; }); }
  var INCOMPLETE = 'le plan n\'est pas complet : quatre réseaux valides dans 10.20.0.0/16 d\'abord';
  /** The smallest aligned block that holds both networks of a site. */
  function summary(a, b) {
    for (var p = Math.min(a.prefix, b.prefix); p >= 0; p--) {
      var size = Math.pow(2, 32 - p), base = Math.floor(a.first / size) * size;
      if (b.first >= base && b.last <= base + size - 1) return { first: base, last: base + size - 1, prefix: p, text: N.n2ip(base) + '/' + p };
    }
    return null;
  }
  S.add({
    id: 'E09', act: 2, tool: 'form',
    form: {
      intro: 'Le bloc du cabinet est 10.20.0.0/16. Un réseau par site et par usage ; la passerelle sur la première adresse utilisable de chaque réseau.',
      docs: [
        { title: 'Les besoins, d\'après Julien et Maëlle', mono: false, lines: [
          'Limoges : 14 personnes aujourd\'hui. Brive : 9. Objectif à trois ans : jusqu\'à 40 personnes par site, chacune avec un poste, plus les portables et les imprimantes. Comptez au moins 60 adresses par réseau de postes.',
          'Serveurs et équipements (serveurs, NAS, box, licences, traceur) : jusqu\'à 20 par site.',
          'Un troisième site est possible un jour : le bloc doit pouvoir l\'accueillir sans tout redécouper.',
          'Le tunnel entre les sites routera chaque site vers l\'autre.'
        ] }
      ],
      fields: [{ id: 'plan', type: 'table', label: 'Le plan d\'adressage', fixedRows: ROWS,
        help: 'Un réseau s\'écrit avec son adresse de réseau et son masque, par exemple 10.20.30.0/24.',
        columns: [{ id: 'reseau', label: 'Réseau', mono: true, placeholder: '10.20.x.0/24' }, { id: 'passerelle', label: 'Passerelle', mono: true, placeholder: '10.20.x.1' }] }]
    },
    start: { plan: [{}, {}, {}, {}] },
    ref: { plan: [
      { reseau: '10.20.10.0/24', passerelle: '10.20.10.1' }, { reseau: '10.20.11.0/24', passerelle: '10.20.11.1' },
      { reseau: '10.20.20.0/24', passerelle: '10.20.20.1' }, { reseau: '10.20.21.0/24', passerelle: '10.20.21.1' }
    ] },
    cases: [
      { id: 'valides', label: 'quatre réseaux valides, tous dans 10.20.0.0/16', visible: true, check: function (a) {
        var c = nets(a);
        for (var i = 0; i < ROWS.length; i++) {
          if (!c[i]) return '« ' + ROWS[i] + ' » n\'est pas une adresse de réseau valide (par exemple 10.20.30.0/24 : le réseau, pas une machine)';
          if (c[i].first < BLOC.first || c[i].last > BLOC.last) return '« ' + ROWS[i] + ' » sort du bloc du cabinet, 10.20.0.0/16';
        }
        return true;
      } },
      { id: 'chevauchement', label: 'aucun réseau ne chevauche un autre', check: function (a) {
        if (!complete(a)) return INCOMPLETE;
        var c = nets(a);
        for (var i = 0; i < 4; i++) for (var j = i + 1; j < 4; j++) if (N.overlap(c[i], c[j])) return '« ' + ROWS[i] + ' » et « ' + ROWS[j] + ' » se chevauchent : une même adresse serait à deux endroits';
        return true;
      } },
      { id: 'marge', label: 'de la marge : 60 postes et 20 serveurs par site', check: function (a) {
        if (!complete(a)) return INCOMPLETE;
        var c = nets(a);
        for (var i = 0; i < 4; i++) {
          var need = i % 2 === 0 ? 60 : 20;
          if (N.hosts(c[i]) < need) return '« ' + ROWS[i] + ' » n\'accueille que ' + N.hosts(c[i]) + ' machines : il en faut au moins ' + need;
        }
        return true;
      } },
      { id: 'passerelles', label: 'chaque passerelle est la première adresse de son réseau', check: function (a) {
        if (!complete(a)) return INCOMPLETE;
        var c = nets(a);
        for (var i = 0; i < 4; i++) {
          var g = N.ip2n(((a.plan || [])[i] || {}).passerelle);
          if (g === null) return 'la passerelle de « ' + ROWS[i] + ' » n\'est pas une adresse';
          if (!N.contains(c[i], g)) return 'la passerelle de « ' + ROWS[i] + ' » n\'est pas dans son réseau : les machines ne pourraient pas la joindre';
          if (g !== c[i].first + 1) return 'la passerelle de « ' + ROWS[i] + ' » devrait être ' + N.n2ip(c[i].first + 1) + ', la première adresse utilisable';
        }
        return true;
      } },
      { id: 'route', label: 'une seule route par site, pour le tunnel', check: function (a) {
        if (!complete(a)) return INCOMPLETE;
        var c = nets(a), lim = summary(c[0], c[1]), bri = summary(c[2], c[3]);
        if (N.overlap(lim, c[2]) || N.overlap(lim, c[3])) return 'le plus petit réseau qui contient Limoges, ' + lim.text + ', contient aussi des réseaux de Brive : aucune route unique ne mène à Limoges';
        if (N.overlap(bri, c[0]) || N.overlap(bri, c[1])) return 'le plus petit réseau qui contient Brive, ' + bri.text + ', contient aussi des réseaux de Limoges : aucune route unique ne mène à Brive';
        return true;
      } },
      { id: 'troisieme', label: 'de la place pour un troisième site', check: function (a) {
        if (!complete(a)) return INCOMPLETE;
        var c = nets(a);
        for (var i = 0; i < 4; i++) if (c[i].prefix < 22) return '« ' + ROWS[i] + ' » prend ' + c[i].size + ' adresses : un bloc aussi gros pour un site de 40 personnes, et le troisième site n\'aura plus de place cohérente';
        return true;
      } }
    ],
    hints: [
      { title: 'Fiche : découper un bloc', body: '1. Compter : 60 machines → /26 au moins (62 utilisables) ; 20 → /27 (30 utilisables). Un /24 (254) laisse de la marge.\n2. Grouper par site : les réseaux d\'un site côte à côte (10.20.10.0/24 et 10.20.11.0/24) se résument en un seul (10.20.10.0/23) : une route par site.\n3. Laisser des trous : un troisième site prendra 10.20.30.0/24 et 10.20.31.0/24.\n4. Passerelle : réseau + 1.' },
      { title: 'La réponse', body: 'Limoges · postes : 10.20.10.0/24, passerelle 10.20.10.1\nLimoges · serveurs : 10.20.11.0/24, passerelle 10.20.11.1\nBrive · postes : 10.20.20.0/24, passerelle 10.20.20.1\nBrive · serveurs : 10.20.21.0/24, passerelle 10.20.21.1' }
    ],
    intro: [
      { who: 'narr', text: 'Lundi 26 octobre, 9 h. Arpège Informatique, Limoges. Sur la table, la maquette des deux agences ; entre elles, rien encore.' },
      { who: 'helene', expr: 'neutre', text: 'Deux sites qui portent le même réseau ne se relient pas. Avant le tunnel, il faut un plan d\'adressage que les deux partagent, et le trimestre pour l\'appliquer.' },
      { who: 'helene', expr: 'sourire', text: 'On commence par le papier. Une adresse se change en une nuit ; un mauvais plan se garde dix ans.' }
    ],
    done: [
      { who: 'helene', expr: 'sourire', text: 'Brive en 10.20.20 et 10.20.21, Limoges en 10.20.10 et 10.20.11, et de la place pour la suite. On l\'affiche au mur.' }
    ]
  });

  // ——— E11 : le tunnel IPsec (formulaire) ———
  /** A list of networks (« 10.20.20.0/24, 10.20.21.0/24 ») → merged ranges, or null if one is invalid. */
  function ranges(s) {
    var parts = String(s || '').split(/[\s,;]+/).filter(function (x) { return x; });
    if (!parts.length) return null;
    var list = [];
    for (var i = 0; i < parts.length; i++) { var c = N.cidr(parts[i]); if (!c) return null; list.push([c.first, c.last]); }
    list.sort(function (x, y) { return x[0] - y[0]; });
    var out = [list[0].slice()];
    list.slice(1).forEach(function (r) { var last = out[out.length - 1]; if (r[0] <= last[1] + 1) last[1] = Math.max(last[1], r[1]); else out.push(r.slice()); });
    return out;
  }
  function same(a, b) { return !!a && !!b && JSON.stringify(a) === JSON.stringify(b); }
  var LIM_NET = ranges('192.168.1.0/24'), BRI_NET = ranges('10.20.20.0/24, 10.20.21.0/24');
  function side(a, i) { return (a.sites || [])[i] || {}; }
  S.add({
    id: 'E11', act: 2, tool: 'form',
    form: {
      intro: 'Un tunnel IPsec site à site entre les deux pfSense. Chaque box décrit le tunnel de son point de vue : son pair, ses réseaux locaux, les réseaux distants. Plusieurs réseaux : sépare-les par une virgule.',
      docs: [
        { title: 'Les deux sites', lines: [
          'box-limoges   adresse publique 203.0.113.10   LAN 192.168.1.0/24 (pas renuméroté ce trimestre)',
          'box-brive     adresse publique 203.0.113.80   LAN 192.168.1.0/24 aujourd\'hui',
          '              après la renumérotation : postes 10.20.20.0/24, serveurs 10.20.21.0/24'
        ] },
        { title: 'Note de Julien, collée sur la configuration de box-limoges', mono: false, lines: [
          'J\'ai mis comme sur un tuto : mode agressif, c\'est plus rapide. Clé : kerbrat2026, facile à retenir. 3DES, ça marche partout. La suite demain !'
        ] }
      ],
      fields: [
        { id: 'sites', type: 'table', label: 'Les deux côtés du tunnel', fixedRows: ['box-limoges (Limoges)', 'box-brive (Brive)'],
          columns: [{ id: 'pair', label: 'Pair distant (adresse publique)', mono: true, placeholder: '203.0.113.x' }, { id: 'local', label: 'Réseaux locaux', mono: true }, { id: 'distant', label: 'Réseaux distants', mono: true }] },
        { id: 'ike', type: 'select', label: 'Phase 1 : version d\'IKE', options: [['ikev2', 'IKEv2'], ['ikev1-main', 'IKEv1, mode principal'], ['ikev1-aggr', 'IKEv1, mode agressif']] },
        { id: 'auth', type: 'select', label: 'Authentification des box', options: [['psk', 'Clé partagée (PSK)'], ['cert', 'Certificats (autorité interne d\'Arpège)']] },
        { id: 'psk', type: 'text', label: 'Clé partagée (si PSK)', mono: true, placeholder: 'vide si certificats' },
        { id: 'p1', type: 'select', label: 'Phase 1 : chiffrement, intégrité, groupe Diffie-Hellman', options: [['aes256gcm-dh19', 'AES-256-GCM, SHA-256, groupe 19 (ECP 256)'], ['aes128-sha1-dh2', 'AES-128-CBC, SHA-1, groupe 2 (1024 bits)'], ['3des-md5-dh1', '3DES, MD5, groupe 1 (768 bits)']] },
        { id: 'pfs', type: 'select', label: 'Phase 2 : PFS (confidentialité persistante)', options: [['19', 'Groupe 19 (ECP 256)'], ['14', 'Groupe 14 (2048 bits)'], ['off', 'Désactivée']] }
      ]
    },
    start: { sites: [{ pair: '203.0.113.80', local: '192.168.1.0/24', distant: '192.168.1.0/24' }, {}], ike: 'ikev1-aggr', auth: 'psk', psk: 'kerbrat2026', p1: '3des-md5-dh1', pfs: 'off' },
    ref: { sites: [{ pair: '203.0.113.80', local: '192.168.1.0/24', distant: '10.20.20.0/24, 10.20.21.0/24' }, { pair: '203.0.113.10', local: '10.20.20.0/24, 10.20.21.0/24', distant: '192.168.1.0/24' }], ike: 'ikev2', auth: 'cert', psk: '', p1: 'aes256gcm-dh19', pfs: '19' },
    cases: [
      { id: 'pairs', label: 'chaque box vise l\'adresse publique de l\'autre', visible: true, check: function (a, H) {
        if (H.clean(side(a, 0).pair) !== '203.0.113.80') return 'box-limoges doit joindre box-brive sur son adresse publique, 203.0.113.80';
        if (H.clean(side(a, 1).pair) !== '203.0.113.10') return 'box-brive doit joindre box-limoges sur son adresse publique, 203.0.113.10';
        return true;
      } },
      { id: 'ecrits', label: 'chaque côté nomme ses réseaux locaux et distants', visible: true, check: function (a) {
        var names = ['box-limoges', 'box-brive'];
        for (var i = 0; i < 2; i++) {
          if (!ranges(side(a, i).local)) return 'les réseaux locaux de ' + names[i] + ' ne sont pas des réseaux valides (exemple : 10.20.20.0/24)';
          if (!ranges(side(a, i).distant)) return 'les réseaux distants de ' + names[i] + ' ne sont pas des réseaux valides';
        }
        return true;
      } },
      { id: 'miroir', label: 'les deux côtés sont le miroir l\'un de l\'autre', check: function (a) {
        if (!same(ranges(side(a, 0).local), ranges(side(a, 1).distant))) return 'ce que Limoges annonce en local n\'est pas ce que Brive attend en distant : la phase 2 ne se négocie pas';
        if (!same(ranges(side(a, 1).local), ranges(side(a, 0).distant))) return 'ce que Brive annonce en local n\'est pas ce que Limoges attend en distant : la phase 2 ne se négocie pas';
        return true;
      } },
      { id: 'limoges', label: 'Limoges garde son réseau ce trimestre', check: function (a) {
        return same(ranges(side(a, 0).local), LIM_NET) ? true : 'Limoges n\'est pas renuméroté ce trimestre : son réseau local reste 192.168.1.0/24, et rien d\'autre';
      } },
      { id: 'brive', label: 'Brive annonce ses nouveaux réseaux', check: function (a) {
        var r = ranges(side(a, 1).local);
        if (r && r.some(function (x) { return x[0] <= LIM_NET[0][1] && LIM_NET[0][0] <= x[1]; })) return 'Brive annonce encore 192.168.1.0/24, le réseau de Limoges : le tunnel ne saurait pas de quel côté envoyer un paquet';
        return same(r, BRI_NET) ? true : 'Brive doit annoncer ses deux réseaux du plan, postes 10.20.20.0/24 et serveurs 10.20.21.0/24, ni plus ni moins';
      } },
      { id: 'mode', label: 'une négociation qui ne livre pas la clé', check: function (a) {
        if (!a.ike) return 'aucune version d\'IKE choisie';
        return a.ike === 'ikev1-aggr' ? 'le mode agressif envoie une empreinte de la clé sans la protéger : elle se casse hors ligne' : true;
      } },
      { id: 'cle', label: 'une clé qu\'on ne devine pas', check: function (a, H) {
        if (a.auth === 'cert') return true;
        if (a.auth !== 'psk') return 'aucune méthode d\'authentification choisie';
        var k = H.clean(a.psk), classes = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^a-zA-Z0-9]/].filter(function (re) { return re.test(k); }).length;
        if (k.length < 20) return 'une clé partagée de ' + k.length + ' caractères se casse : 20 caractères aléatoires au moins, ou des certificats';
        if (/kerbrat|delorme|brive|limoges|2026|password|motdepasse/i.test(k)) return 'la clé contient un mot qu\'on devine (le nom du cabinet, la ville, l\'année)';
        if (classes < 3) return 'la clé mélange trop peu de sortes de caractères : tire-la au hasard';
        return true;
      } },
      { id: 'algos', label: 'des algorithmes encore sûrs', check: function (a) {
        if (a.p1 !== 'aes256gcm-dh19') return a.p1 === '3des-md5-dh1' ? '3DES et MD5 sont cassés depuis longtemps, et le groupe 1 aussi' : (a.p1 ? 'SHA-1 et le groupe 2 (1024 bits) ne sont plus recommandés' : 'aucune proposition de phase 1 choisie');
        if (a.pfs === 'off' || !a.pfs) return 'sans PFS, une clé volée un jour déchiffre tout le trafic enregistré avant';
        return true;
      } }
    ],
    hints: [
      { title: 'Fiche : un tunnel IPsec site à site', body: '1. Les pairs : chaque box vise l\'adresse publique de l\'autre.\n2. La phase 2 : local d\'un côté = distant de l\'autre, au réseau près.\n3. Les réseaux sont ceux du plan cible : on ne relie pas deux 192.168.1.0/24.\n4. IKEv2 (ou IKEv1 en mode principal), jamais le mode agressif avec une clé partagée.\n5. Clé partagée : 20 caractères aléatoires au moins, ou des certificats.\n6. AES-GCM, SHA-256, groupes 14 ou 19, PFS activée.' },
      { title: 'La réponse', body: 'box-limoges : pair 203.0.113.80 · local 192.168.1.0/24 · distant 10.20.20.0/24, 10.20.21.0/24\nbox-brive : pair 203.0.113.10 · local 10.20.20.0/24, 10.20.21.0/24 · distant 192.168.1.0/24\nIKEv2 · certificats · AES-256-GCM, SHA-256, groupe 19 · PFS groupe 19' }
    ],
    done: [
      { who: 'helene', expr: 'sourire', text: 'Le tunnel est écrit des deux côtés. Il montera dès que Brive aura ses nouvelles adresses. Et la clé de Julien part à la poubelle.' }
    ]
  });

  // ——— E12 : les règles WAN de box-brive ———
  S.add({
    id: 'E12', act: 2,
    views: {
      nat: { title: 'box-brive (pfSense 2.7) · règles WAN, NAT et pare-feu · export du 30 octobre 2026', lines: [
        'Interface  Proto  Port externe  Destination          Description                               État',
        'WAN        TCP    3389          192.168.1.101:3389   « bureau à distance Maëlle » (2019)        désactivée',
        'WAN        TCP    5000          192.168.1.20:5000    « DSM nas-kerbrat » (2021)                 désactivée',
        'WAN        TCP    5001          192.168.1.30:5001    « DSM ancien NAS, accès de chez moi » (2017)  active',
        'WAN        UDP    500, 4500     (box-brive)          IPsec vers Limoges (ajout du 29 octobre)   active',
        'WAN        TCP    443           (box-brive)          interface d\'administration                 bloquée',
        'WAN        *      *             *                    tout le reste                               bloqué'
      ] }
    },
    done: [
      { who: 'thibault', expr: 'surprise', text: 'Le 5001 ? C\'est moi, en 2017, pour récupérer mes détails de menuiserie le dimanche. Je ne pensais pas que c\'était encore ouvert.' },
      { who: 'maelle', expr: 'sourcil', text: 'Un serveur ouvert sur Internet depuis neuf ans, avec nos archives dedans. Je veux son journal sur mon bureau.' },
      { who: 'helene', expr: 'neutre', text: 'Journal copié, redirection désactivée. Pour la suite, on le traite comme un suspect, pas comme un coupable.' }
    ]
  });

  // ——— E14 : la nuit de Brive (plan de bascule) ———
  /** A service the plan breaks by itself (wrong order, done out of the window): repairing it in the night does not make the plan right. */
  function mistake(st, svc, text) { st.svc[svc] = false; st.event = text; if (!st.mistake) st.mistake = text; }
  /** A server or network change done a week before, during working hours. */
  function weekday(st, c, svc, what) { if (c.phase !== 'prep') return false; mistake(st, svc, what + ' une semaine avant, en pleine semaine de travail : Brive le perd en plein travail'); return true; }
  var briveGoal = function (st) {
    if (st.mistake) return 'le plan a cassé lui-même ce qu\'il devait déplacer : ' + st.mistake.charAt(0).toLowerCase() + st.mistake.slice(1);
    var miss = [['vlan', 'les nouveaux réseaux n\'existent pas sur la box'], ['dc', 'dc-kerbrat n\'est pas renuméroté'], ['lic', 'lic-kerbrat n\'est pas renuméroté'], ['nas', 'nas-kerbrat n\'est pas renuméroté'], ['dhcp', 'les postes ne reçoivent pas d\'adresse en 10.20.20.0/24'], ['retirer', 'l\'ancien réseau 192.168.1.0/24 est encore sur box-brive : le tunnel ne peut pas joindre Limoges']]
      .filter(function (m) { return !st.r[m[0]]; });
    return miss.length ? miss[0][1] : true;
  };
  S.add({
    id: 'E14', act: 2, tool: 'plan',
    plan: {
      aim: 'Brive passe dans le nouveau plan : postes en 10.20.20.0/24, serveurs en 10.20.21.0/24, et l\'ancien réseau disparaît pour que le tunnel joigne Limoges. Lundi à 8 h, tout le monde travaille comme vendredi.',
      services: {
        postes: 'les postes de Brive ont une adresse et une passerelle',
        noms: 'les postes trouvent les serveurs par leur nom',
        cao: 'les licences de CAO',
        projets: 'le partage Projets',
        impression: 'l\'impression (traceur et imprimante de l\'accueil)',
        routage: 'les passerelles des nouveaux réseaux et le tunnel vers Limoges',
        restes: 'les postes restés allumés pendant le week-end'
      },
      init: function (st) { st.r = {}; },
      windows: [
        { id: 'jeu', label: 'Jeudi 5 novembre, 19 h – 23 h', start: JEU + 1140, end: JEU + 1380, problem: 'jeudi soir, l\'équipe boucle le rendu du permis de construire, déposé vendredi midi' },
        { id: 'ven', label: 'Vendredi 6 novembre, 21 h – 23 h 30', start: VEN + 1260, end: VEN + 1410 },
        { id: 'sam', label: 'Samedi 7 novembre, 8 h – 18 h', start: SAM + 480, end: SAM + 1080, problem: 'Thibault travaille le samedi matin : il a besoin de Projets et d\'une licence' },
        { id: 'dim', label: 'Dimanche 8 novembre, 9 h – 19 h', start: DIM + 540, end: DIM + 1140 },
        { id: 'lun', label: 'Lundi 9 novembre, 5 h – 8 h', start: LUN + 300, end: LUN + 480, problem: 'le traceur doit imprimer à 8 h : aucune marge si quelque chose casse' }
      ],
      ops: [
        { id: 'bail', label: 'Raccourcir à une heure la durée des baux DHCP', dur: 5, safe: true, run: function () { } },
        { id: 'ttl', label: 'Abaisser à 5 minutes le TTL des enregistrements de kerbrat.lan', dur: 5, safe: true, run: function () { } },
        { id: 'sauver', label: 'Sauvegarder la configuration de box-brive (fichier XML)', dur: 5, safe: true, run: function () { } },
        { id: 'relever', label: 'Relever la configuration des machines à adresse fixe (balayage et fiches)', dur: 30, safe: true, run: function () { } },
        { id: 'vlan', label: 'Créer sur box-brive le VLAN serveurs et les passerelles 10.20.20.1 et 10.20.21.1', dur: 30, undo: ['restaurer-box'], run: function (st) { st.r.vlan = true; } },
        { id: 'dc', label: 'Renuméroter dc-kerbrat en 10.20.21.10 et réécrire les enregistrements du domaine', dur: 60, undo: ['remettre-dc'], run: function (st, c) {
          if (weekday(st, c, 'noms', 'dc-kerbrat renuméroté')) { st.r.dc = true; return; }
          if (!st.r.vlan) mistake(st, 'noms', 'dc-kerbrat passe en 10.20.21.10, mais aucune passerelle n\'existe encore dans ce réseau : plus personne ne le joint');
          else if (c.flags.reboot) {
            st.svc.noms = false; st.svc.postes = false; st.svc.routage = false;
            st.event = 'Pendant la renumérotation de dc-kerbrat, box-brive applique sa mise à jour automatique du dimanche, redémarre, et repart sur une configuration à moitié écrite : plus de passerelle, plus de DNS joignable, plus de tunnel';
          }
          st.r.dc = true;
        } },
        { id: 'alias', label: 'Ajouter 192.168.1.15 en adresse secondaire (alias) sur lic-kerbrat', dur: 10, safe: true, run: function () { } },
        { id: 'lic', label: 'Renuméroter lic-kerbrat en 10.20.21.15 et redémarrer le démon de licences', dur: 20, undo: ['remettre-lic'], run: function (st, c) {
          if (weekday(st, c, 'cao', 'lic-kerbrat renuméroté')) { st.r.lic = true; return; }
          if (!st.r.vlan) mistake(st, 'cao', 'lic-kerbrat passe en 10.20.21.15, un réseau sans passerelle : les postes ne le joignent plus');
          else if (c.flags.licOld && !c.done.alias) { st.svc.cao = false; st.event =  'Le démon de licences redémarre et refuse : la licence réémise par l\'éditeur porte 10.20.12.15, une faute de frappe, et l\'ancienne est liée à 192.168.1.15, que la machine n\'a plus. L\'éditeur ne répond pas avant lundi'; }
          st.r.lic = true;
        } },
        { id: 'nas', label: 'Renuméroter nas-kerbrat en 10.20.21.20 (et srv-kerbrat-old, provisoirement, en 10.20.21.30), mettre le DNS à jour', dur: 30, undo: ['remettre-nas'], run: function (st, c) {
          if (weekday(st, c, 'projets', 'nas-kerbrat renuméroté')) { st.r.nas = true; return; }
          if (!st.r.vlan) mistake(st, 'projets', 'nas-kerbrat passe en 10.20.21.20, un réseau sans passerelle : Projets ne s\'ouvre plus');
          else if (c.flags.dnsCache && c.done.ttl !== 'prep') { st.svc.restes = false; st.event =  'Le poste de Claire, resté allumé avec Projets ouvert, garde en cache nas-kerbrat = 192.168.1.20 pour 24 h, la durée de vie de l\'enregistrement : lundi matin, Projets ne s\'ouvre pas chez elle'; }
          st.r.nas = true;
        } },
        { id: 'dhcp', label: 'Passer l\'étendue DHCP sur 10.20.20.0/24 (DNS 10.20.21.10, réservation du traceur) et activer le relais DHCP de box-brive', dur: 30, undo: ['ancienne-etendue'], run: function (st, c) {
          if (weekday(st, c, 'postes', 'L\'étendue DHCP basculée')) { st.r.dhcp = true; return; }
          if (!st.r.vlan) mistake(st, 'postes', 'Les postes demandent une adresse en 10.20.20.0/24, mais la box n\'a ni ce réseau ni de relais DHCP : ils n\'ont plus rien');
          else if (!st.r.dc) mistake(st, 'noms', 'Les postes reçoivent comme DNS 10.20.21.10, où personne ne répond encore : ils ne trouvent plus aucun serveur par son nom');
          st.r.dhcp = true;
        } },
        { id: 'retirer', label: 'Supprimer l\'ancien réseau 192.168.1.0/24 : interface de la box, ancienne étendue et ses baux', dur: 15, irreversible: true, run: function (st, c) {
          if (weekday(st, c, 'postes', 'L\'ancien réseau supprimé')) { st.r.retirer = true; return; }
          var left = [['dhcp', 'postes', 'les postes'], ['dc', 'noms', 'dc-kerbrat'], ['lic', 'cao', 'lic-kerbrat'], ['nas', 'projets', 'nas-kerbrat']].filter(function (m) { return !st.r[m[0]]; });
          if (left.length) left.forEach(function (m) { mistake(st, m[1], 'L\'ancien réseau disparaît alors que ' + left.map(function (x) { return x[2]; }).join(', ') + ' y ' + (left.length > 1 ? 'sont' : 'est') + ' encore : coupés de tout'); });
          else if (c.flags.rendus && c.done.bail !== 'prep') { st.svc.restes = false; st.event = 'Trois postes de rendu, restés allumés tout le week-end, gardent leur bail de huit jours en 192.168.1.x : l\'ancien réseau supprimé, ils sont coupés de tout jusqu\'à mercredi'; }
          else if (c.flags.forgotten) { st.svc.impression = false; st.event = 'L\'imprimante de l\'accueil, en 192.168.1.60 écrite à la main en juillet, éteinte le jour du balayage, ne répond plus'; }
          st.r.retirer = true;
        } },
        { id: 'reboot-postes', label: 'Redémarrer à distance les neuf postes de Brive', dur: 20, safe: true, run: function (st, c) {
          if (c.flags.rendus) { st.lost.push('trois rendus de nuit, 30 heures de calcul'); st.event = 'Les trois postes qui calculaient des rendus redémarrent : 30 heures de calcul perdues'; }
        } },
        { id: 'pfsense', label: 'Mettre à jour pfSense en 2.8, tant qu\'on y est', dur: 60, run: function () { } },
        { id: 'nat11', label: 'Monter un NAT 1:1 provisoire (Brive vue de Limoges en 10.99.1.0/24)', dur: 40, safe: true, run: function () { } }
      ],
      checks: [
        { id: 'passerelles', label: 'Le portable du technicien, sur le VLAN serveurs, joint 10.20.21.1 et SRV-DELORME par le tunnel', sees: ['routage'] },
        { id: 'nslookup', label: 'Depuis un poste, nslookup dc-kerbrat.kerbrat.lan répond 10.20.21.10', sees: ['noms'] },
        { id: 'licence', label: 'Un poste lance la CAO et obtient une licence', sees: ['cao'] },
        { id: 'projets', label: 'Un poste ouvre \\\\nas-kerbrat\\Projets', sees: ['projets'] },
        { id: 'poste', label: 'Un poste redémarré reçoit une adresse en 10.20.20.x et ouvre une session', sees: ['postes', 'noms'] },
        { id: 'final', label: 'Tour complet depuis un poste : session, licence, Projets, une page sur chaque imprimante', sees: ['postes', 'noms', 'cao', 'projets', 'impression'] },
        { id: 'ping-lic', label: 'lic-kerbrat répond au ping sur 10.20.21.15', sees: [] },
        { id: 'console-dhcp', label: 'La console DHCP affiche la nouvelle étendue', sees: [] }
      ],
      backs: [
        { id: 'restaurer-box', label: 'Recharger la configuration sauvegardée de box-brive', dur: 20, needs: ['sauver'] },
        { id: 'remettre-dc', label: 'Remettre dc-kerbrat sur son ancienne configuration relevée', dur: 30, needs: ['relever'] },
        { id: 'remettre-lic', label: 'Remettre lic-kerbrat sur 192.168.1.15', dur: 15, needs: ['relever'] },
        { id: 'remettre-nas', label: 'Remettre nas-kerbrat sur 192.168.1.20', dur: 15, needs: ['relever'] },
        { id: 'ancienne-etendue', label: 'Réactiver l\'ancienne étendue DHCP', dur: 15 },
        { id: 'redemarrer-box', label: 'Redémarrer box-brive', dur: 10 }
      ],
      goal: briveGoal,
      repair: 45
    },
    start: { window: '', prep: [], night: [], noReturn: null },
    ref: {
      window: 'dim', prep: ['bail', 'ttl', 'sauver', 'relever'],
      night: [
        { op: 'vlan', check: 'passerelles', back: 'restaurer-box' },
        { op: 'dc', check: 'nslookup', back: 'remettre-dc' },
        { op: 'alias' },
        { op: 'lic', check: 'licence' },
        { op: 'nas', check: 'projets' },
        { op: 'dhcp', check: 'poste' },
        { op: 'retirer', check: 'final' }
      ],
      noReturn: 2
    },
    cases: [
      { id: 'nuit', label: 'la nuit prévue, sans incident : lundi, Brive travaille dans son nouveau réseau', visible: true, flags: {} },
      { id: 'bail', label: 'des baux DHCP de huit jours et trois postes de rendu allumés tout le week-end', flags: { rendus: true } },
      { id: 'licence', label: 'un serveur de licences lié à l\'ancienne adresse', flags: { licOld: true, unrepairable: ['cao'] } },
      { id: 'cache', label: 'un enregistrement DNS gardé en cache pour 24 h', flags: { dnsCache: true } },
      { id: 'fixe', label: 'une adresse fixe que personne n\'a relevée', flags: { forgotten: true }, unavoidable: true },
      { id: 'reboot', label: 'une box qui redémarre au mauvais moment', flags: { reboot: true, unrepairable: ['postes', 'noms', 'routage'] }, unavoidable: true }
    ],
    hints: [
      { title: 'Fiche : la nuit d\'une renumérotation', body: '1. Une semaine avant : raccourcir les baux (un poste ne revient qu\'à mi-bail), baisser le TTL du DNS (les caches gardent l\'ancienne adresse), sauvegarder la box, relever les adresses fixes. Sans sauvegarde ni relevé, pas de retour arrière.\n2. Dans l\'ordre : les passerelles, le contrôleur de domaine, les autres serveurs (la licence garde son ancienne adresse en alias), puis l\'étendue DHCP, et l\'ancien réseau en dernier.\n3. Un contrôle après chaque opération risquée, qui teste ce que fait un utilisateur.\n4. Point de non-retour après le contrôleur de domaine : au-delà, on répare en avant. Ce qui ne se défait pas (supprimer l\'ancien réseau) vient après lui, suivi d\'un contrôle complet.' },
      { title: 'La réponse', body: 'Fenêtre : dimanche 9 h – 19 h.\nAvant : raccourcir les baux · abaisser le TTL · sauvegarder la box · relever les adresses fixes.\n1. VLAN et passerelles — contrôle : le portable joint 10.20.21.1 et Limoges — retour : recharger la configuration de la box.\n2. Renuméroter dc-kerbrat — contrôle : nslookup — retour : remettre l\'ancienne configuration.\n◆ Point de non-retour.\n3. Alias 192.168.1.15 sur lic-kerbrat.\n4. Renuméroter lic-kerbrat — contrôle : une licence.\n5. Renuméroter nas-kerbrat — contrôle : Projets.\n6. Étendue DHCP — contrôle : un poste redémarré ouvre une session.\n7. Supprimer l\'ancien réseau — contrôle : le tour complet.' }
    ],
    intro: [
      { who: 'helene', expr: 'neutre', text: 'Le traceur, c\'était une machine. Ici, c\'est tout un site, et cinq nuits cachées au lieu de trois. Même règles : une fenêtre, des contrôles, des retours, un point de non-retour.' },
      { who: 'maelle', expr: 'sourcil', text: 'Et une règle à moi : lundi, je ne veux voir personne chercher ses fichiers.' }
    ],
    done: [
      { who: 'narr', text: 'Dimanche 8 novembre, 15 h 40. Sur la maquette, la première planche de la passerelle se pose entre les deux agences.' },
      { who: 'maelle', expr: 'sourire', text: 'Lundi, 8 h 05. Claire a ouvert Projets, Thibault a sa licence, le traceur imprime. Personne n\'a rien remarqué.' },
      { who: 'helene', expr: 'sourire', text: 'Brive est en 10.20.20 et 10.20.21, le tunnel est monté. Limoges et Brive se parlent pour la première fois.' }
    ]
  });

  // ——— E15 : le journal du vieux NAS ———
  S.add({
    id: 'E15', act: 2,
    views: {
      journal: { title: 'srv-kerbrat-old · Centre des journaux · connexions et fichiers · 1er juin – 30 octobre 2026 (copie du 30 octobre)', lines: [
        '2026-06-12 09:30  thibault  SMB          écriture   /Concours/Piscine-Ussel/rendu_final.zip',
        '2026-07-21 10:15  thibault  HTTPS:5001   connexion réussie        86.243.12.7 (Orange, Brive)',
        '2026-07-21 10:17  thibault  File Station lecture    /Archives/Details/menuiseries_2015.dwg',
        '2026-09-14 03:12  admin     HTTPS:5001   échec (mot de passe)     185.220.101.34 (Allemagne)',
        '2026-10-03 22:48  admin     HTTPS:5001   échec (mot de passe) ×212 45.155.205.8 (Pays-Bas)',
        '2026-10-16 11:02  thibault  SMB          lecture    /Archives/Details/escaliers_2016.dwg',
        '2026-10-27 04:30  admin     HTTPS:5001   échec (compte bloqué)    103.75.190.2 (Viêt Nam)',
        '/Concours : 412 fichiers · dernière écriture le 12/06/2026 à 09:30 · aucun fichier créé ni modifié depuis'
      ] }
    },
    done: [
      { who: 'maelle', expr: 'neutre', text: 'Le dossier Concours dort depuis juin. La médiathèque n\'a jamais touché ce NAS. Rayez-le de la liste.' },
      { who: 'helene', expr: 'sourcil', text: 'Un suspect écarté, pas un chemin trouvé. Le 14 octobre, la planche est partie par ailleurs. On continue, migration par migration.' }
    ]
  });

  // ——— E16 : éteindre proprement le vieux NAS (plan de décommission) ———
  function wipeLosses(st, c) {
    if (!c.done.sauvegarde) st.lost.push('tout le contenu du vieux NAS, jamais sauvegardé');
    else if (st.svc.sauvegarde === false) st.lost.push('tout le contenu du vieux NAS : la seule sauvegarde était illisible');
    if (st.missing) st.lost.push('les photos de chantier 2009-2015 du volume 2, présentes nulle part ailleurs');
  }
  var nasGoal = function (st, done) {
    if (st.mistake) return st.mistake;
    if (!done.eteindre) return 'srv-kerbrat-old a été effacé en marche, sans avoir jamais été éteint : personne n\'a pu constater qu\'il manquait';
    if (!done.fermer) return 'le port 5001 est toujours redirigé vers le vieux NAS';
    if (!st.erased) return st.quick ? 'un formatage rapide laisse les données lisibles : les disques doivent être effacés' : 'les disques du vieux NAS ne sont pas effacés';
    return true;
  };
  var FRIDAY = VEN + 1080;
  S.add({
    id: 'E16', act: 2, tool: 'plan',
    plan: {
      aim: 'srv-kerbrat-old disparaît sans rien emporter : plus de port ouvert, tout son contenu retrouvable ailleurs, ses disques effacés quand plus personne n\'en a besoin.',
      services: {
        expose: 'le port 5001 fermé sur Internet',
        sauvegarde: 'une sauvegarde lisible du vieux NAS',
        postes: 'les postes de Brive et leurs lecteurs réseau'
      },
      init: function (st) { st.svc.expose = false; },
      windows: [
        { id: 'jeu', label: 'Jeudi 12 novembre, 12 h – 14 h, effacement le 14 décembre', start: JEU + 720, end: JEU + 720 + 33 * DAY, problem: 'en pleine journée de travail : éteindre un serveur pendant que les postes s\'en servent, c\'est couper sans prévenir ceux qui l\'utilisent encore' },
        { id: 'court', label: 'Vendredi 13 novembre 18 h, effacement le lundi 16 novembre', start: FRIDAY, end: LUN + 480 },
        { id: 'ven', label: 'Vendredi 13 novembre 18 h, effacement le lundi 14 décembre', start: FRIDAY, end: FRIDAY + 31 * DAY },
        { id: 'janv', label: 'Vendredi 8 janvier 18 h, effacement le 8 février', start: FRIDAY + 56 * DAY, end: FRIDAY + 87 * DAY, problem: 'après la fusion : Maëlle veut qu\'au 1er janvier, plus rien de l\'ancien SI de Kerbrat ne traîne dans le nouveau cabinet' }
      ],
      ops: [
        { id: 'fermer', label: 'Supprimer la redirection du port 5001 et sa règle de pare-feu sur box-brive', dur: 10, safe: true, run: function (st) { st.svc.expose = true; } },
        { id: 'annoncer', label: 'Prévenir l\'équipe de Brive : le vieux NAS s\'éteint vendredi soir', dur: 10, safe: true, run: function () { } },
        { id: 'inventaire', label: 'Lister tout le contenu du vieux NAS (volumes, dossiers, comptes) et le comparer à nas-kerbrat', dur: 60, safe: true, run: function () { } },
        { id: 'sauvegarde', label: 'Sauvegarder tout le vieux NAS sur nas-delorme (Hyper Backup chiffré, 1,8 To)', dur: 240, safe: true, run: function (st, c) {
          if (st.off) { st.svc.sauvegarde = false; st.event = 'Le vieux NAS est déjà éteint : la sauvegarde ne démarre pas'; return; }
          if (c.flags.unique && !c.done.inventaire) { st.missing = true; st.event = 'La tâche de sauvegarde ne prend que les dossiers partagés : le volume 2, partagé avec personne, n\'y est pas'; }
          if (c.flags.unreadable) { st.svc.sauvegarde = false; st.event = 'Hyper Backup affiche « Sauvegarde réussie », mais l\'archive ne s\'ouvre pas : la clé de chiffrement notée par Thibault n\'est pas la bonne'; }
        } },
        { id: 'eteindre', label: 'Éteindre srv-kerbrat-old, le débrancher, l\'étiqueter « ne pas effacer avant le 14 décembre »', dur: 15, undo: ['rallumer'], run: function (st, c) { st.off = true; if (c.flags.mounted) st.pendingMount = true; } },
        { id: 'attendre', label: 'Attendre trente jours, le NAS éteint mais intact', dur: 30 * DAY, safe: true, run: function (st) {
          if (st.erased) st.mistake = 'attendre trente jours après l\'effacement ne sert à rien : il n\'y a plus rien à rallumer';
          if (st.pendingMount) { st.pendingMount = false; st.svc.postes = false; st.event = 'Le lundi suivant, Thibault : « Mon lecteur Z: a disparu. » Ses bibliothèques de détails y étaient encore montées. Il était en congé la semaine de l\'annonce'; }
        } },
        { id: 'effacer', label: 'Effacer les disques (effacement sécurisé) et les remettre au recyclage', dur: 180, irreversible: true, run: function (st, c) {
          if (st.pendingMount) { st.pendingMount = false; st.svc.postes = false; st.event = 'Le lundi suivant, Thibault cherche son lecteur Z:, encore monté sur le vieux NAS. Les disques sont déjà effacés'; }
          wipeLosses(st, c);
          st.erased = true;
        } },
        { id: 'dsm', label: 'Mettre à jour DSM avant de l\'éteindre', dur: 60, safe: true, run: function () { } },
        { id: 'formater', label: 'Formater les disques depuis DSM (formatage rapide)', dur: 15, irreversible: true, run: function (st, c) {
          wipeLosses(st, c);
          st.quick = true;
        } }
      ],
      checks: [
        { id: 'restaurer', label: 'Restaurer trois fichiers de la sauvegarde sur un poste et les ouvrir', sees: ['sauvegarde'] },
        { id: 'succes', label: 'Hyper Backup affiche « Sauvegarde réussie »', sees: [] },
        { id: 'personne', label: 'Trente jours sans que personne ne réclame le vieux NAS (appels, lecteurs réseau des postes)', sees: ['postes'] },
        { id: 'ping', label: 'srv-kerbrat-old ne répond plus au ping', sees: [] },
        { id: 'scan', label: 'Un scan depuis Internet ne voit plus le port 5001', sees: ['expose'] }
      ],
      backs: [
        { id: 'rallumer', label: 'Rebrancher et rallumer srv-kerbrat-old', dur: 20 },
        { id: 'rouvrir', label: 'Rouvrir la redirection du port 5001', dur: 5 },
        { id: 'restaurer-tout', label: 'Restaurer toute la sauvegarde sur nas-kerbrat', dur: 300, needs: ['sauvegarde'] }
      ],
      goal: nasGoal,
      repair: 45
    },
    start: { window: '', prep: [], night: [], noReturn: null },
    ref: {
      window: 'ven', prep: ['fermer', 'annoncer', 'inventaire'],
      night: [
        { op: 'sauvegarde', check: 'restaurer' },
        { op: 'eteindre', back: 'rallumer' },
        { op: 'attendre', check: 'personne' },
        { op: 'effacer' }
      ],
      noReturn: 3
    },
    cases: [
      { id: 'nuit', label: 'le mois prévu, sans surprise : le vieux NAS disparaît, rien ne manque', visible: true, flags: {} },
      { id: 'unique', label: 'des données présentes nulle part ailleurs', flags: { unique: true } },
      { id: 'monte', label: 'un partage encore monté par un poste', flags: { mounted: true }, unavoidable: true },
      { id: 'illisible', label: 'une sauvegarde illisible', flags: { unreadable: true }, unavoidable: true }
    ],
    hints: [
      { title: 'Fiche : décommissionner un serveur', body: '1. Avant : fermer ce qui l\'expose, prévenir les utilisateurs, lister TOUT ce qu\'il contient (volumes, dossiers non partagés, comptes).\n2. Sauvegarder, puis restaurer quelques fichiers et les ouvrir : une sauvegarde jamais relue n\'est pas une sauvegarde.\n3. Éteindre sans effacer, avec un retour : le rallumer.\n4. Attendre un mois, éteint mais intact, et noter qui le réclame.\n5. Point de non-retour, puis effacer : ça ne se défait pas.' },
      { title: 'La réponse', body: 'Fenêtre : vendredi 13 novembre 18 h, effacement le 14 décembre.\nAvant : supprimer la redirection du port 5001 · prévenir l\'équipe · lister le contenu et le comparer à nas-kerbrat.\n1. Sauvegarder — contrôle : restaurer trois fichiers et les ouvrir.\n2. Éteindre — retour : rallumer.\n3. Attendre trente jours — contrôle : personne ne l\'a réclamé.\n◆ Point de non-retour.\n4. Effacer les disques.' }
    ],
    done: [
      { who: 'thibault', expr: 'neutre', text: 'Trente jours, et personne ne l\'a réclamé. Même pas moi. Bon. Adieu, vieux DS214.' },
      { who: 'helene', expr: 'sourire', text: 'Le port est fermé, la sauvegarde se relit, les disques sont effacés. Une machine de moins à oublier : c\'est aussi ça, une migration.' },
      { who: 'narr', text: 'Sur la maquette, un petit carton gris quitte l\'agence de Brive. Le deuxième acte se referme.' }
    ]
  });
})(FSSteps);
