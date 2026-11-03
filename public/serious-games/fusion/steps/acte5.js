/* Acte 5 — Le courrier (semaines du 21 et du 28 décembre 2026). Les enregistrements publics de
   kerbrat-architectes.fr, le SPF, DKIM et DMARC, le suivi du mail du 14 octobre, la règle de Bastien,
   les preuves, le plan de la messagerie, et la dernière nuit. */
(function (S) {
  'use strict';

  // ——— E33 : les enregistrements publics ———
  S.add({
    id: 'E33', act: 5,
    views: {
      dig: { title: 'Ce que voit le reste du monde : dig depuis le portable, lundi 21 décembre', lines: [
        '$ dig +noall +answer kerbrat-architectes.fr MX',
        'kerbrat-architectes.fr.       86400  IN  MX   10 mail.delorme-architectes.fr.',
        'kerbrat-architectes.fr.       86400  IN  MX   20 mx2.arveo-hebergement.fr.',
        '$ dig +noall +answer kerbrat-architectes.fr TXT',
        'kerbrat-architectes.fr.       86400  IN  TXT  "v=spf1 include:_spf.arveo-hebergement.fr -all"',
        '$ dig +noall +answer _dmarc.kerbrat-architectes.fr TXT',
        '(aucune réponse : pas d\'enregistrement DMARC)',
        '$ dig +noall +answer mail.delorme-architectes.fr A',
        'mail.delorme-architectes.fr.  3600   IN  A    203.0.113.10',
        '$ dig +noall +answer www.kerbrat-architectes.fr A',
        'www.kerbrat-architectes.fr.   86400  IN  A    198.51.100.24',
        '— Le refus reçu de la mairie de Brive, 28/09/2026 —',
        '550 5.7.23 Message rejected: 203.0.113.10 is not permitted to send for kerbrat-architectes.fr'
      ] }
    },
    intro: [
      { who: 'narr', text: 'Lundi 21 décembre, 8 h 30. Arpège Informatique, Limoges. Les partages sont migrés, les droits vérifiés. Il reste dix jours, et la messagerie.' },
      { who: 'helene', expr: 'neutre', text: 'Julien croit la messagerie réglée depuis septembre. Maëlle reçoit des plaintes de clients. Avant de toucher quoi que ce soit, on regarde ce que voit le reste du monde : les enregistrements publics.' },
      { who: 'helene', expr: 'sourcil', text: 'Et garde en tête que c\'est la seule migration qu\'on n\'a pas faite nous-mêmes.' }
    ],
    done: [
      { who: 'helene', expr: 'neutre', text: 'Le courrier arrive chez Exchange, mais le SPF dit au monde que seul Arvéo a le droit d\'écrire pour Kerbrat. Et Arvéo garde un MX de secours. Deux restes de septembre.' }
    ]
  });

  // ——— E34 : SPF, DKIM, DMARC (formulaire) ———
  // SPF lookups: each include/a/mx/ptr/exists/redirect costs one, an include also what it includes.
  var NESTED = { '_spf.arveo-hebergement.fr': 4, 'spf.protection.outlook.com': 1, '_spf.google.com': 3, 'delorme-architectes.fr': 2 };
  function terms(a, H) { return H.low(a.spf).replace(/^"+|"+$/g, '').split(' ').filter(Boolean); }
  function authorizesExchange(t) {
    return t.some(function (x) { x = x.replace(/^\+/, ''); return x === 'ip4:203.0.113.10' || x === 'ip4:203.0.113.10/32' || x === 'mx' || x === 'mx:kerbrat-architectes.fr' || x === 'a:mail.delorme-architectes.fr' || x === 'include:delorme-architectes.fr'; });
  }
  function lookups(t) {
    return t.reduce(function (n, x) {
      x = x.replace(/^[+~?-]/, '');
      var m = /^include:(.+)$/.exec(x);
      if (m) return n + 1 + (NESTED[m[1]] || 0);
      return n + (/^(a|mx|ptr|exists|redirect)([:=/]|$)/.test(x) ? 1 : 0);
    }, 0);
  }
  function dmarcTags(a, H) {
    var tags = {};
    H.low(a.dmarc).replace(/^"+|"+$/g, '').replace(/\s+/g, '').split(';').forEach(function (p) { var i = p.indexOf('='); if (i > 0) tags[p.slice(0, i)] = p.slice(i + 1); });
    return tags;
  }
  S.add({
    id: 'E34', act: 5, tool: 'form',
    form: {
      intro: 'Trois enregistrements à écrire pour kerbrat-architectes.fr. Le SPF dit quels serveurs ont le droit d\'envoyer au nom du domaine, DKIM publie la clé qui vérifie la signature des mails, DMARC dit au destinataire quoi faire d\'un mail qui échoue, et à qui en rendre compte.',
      docs: [
        { title: 'La zone publique aujourd\'hui (dig)', lines: [
          'kerbrat-architectes.fr.       MX   10 mail.delorme-architectes.fr.',
          'kerbrat-architectes.fr.       MX   20 mx2.arveo-hebergement.fr.',
          'kerbrat-architectes.fr.       TXT  "v=spf1 include:_spf.arveo-hebergement.fr -all"',
          'mail.delorme-architectes.fr.  A    203.0.113.10   (Exchange, EXCH-DELORME)'
        ] },
        { title: 'L\'en-tête d\'un mail de Claire, classé en indésirables chez un client (Gmail)', lines: [
          'Authentication-Results: mx.google.com;',
          '   spf=fail (google.com: domain of c.nguyen@kerbrat-architectes.fr does not designate 203.0.113.10 as permitted sender)',
          '   dkim=none (message not signed);',
          '   dmarc=none'
        ] },
        { title: 'L\'agent de signature DKIM d\'Exchange (installé par Hélène le 18 décembre)', lines: [
          'Domaine signé      : kerbrat-architectes.fr',
          'Sélecteur          : exch2026',
          'Clé publique       : v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAu3x…IDAQAB',
          'Le destinataire cherche la clé à l\'adresse <sélecteur>._domainkey.<domaine>.'
        ] },
        { title: 'Notes d\'Hélène', mono: false, lines: [
          'Un seul SPF par domaine : on corrige celui qui existe.',
          'Au plus 10 requêtes DNS pour évaluer un SPF : include, a, mx, exists et redirect en coûtent une chacun, plus ce qu\'ils incluent. _spf.arveo-hebergement.fr en coûte 5 à lui seul.',
          'Arvéo reste en service jusqu\'à la bascule de fin décembre : on peut le garder dans le SPF d\'ici là.',
          'DMARC : on commence par observer (p=none) ou mettre de côté (p=quarantine). Jamais p=reject le premier jour. Les rapports (rua) iront à dmarc@kerbrat-architectes.fr.'
        ] }
      ],
      fields: [
        { id: 'spf', type: 'text', mono: true, label: 'SPF : enregistrement TXT de kerbrat-architectes.fr', placeholder: 'v=spf1 … -all' },
        { id: 'place', type: 'select', label: 'Où publier ce SPF ?', options: [['remplacer', 'À la place de l\'enregistrement SPF existant'], ['ajouter', 'Dans un second enregistrement TXT v=spf1, à côté de l\'ancien'], ['mx', 'Dans l\'enregistrement MX']] },
        { id: 'dkimName', type: 'text', mono: true, label: 'DKIM : nom de l\'enregistrement à créer dans kerbrat-architectes.fr', placeholder: '…._domainkey' },
        { id: 'dkimType', type: 'select', label: 'DKIM : type de l\'enregistrement', options: [['TXT', 'TXT'], ['MX', 'MX'], ['A', 'A'], ['SRV', 'SRV']] },
        { id: 'dmarc', type: 'text', mono: true, label: 'DMARC : enregistrement TXT _dmarc.kerbrat-architectes.fr', placeholder: 'v=DMARC1; p=…; rua=mailto:…' }
      ]
    },
    start: { spf: 'v=spf1 include:_spf.arveo-hebergement.fr -all', place: '', dkimName: '', dkimType: '', dmarc: '' },
    ref: { spf: 'v=spf1 ip4:203.0.113.10 include:_spf.arveo-hebergement.fr -all', place: 'remplacer', dkimName: 'exch2026._domainkey', dkimType: 'TXT', dmarc: 'v=DMARC1; p=none; rua=mailto:dmarc@kerbrat-architectes.fr' },
    cases: [
      { id: 'exchange', label: 'un mail de Brive envoyé par Exchange passe le SPF', visible: true, check: function (a, H) {
        var t = terms(a, H);
        if (t[0] !== 'v=spf1') return 'un SPF commence par « v=spf1 »';
        if (t.indexOf('+all') !== -1 || t.indexOf('all') !== -1) return '« +all » autorise n\'importe quel serveur au monde à écrire au nom de Kerbrat';
        return authorizesExchange(t) ? true : 'Exchange (203.0.113.10) n\'est toujours pas autorisé : le destinataire voit encore spf=fail';
      } },
      { id: 'fin', label: 'le SPF dit quoi faire des autres serveurs', check: function (a, H) {
        var last = terms(a, H).slice(-1)[0];
        if (last === '-all' || last === '~all') return true;
        if (last === '?all') return '« ?all » est neutre : un faussaire passe aussi bien qu\'Exchange';
        return 'le SPF doit se terminer par « -all » ou « ~all », sinon il ne refuse personne';
      } },
      { id: 'unique', label: 'un seul SPF pour le domaine', check: function (a) {
        if (a.place === 'remplacer') return true;
        return a.place === 'ajouter' ? 'deux enregistrements v=spf1 : le destinataire rend une erreur (permerror) et le contrôle échoue' : 'un SPF est un enregistrement TXT, à la place de l\'ancien';
      } },
      { id: 'include', label: 'chaque include vise un domaine qui publie un SPF', check: function (a, H) {
        var bad = terms(a, H).map(function (x) { return /^[+~?-]?include:(.+)$/.exec(x); }).filter(function (m) { return m && NESTED[m[1]] === undefined; })[0];
        return bad ? '« include:' + bad[1] + ' » va lire un SPF qui n\'existe pas (permerror). Pour une machine, on écrit ip4: ou a:' : true;
      } },
      { id: 'lookups', label: 'au plus 10 requêtes DNS', check: function (a, H) { var n = lookups(terms(a, H)); return n <= 10 ? true : 'ce SPF demande ' + n + ' requêtes DNS : au-delà de 10, le destinataire abandonne (permerror)'; } },
      { id: 'dkim', label: 'la clé DKIM est publiée où le destinataire la cherche', check: function (a, H) {
        var n = H.low(a.dkimName).replace(/\.$/, '').replace(/\.kerbrat-architectes\.fr$/, '');
        if (n !== 'exch2026._domainkey') return 'le destinataire cherche la clé à exch2026._domainkey.kerbrat-architectes.fr (sélecteur, puis _domainkey)';
        return a.dkimType === 'TXT' ? true : 'une clé DKIM se publie dans un enregistrement TXT';
      } },
      { id: 'dmarc-p', label: 'une politique DMARC prudente', check: function (a, H) {
        var t = dmarcTags(a, H);
        if (t.v !== 'dmarc1') return 'un enregistrement DMARC commence par « v=DMARC1; »';
        if (t.p === 'reject') return 'p=reject le premier jour : le moindre expéditeur oublié voit tous ses mails refusés. On commence par none ou quarantine';
        return t.p === 'none' || t.p === 'quarantine' ? true : 'il manque la politique : p=none ou p=quarantine';
      } },
      { id: 'dmarc-rua', label: 'les rapports DMARC arrivent quelque part', check: function (a, H) { var t = dmarcTags(a, H); return /^mailto:[^@]+@[^@]+\.[a-z]+$/.test(t.rua || '') ? true : 'sans « rua=mailto:… », personne ne reçoit les rapports : on ne saura jamais quel expéditeur échoue'; } }
    ],
    hints: [
      { title: 'Fiche : SPF, DKIM, DMARC', body: 'SPF (TXT du domaine) : la liste des serveurs qui envoient pour le domaine. v=spf1, puis des mécanismes (ip4:, a:, mx, include: vers un autre SPF), puis -all ou ~all. Un seul SPF, 10 requêtes au plus.\nDKIM (TXT <sélecteur>._domainkey) : la clé publique qui vérifie la signature posée par le serveur.\nDMARC (TXT _dmarc) : v=DMARC1; p=none|quarantine|reject; rua=mailto:… pour les rapports. On commence par observer.' },
      { title: 'La réponse', body: 'SPF, à la place de l\'ancien :\nv=spf1 ip4:203.0.113.10 include:_spf.arveo-hebergement.fr -all\nDKIM : TXT exch2026._domainkey, avec la clé de l\'agent.\nDMARC : v=DMARC1; p=none; rua=mailto:dmarc@kerbrat-architectes.fr' }
    ],
    done: [
      { who: 'julien', expr: 'sourire', text: 'La mairie de Brive vient de répondre à Claire. Dans sa boîte de réception, pas dans ses indésirables. Bon. Il y avait donc des choses à faire, en septembre.' }
    ]
  });

  // ——— E35 : le suivi des messages ———
  S.add({
    id: 'E35', act: 5,
    views: {
      trace: { title: 'Suivi des messages sur EXCH-DELORME (journaux conservés 120 jours)', lines: [
        '[PS] EXCH-DELORME> Get-MessageTrackingLog -Start "14/10/2026 16:40" -End "14/10/2026 16:45" -MessageSubject "PL07" | Sort Timestamp',
        'Objet : PL07 indice C - brise-soleil - rendu jury · pièce jointe : PL07-indiceC.pdf (8,4 Mo)',
        'Timestamp            EventId   Source        Sender                           Recipients',
        '14/10/2026 16:42:06  RECEIVE   STOREDRIVER   c.nguyen@kerbrat-architectes.fr  concours@kerbrat-architectes.fr',
        '14/10/2026 16:42:06  EXPAND    ROUTING       c.nguyen@kerbrat-architectes.fr  m.kerbrat, c.nguyen, t.lacoste, l.ferrand, b.roux',
        '14/10/2026 16:42:07  DELIVER   STOREDRIVER   c.nguyen@kerbrat-architectes.fr  m.kerbrat@kerbrat-architectes.fr',
        '14/10/2026 16:42:07  DELIVER   STOREDRIVER   c.nguyen@kerbrat-architectes.fr  c.nguyen@kerbrat-architectes.fr',
        '14/10/2026 16:42:07  DELIVER   STOREDRIVER   c.nguyen@kerbrat-architectes.fr  t.lacoste@kerbrat-architectes.fr',
        '14/10/2026 16:42:07  DELIVER   STOREDRIVER   c.nguyen@kerbrat-architectes.fr  l.ferrand@kerbrat-architectes.fr',
        '14/10/2026 16:42:07  DELIVER   STOREDRIVER   c.nguyen@kerbrat-architectes.fr  b.roux@kerbrat-architectes.fr',
        '14/10/2026 16:42:09  REDIRECT  MAILBOXRULE   b.roux@kerbrat-architectes.fr    bastien.roux.archi@gmail.com',
        '14/10/2026 16:42:11  SEND      SMTP          b.roux@kerbrat-architectes.fr    1 destinataire, connecteur « Internet », 250 2.0.0 OK gmail-smtp-in.l.google.com'
      ] },
      history: { title: 'Toutes les copies vers l\'extérieur depuis la boîte b.roux, jour par jour', lines: [
        '[PS] EXCH-DELORME> Get-MessageTrackingLog -EventId REDIRECT -Sender b.roux@kerbrat-architectes.fr -Start "01/09/2026" | Group-Object {$_.Timestamp.ToString("yyyy-MM-dd")}',
        'Jour         Copies   Première copie du jour',
        '2026-09-21   1        23:24  « Test après import, ça marche ! » (de j.delorme)',
        '2026-09-22   4        08:51  « Planning concours, semaine 39 »',
        '2026-09-28   3        10:12  « PL07 indice B - reprise de la trame »',
        '2026-10-02   2        14:30  « Compte rendu réunion jury blanc »',
        '2026-10-14   2        16:42  « PL07 indice C - brise-soleil - rendu jury »',
        '2026-11-09   1        09:05  « Résultats : premier tour »',
        '2026-12-18   1        17:20  « Avis du jury attendu le 24 »',
        'Total : 61 copies vers bastien.roux.archi@gmail.com, aucune avant le 21/09/2026'
      ] }
    },
    done: [
      { who: 'maelle', expr: 'surprise', text: 'Soixante et une copies. Chaque mail du concours, depuis trois mois. Le planning, les comptes rendus, les trois indices. Chez Bastien.' },
      { who: 'julien', expr: 'surprise', text: 'Le 21 septembre, 23 h 24. C\'est mon mail de test. Le soir de l\'import.' },
      { who: 'helene', expr: 'neutre', text: 'Personne n\'a cliqué sur « transférer ». C\'est une règle, dans sa boîte. Allons la lire, et ne touchons à rien.' }
    ]
  });

  // ——— E36 : la règle ———
  S.add({
    id: 'E36', act: 5,
    views: {
      regle: { title: 'Les règles de la boîte b.roux, et ce qu\'en disait Arvéo', lines: [
        '[PS] EXCH-DELORME> Get-InboxRule -Mailbox b.roux | Format-List',
        'Name                : Suivi concours pendant congé',
        'Enabled             : True',
        'Priority            : 1',
        'SentTo              : {concours}',
        'ForwardTo           : {"bastien.roux.archi@gmail.com" [SMTP:bastien.roux.archi@gmail.com]}',
        'RedirectTo          :',
        'StopProcessingRules : False',
        'Description         : Si le message a été envoyé à « concours », le transférer à bastien.roux.archi@gmail.com',
        '— Export des filtres de l\'ancienne boîte, fourni par Arvéo (Sieve) —',
        '# Règle « Suivi concours pendant congé », créée le 08/07/2024 par b.roux (webmail)',
        'if address :is "to" "concours@kerbrat-architectes.fr" { redirect :copy "bastien.roux.archi@gmail.com"; }',
        '# Arvéo, offre Pro Mail : redirection vers un domaine extérieur refusée. Règle conservée, sans effet.',
        '— Journal de l\'import de Julien —',
        '2026-09-21 20:37  b.roux : 4 102 éléments, règles de boîte : 1'
      ] }
    },
    done: [
      { who: 'helene', expr: 'neutre', text: 'Juillet 2024 : Bastien part en congé et veut suivre le concours de chez lui. Il pose une règle. Arvéo la refuse en silence, et il l\'oublie.' },
      { who: 'helene', expr: 'sourcil', text: 'Vingt-six mois plus tard, l\'import la recopie, ligne pour ligne. Le journal le disait : « règles de boîte : 1 ». Personne n\'a lu ce chiffre.' }
    ]
  });

  // ——— E37 : pourquoi depuis septembre ———
  S.add({
    id: 'E37', act: 5,
    views: {
      exchange: { title: 'Ce que dit Exchange, lundi 21 décembre', lines: [
        '[PS] EXCH-DELORME> Get-RemoteDomain | Format-List',
        'Name                  : Default',
        'DomainName            : *',
        'AllowedOOFType        : External',
        'AutoReplyEnabled      : True',
        'AutoForwardEnabled    : True',
        'DeliveryReportEnabled : True',
        '[PS] EXCH-DELORME> Get-ADUser b.roux -Properties whenCreated,LastLogonDate,Description',
        'DistinguishedName : CN=Bastien Roux,OU=Boites-Kerbrat,DC=delorme,DC=local',
        'Enabled           : True',
        'whenCreated       : 21/09/2026 20:37:12',
        'LastLogonDate     :',
        'Description       : Créé par l\'outil d\'import (boîte kerbrat-architectes.fr)',
        '[PS] EXCH-DELORME> Get-MailboxStatistics b.roux | Format-List DisplayName,ItemCount,LastLogonTime',
        'DisplayName   : Bastien Roux',
        'ItemCount     : 4 163',
        'LastLogonTime :'
      ] }
    },
    done: [
      { who: 'julien', expr: 'sourcil', text: 'Un compte créé par mon outil, jamais ouvert. Une règle de 2024. Un réglage par défaut. Et moi qui disais « ça marche, non ? ».' },
      { who: 'maelle', expr: 'neutre', text: 'Ce n\'est pas un pirate. C\'est un oubli qu\'on a déménagé avec le reste. Ça ne me console pas, mais ça change ce que je vais faire.' }
    ]
  });

  // ——— E38 : les preuves d'abord ———
  S.add({
    id: 'E38', act: 5,
    done: [
      { who: 'helene', expr: 'neutre', text: 'Le suivi exporté, la règle capturée, les empreintes notées à 11 h 07, la note signée. Ensuite seulement, la règle désactivée, et les transferts vers l\'extérieur bloqués pour tout le monde.' },
      { who: 'maelle', expr: 'sourcil', text: 'Le dossier est dans mon coffre. Ce soir, je relis tout. Demain, je décide qui j\'appelle.' }
    ]
  });

  // ——— E39 : le plan de la messagerie ———
  // Minutes counted from Saturday 26 December 2026, 0 h.
  var SAM = 0, DIM = 1440, LUN = 2 * 1440, MAR = -4 * 1440, JEU = -2 * 1440;
  // The final synchronisation compares everything when no catch-up ran during the week: its duration
  // depends on the night, which the simulator reads from op.dur right after run().
  var sync = { slow: false };
  function fast() { sync.slow = false; }
  S.add({
    id: 'E39', act: 5, tool: 'plan',
    plan: {
      aim: 'Plus rien ne dépend d\'Arvéo : le courrier qui y dormait est dans Exchange, son MX de secours est retiré, les règles de toutes les boîtes sont vérifiées, les transferts vers l\'extérieur sont bloqués, le SPF ne cite plus qu\'Exchange, et Arvéo est coupé. Lundi 28, Brive reçoit et envoie.',
      services: { recoit: 'le courrier envoyé à @kerbrat-architectes.fr arrive dans Exchange', envoie: 'les mails de Brive arrivent chez les destinataires extérieurs', boites: 'chaque boîte de Brive a tout son courrier dans Exchange', mobile: 'les téléphones de Brive ouvrent le courrier et les archives' },
      init: function (st, flags) { fast(); st.mx20 = true; st.ttlLow = false; st.presync = false; st.frozen = false; st.pending = flags.queued ? 214 : 0; st.rules = false; st.rulesOne = false; st.blocked = false; st.spfFinal = false; st.cut = false; },
      windows: [
        { id: 'mar', label: 'Mardi 22 décembre, 12 h – 13 h 30', start: MAR + 720, end: MAR + 810 },
        { id: 'jeu', label: 'Jeudi 24 décembre, 18 h – 23 h', start: JEU + 1080, end: JEU + 1380, problem: 'le jury rend son avis le 24 au soir, par mail, à concours@ : Maëlle ne veut aucune coupure ce soir-là' },
        { id: 'sam', label: 'Samedi 26 décembre, 9 h – 13 h', start: SAM + 540, end: SAM + 780 },
        { id: 'dim', label: 'Dimanche 27 décembre, 14 h – 18 h', start: DIM + 840, end: DIM + 1080 },
        { id: 'lun', label: 'Lundi 28 décembre, 7 h – 9 h', start: LUN + 420, end: LUN + 540, problem: 'le cabinet rouvre le 28 à 9 h, et Sandrine envoie les factures de fin d\'année ce matin-là' }
      ],
      ops: [
        { id: 'ttl', label: 'Abaisser à 300 s le TTL des enregistrements MX et SPF de kerbrat-architectes.fr', dur: 5, safe: true, run: function (st, c) { fast(); if (c.phase === 'prep') st.ttlLow = true; } },
        { id: 'presync', label: 'Lancer une synchronisation de rattrapage de toutes les boîtes, d\'Arvéo vers Exchange', dur: 240, safe: true, run: function (st) { fast(); st.presync = true; } },
        { id: 'gel', label: 'Geler la réception chez Arvéo : tout nouveau mail est refusé pour l\'instant (code 4xx), l\'expéditeur réessaie sur Exchange', dur: 10, undo: ['degel'], run: function (st) { fast(); st.frozen = true; } },
        { id: 'mx', label: 'Retirer le MX de secours d\'Arvéo (MX 20)', dur: 10, undo: ['remettre-mx'], run: function (st) { fast(); st.mx20 = false; } },
        { id: 'delta', label: 'Dernière synchronisation des boîtes d\'Arvéo vers Exchange (delta)', safe: true,
          get dur() { return sync.slow ? 330 : 40; },
          run: function (st, c) {
            sync.slow = !!c.flags.big && !st.presync;
            if (sync.slow) st.event = 'La boîte de Thibault fait 48 Go chez Arvéo, dix ans de plans en pièces jointes. Sans rattrapage dans la semaine, la synchronisation doit tout comparer : 5 h 30 au lieu de 40 minutes';
            st.pending = 0;
            if (c.flags.queued && !st.frozen) { st.pending = 9; st.svc.boites = false; st.event = '9 mails arrivent chez Arvéo par le MX de secours pendant la synchronisation : Arvéo n\'était pas gelé, ils n\'ont pas été copiés'; }
          } },
        { id: 'regles-broux', label: 'Supprimer la règle de la boîte b.roux', dur: 5, safe: true, run: function (st) { fast(); st.rulesOne = true; } },
        { id: 'regles', label: 'Inventorier les règles de toutes les boîtes (Get-InboxRule) et supprimer chaque transfert vers l\'extérieur', dur: 30, safe: true, run: function (st) { fast(); st.rules = true; } },
        { id: 'bloquer', label: 'Bloquer le transfert automatique vers l\'extérieur (Set-RemoteDomain Default -AutoForwardEnabled $false)', dur: 5, safe: true, run: function (st) { fast(); st.blocked = true; } },
        { id: 'spf', label: 'Publier le SPF définitif : v=spf1 ip4:203.0.113.10 -all (Arvéo retiré)', dur: 10, undo: ['spf-retour'], run: function (st, c) {
          fast(); st.spfFinal = true;
          if (c.flags.spfStrict) { st.svc.envoie = false; st.event = 'Le logiciel de facturation de Kerbrat, en ligne, envoie les factures au nom de compta@kerbrat-architectes.fr depuis ses propres serveurs. Le SPF définitif ne le cite pas : les clients les refusent'; }
        } },
        { id: 'dmarc-reject', label: 'Passer DMARC en p=reject dès ce soir', dur: 5, run: function (st, c) { fast(); if (c.flags.spfStrict) { st.svc.envoie = false; st.event = 'Avec p=reject, chaque mail qui échoue au SPF est refusé sans appel'; } } },
        { id: 'accepte', label: 'Retirer kerbrat-architectes.fr des domaines acceptés d\'Exchange', dur: 5, run: function (st) { fast(); st.svc.recoit = false; st.event = 'Exchange ne se croit plus responsable de kerbrat-architectes.fr : il refuse le courrier de Brive (« 550 relais interdit »)'; } },
        { id: 'suppr-broux', label: 'Supprimer la boîte b.roux', dur: 5, irreversible: true, run: function (st) { fast(); st.lost.push('la boîte b.roux, pièce du dossier de preuves de Maëlle'); } },
        { id: 'cut', label: 'Couper Arvéo : supprimer les boîtes et la réception de kerbrat-architectes.fr', dur: 15, irreversible: true, run: function (st, c) {
          fast(); st.cut = true;
          var ev = [];
          if (st.pending) { st.lost.push(st.pending + ' mails restés dans les boîtes d\'Arvéo, effacés avec elles'); ev.push(st.pending + ' mails dormaient encore chez Arvéo : ils disparaissent avec les boîtes'); }
          if (c.flags.mxCache && (st.mx20 || !(st.ttlLow || c.done.mx === 'prep'))) {
            st.lost.push('23 mails de clients, renvoyés à leurs expéditeurs par Arvéo');
            ev.push('Après la coupure, la fibre de Limoges tombe dix minutes. Les serveurs qui gardent en cache l\'ancien MX de secours (TTL d\'un jour) déposent chez Arvéo, qui répond « 550 destinataire inconnu » : 23 mails reviennent à leurs expéditeurs');
          }
          if (c.flags.phone) { st.svc.mobile = false; ev.push('Thibault consultait ses archives de plans chez Arvéo depuis son téléphone, sur les chantiers. Arvéo coupé, son téléphone ne trouve plus rien'); }
          if (ev.length) st.event = ev.join('. ');
        } }
      ],
      checks: [
        { id: 'telephones', label: 'Le téléphone de Thibault et celui de Maëlle ouvrent leur courrier et leurs archives', sees: ['mobile'] },
        { id: 'compter', label: 'Comparer, boîte par boîte, le nombre de mails chez Arvéo et dans Exchange', sees: ['boites'] },
        { id: 'externe', label: 'Chaque service qui écrit au nom de Kerbrat (Outlook, copieur, facturation) envoie un mail à une boîte Gmail : en-tête « spf=pass »', sees: ['envoie'] },
        { id: 'entrant', label: 'Un mail envoyé depuis Gmail à concours@ arrive dans Exchange', sees: ['recoit'] },
        { id: 'dig', label: 'dig MX kerbrat-architectes.fr ne montre plus qu\'Exchange', sees: [] }
      ],
      backs: [
        { id: 'degel', label: 'Rouvrir la réception chez Arvéo', dur: 10 },
        { id: 'remettre-mx', label: 'Republier le MX de secours d\'Arvéo', dur: 10 },
        { id: 'spf-retour', label: 'Republier le SPF de transition (avec Arvéo)', dur: 10 },
        { id: 'rouvrir-arveo', label: 'Demander à Arvéo de rouvrir les boîtes', dur: 30 },
        { id: 'reactiver', label: 'Réactiver les règles supprimées', dur: 10 }
      ],
      goal: function (st) {
        if (st.mx20) return 'le MX de secours d\'Arvéo est encore publié';
        if (!st.rules) return st.rulesOne ? 'seule la boîte de b.roux a été vérifiée : les autres boîtes importées n\'ont pas été inventoriées' : 'les règles de transfert des boîtes n\'ont pas été inventoriées';
        if (!st.blocked) return 'le transfert automatique vers l\'extérieur est encore permis';
        if (!st.spfFinal) return 'le SPF cite encore Arvéo';
        if (!st.cut) return 'Arvéo n\'est pas coupé';
        return true;
      },
      repair: 45
    },
    start: { window: '', prep: [], night: [], noReturn: null },
    ref: { window: 'sam', prep: ['ttl', 'presync'], night: [
      { op: 'gel', back: 'degel' },
      { op: 'mx', back: 'remettre-mx' },
      { op: 'delta', check: 'compter' },
      { op: 'regles' },
      { op: 'bloquer' },
      { op: 'spf', check: 'externe', back: 'spf-retour' },
      { op: 'cut', check: 'telephones' }
    ], noReturn: 6 },
    cases: [
      { id: 'nuit', label: 'la nuit prévue, sans incident : lundi 28, Brive reçoit et envoie', visible: true, flags: {} },
      { id: 'cache', label: 'des serveurs qui gardent l\'ancien MX en cache', flags: { mxCache: true } },
      { id: 'file', label: 'des mails en file chez l\'ancien hébergeur', flags: { queued: true } },
      { id: 'spf', label: 'un SPF trop strict', flags: { spfStrict: true, unrepairable: ['envoie'] }, unavoidable: true },
      { id: 'gros', label: 'une boîte de 48 Go', flags: { big: true } },
      { id: 'telephone', label: 'un téléphone encore branché sur Arvéo', flags: { phone: true }, unavoidable: true }
    ],
    hints: [
      { title: 'Fiche : finir une migration de messagerie', body: 'Avant la nuit : on abaisse le TTL (les autres gardent l\'ancienne réponse pendant l\'ancien TTL) et on rattrape le gros des boîtes.\nLa nuit : on gèle l\'ancien serveur, puis on retire son MX, puis on fait le dernier delta (rien n\'arrive plus après lui), et on compte.\nOn vérifie les règles de TOUTES les boîtes, et on bloque le transfert automatique : ces deux mesures ne se défont pas.\nSPF définitif, puis un vrai mail de chaque expéditeur vers l\'extérieur.\nLa coupure ne se défait pas : après le point de non-retour, avec un contrôle après elle, puisqu\'on ne pourra que réparer en avant.' },
      { title: 'La réponse', body: 'Fenêtre : samedi 26, 9 h – 13 h (ou dimanche 27 après-midi).\nAvant : abaisser le TTL ; synchronisation de rattrapage.\n1. Geler Arvéo — retour : rouvrir la réception.\n2. Retirer le MX 20 — retour : le republier.\n3. Dernière synchronisation — contrôle : comparer les boîtes.\n4. Inventorier et nettoyer les règles de toutes les boîtes.\n5. Bloquer le transfert automatique.\n6. SPF définitif — contrôle : un mail de chaque expéditeur vers Gmail — retour : SPF de transition.\n◆ Point de non-retour.\n7. Couper Arvéo — contrôle : les téléphones ouvrent courrier et archives (on répare en avant s\'il le faut).' }
    ],
    intro: [
      { who: 'helene', expr: 'neutre', text: 'En septembre, Julien a importé, changé le MX, et il est passé à autre chose. Nous, on finit avec un plan : ce qui dort chez Arvéo, son MX de secours, les règles de toutes les boîtes, et la coupure.' },
      { who: 'thibault', expr: 'sourcil', text: 'Ma boîte chez Arvéo ? Elle est un peu grosse. J\'y range mes plans depuis 2016. Ça pose un problème ?' }
    ],
    done: [
      { who: 'thibault', expr: 'sourire', text: 'Lundi 28, 9 h. Mes dix ans de plans sont dans Outlook, et la facture d\'un client est partie sans revenir. Je n\'ai rien vu passer.' },
      { who: 'helene', expr: 'neutre', text: 'Arvéo est coupé, et aucune boîte ne transfère plus rien dehors. Il reste une nuit. La dernière.' }
    ]
  });

  // ——— E40 : la nuit du 31 décembre (épreuve finale) ———
  // Minutes counted from Saturday 2 January 2027, 0 h: Thursday 31 December is day -2.
  var J31 = -2 * 1440, S2 = 0;
  S.add({
    id: 'E40', act: 5, tool: 'plan',
    plan: {
      aim: 'À minuit, chacun écrit depuis son adresse @delorme-kerbrat.fr, les anciennes adresses reçoivent toujours, les comptes des partis créés par l\'import sont traités, DMARC passe en quarantaine, et les clients ont reçu l\'annonce. Les enregistrements de delorme-kerbrat.fr (MX, SPF, DKIM, DMARC) sont publiés depuis le 22 décembre.',
      services: { recoit: 'le courrier arrive, aux nouvelles adresses comme aux anciennes', envoie: 'les mails du cabinet arrivent chez les clients, pas en indésirables', outlook: 'les postes ouvrent Outlook sans alerte' },
      init: function (st) { st.cert = false; st.ttlLow = false; st.policyOn = false; st.newAddr = false; st.partis = false; st.dmarc = ''; st.annonce = false; },
      windows: [
        { id: 'matin', label: 'Jeudi 31 décembre, 9 h – 12 h', start: J31 + 540, end: J31 + 720, problem: 'le cabinet est ouvert le 31 au matin : Sandrine envoie les dernières factures de l\'année' },
        { id: 'soir', label: 'Jeudi 31 décembre, 18 h – 23 h 45', start: J31 + 1080, end: J31 + 1425 },
        { id: 'tard', label: 'Jeudi 31 décembre, 23 h 15 – 23 h 59', start: J31 + 1395, end: J31 + 1439 },
        { id: 'sam', label: 'Samedi 2 janvier, 7 h – 9 h', start: S2 + 420, end: S2 + 540, problem: 'au 1er janvier à minuit, le cabinet change de nom : les nouvelles adresses doivent marcher dès minuit, et les vœux partent à minuit' }
      ],
      ops: [
        { id: 'cert', label: 'Ajouter autodiscover.delorme-kerbrat.fr au certificat d\'Exchange', dur: 30, safe: true, run: function (st) { st.cert = true; } },
        { id: 'ttl', label: 'Abaisser à 300 s le TTL des enregistrements DMARC des trois domaines', dur: 5, safe: true, run: function (st, c) { if (c.phase === 'prep') st.ttlLow = true; } },
        { id: 'adresses', label: 'Réactiver la stratégie d\'adresses sur les boîtes importées de Brive', dur: 10, safe: true, run: function (st) { st.policyOn = true; } },
        { id: 'politique', label: 'Appliquer la stratégie d\'adresses : @delorme-kerbrat.fr en adresse principale, les anciennes gardées en alias', dur: 20, undo: ['politique-retour'], run: function (st, c) {
          st.newAddr = true;
          var ev = [];
          if (c.flags.frozenBoxes && !st.policyOn) { st.svc.recoit = false; ev.push('Les boîtes importées de Brive ont la stratégie d\'adresses désactivée, comme souvent après un import : elles n\'ont pas reçu leur adresse @delorme-kerbrat.fr. Un mail à claire.nguyen@delorme-kerbrat.fr revient : destinataire inconnu'); }
          if (c.flags.cert && !st.cert) { st.svc.outlook = false; ev.push('Outlook cherche maintenant autodiscover.delorme-kerbrat.fr : le certificat d\'Exchange ne porte pas ce nom, et chaque poste affiche une alerte de sécurité'); }
          if (ev.length) st.event = ev.join('. ');
        } },
        { id: 'partis', label: 'Traiter le compte b.roux créé par l\'import : le désactiver, le retirer de concours@, conserver sa boîte', dur: 20, safe: true, run: function (st) { st.partis = true; } },
        { id: 'dmarc', label: 'Passer DMARC de p=none à p=quarantine sur les trois domaines', dur: 5, undo: ['dmarc-none'], run: function (st, c) {
          st.dmarc = 'quarantine';
          if (c.flags.forgot) {
            st.svc.envoie = false;
            st.event = 'Les vœux du cabinet partent à minuit par un service d\'envoi en nombre, au nom de contact@delorme-architectes.fr, sans signature DKIM du domaine : avec la quarantaine, ils finissent en indésirables';
            if (!st.ttlLow) st.lost.push('les vœux de minuit, mis en quarantaine chez les clients : le retour à p=none n\'est lu qu\'à la fin du TTL d\'un jour');
          }
        } },
        { id: 'reject', label: 'Passer DMARC directement en p=reject', dur: 5, undo: ['dmarc-none'], run: function (st, c) {
          st.dmarc = 'reject';
          if (c.flags.forgot) { st.svc.envoie = false; st.lost.push('les vœux de minuit, refusés par les serveurs des clients'); st.event = 'Avec p=reject, les vœux envoyés sans signature DKIM sont refusés'; }
        } },
        { id: 'anciens', label: 'Retirer les anciennes adresses @kerbrat-architectes.fr et @delorme-architectes.fr des boîtes', dur: 15, undo: ['remettre-anciens'], run: function (st) { st.svc.recoit = false; st.event = 'Les clients qui écrivent à l\'ancienne adresse reçoivent « destinataire inconnu »'; } },
        { id: 'annonce', label: 'Envoyer aux clients et partenaires le mail qui annonce les nouvelles adresses', dur: 10, irreversible: true, run: function (st) {
          st.annonce = true;
          if (!st.newAddr) { st.lost.push('les réponses des clients aux nouvelles adresses, envoyées avant qu\'elles existent'); st.event = 'L\'annonce part avant que les nouvelles adresses existent : les premiers clients qui répondent reçoivent « destinataire inconnu »'; }
        } }
      ],
      checks: [
        { id: 'ecrire', label: 'Un mail envoyé depuis Gmail à la nouvelle adresse de Claire arrive dans sa boîte', sees: ['recoit'] },
        { id: 'externe', label: 'Chaque service qui écrit au nom du cabinet (Outlook, copieurs, service d\'envoi des vœux) écrit à une boîte Gmail : en-tête « dmarc=pass »', sees: ['envoie'] },
        { id: 'outlook', label: 'Un poste de Brive rouvre Outlook sans alerte de certificat', sees: ['outlook'] },
        { id: 'getmbx', label: 'Get-Mailbox montre @delorme-kerbrat.fr en adresse principale pour Julien', sees: [] }
      ],
      backs: [
        { id: 'politique-retour', label: 'Remettre l\'ancienne stratégie d\'adresses', dur: 15 },
        { id: 'dmarc-none', label: 'Republier DMARC en p=none', dur: 5 },
        { id: 'remettre-anciens', label: 'Remettre les anciennes adresses', dur: 15 },
        { id: 'rappel', label: 'Rappeler le mail d\'annonce', dur: 5 }
      ],
      goal: function (st) {
        if (!st.newAddr) return 'personne n\'a sa nouvelle adresse';
        if (!st.partis) return 'le compte b.roux créé par l\'import est toujours actif et membre de concours@';
        if (st.dmarc === 'reject') return 'DMARC en p=reject sans semaines de rapports : c\'est trop tôt, la quarantaine suffit';
        if (st.dmarc !== 'quarantine') return 'DMARC est resté en p=none';
        if (!st.annonce) return 'les clients ne connaissent pas les nouvelles adresses';
        return true;
      },
      repair: 45
    },
    start: { window: '', prep: [], night: [], noReturn: null },
    ref: { window: 'soir', prep: ['cert', 'ttl'], night: [
      { op: 'adresses' },
      { op: 'politique', check: 'ecrire', back: 'politique-retour' },
      { op: 'partis' },
      { op: 'dmarc', check: 'externe', back: 'dmarc-none' },
      { op: 'annonce' }
    ], noReturn: 4 },
    cases: [
      { id: 'nuit', label: 'la nuit du 31, sans incident : à minuit, Delorme Kerbrat Architectes', visible: true, flags: {} },
      { id: 'boites', label: 'des boîtes importées qui ne suivent pas la stratégie d\'adresses', flags: { frozenBoxes: true } },
      { id: 'voeux', label: 'un expéditeur oublié qui écrit au nom du cabinet', flags: { forgot: true, unrepairable: ['envoie'] }, unavoidable: true },
      { id: 'cert', label: 'un certificat qui ne porte pas le nouveau nom', flags: { cert: true } }
    ],
    hints: [
      { title: 'Fiche : la dernière nuit', body: 'Ce qui se prépare : le certificat qui doit porter les nouveaux noms, le TTL de ce qu\'on pourrait devoir remettre, et les boîtes importées, qui ont souvent la stratégie d\'adresses désactivée.\nAprès chaque changement, un contrôle qui fait ce que fait l\'utilisateur ou le client : écrire à la nouvelle adresse, envoyer depuis chaque service.\nChaque changement a son retour.\nCe qui ne se défait pas, un mail envoyé à trois cents clients, vient après le point de non-retour.' },
      { title: 'La réponse', body: 'Fenêtre : jeudi 31, 18 h – 23 h 45.\nAvant : ajouter autodiscover.delorme-kerbrat.fr au certificat ; abaisser le TTL de DMARC.\n1. Réactiver la stratégie d\'adresses sur les boîtes importées de Brive.\n2. Stratégie d\'adresses — contrôle : un mail de Gmail à la nouvelle adresse de Claire — retour : ancienne stratégie.\n3. Traiter le compte b.roux.\n4. DMARC en quarantaine — contrôle : chaque service écrit à Gmail, dmarc=pass — retour : p=none.\n◆ Point de non-retour.\n5. Envoyer l\'annonce.' }
    ],
    intro: [
      { who: 'narr', text: 'Jeudi 31 décembre, 17 h 45. Le cabinet de Limoges est vide. Sur la table d\'Hélène, la maquette : il manque une planche à la passerelle.' },
      { who: 'helene', expr: 'sourire', text: 'Cette nuit, tu la fais d\'une traite. Je suis là, mais je ne dis rien, sauf si tu me le demandes.' }
    ],
    done: [
      { who: 'narr', text: '23 h 40. Les nouvelles adresses répondent, les vœux partent en boîte de réception, l\'annonce est envoyée. Sur l\'écran, le suivi des messages ne montre plus une seule copie vers l\'extérieur.' },
      { who: 'maelle', expr: 'neutre', text: 'Le rapport est sur mon bureau, avec la chaîne entière, de juillet 2024 au cartouche. Avant minuit, j\'ai un appel à passer.' }
    ]
  });
})(FSSteps);
