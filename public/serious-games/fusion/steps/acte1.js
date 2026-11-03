/* Acte 1 — Deux SI pour un (semaines du 12 et du 19 octobre 2026). L'état des lieux : inventaires,
   dépendances, la migration de septembre, le cartouche, et le premier plan de bascule (le traceur). */
(function (S) {
  'use strict';

  // ——— E01 : les deux schémas ———
  S.add({
    id: 'E01', act: 1,
    views: {
      schemas: { title: 'Schémas fournis par les anciens prestataires', lines: [
        '— ATELIER DELORME · LIMOGES (schéma de 2021) —',
        'Accès Internet : fibre pro 1 Gbit/s, box-limoges (pfSense)',
        'Réseau local : 192.168.1.0/24 (Limoges)',
        'Passerelle : 192.168.1.1 · DNS : 192.168.1.10 (SRV-DELORME)',
        'Domaine : delorme.local (Windows Server 2019)',
        '— KERBRAT ARCHITECTES · BRIVE (schéma de 2016) —',
        'Accès Internet : fibre pro 500 Mbit/s, box-brive (pfSense)',
        'Réseau local : 192.168.1.0/24 (Brive)',
        'Passerelle : 192.168.1.1 · DNS : 192.168.1.10 (dc-kerbrat)',
        'Domaine : kerbrat.lan (Samba 4 sur Debian)'
      ] }
    },
    intro: [
      { who: 'narr', text: 'Lundi 12 octobre, 9 h. Arpège Informatique, Limoges. Ta tutrice t\'attend avec deux cafés et un dossier mince.' },
      { who: 'helene', expr: 'sourire', text: 'Je suis Hélène, cheffe de projet. Deux cabinets d\'architectes fusionnent au 1er janvier : l\'Atelier Delorme, ici, et Kerbrat Architectes, à Brive. On a un trimestre pour faire un seul SI des deux.' },
      { who: 'helene', expr: 'neutre', text: 'Ma règle : on ne touche à rien qu\'on n\'a pas compris, et on ne bascule rien sans savoir comment revenir. Julien Delorme vient de m\'écrire. Lis son mail avec moi.' }
    ]
  });

  // ——— E02 : l'inventaire de Limoges (formulaire) ———
  var LIM = [['box', 'box-limoges · 192.168.1.1'], ['srv', 'SRV-DELORME · 192.168.1.10'], ['exch', 'EXCH-DELORME · 192.168.1.11'], ['nas', 'nas-delorme · 192.168.1.12'],
    ['copieur', 'copieur-limoges · 192.168.1.40'], ['pc03', 'PC-LIM-03 · 192.168.1.103'], ['pc07', 'PC-LIM-07 · 192.168.1.107'], ['portable', 'PORTABLE-JD · 192.168.1.131'], ['pc12', 'PC-LIM-12 · 192.168.1.20']];
  var LIM_FIXED = ['box', 'srv', 'exch', 'nas', 'copieur'];
  function same(a, b) { a = (a || []).slice().sort(); b = b.slice().sort(); return a.length === b.length && a.every(function (x, i) { return x === b[i]; }); }
  S.add({
    id: 'E02', act: 1, tool: 'form',
    form: {
      intro: 'Sandrine a sorti deux listes du serveur. Une machine qui répond sur le réseau sans avoir de bail DHCP a une adresse écrite à la main : une adresse fixe.',
      docs: [
        { title: 'Baux DHCP actifs (SRV-DELORME, étendue 192.168.1.0)', lines: [
          'Adresse IP       Nom               Expiration du bail',
          '192.168.1.20     PC-LIM-12         19/10/2026 08:12   (réservation)',
          '192.168.1.103    PC-LIM-03         18/10/2026 17:40',
          '192.168.1.107    PC-LIM-07         19/10/2026 09:02',
          '192.168.1.131    PORTABLE-JD       13/10/2026 18:55',
          '192.168.1.144    PC-LIM-09         17/10/2026 11:30'
        ] },
        { title: 'Table ARP de SRV-DELORME (arp -a), lundi 9 h 40', lines: [
          'Adresse Internet      Adresse physique      Type',
          '192.168.1.1           00-08-a2-1c-40-01     dynamique',
          '192.168.1.11          00-15-5d-0a-11-02     dynamique',
          '192.168.1.12          00-11-32-8e-77-0c     dynamique',
          '192.168.1.20          f4-39-09-22-ab-10     dynamique',
          '192.168.1.40          00-26-73-91-5c-3e     dynamique',
          '192.168.1.103         d8-bb-c1-04-13-77     dynamique',
          '192.168.1.107         d8-bb-c1-04-19-2a     dynamique',
          '192.168.1.131         3c-22-fb-6e-90-01     dynamique',
          '(SRV-DELORME lui-même : 192.168.1.10, adresse saisie dans ses propriétés réseau)'
        ] }
      ],
      fields: [{ id: 'fixes', type: 'checks', label: 'Machines à adresse fixe', options: LIM }]
    },
    start: { fixes: [] },
    ref: { fixes: LIM_FIXED.slice() },
    cases: [
      { id: 'serveurs', label: 'les serveurs et la box sont cochés', visible: true, check: function (a, H) {
        var miss = ['box', 'srv', 'exch', 'nas'].filter(function (x) { return !H.has(a.fixes, x); });
        return miss.length ? 'il manque ' + miss.length + ' serveur' + (miss.length > 1 ? 's' : '') + ' ou la box' : true;
      } },
      { id: 'copieur', label: 'une machine qui n\'est pas un serveur, mais qui n\'a pas de bail', check: function (a, H) { return H.has(a.fixes, 'copieur') ? true : 'le copieur répond dans la table ARP et n\'a aucun bail : son adresse est fixe'; } },
      { id: 'reservation', label: 'une réservation n\'est pas une adresse fixe', check: function (a, H) { return H.has(a.fixes, 'pc12') ? 'PC-LIM-12 a une adresse en .20, mais c\'est le DHCP qui la lui donne (réservation)' : true; } },
      { id: 'postes', label: 'les postes en DHCP ne sont pas cochés', check: function (a, H) { return ['pc03', 'pc07', 'portable'].some(function (x) { return H.has(a.fixes, x); }) ? 'un poste qui a un bail reçoit son adresse du DHCP' : true; } },
      { id: 'exact', label: 'la liste complète, ni plus ni moins', check: function (a) { return same(a.fixes, LIM_FIXED) ? true : 'la liste n\'est pas exacte'; } }
    ],
    hints: [
      { title: 'Fiche : bail, réservation, adresse fixe', body: 'Bail : le DHCP prête une adresse pour une durée.\nRéservation : le DHCP donne toujours la même adresse à une machine (reconnue par son adresse MAC). C\'est encore le DHCP.\nAdresse fixe : écrite dans la machine elle-même. Le DHCP ne la connaît pas.\n\nMéthode : une machine de la table ARP sans bail ni réservation = adresse fixe.' },
      { title: 'La réponse', body: 'Fixes : box-limoges (.1), SRV-DELORME (.10), EXCH-DELORME (.11), nas-delorme (.12), copieur-limoges (.40).\nPC-LIM-12 (.20) a une réservation : c\'est le DHCP.' }
    ],
    done: [
      { who: 'sandrine', expr: 'sourire', text: 'Cinq machines à adresse fixe. Et moi qui croyais que le copieur « prenait internet tout seul ».' },
      { who: 'helene', expr: 'neutre', text: 'Ces cinq-là, le jour où on renumérote, il faudra les changer à la main. Les autres suivront le DHCP.' }
    ]
  });

  // ——— E03 : le balayage de Brive ———
  S.add({
    id: 'E03', act: 1,
    views: {
      scan: { title: 'Balayage du réseau de Brive (nmap -sn 192.168.1.0/24), comparé à l\'inventaire de 2016', lines: [
        '192.168.1.1     box-brive          inventaire : passerelle',
        '192.168.1.10    dc-kerbrat         inventaire : contrôleur de domaine, DNS, DHCP',
        '192.168.1.15    lic-kerbrat        inventaire : serveur de licences de CAO',
        '192.168.1.20    nas-kerbrat        inventaire : partages Projets et Concours',
        '192.168.1.30    (sans nom)         Synology DS214, DSM 6.2',
        '192.168.1.50    traceur-a0         inventaire : traceur A0',
        '192.168.1.101   PC-BRI-01          inventaire : poste (DHCP)',
        '192.168.1.102   PC-BRI-02          inventaire : poste (DHCP)',
        '192.168.1.105   PC-BRI-05          inventaire : poste (DHCP)'
      ] }
    },
    done: [
      { who: 'thibault', expr: 'surprise', text: 'Ah, lui ? C\'est l\'ancien NAS. Quand on a acheté le nouveau, j\'ai tout copié, mais je l\'ai gardé, au cas où.' },
      { who: 'helene', expr: 'sourcil', text: 'Un NAS de 2014, pas à jour, que personne n\'inventorie. On le note. On en reparlera.' }
    ]
  });

  // ——— E04 : la matrice des dépendances (formulaire) ———
  var SERV = [['cao', 'Licences de CAO (lic-kerbrat)'], ['traceur', 'Impression sur le traceur'], ['scan', 'Numérisation vers mail (copieur)'], ['projets', 'Partage Projets (nas-kerbrat)']];
  var DEPS = [['ipfixe', 'Son adresse IP ne change pas'], ['dns', 'Le DNS de Brive'], ['exch', 'Exchange à Limoges'], ['ad', 'Le domaine kerbrat.lan']];
  var TRUTH = { cao: ['ipfixe'], traceur: ['ipfixe'], scan: ['exch'], projets: ['dns', 'ad'] };
  function matrixFrom(t) { var m = {}; SERV.forEach(function (s) { m[s[0]] = {}; DEPS.forEach(function (d) { m[s[0]][d[0]] = t[s[0]].indexOf(d[0]) !== -1 ? 'x' : ''; }); }); return m; }
  function row(a, s) { var r = (a.deps || {})[s] || {}; return DEPS.map(function (d) { return d[0]; }).filter(function (d) { return r[d] === 'x'; }); }
  S.add({
    id: 'E04', act: 1, tool: 'form',
    form: {
      intro: 'Les fiches des services, telles que les a laissées l\'ancien prestataire de Brive.',
      docs: [
        { title: 'Fiches des services de Brive', mono: false, lines: [
          'Licences de CAO — lic-kerbrat accorde 6 licences flottantes. Le fichier de licence de l\'éditeur est lié à l\'adresse IP du serveur (192.168.1.15) : à une autre adresse, il refuse de démarrer.',
          'Impression sur le traceur — les pilotes des postes impriment directement sur 192.168.1.50.',
          'Numérisation vers mail — le copieur envoie les numérisations par le serveur de messagerie. Depuis septembre, c\'est Exchange, à Limoges.',
          'Partage Projets — \\\\nas-kerbrat.kerbrat.lan\\Projets, droits donnés aux groupes du domaine kerbrat.lan ; les postes le trouvent par son nom.'
        ] }
      ],
      fields: [{ id: 'deps', type: 'matrix', label: 'Ce dont chaque service dépend', rows: SERV, cols: DEPS, values: [['', '—'], ['x', 'dépend']] }]
    },
    start: { deps: matrixFrom({ cao: [], traceur: [], scan: [], projets: [] }) },
    ref: { deps: matrixFrom(TRUTH) },
    cases: [
      { id: 'cao', label: 'les licences de CAO', visible: true, check: function (a) { var r = row(a, 'cao'); return r.length === 1 && r[0] === 'ipfixe' ? true : 'relis la fiche du serveur de licences : à quoi la licence est-elle liée ?'; } },
      { id: 'traceur', label: 'le traceur', check: function (a) { var r = row(a, 'traceur'); return r.length === 1 && r[0] === 'ipfixe' ? true : 'les pilotes impriment sur une adresse, pas sur un nom'; } },
      { id: 'scan', label: 'la numérisation vers mail', check: function (a) { var r = row(a, 'scan'); return r.length === 1 && r[0] === 'exch' ? true : 'le copieur envoie par le serveur de messagerie, qui est à Limoges depuis septembre'; } },
      { id: 'projets', label: 'le partage Projets', check: function (a) { var r = row(a, 'projets'); return r.length === 2 && r.indexOf('dns') !== -1 && r.indexOf('ad') !== -1 ? true : 'un partage trouvé par son nom, avec des droits donnés à des groupes : deux dépendances'; } }
    ],
    hints: [
      { title: 'Fiche : lire une dépendance', body: 'Pour chaque service, cherche dans sa fiche le mot qui dit « à condition que » :\n- une licence « liée à l\'adresse » → l\'adresse ne doit pas changer ;\n- « par son nom » → le DNS ;\n- « droits donnés aux groupes du domaine » → le domaine ;\n- « envoie par le serveur de messagerie » → Exchange.' },
      { title: 'La réponse', body: 'Licences de CAO : adresse IP fixe.\nTraceur : adresse IP fixe.\nNumérisation : Exchange à Limoges.\nPartage Projets : DNS de Brive + domaine kerbrat.lan.' }
    ],
    done: [
      { who: 'helene', expr: 'sourire', text: 'Deux services liés à leur adresse à Brive : la licence et le traceur. Le jour de la renumérotation, ce sont eux qui casseront en premier.' }
    ]
  });

  // ——— E05 : le journal de l'import de Julien ———
  S.add({
    id: 'E05', act: 1,
    views: {
      import: { title: 'Journal de l\'outil d\'import IMAP → Exchange (poste de Julien)', lines: [
        '2026-09-21 19:02  Connexion à imap.arveo-hebergement.fr (kerbrat-architectes.fr) : OK',
        '2026-09-21 19:02  Connexion à EXCH-DELORME : OK',
        '2026-09-21 19:03  9 boîtes à importer',
        '2026-09-21 19:41  m.kerbrat : 18 214 éléments, règles de boîte : 3',
        '2026-09-21 20:12  c.nguyen : 9 877 éléments, règles de boîte : 1',
        '2026-09-21 20:37  b.roux : 4 102 éléments, règles de boîte : 1',
        '2026-09-21 22:58  concours (boîte partagée) : 2 016 éléments',
        '2026-09-21 23:16  Import terminé : 9 boîtes, 0 erreur',
        '2026-09-21 23:17  Champ MX de kerbrat-architectes.fr : à modifier manuellement chez l\'hébergeur'
      ] }
    },
    done: [
      { who: 'helene', expr: 'sourcil', text: 'Neuf boîtes, zéro erreur, et une ligne « règles de boîte » que personne n\'a regardée. Note la date : 21 septembre.' }
    ]
  });

  // ——— E06 : le cartouche ———
  S.add({
    id: 'E06', act: 1,
    views: {
      cartouche: { title: 'Agrandissement envoyé par le membre du jury (sous l\'esquisse de l\'Atelier Varenne)', lines: [
        'KERBRAT ARCHITECTES · 14 avenue de Paris, Brive',
        'Concours · Médiathèque des Bords de Vienne',
        'Planche PL 07 · Façade sud et coupe AA',
        'Échelle 1/100',
        'Indice A · 09/09/2026 · première esquisse',
        'Indice B · 28/09/2026 · reprise de la trame',
        'Indice C · 14/10/2026 · brise-soleil, rendu jury',
        'Dessiné : C. Nguyen · Vérifié : M. Kerbrat'
      ] }
    },
    intro: [
      { who: 'narr', text: 'Jeudi 22 octobre, 17 h 30. Maëlle Kerbrat appelle Hélène. Elle ne dit pas bonjour.' },
      { who: 'maelle', expr: 'surprise', text: 'Nos plans du concours sont chez Varenne. Pas une idée qui ressemble : nos plans. Avec notre cartouche dessous.' }
    ],
    done: [
      { who: 'maelle', expr: 'sourcil', text: 'Le 14 octobre. On avait annoncé la fusion le 1er. Quelqu\'un, ou quelque chose, a fait sortir ce plan depuis.' },
      { who: 'helene', expr: 'neutre', text: 'On ne cherche pas un coupable, on cherche un chemin. Et chaque migration qu\'on fera va nous montrer un morceau du SI. Ouvre l\'œil.' }
    ]
  });

  // ——— E08 : le samedi du traceur (premier plan de bascule) ———
  // Minutes counted from Saturday 0 h.
  var SAM = 0, DIM = 1440, LUN = 2880, JEU = -2 * 1440, VEN = -1440;
  S.add({
    id: 'E08', act: 1, tool: 'plan',
    plan: {
      aim: 'Le traceur A0 reçoit son adresse du DHCP (réservation), et garde 192.168.1.50 : les postes impriment lundi matin.',
      services: { adresse: 'le traceur répond sur 192.168.1.50', impression: 'les postes de Brive impriment sur le traceur' },
      init: function (st) { st.dhcp = false; st.resa = null; st.on = false; },
      windows: [
        { id: 'jeu', label: 'Jeudi 12 h – 14 h, pendant la pause', start: JEU + 720, end: JEU + 840, problem: 'jeudi, l\'atelier imprime les planches du rendu de vendredi' },
        { id: 'ven', label: 'Vendredi 19 h – 23 h', start: VEN + 1140, end: VEN + 1380 },
        { id: 'sam-mat', label: 'Samedi 9 h – 12 h', start: SAM + 540, end: SAM + 720, problem: 'Thibault vient imprimer le samedi matin' },
        { id: 'sam', label: 'Samedi 14 h – 18 h', start: SAM + 840, end: SAM + 1080 },
        { id: 'lun', label: 'Lundi 7 h – 8 h', start: LUN + 420, end: LUN + 480, problem: 'les dessinateurs arrivent à 7 h 30 : aucune marge pour revenir en arrière' }
      ],
      ops: [
        { id: 'noter', label: 'Relever la configuration du traceur (adresse, masque, passerelle, MAC)', dur: 10, safe: true, run: function (st) { st.noted = true; } },
        { id: 'resa', label: 'Créer la réservation DHCP 192.168.1.50 pour l\'adresse MAC du traceur', dur: 10, undo: ['suppr-resa'], run: function (st, c) { st.resa = c.flags.macTypo && !c.done.noter ? 'mauvaise' : 'ok'; } },
        { id: 'allumer', label: 'Allumer le traceur et vérifier qu\'il est prêt', dur: 5, safe: true, run: function (st) { st.on = true; } },
        { id: 'dhcp', label: 'Passer le traceur en DHCP', dur: 10, undo: ['remettre-fixe'], run: function (st, c) {
          if (c.flags.off && !st.on) { st.svc.adresse = false; st.svc.impression = false; st.event = 'Le traceur ne répond pas : quelqu\'un l\'a éteint vendredi soir, le changement n\'est pas passé'; return; }
          st.dhcp = true;
          if (st.resa === 'ok' && c.flags.squat) { st.svc.impression = false; st.event = 'Un portable branché ce samedi porte 192.168.1.50 écrit à la main : le DHCP voit l\'adresse occupée et donne 192.168.1.138 au traceur. Le portable, lui, répond au ping sur la .50'; return; }
          if (st.resa !== 'ok') { st.svc.adresse = false; st.svc.impression = false; st.event = st.resa === 'mauvaise' ? 'Le traceur prend 192.168.1.137 : la réservation porte une adresse MAC mal recopiée' : 'Le traceur prend 192.168.1.137, la première adresse libre : aucune réservation ne lui garde la .50'; }
        } },
        { id: 'dnsrec', label: 'Créer l\'enregistrement DNS traceur-a0.kerbrat.lan', dur: 10, undo: ['suppr-dns'], run: function (st) { st.dns = true; } },
        { id: 'pilotes', label: 'Remplacer l\'adresse par le nom traceur-a0 dans le pilote des 9 postes', dur: 40, undo: ['pilotes-ip'], run: function (st, c) { if (!c.done.dnsrec) { st.svc.impression = false; st.event = 'Les postes cherchent « traceur-a0 », que le DNS ne connaît pas'; } } },
        { id: 'firmware', label: 'Mettre à jour le micrologiciel du traceur', dur: 60, run: function () { } },
        { id: 'tetes', label: 'Nettoyer les têtes d\'impression', dur: 20, safe: true, run: function () { } }
      ],
      checks: [
        { id: 'page', label: 'Un poste de Brive imprime une page de test', sees: ['adresse', 'impression'] },
        { id: 'ping', label: 'Le traceur répond au ping sur 192.168.1.50', sees: ['adresse'] },
        { id: 'ecran', label: 'L\'écran du traceur affiche « DHCP : activé »', sees: [] }
      ],
      backs: [
        { id: 'remettre-fixe', label: 'Remettre l\'adresse fixe relevée sur le traceur', dur: 10, needs: ['noter'] },
        { id: 'suppr-resa', label: 'Supprimer la réservation DHCP', dur: 5 },
        { id: 'suppr-dns', label: 'Supprimer l\'enregistrement DNS', dur: 5 },
        { id: 'pilotes-ip', label: 'Remettre l\'adresse dans les pilotes', dur: 40 },
        { id: 'redemarrer-dhcp', label: 'Redémarrer le serveur DHCP', dur: 5 }
      ],
      goal: function (st) { return st.dhcp ? true : 'le traceur n\'est pas passé en DHCP'; }
    },
    start: { window: '', prep: [], night: [], noReturn: null },
    ref: { window: 'sam', prep: [], night: [{ op: 'noter' }, { op: 'resa', back: 'suppr-resa' }, { op: 'allumer' }, { op: 'dhcp', check: 'page', back: 'remettre-fixe' }], noReturn: null },
    cases: [
      { id: 'nuit', label: 'la nuit prévue, sans incident : lundi, le traceur imprime', visible: true, flags: {} },
      { id: 'mac', label: 'une adresse MAC recopiée de mémoire', flags: { macTypo: true } },
      { id: 'eteint', label: 'un traceur éteint le vendredi soir', flags: { off: true } },
      { id: 'squat', label: 'un portable de passage qui a pris la .50 à la main', flags: { squat: true }, unavoidable: true }
    ],
    hints: [
      { title: 'Fiche : un plan de bascule', body: '1. La fenêtre : un moment où personne n\'a besoin du service, avec de la marge pour revenir en arrière.\n2. On relève l\'existant avant d\'y toucher (sans ça, pas de retour possible).\n3. Chaque opération qui change quelque chose a son retour arrière.\n4. Après l\'opération risquée, un contrôle qui teste ce que fait l\'utilisateur, pas ce que dit la machine.\n5. Ce qui se prévient se prévient : un retour arrière sauve la nuit, mais la bascule est à refaire.' },
      { title: 'La réponse', body: 'Fenêtre : samedi 14 h – 18 h (ou vendredi soir).\n1. Relever la configuration du traceur.\n2. Créer la réservation DHCP — retour : supprimer la réservation.\n3. Allumer le traceur et vérifier qu\'il est prêt (quelqu\'un a pu l\'éteindre vendredi soir).\n4. Passer le traceur en DHCP — contrôle : un poste imprime une page de test — retour : remettre l\'adresse fixe relevée.' }
    ],
    intro: [
      { who: 'helene', expr: 'neutre', text: 'Ton premier plan de bascule. Petit, exprès : une machine, trois opérations. Mais les règles sont celles des grands.' },
      { who: 'helene', expr: 'sourire', text: 'Quand tu « joues la nuit », le jeu la déroule heure par heure. Puis il la rejoue sur d\'autres nuits, que tu ne vois pas, où quelque chose tourne mal. Un bon plan les traverse toutes.' }
    ],
    done: [
      { who: 'thibault', expr: 'sourire', text: 'Lundi, 8 h 02, première planche imprimée. Je n\'ai rien remarqué. C\'est bon signe ?' },
      { who: 'helene', expr: 'sourire', text: 'C\'est exactement le but. Une bascule réussie, c\'est une bascule que personne ne remarque.' }
    ]
  });
})(FSSteps);
