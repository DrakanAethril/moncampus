/* Acte 3 — Deux domaines (semaines du 16 novembre au 4 décembre 2026). Résolution de noms entre les
   deux sites, redirecteurs conditionnels, TTL, approbation, comptes dormants de kerbrat.lan, et le plan
   de bascule des neuf postes de Brive vers delorme.local. */
(function (S) {
  'use strict';

  // DNS names as typed: lower case, no trailing dot, no quotes.
  function zone(s) { return String(s == null ? '' : s).trim().toLowerCase().replace(/^"+|"+$/g, '').replace(/\.+$/, ''); }
  function ip(s) { return String(s == null ? '' : s).trim().replace(/;$/, '').trim(); }

  // ——— E17 : qui résout quoi (opening scene of the act) ———
  S.add({
    id: 'E17', act: 3,
    intro: [
      { who: 'narr', text: 'Lundi 16 novembre, 9 h. Limoges, bureau de Julien. Brive a ses nouvelles adresses, et le tunnel tient depuis la nuit de la renumérotation.' },
      { who: 'julien', expr: 'sourire', text: 'Le tunnel marche ! Alors je vais chercher le dossier de la médiathèque directement à Brive. Enfin, j\'essaie.' },
      { who: 'helene', expr: 'neutre', text: 'Les réseaux se parlent. Les domaines, pas encore : chacun son DNS, chacun ses comptes. C\'est le chantier des trois semaines qui viennent.' }
    ]
  });

  // ——— E18 : les redirecteurs conditionnels (formulaire) ———
  S.add({
    id: 'E18', act: 3, tool: 'form',
    form: {
      intro: 'Un redirecteur conditionnel dit à un serveur DNS : « pour ce domaine-là, demande à ce serveur-là ». Il en faut un de chaque côté.',
      docs: [
        { title: 'Les deux sites aujourd\'hui (inventaire d\'Arpège, mis à jour après la renumérotation)', lines: [
          'Limoges  192.168.1.0/24                      SRV-DELORME 192.168.1.10 : AD DS et DNS de delorme.local',
          'Brive    postes 10.20.20.0/24, serveurs 10.20.21.0/24',
          '         dc-kerbrat 10.20.21.10 : Samba AD et DNS de kerbrat.lan',
          'Tunnel IPsec 192.168.1.0/24 <-> 10.20.20.0/23 : monté',
          'Redirecteur général de SRV-DELORME : 192.168.1.1 (box-limoges)',
          'Redirecteur général de dc-kerbrat  : 10.20.20.1 (box-brive)'
        ] },
        { title: 'Schéma de Brive de 2016, encore punaisé dans le local technique', lines: [
          'Réseau local : 192.168.1.0/24',
          'DNS : 192.168.1.10 (dc-kerbrat)'
        ] },
        { title: 'dc-kerbrat : Samba sert son DNS avec BIND 9 (mode BIND9_DLZ)', lines: [
          '# /etc/bind/named.conf.local : un redirecteur conditionnel s\'y écrit ainsi',
          'zone "<domaine>" {',
          '    type forward;',
          '    forward only;',
          '    forwarders { <adresse du DNS qui connaît ce domaine>; };',
          '};'
        ] }
      ],
      fields: [
        { id: 'lim_zone', type: 'text', mono: true, label: 'SRV-DELORME · Nouveau redirecteur conditionnel · Domaine DNS', placeholder: 'nom du domaine' },
        { id: 'lim_ip', type: 'text', mono: true, label: 'SRV-DELORME · Adresse IP du serveur maître', placeholder: 'adresse IPv4' },
        { id: 'lim_global', type: 'select', label: 'SRV-DELORME · Redirecteur général (tout le reste, Internet compris)', options: [['box', '192.168.1.1, box-limoges (inchangé)'], ['brive', '10.20.21.10, dc-kerbrat']] },
        { id: 'bri_zone', type: 'text', mono: true, label: 'dc-kerbrat · zone "…"', placeholder: 'nom du domaine' },
        { id: 'bri_type', type: 'select', label: 'dc-kerbrat · type de la zone', options: [['forward', 'forward (redirecteur conditionnel)'], ['slave', 'slave (copie de la zone de Limoges)'], ['master', 'master (zone hébergée sur dc-kerbrat)']] },
        { id: 'bri_ip', type: 'text', mono: true, label: 'dc-kerbrat · forwarders { … }', placeholder: 'adresse IPv4' },
        { id: 'bri_global', type: 'select', label: 'dc-kerbrat · redirecteur général (options { forwarders })', options: [['box', '10.20.20.1, box-brive (inchangé)'], ['limoges', '192.168.1.10, SRV-DELORME']] }
      ]
    },
    start: { lim_zone: '', lim_ip: '', lim_global: 'box', bri_zone: '', bri_type: '', bri_ip: '', bri_global: 'box' },
    ref: { lim_zone: 'kerbrat.lan', lim_ip: '10.20.21.10', lim_global: 'box', bri_zone: 'delorme.local', bri_type: 'forward', bri_ip: '192.168.1.10', bri_global: 'box' },
    cases: [
      { id: 'limoges', label: 'depuis Limoges, nslookup nas-kerbrat.kerbrat.lan répond 10.20.21.20', visible: true, check: function (a) {
        var z = zone(a.lim_zone), i = ip(a.lim_ip);
        if (z !== 'kerbrat.lan') return z ? 'SRV-DELORME redirige « ' + z + ' », mais le poste cherche un nom en kerbrat.lan' : 'aucun domaine dans le redirecteur de SRV-DELORME';
        if (i === '192.168.1.10') return '192.168.1.10, vu de Limoges, c\'est SRV-DELORME lui-même : la question tourne en rond. C\'était l\'adresse de dc-kerbrat avant la renumérotation';
        if (i !== '10.20.21.10') return i ? 'à ' + i + ', aucun serveur ne connaît kerbrat.lan' : 'aucune adresse de serveur maître';
        return true;
      } },
      { id: 'brive', label: 'depuis Brive, nslookup srv-delorme.delorme.local', check: function (a) {
        var z = zone(a.bri_zone), i = ip(a.bri_ip);
        if (z !== 'delorme.local') return z ? 'dc-kerbrat déclare la zone « ' + z + ' » : les postes de Brive cherchent delorme.local' : 'rien côté dc-kerbrat : Brive ne trouve toujours pas delorme.local, et l\'approbation en aura besoin';
        if (i === '10.20.21.10') return 'dc-kerbrat se renvoie la question à lui-même';
        if (i !== '192.168.1.10') return i ? 'à ' + i + ', aucun serveur ne connaît delorme.local' : 'aucune adresse dans forwarders';
        return true;
      } },
      { id: 'type', label: 'la zone delorme.local sur dc-kerbrat transmet les questions', check: function (a) {
        if (a.bri_type === 'master') return 'une zone master delorme.local, vide, sur dc-kerbrat : il répond lui-même « ce nom n\'existe pas » à toutes les questions sur Limoges';
        if (a.bri_type === 'slave') return 'une zone slave demande que SRV-DELORME autorise le transfert de sa zone, ce qu\'un DNS Active Directory refuse par défaut : la zone reste vide';
        return a.bri_type === 'forward' ? true : 'aucun type de zone choisi sur dc-kerbrat';
      } },
      { id: 'internet-limoges', label: 'une nuit où le tunnel tombe : Limoges navigue encore', check: function (a) {
        return a.lim_global === 'box' ? true : 'avec dc-kerbrat en redirecteur général, chaque nom d\'Internet demandé à Limoges passe par Brive : tunnel coupé, Limoges ne résout plus rien';
      } },
      { id: 'internet-brive', label: 'une nuit où le tunnel tombe : Brive navigue encore', check: function (a) {
        return a.bri_global === 'box' ? true : 'avec SRV-DELORME en redirecteur général, Brive dépend de Limoges pour tout Internet : tunnel coupé, Brive ne résout plus rien';
      } }
    ],
    hints: [
      { title: 'Fiche : le redirecteur conditionnel', body: 'Sur chaque DNS, on écrit : le domaine de l\'AUTRE site, et l\'adresse du DNS de l\'AUTRE site.\nOn ne touche pas au redirecteur général : Internet continue par la box locale.\nUne adresse se vérifie dans l\'inventaire à jour, jamais sur un vieux schéma : Brive a été renumérotée.\nUne zone « forward » transmet ; une zone « master » vide répond « n\'existe pas » à tout.' },
      { title: 'La réponse', body: 'SRV-DELORME : domaine kerbrat.lan, serveur maître 10.20.21.10 ; redirecteur général inchangé (box-limoges).\ndc-kerbrat : zone "delorme.local" { type forward; forward only; forwarders { 192.168.1.10; }; }; ; redirecteur général inchangé (box-brive).' }
    ],
    done: [
      { who: 'julien', expr: 'sourire', text: '\\\\nas-kerbrat.kerbrat.lan s\'ouvre ! Enfin… il me demande un nom d\'utilisateur et un mot de passe.' },
      { who: 'helene', expr: 'neutre', text: 'Le nom se résout. Pour le mot de passe, il faudra que les deux domaines se fassent confiance. Une chose après l\'autre.' }
    ]
  });

  // ——— E19 : le TTL ———
  S.add({
    id: 'E19', act: 3,
    views: {
      dig: { title: 'dig projets.kerbrat.lan, lancé depuis dc-kerbrat', lines: [
        '; <<>> DiG 9.18.28-1~deb12u2-Debian <<>> projets.kerbrat.lan',
        ';; QUESTION SECTION:',
        ';projets.kerbrat.lan.            IN    A',
        ';; ANSWER SECTION:',
        'projets.kerbrat.lan.      86400  IN    CNAME  nas-kerbrat.kerbrat.lan.',
        'nas-kerbrat.kerbrat.lan.  3600   IN    A      10.20.21.20',
        ';; Query time: 1 msec',
        ';; SERVER: 10.20.21.10#53(10.20.21.10) (UDP)',
        ';; WHEN: Mon Nov 23 09:12:40 CET 2026'
      ] }
    }
  });

  // ——— E20 : l'approbation (formulaire) ———
  var PREREQ_OK = ['dns', 'heure', 'ports'], PREREQ_BAD = ['mdp', 'os', 'reseau'];
  S.add({
    id: 'E20', act: 3, tool: 'form',
    form: {
      intro: 'L\'assistant « Nouvelle approbation » de SRV-DELORME, et ce qu\'on sait des deux contrôleurs.',
      docs: [
        { title: 'Note d\'Hélène', mono: false, lines: [
          'L\'approbation est provisoire : elle sert pendant la migration des comptes et des postes, puis on la supprimera avec kerbrat.lan.',
          'Les dessinateurs de Brive ouvrent les partages de Limoges, et Julien doit ouvrir \\\\nas-kerbrat\\Projets avec son compte delorme jusqu\'au week-end des partages.',
          'Chaque forêt ne contient qu\'un domaine. On ouvre le moins possible : tout le cabinet, mais rien d\'autre.'
        ] },
        { title: 'Relevés du matin', lines: [
          'PS C:\\> w32tm /stripchart /computer:dc-kerbrat.kerbrat.lan /samples:1',
          '09:41:07, d:+00.0112s o:+418.2304s',
          'PS C:\\> Resolve-DnsName -Type SRV _ldap._tcp.kerbrat.lan',
          '_ldap._tcp.kerbrat.lan   SRV  0 100 389  dc-kerbrat.kerbrat.lan',
          'root@dc-kerbrat:~# host -t SRV _ldap._tcp.delorme.local',
          '_ldap._tcp.delorme.local has SRV record 0 100 389 srv-delorme.delorme.local.',
          'pfSense, règles du tunnel : 10.20.21.10 <-> 192.168.1.10, TCP 445 seulement',
          'Samba 4.17 : approbations externes et de forêt possibles avec un domaine Windows'
        ] }
      ],
      fields: [
        { id: 'cible', type: 'text', mono: true, label: 'Nom de l\'approbation (le domaine à approuver)', placeholder: 'nom du domaine' },
        { id: 'type', type: 'select', label: 'Type d\'approbation', options: [['externe', 'Approbation externe'], ['foret', 'Approbation de forêt'], ['raccourci', 'Approbation raccourcie'], ['royaume', 'Approbation de royaume (Kerberos non Windows)']] },
        { id: 'sens', type: 'select', label: 'Sens', options: [['bi', 'Bidirectionnelle'], ['entrant', 'Unidirectionnelle : delorme.local approuve kerbrat.lan (les comptes de Brive entrent à Limoges)'], ['sortant', 'Unidirectionnelle : kerbrat.lan approuve delorme.local (les comptes de Limoges entrent à Brive)']] },
        { id: 'transit', type: 'select', label: 'Transitivité', options: [['non', 'Non transitive'], ['oui', 'Transitive']] },
        { id: 'auth', type: 'select', label: 'Niveau d\'authentification', options: [['domaine', 'Authentification à l\'échelle du domaine'], ['selective', 'Authentification sélective']] },
        { id: 'prereq', type: 'checks', label: 'À régler avant de lancer l\'assistant', options: [
          ['dns', 'Chaque domaine résout les noms de l\'autre (redirecteurs)'],
          ['heure', 'Les deux contrôleurs à l\'heure, moins de 5 minutes d\'écart'],
          ['ports', 'Le tunnel laisse passer, entre les contrôleurs, les ports de l\'AD : DNS 53, Kerberos 88, RPC 135 et sa plage, LDAP 389, SMB 445'],
          ['mdp', 'Le même mot de passe administrateur des deux côtés'],
          ['os', 'La même version de Windows Server des deux côtés'],
          ['reseau', 'Les deux contrôleurs dans le même sous-réseau']
        ] }
      ]
    },
    start: { cible: '', type: '', sens: '', transit: '', auth: '', prereq: [] },
    ref: { cible: 'kerbrat.lan', type: 'externe', sens: 'bi', transit: 'non', auth: 'domaine', prereq: PREREQ_OK.slice() },
    cases: [
      { id: 'brive-limoges', label: 'un dessinateur de Brive ouvre \\\\SRV-DELORME\\Commun', visible: true, check: function (a, H) {
        var c = zone(a.cible);
        if (c !== 'kerbrat.lan' && c !== 'kerbrat') return c ? 'l\'assistant de SRV-DELORME cherche « ' + c + ' » : c\'est kerbrat.lan qu\'on approuve' : 'aucun domaine à approuver';
        if (a.type === 'raccourci') return 'une approbation raccourcie relie deux domaines d\'une même forêt : ici, ce sont deux forêts';
        if (a.type === 'royaume') return 'une approbation de royaume relie un royaume Kerberos qui n\'est pas un domaine AD : Samba est un vrai domaine AD';
        if (a.type !== 'externe' && a.type !== 'foret') return 'aucun type d\'approbation choisi';
        if (a.sens === 'sortant') return 'dans ce sens, ce sont les comptes de Limoges qui entrent à Brive : Claire est toujours refusée sur SRV-DELORME';
        if (a.sens !== 'bi' && a.sens !== 'entrant') return 'aucun sens choisi';
        if (!H.has(a.prereq, 'dns')) return 'l\'assistant ne trouve pas le domaine kerbrat.lan : la résolution de noms est un préalable';
        return true;
      } },
      { id: 'retour', label: 'Julien ouvre \\\\nas-kerbrat\\Projets avec son compte delorme', check: function (a) {
        return a.sens === 'bi' ? true : 'dans un seul sens, Julien reste refusé à Brive jusqu\'au week-end des partages';
      } },
      { id: 'heure', label: 'dc-kerbrat avance de sept minutes', check: function (a, H) {
        return H.has(a.prereq, 'heure') ? true : 'o:+418 s : sept minutes d\'écart, Kerberos refuse les tickets. L\'approbation se crée, puis personne ne s\'authentifie';
      } },
      { id: 'ports', label: 'le tunnel ne laisse passer que le partage de fichiers', check: function (a, H) {
        return H.has(a.prereq, 'ports') ? true : 'entre les contrôleurs, le tunnel ne laisse passer que TCP 445 : ni Kerberos, ni LDAP, ni RPC. L\'assistant échoue à la vérification';
      } },
      { id: 'selective', label: 'lundi matin, les dessinateurs de Brive ouvrent les dossiers de Limoges', check: function (a) {
        return a.auth === 'domaine' ? true : 'en authentification sélective, chaque serveur de Limoges refuse les comptes de Brive tant qu\'on ne leur a pas donné « Autorisé à s\'authentifier » un par un : lundi, personne n\'ouvre rien';
      } },
      { id: 'portee', label: 'une approbation qui n\'ouvre que ces deux domaines', check: function (a) {
        return a.type === 'foret' ? 'une approbation de forêt ouvrirait aussi tout domaine ajouté plus tard à l\'une des deux forêts. Pour un lien provisoire entre deux domaines, l\'approbation externe suffit' : true;
      } },
      { id: 'transit', label: 'la transitivité cohérente avec le type', check: function (a) {
        if (a.transit === 'oui') return 'une approbation externe n\'est jamais transitive : kerbrat.lan ne doit pas ouvrir ce que delorme.local approuverait d\'autre';
        return a.transit === 'non' ? true : 'transitivité non choisie';
      } },
      { id: 'faux-prerequis', label: 'rien d\'inutile dans les préalables', check: function (a, H) {
        var bad = PREREQ_BAD.filter(function (x) { return H.has(a.prereq, x); });
        return bad.length ? 'un préalable coché n\'en est pas un : l\'approbation ne demande ni le même mot de passe, ni la même version, ni le même sous-réseau' : true;
      } }
    ],
    hints: [
      { title: 'Fiche : une approbation entre deux domaines', body: 'Sens : le domaine qui a les ressources approuve le domaine qui a les comptes. Si les deux côtés ouvrent chez l\'autre : bidirectionnelle.\nType : deux forêts d\'un seul domaine, lien provisoire → externe (jamais transitive). Une approbation de forêt ouvre toute la forêt, présente et future.\nAuthentification sélective : il faut autoriser chaque compte sur chaque serveur, un par un.\nAvant : le DNS dans les deux sens, l\'heure (Kerberos : 5 minutes d\'écart au plus), les ports de l\'AD entre les contrôleurs.' },
      { title: 'La réponse', body: 'Nom : kerbrat.lan.\nType : externe. Sens : bidirectionnelle. Non transitive.\nAuthentification à l\'échelle du domaine.\nPréalables : résolution de noms croisée, heure synchronisée, ports de l\'AD ouverts dans le tunnel entre les contrôleurs.' }
    ]
  });

  // ——— E21 : l'export des comptes de kerbrat.lan ———
  S.add({
    id: 'E21', act: 3,
    views: {
      export: { title: 'Get-ADUser -Server dc-kerbrat.kerbrat.lan -Filter * -Properties Enabled,MemberOf,Description (groupes en plus de Domain Users)', lines: [
        'SamAccountName  Enabled  Groupes             Description',
        'Administrator   True     Domain Admins       Compte intégré',
        'krbtgt          False    —                   Compte de service Kerberos',
        'm.kerbrat       True     associes            Maëlle Kerbrat, associée',
        't.lacoste       True     dessin              Thibault Lacoste, dessinateur',
        'c.nguyen        True     dessin, concours    Claire Nguyen, dessinatrice',
        'l.ferrand       True     dessin, concours    Léa Ferrand, architecte',
        'h.martin        True     dessin              Hugo Martin, dessinateur',
        'j.costa         True     dessin              Julie Costa, architecte',
        'n.blanc         True     dessin              Nicolas Blanc, dessinateur',
        'e.marchal       True     administration      Élodie Marchal, secrétariat',
        'v.leroy         True     dessin              Vincent Leroy, maquettiste',
        'b.roux          True     dessin, concours    Bastien Roux, dessinateur',
        'a.perrin        True     dessin              Anaïs Perrin, dessinatrice',
        'stagiaire2024   True     —                   Stage été 2024'
      ] }
    },
    done: [
      { who: 'maelle', expr: 'surprise', text: 'Bastien. Toujours dans le groupe concours. Il est chez Varenne depuis dix-huit mois.' },
      { who: 'helene', expr: 'neutre', text: 'Un compte actif, c\'est une porte ouverte. Ça ne dit pas que quelqu\'un l\'a franchie. On regarde les traces avant de dire quoi que ce soit.' }
    ]
  });

  // ——— E22 : son dernier passage ———
  S.add({
    id: 'E22', act: 3,
    views: {
      compte: { title: 'Get-ADUser b.roux -Server dc-kerbrat.kerbrat.lan -Properties * (extrait)', lines: [
        'DistinguishedName      : CN=Bastien Roux,CN=Users,DC=kerbrat,DC=lan',
        'Enabled                : True',
        'whenCreated            : 01/02/2016 09:02:11',
        'PasswordLastSet        : 14/01/2025 08:40:57',
        'LastBadPasswordAttempt : 11/03/2025 08:52:30',
        'BadLogonCount          : 0',
        'LastLogonDate          : 30/04/2025 17:52:08',
        'logonCount             : 2214',
        'MemberOf               : {CN=concours,CN=Users,DC=kerbrat,DC=lan, CN=dessin,CN=Users,DC=kerbrat,DC=lan}'
      ] },
      journal: { title: 'Journal d\'audit de dc-kerbrat (auth_audit), compte b.roux, synthèse par mois · conservé depuis le 01/01/2025', lines: [
        '2025-01 : 41 ouvertures réussies, 0 refusée',
        '2025-02 : 38 ouvertures réussies, 0 refusée',
        '2025-03 : 44 ouvertures réussies, 1 refusée (mot de passe erroné, 11/03 08:52, PC-BRI-04)',
        '2025-04 : 37 ouvertures réussies, 0 refusée · dernière le 30/04 à 17:52, PC-BRI-04',
        '2025-05 → 2026-11 : 0 ouverture réussie, Kerberos comme NTLM',
        '2025-05 → 2026-11 : 0 tentative refusée, aucune demande de ticket au nom de b.roux',
        'Dernier événement du journal, tous comptes : 23/11/2026 09:58 (aucune interruption depuis janvier 2025)'
      ] }
    },
    done: [
      { who: 'maelle', expr: 'neutre', text: 'Personne. Ni Bastien, ni quelqu\'un avec son mot de passe. Bon. Ce n\'est pas par là.' },
      { who: 'helene', expr: 'neutre', text: 'Un chemin de moins. Note-le dans le dossier de la fuite : c\'est le troisième indice. Et ce compte, on le désactive quand même, proprement.' }
    ]
  });

  // ——— E23 : la procédure de départ ———
  S.add({
    id: 'E23', act: 3,
    done: [
      { who: 'helene', expr: 'sourire', text: 'Trois comptes relevés, désactivés, sortis de leurs groupes. Dans 90 jours, on les supprime. Et la procédure est écrite, pour le cabinet d\'après.' }
    ]
  });

  // ——— E24 : les postes de Brive (plan de bascule) ———
  // Minutes counted from Saturday 0 h.
  var SAM = 0, DIM = 1440, LUN = 2880, MER = -3 * 1440, VEN = -1440;
  function ev(st, text) { st.event = st.event ? st.event + ' · ' + text : text; }
  S.add({
    id: 'E24', act: 3, tool: 'plan',
    plan: {
      aim: 'Les neuf postes de Brive passent de kerbrat.lan à delorme.local. Lundi à 8 h, chacun ouvre sa session delorme.local avec son mot de passe, retrouve son bureau et ses documents, et ouvre Projets.',
      services: {
        postes: 'les 9 postes de Brive sont dans delorme.local',
        session: 'les utilisateurs de Brive ouvrent leur session delorme.local',
        bureau: 'chacun retrouve son bureau et ses documents',
        projets: 'les postes ouvrent \\\\nas-kerbrat\\Projets',
        mdp: 'chacun entre avec le mot de passe qu\'il avait vendredi'
      },
      init: function (st) { st.accounts = null; st.transl = null; st.joined = false; st.awake = false; st.profilBad = false; },
      windows: [
        { id: 'mer', label: 'Mercredi 12 h – 14 h, pendant la pause', start: MER + 720, end: MER + 840, problem: 'les dessinateurs déjeunent devant leur poste, sessions ouvertes : on ne change pas le domaine d\'un poste sous les doigts de quelqu\'un' },
        { id: 'ven', label: 'Vendredi 20 h – 22 h', start: VEN + 1200, end: VEN + 1320 },
        { id: 'sam-mat', label: 'Samedi 8 h – 13 h', start: SAM + 480, end: SAM + 780, problem: 'Thibault travaille le samedi matin sur son poste' },
        { id: 'sam', label: 'Samedi 14 h – 21 h', start: SAM + 840, end: SAM + 1260 },
        { id: 'dim', label: 'Dimanche 9 h – 19 h', start: DIM + 540, end: DIM + 1140 },
        { id: 'lun', label: 'Lundi 6 h – 8 h', start: LUN + 360, end: LUN + 480, problem: 'les premiers arrivent à 7 h 30 : aucune marge pour revenir en arrière' }
      ],
      ops: [
        { id: 'comptes', label: 'Migrer les comptes des 9 utilisateurs dans delorme.local, avec l\'historique des SID et les mots de passe', dur: 480, undo: ['suppr-comptes'], run: function (st, c) {
          st.accounts = 'sid';
          // Migrated days before: whoever changes their kerbrat.lan password in between keeps the old one in delorme.local.
          if (c.phase === 'prep' && c.flags.expire) st.svc.mdp = false;
        } },
        { id: 'comptes-neufs', label: 'Créer 9 comptes neufs dans delorme.local, aux mêmes noms', dur: 45, undo: ['suppr-comptes'], run: function (st) { if (!st.accounts) st.accounts = 'neufs'; } },
        { id: 'sauver', label: 'Sauvegarder les profils des 9 postes sur nas-kerbrat', dur: 90, safe: true, run: function (st) { st.saved = true; } },
        { id: 'relever', label: 'Relever le mot de passe administrateur local de chaque poste', dur: 20, safe: true, run: function () { } },
        { id: 'reveil', label: 'Réveiller les 9 postes à distance (Wake-on-LAN) et vérifier qu\'ils répondent', dur: 15, safe: true, run: function (st, c) { if (c.phase === 'night') st.awake = true; } },
        { id: 'resync', label: 'Recopier les mots de passe actuels de kerbrat.lan vers les comptes migrés', dur: 15, safe: true, run: function (st, c) {
          if (c.phase === 'night' && st.accounts === 'sid') st.svc.mdp = true;
        } },
        { id: 'dns-postes', label: 'Mettre SRV-DELORME (192.168.1.10) comme DNS des postes de Brive', dur: 20, undo: ['dns-retour'], run: function () { } },
        { id: 'traduire', label: 'Traduire les profils locaux des 9 postes (ajouter les SID de delorme.local)', dur: 60, undo: ['restaurer'], run: function (st, c) {
          if (!st.accounts) { st.transl = 'none'; ev(st, 'L\'outil ne trouve aucun compte cible dans delorme.local : aucun profil n\'est traduit'); return; }
          st.transl = 'ok';
          if (c.flags.off && !st.awake) ev(st, 'PC-BRI-05 ne répond pas : éteint depuis vendredi soir, son profil n\'est pas traduit');
          if (c.flags.profil) { st.profilBad = true; ev(st, 'Sur 4 postes, l\'antivirus verrouille NTUSER.DAT pendant la traduction : ces profils ne sont traduits qu\'à moitié'); }
        } },
        { id: 'joindre', label: 'Changer le domaine des 9 postes pour delorme.local et les redémarrer', dur: 45, undo: ['rejoindre'], run: function (st, c) {
          st.joined = true;
          if (c.flags.tunnel) { st.svc.session = false; st.svc.projets = false; ev(st, 'La fibre de Brive coupe : le tunnel tombe pendant le changement de domaine. Les postes ne joignent aucun contrôleur de delorme.local et redémarrent sans session possible'); return; }
          if (c.flags.off && !st.awake) { st.svc.postes = false; ev(st, 'PC-BRI-05, éteint, n\'a pas changé de domaine : il est resté dans kerbrat.lan'); }
          if (!st.accounts) { st.svc.session = false; ev(st, 'Aucun compte des utilisateurs de Brive n\'existe dans delorme.local : personne ne peut ouvrir de session'); return; }
          if (st.transl !== 'ok') { st.svc.bureau = false; ev(st, 'Claire ouvre une session sur un bureau vide : son profil appartient encore à son seul compte kerbrat'); }
          else if (st.profilBad) { st.svc.bureau = false; ev(st, 'Sur les 4 postes à moitié traduits, Windows charge un profil temporaire : bureau vide, documents introuvables'); }
          if (st.accounts === 'neufs') { st.svc.projets = false; ev(st, 'Projets refuse l\'accès : les droits sont donnés aux SID de kerbrat.lan, que les comptes neufs ne portent pas'); }
        } },
        { id: 'desactiver', label: 'Désactiver les comptes kerbrat.lan des 9 utilisateurs', dur: 10, undo: ['reactiver'], run: function (st) {
          if (!st.joined) { st.svc.session = false; ev(st, 'Les postes sont encore dans kerbrat.lan : leurs utilisateurs ne peuvent plus ouvrir de session'); }
        } },
        { id: 'purger', label: 'Supprimer les anciens profils kerbrat.lan des postes, pour libérer le disque', dur: 30, irreversible: true, run: function (st) {
          if (st.transl !== 'ok' || st.profilBad || !st.joined) st.lost.push('les profils des postes de Brive (bureaux, documents)');
        } }
      ],
      checks: [
        { id: 'user', label: 'Claire ouvre sa session delorme.local sur son poste : son bureau, ses documents, Projets', sees: ['session', 'bureau', 'projets'] },
        { id: 'admin', label: 'L\'administrateur de delorme.local ouvre une session sur PC-BRI-02', sees: ['session'] },
        { id: 'annuaire', label: 'Les 9 postes apparaissent dans delorme.local (Ordinateurs)', sees: ['postes'] },
        { id: 'outil', label: 'L\'outil de migration affiche « Terminé, 0 erreur »', sees: [] }
      ],
      backs: [
        { id: 'restaurer', label: 'Restaurer les profils depuis la sauvegarde', dur: 45, needs: ['sauver'] },
        { id: 'rejoindre', label: 'Remettre les postes dans kerbrat.lan (session administrateur local)', dur: 40, needs: ['relever'] },
        { id: 'suppr-comptes', label: 'Supprimer les comptes créés dans delorme.local', dur: 15 },
        { id: 'dns-retour', label: 'Remettre dc-kerbrat comme DNS des postes', dur: 20 },
        { id: 'reactiver', label: 'Réactiver les comptes kerbrat.lan', dur: 10 }
      ],
      goal: function (st) {
        if (!st.accounts) return 'aucun compte n\'a été migré dans delorme.local';
        return st.joined ? true : 'les postes n\'ont pas changé de domaine';
      }
    },
    start: { window: '', prep: [], night: [], noReturn: null },
    ref: {
      window: 'sam', prep: ['comptes', 'sauver', 'relever'],
      night: [{ op: 'reveil' }, { op: 'resync' }, { op: 'traduire', back: 'restaurer' }, { op: 'joindre', check: 'user', back: 'rejoindre' }],
      noReturn: null
    },
    cases: [
      { id: 'nuit', label: 'la nuit prévue : lundi, chacun ouvre sa session delorme.local et retrouve son bureau', visible: true, flags: {} },
      { id: 'eteint', label: 'un poste éteint le vendredi soir', flags: { off: true } },
      { id: 'expire', label: 'un mot de passe expiré, et changé, dans la semaine', flags: { expire: true } },
      { id: 'profil', label: 'des profils locaux qui ne se laissent pas traduire', flags: { profil: true }, unavoidable: true },
      { id: 'tunnel', label: 'le tunnel qui tombe pendant la nuit', flags: { tunnel: true }, unavoidable: true }
    ],
    hints: [
      { title: 'Fiche : changer des postes de domaine', body: 'Les jours d\'avant : migrer les comptes (avec l\'historique des SID), sauvegarder les profils, relever ce qu\'il faudra pour revenir en arrière.\nLa nuit : s\'assurer que tous les postes sont allumés, rafraîchir ce qui a pu changer depuis la migration des comptes, traduire les profils, puis seulement changer de domaine.\nLe contrôle : un vrai utilisateur, devant son poste, avec son bureau et ses dossiers. Pas la machine, pas l\'administrateur.\nChaque retour arrière demande quelque chose fait avant : vérifie-le.' },
      { title: 'La réponse', body: 'Les jours d\'avant : migrer les comptes avec l\'historique des SID et les mots de passe ; sauvegarder les profils ; relever le mot de passe administrateur local de chaque poste.\nFenêtre : samedi 14 h – 21 h (ou dimanche).\n1. Réveiller les 9 postes (Wake-on-LAN).\n2. Recopier les mots de passe actuels.\n3. Traduire les profils — retour : restaurer les profils.\n4. Changer le domaine et redémarrer — contrôle : Claire ouvre sa session et retrouve tout — retour : remettre les postes dans kerbrat.lan.' }
    ],
    intro: [
      { who: 'helene', expr: 'neutre', text: 'Le gros morceau de l\'acte. Neuf postes, neuf personnes, et autant de bureaux à ne pas perdre.' },
      { who: 'helene', expr: 'sourcil', text: 'Ce qui prend des heures se fait les jours d\'avant. La nuit, on ne fait que ce qui ne peut pas attendre, et on vérifie avec quelqu\'un d\'assis devant l\'écran.' }
    ],
    done: [
      { who: 'maelle', expr: 'sourire', text: 'Claire est arrivée à 8 h, a ouvert sa session, et a râlé parce que rien n\'avait changé. Je crois que c\'est un compliment.' },
      { who: 'helene', expr: 'sourire', text: 'Un seul domaine pour tout le monde. dc-kerbrat reste en sommeil quelques semaines, au cas où. Puis on l\'éteindra, avec un plan.' }
    ]
  });
})(FSSteps);
