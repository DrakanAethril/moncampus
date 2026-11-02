/* Acte 4 — Les droits (semaines du 7 et du 14 décembre 2026). Les ACL du NAS de Brive, la matrice des
   habilitations, AGDLP, la chaîne de groupes qui fait lire Concours à Sandrine, le journal d'audit du
   NAS, la copie sans les anciens droits, le week-end des partages et la recette. */
(function (S) {
  'use strict';

  // ——— Shared: the profiles, the folders and the target matrix (what E26 asks for, what E27 and E32 read) ———
  var PROFILES = [['assoc', 'Associés'], ['dlim', 'Dessinateurs Limoges'], ['dbri', 'Dessinateurs Brive'], ['conc', 'Équipe concours'], ['adm', 'Administration'], ['stag', 'Stagiaires']];
  var FOLDERS = [['lim', 'Projets Limoges'], ['bri', 'Projets Brive'], ['conc', 'Concours'], ['adm', 'Administratif'], ['arch', 'Archives']];
  var MATRIX = {
    assoc: { lim: 'M', bri: 'M', conc: 'L', adm: 'M', arch: 'L' },
    dlim: { lim: 'M', bri: 'L', conc: '', adm: '', arch: 'L' },
    dbri: { lim: 'L', bri: 'M', conc: '', adm: '', arch: 'L' },
    conc: { lim: '', bri: '', conc: 'M', adm: '', arch: '' },
    adm: { lim: 'L', bri: 'L', conc: '', adm: 'M', arch: 'M' },
    stag: { lim: 'L', bri: 'L', conc: '', adm: '', arch: '' }
  };
  var RIGHT_LABEL = { '': 'aucun accès', L: 'lecture', M: 'modification' };
  function label(list, id) { for (var i = 0; i < list.length; i++) if (list[i][0] === id) return list[i][1]; return id; }
  function pad(s, n) { s = String(s); while (s.length < n) s += ' '; return s; }
  /** The target matrix as text lines, for the docs of E27 and E32. */
  function matrixLines() {
    var head = pad('', 22) + FOLDERS.map(function (f) { return pad(f[1].replace('Projets ', ''), 14); }).join('');
    return [head].concat(PROFILES.map(function (p) { return pad(p[1], 22) + FOLDERS.map(function (f) { return pad(MATRIX[p[0]][f[0]] || '—', 14); }).join(''); }))
      .concat(['L = lecture · M = modification · — = aucun accès. Chacun cumule les droits de toutes ses lignes.']);
  }
  function norm(s) { return String(s == null ? '' : s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); }
  function items(s) { return norm(s).split(/[\s,;]+/).filter(Boolean); }
  function sameSet(a, b) { a = a.slice().sort(); b = b.slice().sort(); return a.length === b.length && a.every(function (x, i) { return x === b[i]; }); }

  // ——— E25 : les droits du NAS de Brive ———
  S.add({
    id: 'E25', act: 4,
    views: {
      icacls: { title: 'Les droits des dossiers du partage Projets de nas-kerbrat, relevés par Thibault', lines: [
        'PS C:\\> icacls \\\\nas-kerbrat\\Projets\\*',
        '\\\\nas-kerbrat\\Projets\\Administratif DELORME\\associes:(OI)(CI)(M)',
        '                                     BUILTIN\\Administrateurs:(OI)(CI)(F)',
        '\\\\nas-kerbrat\\Projets\\Archives DELORME\\dessin:(OI)(CI)(RX)',
        '                                DELORME\\associes:(OI)(CI)(RX)',
        '                                BUILTIN\\Administrateurs:(OI)(CI)(F)',
        '\\\\nas-kerbrat\\Projets\\Chantiers DELORME\\dessin:(OI)(CI)(M)',
        '                                 DELORME\\associes:(OI)(CI)(M)',
        '                                 DELORME\\lecture-projets:(I)(OI)(CI)(RX)',
        '                                 BUILTIN\\Administrateurs:(I)(OI)(CI)(F)',
        '\\\\nas-kerbrat\\Projets\\Concours Tout le monde:(OI)(CI)(F)',
        '                                DELORME\\concours:(OI)(CI)(M)',
        '                                DELORME\\lecture-projets:(I)(OI)(CI)(RX)',
        '                                BUILTIN\\Administrateurs:(I)(OI)(CI)(F)',
        '4 fichiers correctement traités ; échec du traitement de 0 fichiers',
        'Légende : (F) contrôle total · (M) modification · (RX) lecture · (I) hérité du dossier parent · (OI)(CI) vaut pour les fichiers et sous-dossiers'
      ] }
    },
    intro: [
      { who: 'narr', text: 'Lundi 7 décembre, 9 h. Brive, le bureau de Maëlle. Depuis vendredi, il n\'y a plus qu\'un domaine : delorme.local. Le NAS de Brive en est membre, et les anciens groupes de Kerbrat ont suivi les comptes.' },
      { who: 'helene', expr: 'neutre', text: 'Les deux agences vont partager un seul espace de fichiers, P:, sur SRV-DELORME. Avant de copier quoi que ce soit, on regarde qui peut ouvrir quoi aujourd\'hui.' },
      { who: 'maelle', expr: 'sourcil', text: 'Et le dossier du concours, en particulier. Thibault, montre-leur.' }
    ],
    done: [
      { who: 'maelle', expr: 'surprise', text: 'Contrôle total. Pour tout le monde. Sur le concours. Depuis combien de temps ?' },
      { who: 'thibault', expr: 'sourcil', text: 'Depuis toujours, je crois. Le prestataire de 2016 avait fait comme ça « pour que ça marche ». À l\'époque, on était neuf.' },
      { who: 'helene', expr: 'neutre', text: 'Avant de tout fermer, on décide qui doit lire quoi. Sinon on remplace un trou par un autre. On écrit la matrice avec Julien et vous.' }
    ]
  });

  // ——— E26 : la matrice des habilitations (formulaire, matrix) ———
  function emptyMatrix() { var m = {}; PROFILES.forEach(function (p) { m[p[0]] = {}; FOLDERS.forEach(function (f) { m[p[0]][f[0]] = ''; }); }); return m; }
  function cell(a, p, f) { return ((a.mat || {})[p] || {})[f] || ''; }
  /** Compares some cells with the target; returns true or « Administration · Concours : lecture au lieu de aucun accès ». */
  function cells(a, pairs) {
    for (var i = 0; i < pairs.length; i++) {
      var p = pairs[i][0], f = pairs[i][1], got = cell(a, p, f), want = MATRIX[p][f];
      if (got !== want) return label(PROFILES, p) + ' · ' + label(FOLDERS, f) + ' : ' + RIGHT_LABEL[got] + ', alors que les notes disent ' + RIGHT_LABEL[want];
    }
    return true;
  }
  function column(f) { return PROFILES.map(function (p) { return [p[0], f]; }); }
  function line(p) { return FOLDERS.map(function (f) { return [p, f[0]]; }); }
  S.add({
    id: 'E26', act: 4, tool: 'form',
    form: {
      intro: 'Les notes d\'Hélène, prises pendant la réunion du mardi 8 décembre. Chaque personne cumule les droits de toutes ses lignes : une ligne n\'écrit que ce que le profil ajoute.',
      docs: [
        { title: 'Réunion du 8 décembre · Julien, Maëlle, Hélène · qui lit, qui modifie', mono: false, lines: [
          'Julien : « Chaque agence modifie ses projets et lit ceux de l\'autre. On travaille ensemble, mais chacun range chez soi. »',
          'Julien : « Les associés modifient les projets des deux agences, et l\'administratif. »',
          'Julien : « Sandrine tient l\'administratif et range les archives : elle modifie les deux. Elle lit les projets des deux agences, pour retrouver un plan qu\'un client réclame. Élodie, au secrétariat de Brive, fait le même travail : même ligne. »',
          'Julien : « Les stagiaires lisent les projets des deux agences. Rien d\'autre : ni l\'administratif, ni les archives, il y a les honoraires dedans. »',
          'Maëlle : « Le concours est anonyme jusqu\'au jury. L\'équipe concours le modifie : Claire, Léa, Thibault et moi. Les associés le lisent. Personne d\'autre, même en lecture. Ni Sandrine, ni les stagiaires. »',
          'Maëlle : « Les archives, les associés et les dessinateurs les lisent. Seule Sandrine y range. »',
          'Maëlle : « L\'administratif, à part les associés et Sandrine, personne. »',
          'Hélène : l\'équipe concours n\'a que le concours sur sa ligne. Ses membres ont le reste par leur agence ou par la ligne des associés.'
        ] }
      ],
      fields: [{ id: 'mat', type: 'matrix', label: 'La matrice des habilitations', rows: PROFILES, cols: FOLDERS, values: [['', 'aucun'], ['L', 'lecture'], ['M', 'modification']] }]
    },
    start: { mat: emptyMatrix() },
    ref: { mat: JSON.parse(JSON.stringify(MATRIX)) },
    cases: [
      { id: 'concours', label: 'le dossier Concours, tel que je l\'ai dit', visible: true, check: function (a) { return cells(a, column('conc')); } },
      { id: 'agences', label: 'chaque agence modifie ses projets et lit ceux de l\'autre', check: function (a) { return cells(a, [['dlim', 'lim'], ['dlim', 'bri'], ['dbri', 'lim'], ['dbri', 'bri'], ['assoc', 'lim'], ['assoc', 'bri']]); } },
      { id: 'administratif', label: 'l\'administratif, fermé à tous sauf deux profils', check: function (a) { return cells(a, column('adm')); } },
      { id: 'archives', label: 'les archives : qui lit, et qui range', check: function (a) { return cells(a, column('arch')); } },
      { id: 'sandrine', label: 'la ligne de l\'administration', check: function (a) { return cells(a, line('adm')); } },
      { id: 'stagiaires', label: 'les stagiaires ne lisent que les projets', check: function (a) { return cells(a, line('stag')); } },
      { id: 'equipe', label: 'la ligne de l\'équipe concours n\'ajoute que le concours', check: function (a) { return cells(a, line('conc')); } }
    ],
    hints: [
      { title: 'Fiche : remplir une matrice des habilitations', body: '1. Une colonne à la fois : pour chaque dossier, relis toutes les phrases qui le nomment.\n2. « Modifie » donne M, « lit » donne L, « personne » ou rien de dit donne aucun accès.\n3. Moindre privilège : en cas de doute, on ne donne pas. Un droit se décide, il ne se suppose pas.\n4. Les droits se cumulent : une ligne de groupe transversal (l\'équipe concours) n\'écrit que ce qu\'elle ajoute.' },
      { title: 'La réponse', body: '                    Lim.  Brive  Concours  Admin.  Archives\nAssociés            M     M      L         M       L\nDessinateurs Lim.   M     L      —         —       L\nDessinateurs Brive  L     M      —         —       L\nÉquipe concours     —     —      M         —       —\nAdministration      L     L      —         M       M\nStagiaires          L     L      —         —       —' }
    ],
    done: [
      { who: 'julien', expr: 'sourcil', text: 'Les stagiaires ne lisent pas les archives ? Bon. Les honoraires, d\'accord.' },
      { who: 'maelle', expr: 'neutre', text: 'Et le concours, cinq tirets sur six. Voilà comment ça aurait dû être depuis le début.' }
    ]
  });

  // ——— E27 : AGDLP (formulaire, deux tables) ———
  var MEMBERS = { assoc: ['j.delorme', 'm.kerbrat'], dlim: ['p.morel', 'k.benali'], dbri: ['t.lacoste', 'c.nguyen', 'l.ferrand', 'h.martin', 'j.costa', 'n.blanc', 'v.leroy'], conc: ['c.nguyen', 't.lacoste', 'l.ferrand', 'm.kerbrat'], adm: ['s.aubry', 'e.marchal'], stag: [] };
  var DISABLED = ['b.roux', 'a.perrin', 'stagiaire2024'];
  var ACCOUNTS = ['j.delorme', 's.aubry', 'p.morel', 'k.benali', 'm.kerbrat', 't.lacoste', 'c.nguyen', 'l.ferrand', 'h.martin', 'j.costa', 'n.blanc', 'e.marchal', 'v.leroy'].concat(DISABLED);
  var TOKEN = { lim: 'limoges', bri: 'brive', conc: 'concours', adm: 'admin', arch: 'archive' };
  /** Name of the global group the student wrote for a profile (normalized), or ''. */
  function gName(a, p) { var i = PROFILES.map(function (x) { return x[0]; }).indexOf(p); return norm(((a.globaux || [])[i] || {}).nom); }
  /** Normalized global group name → profile. */
  function gIndex(a) { var o = {}; PROFILES.forEach(function (p) { var n = gName(a, p[0]); if (n) o[n] = p[0]; }); return o; }
  function dlRows(a, H) { return H.rows(a.locaux); }
  /** The pairs (folder, right) the matrix needs, with the profiles each one gathers. */
  function neededPairs() {
    var out = [];
    FOLDERS.forEach(function (f) { ['L', 'M'].forEach(function (r) { var ps = PROFILES.filter(function (p) { return MATRIX[p[0]][f[0]] === r; }).map(function (p) { return p[0]; }); if (ps.length) out.push({ f: f[0], r: r, profiles: ps }); }); });
    return out;
  }
  function dlLabel(f, r) { return 'le groupe ' + (r === 'M' ? 'de modification' : 'de lecture') + ' de ' + label(FOLDERS, f); }
  S.add({
    id: 'E27', act: 4, tool: 'form',
    form: {
      intro: 'AGDLP : les comptes (A) dans des groupes globaux par métier (G), les groupes globaux dans des groupes de domaine local par dossier et par droit (DL), et sur l\'ACL des dossiers, les DL et rien d\'autre (P, les permissions). Convention d\'Arpège : G_Métier, DL_Dossier_L ou DL_Dossier_M.',
      docs: [
        { title: 'La matrice signée par Julien et Maëlle (étape précédente)', lines: matrixLines() },
        { title: 'Les comptes de delorme.local concernés (Get-ADUser)', lines: [
          'SamAccountName  Nom                Enabled  Poste',
          'j.delorme       Julien Delorme     True     associé, Limoges',
          's.aubry         Sandrine Aubry     True     assistante de direction, Limoges',
          'p.morel         Pauline Morel      True     dessinatrice, Limoges',
          'k.benali        Karim Benali       True     dessinateur, Limoges',
          'm.kerbrat       Maëlle Kerbrat     True     associée, Brive · équipe concours',
          't.lacoste       Thibault Lacoste   True     dessinateur, Brive · équipe concours',
          'c.nguyen        Claire Nguyen      True     dessinatrice, Brive · équipe concours',
          'l.ferrand       Léa Ferrand        True     architecte, Brive · équipe concours',
          'h.martin        Hugo Martin        True     dessinateur, Brive',
          'j.costa         Julie Costa        True     architecte, Brive (dessin)',
          'n.blanc         Nicolas Blanc      True     dessinateur, Brive',
          'v.leroy         Vincent Leroy      True     maquettiste, Brive (dessin)',
          'e.marchal       Élodie Marchal     True     secrétariat, Brive (administration)',
          'b.roux          Bastien Roux       False    parti en 2025',
          'a.perrin        Anaïs Perrin       False    partie en 2025',
          'stagiaire2024   Stagiaire 2024     False    stage terminé',
          'Pas de stagiaire en ce moment : le prochain arrive le 11 janvier.'
        ] }
      ],
      fields: [
        { id: 'globaux', type: 'table', label: 'Les groupes globaux, par métier (G)', fixedRows: PROFILES.map(function (p) { return p[1]; }),
          columns: [{ id: 'nom', label: 'Nom du groupe', placeholder: 'G_…', mono: true }, { id: 'membres', label: 'Comptes membres', placeholder: 'p.morel, k.benali', mono: true }] },
        { id: 'locaux', type: 'table', label: 'Les groupes de domaine local, par dossier et par droit (DL)', rows: 10,
          columns: [
            { id: 'nom', label: 'Nom du groupe', placeholder: 'DL_…', mono: true },
            { id: 'membres', label: 'Membres', placeholder: 'G_…, G_…', mono: true },
            { id: 'dossier', label: 'Sur l\'ACL de', type: 'select', options: FOLDERS },
            { id: 'droit', label: 'Droit', type: 'select', options: [['L', 'lecture (L)'], ['M', 'modification (M)']] }
          ] },
        { id: 'acl', type: 'select', label: 'Ce qu\'on inscrit sur l\'ACL des dossiers', options: [
          ['dl', 'Les groupes DL_ et rien d\'autre'],
          ['g', 'Les groupes G_ des métiers concernés'],
          ['dlu', 'Les groupes DL_, plus les associés en direct, pour aller plus vite'],
          ['auth', 'Les groupes DL_, plus « Utilisateurs authentifiés » en lecture']
        ] }
      ]
    },
    start: { globaux: PROFILES.map(function () { return {}; }), locaux: [], acl: '' },
    ref: {
      globaux: [
        { nom: 'G_Associes', membres: 'j.delorme, m.kerbrat' },
        { nom: 'G_Dessinateurs_Limoges', membres: 'p.morel, k.benali' },
        { nom: 'G_Dessinateurs_Brive', membres: 't.lacoste, c.nguyen, l.ferrand, h.martin, j.costa, n.blanc, v.leroy' },
        { nom: 'G_Equipe_Concours', membres: 'c.nguyen, t.lacoste, l.ferrand, m.kerbrat' },
        { nom: 'G_Administration', membres: 's.aubry, e.marchal' },
        { nom: 'G_Stagiaires', membres: '' }
      ],
      locaux: [
        { nom: 'DL_Limoges_M', membres: 'G_Associes, G_Dessinateurs_Limoges', dossier: 'lim', droit: 'M' },
        { nom: 'DL_Limoges_L', membres: 'G_Dessinateurs_Brive, G_Administration, G_Stagiaires', dossier: 'lim', droit: 'L' },
        { nom: 'DL_Brive_M', membres: 'G_Associes, G_Dessinateurs_Brive', dossier: 'bri', droit: 'M' },
        { nom: 'DL_Brive_L', membres: 'G_Dessinateurs_Limoges, G_Administration, G_Stagiaires', dossier: 'bri', droit: 'L' },
        { nom: 'DL_Concours_M', membres: 'G_Equipe_Concours', dossier: 'conc', droit: 'M' },
        { nom: 'DL_Concours_L', membres: 'G_Associes', dossier: 'conc', droit: 'L' },
        { nom: 'DL_Administratif_M', membres: 'G_Associes, G_Administration', dossier: 'adm', droit: 'M' },
        { nom: 'DL_Archives_M', membres: 'G_Administration', dossier: 'arch', droit: 'M' },
        { nom: 'DL_Archives_L', membres: 'G_Associes, G_Dessinateurs_Limoges, G_Dessinateurs_Brive', dossier: 'arch', droit: 'L' }
      ],
      acl: 'dl'
    },
    cases: [
      { id: 'concours', label: 'le dossier Concours : l\'équipe modifie, les associés lisent', visible: true, check: function (a, H) {
        var gc = gName(a, 'conc'), ga = gName(a, 'assoc');
        if (!gc || !ga) return 'nomme d\'abord les groupes globaux de l\'équipe concours et des associés';
        if (!sameSet(items((a.globaux[3] || {}).membres), MEMBERS.conc)) return 'le groupe de l\'équipe concours doit réunir Claire, Thibault, Léa et Maëlle (c.nguyen, t.lacoste, l.ferrand, m.kerbrat)';
        var rows = dlRows(a, H), m = rows.filter(function (r) { return r.dossier === 'conc' && r.droit === 'M'; }), l = rows.filter(function (r) { return r.dossier === 'conc' && r.droit === 'L'; });
        if (m.length !== 1) return m.length ? 'deux groupes de modification sur Concours : un seul suffit' : 'aucun groupe de domaine local ne donne la modification sur Concours';
        if (!sameSet(items(m[0].membres), [gc])) return 'le groupe de modification de Concours doit contenir le groupe de l\'équipe concours, et lui seul';
        if (l.length !== 1) return l.length ? 'deux groupes de lecture sur Concours : un seul suffit' : 'aucun groupe de domaine local ne donne la lecture de Concours aux associés';
        if (!sameSet(items(l[0].membres), [ga])) return 'le groupe de lecture de Concours doit contenir le groupe des associés, et lui seul';
        return true;
      } },
      { id: 'nommage', label: 'les noms suivent la convention G_ et DL_Dossier_L ou _M', check: function (a, H) {
        var seen = {};
        for (var i = 0; i < PROFILES.length; i++) {
          var n = gName(a, PROFILES[i][0]);
          if (!n) return 'le groupe global « ' + PROFILES[i][1] + ' » n\'a pas de nom';
          if (n.indexOf('g_') !== 0) return '« ' + ((a.globaux[i] || {}).nom) + ' » : un groupe global commence par G_';
          if (seen[n]) return 'deux groupes globaux portent le nom « ' + n + ' »';
          seen[n] = 1;
        }
        var rows = dlRows(a, H);
        for (var j = 0; j < rows.length; j++) {
          var r = rows[j], dn = norm(r.nom);
          if (!r.dossier || !r.droit) return 'le groupe « ' + (r.nom || 'sans nom') + ' » : choisis son dossier et son droit';
          if (dn.indexOf('dl_') !== 0) return '« ' + r.nom + ' » : un groupe de domaine local commence par DL_';
          if (dn.indexOf(TOKEN[r.dossier]) === -1) return '« ' + r.nom + ' » : son nom doit dire le dossier, ' + label(FOLDERS, r.dossier);
          if (dn.slice(-2) !== '_' + r.droit.toLowerCase()) return '« ' + r.nom + ' » : son nom doit finir par _' + r.droit + ', le droit qu\'il donne';
          if (seen[dn]) return 'deux groupes portent le nom « ' + r.nom + ' »';
          seen[dn] = 1;
        }
        return true;
      } },
      { id: 'membres', label: 'chaque compte dans le groupe global de son métier', check: function (a) {
        for (var i = 0; i < PROFILES.length; i++) {
          var p = PROFILES[i][0], got = items((a.globaux[i] || {}).membres), want = MEMBERS[p];
          var miss = want.filter(function (x) { return got.indexOf(x) === -1; }), extra = got.filter(function (x) { return want.indexOf(x) === -1; });
          if (miss.length) return PROFILES[i][1] + ' : il manque ' + miss.join(', ');
          if (extra.length) return PROFILES[i][1] + ' : ' + extra.join(', ') + ' n\'a rien à faire dans ce groupe';
        }
        return true;
      } },
      { id: 'partis', label: 'aucun compte de personne partie', check: function (a, H) {
        var all = [];
        (a.globaux || []).forEach(function (r) { all = all.concat(items(r && r.membres)); });
        dlRows(a, H).forEach(function (r) { all = all.concat(items(r.membres)); });
        var bad = all.filter(function (x) { return DISABLED.indexOf(x) !== -1; });
        return bad.length ? bad[0] + ' est désactivé : un compte de personne partie n\'entre dans aucun nouveau groupe' : true;
      } },
      { id: 'imbrication', label: 'dans les DL, des groupes globaux, jamais des comptes', check: function (a, H) {
        var gi = gIndex(a), rows = dlRows(a, H);
        for (var i = 0; i < rows.length; i++) {
          var ms = items(rows[i].membres);
          for (var k = 0; k < ms.length; k++) {
            if (ACCOUNTS.indexOf(ms[k]) !== -1) return '« ' + rows[i].nom + ' » contient le compte ' + ms[k] + ' : les comptes vont dans les groupes globaux';
            if (!gi[ms[k]]) return '« ' + rows[i].nom + ' » contient « ' + ms[k] + ' », qui n\'est pas un de tes groupes globaux';
          }
        }
        return true;
      } },
      { id: 'matrice', label: 'les groupes donnent exactement la matrice, dossier par dossier', check: function (a, H) {
        var gi = gIndex(a), rows = dlRows(a, H), need = neededPairs();
        for (var i = 0; i < need.length; i++) {
          var n = need[i], hit = rows.filter(function (r) { return r.dossier === n.f && r.droit === n.r; });
          if (!hit.length) return 'il manque ' + dlLabel(n.f, n.r);
          if (hit.length > 1) return 'deux groupes pour ' + label(FOLDERS, n.f) + ' en ' + RIGHT_LABEL[n.r] + ' : un seul par dossier et par droit';
          var got = items(hit[0].membres).map(function (x) { return gi[x] || x; });
          var miss = n.profiles.filter(function (p) { return got.indexOf(p) === -1; }), extra = got.filter(function (p) { return n.profiles.indexOf(p) === -1; });
          if (miss.length) return '« ' + hit[0].nom + ' » : il manque le groupe ' + label(PROFILES, miss[0]);
          if (extra.length) return '« ' + hit[0].nom + ' » : le groupe ' + label(PROFILES, extra[0]) + ' n\'a pas ce droit dans la matrice';
        }
        for (var j = 0; j < rows.length; j++) {
          var r = rows[j];
          if (!need.some(function (n) { return n.f === r.dossier && n.r === r.droit; }) && items(r.membres).length) return '« ' + r.nom + ' » donne ' + RIGHT_LABEL[r.droit] + ' sur ' + label(FOLDERS, r.dossier) + ', que la matrice ne donne à personne';
        }
        return true;
      } },
      { id: 'concours-seul', label: 'le groupe de l\'équipe concours n\'est que dans un seul DL', check: function (a, H) {
        var gc = gName(a, 'conc');
        if (!gc) return 'le groupe de l\'équipe concours n\'a pas de nom';
        var where = dlRows(a, H).filter(function (r) { return items(r.membres).indexOf(gc) !== -1; });
        var wrong = where.filter(function (r) { return !(r.dossier === 'conc' && r.droit === 'M'); });
        return wrong.length ? 'le groupe de l\'équipe concours est aussi dans « ' + wrong[0].nom + ' » : il n\'ajoute que la modification du concours' : true;
      } },
      { id: 'acl', label: 'sur les ACL, les DL et rien d\'autre', check: function (a) {
        var why = { g: 'un groupe global sur une ACL, c\'est l\'étape DL sautée : au premier dossier partagé avec un autre domaine, il faudra tout reprendre', dlu: 'un compte sur une ACL, c\'est un droit que personne ne retrouvera le jour où il part', auth: '« Utilisateurs authentifiés » en lecture, c\'est « Tout le monde » sous un autre nom' };
        return a.acl === 'dl' ? true : (why[a.acl] || 'choisis ce qu\'on inscrit sur l\'ACL des dossiers');
      } }
    ],
    hints: [
      { title: 'Fiche : AGDLP', body: 'A — les comptes (Accounts) vont dans\nG — des groupes globaux, un par métier : G_Dessinateurs_Brive. Pas de compte désactivé.\nDL — qui vont dans des groupes de domaine local, un par dossier et par droit : DL_Brive_M, DL_Brive_L.\nP — seuls les DL sont sur l\'ACL (Permissions).\n\nPour chaque colonne de la matrice : un DL_Dossier_M avec les G qui ont M, un DL_Dossier_L avec les G qui ont L. Pas de DL pour une case que personne n\'a.' },
      { title: 'La réponse', body: 'G_Associes : j.delorme, m.kerbrat\nG_Dessinateurs_Limoges : p.morel, k.benali\nG_Dessinateurs_Brive : t.lacoste, c.nguyen, l.ferrand, h.martin, j.costa, n.blanc, v.leroy\nG_Equipe_Concours : c.nguyen, t.lacoste, l.ferrand, m.kerbrat\nG_Administration : s.aubry, e.marchal\nG_Stagiaires : (vide)\n\nDL_Limoges_M : G_Associes, G_Dessinateurs_Limoges\nDL_Limoges_L : G_Dessinateurs_Brive, G_Administration, G_Stagiaires\nDL_Brive_M : G_Associes, G_Dessinateurs_Brive\nDL_Brive_L : G_Dessinateurs_Limoges, G_Administration, G_Stagiaires\nDL_Concours_M : G_Equipe_Concours\nDL_Concours_L : G_Associes\nDL_Administratif_M : G_Associes, G_Administration\nDL_Archives_M : G_Administration\nDL_Archives_L : G_Associes, G_Dessinateurs_Limoges, G_Dessinateurs_Brive\n\nSur les ACL : les DL et rien d\'autre.' }
    ],
    done: [
      { who: 'helene', expr: 'sourire', text: 'Six groupes de personnes, neuf groupes de droits. Le stagiaire de janvier ? Une ligne dans G_Stagiaires, et il a exactement ce que la matrice prévoit.' }
    ]
  });

  // ——— E28 : la chaîne d'imbrication ———
  S.add({
    id: 'E28', act: 4,
    views: {
      chaine: { title: 'L\'essai d\'Hélène : une copie de Concours sans « Tout le monde », puis l\'annuaire, groupe par groupe', lines: [
        'PS C:\\> icacls \\\\nas-kerbrat\\Projets\\Concours-essai',
        '\\\\nas-kerbrat\\Projets\\Concours-essai DELORME\\concours:(OI)(CI)(M)',
        '                                      DELORME\\lecture-projets:(I)(OI)(CI)(RX)',
        '                                      BUILTIN\\Administrateurs:(I)(OI)(CI)(F)',
        'PS C:\\> Get-ADUser s.aubry -Properties MemberOf | Select-Object -ExpandProperty MemberOf',
        'CN=Administration,OU=Groupes,DC=delorme,DC=local',
        'PS C:\\> Get-ADGroup Administration -Properties MemberOf | Select-Object -ExpandProperty MemberOf',
        'CN=Personnel Delorme,OU=Groupes,DC=delorme,DC=local',
        'PS C:\\> Get-ADGroup "Personnel Delorme" -Properties MemberOf | Select-Object -ExpandProperty MemberOf',
        'CN=lecture-projets,OU=Kerbrat,DC=delorme,DC=local',
        'PS C:\\> Get-ADGroup lecture-projets -Properties Description,GroupScope | Format-List Description,GroupScope',
        'Description : Lecture des projets de Brive pour Limoges (ajouté pendant l\'approbation, novembre)',
        'GroupScope  : DomainLocal'
      ] }
    },
    done: [
      { who: 'sandrine', expr: 'surprise', text: 'Donc je lis le concours parce que je suis dans « Administration ». C\'est d\'un compliqué.' },
      { who: 'maelle', expr: 'sourcil', text: 'Tout Limoges pouvait lire nos plans. « Tout le monde » en plus. Trop de monde, de toute façon.' },
      { who: 'helene', expr: 'neutre', text: 'Pouvait. Reste à savoir si quelqu\'un l\'a fait. Thibault a activé le journal des accès du NAS l\'an dernier : on va le lire.' }
    ]
  });

  // ——— E29 : le journal d'audit du NAS ———
  S.add({
    id: 'E29', act: 4,
    views: {
      audit: { title: 'Centre des journaux de nas-kerbrat · Transfert de fichiers (SMB) · filtre : /Projets/Concours · du 14/10/2026 au 07/12/2026', lines: [
        'Journal activé le 12/03/2025 par t.lacoste · événements notés : lecture, écriture, création, suppression, renommage',
        'Heure              Adresse IP      Utilisateur  Événement  Fichier',
        '2026-10-14 18:52   192.168.1.104   c.nguyen     Création   /Projets/Concours/PL07-indiceC.pdf',
        '2026-10-14 19:05   192.168.1.103   m.kerbrat    Lecture    /Projets/Concours/PL07-indiceC.pdf',
        '2026-10-15 09:12   192.168.1.102   t.lacoste    Lecture    /Projets/Concours/PL07-indiceC.pdf',
        '2026-10-15 09:40   192.168.1.102   t.lacoste    Écriture   /Projets/Concours/PL06-indiceC.dwg',
        '2026-10-20 16:31   192.168.1.104   c.nguyen     Lecture    /Projets/Concours/PL07-indiceC.pdf',
        '2026-11-26 10:03   192.168.1.23    s.aubry      Lecture    /Projets/Concours/Reglement-concours.pdf',
        '2026-12-02 11:47   10.20.20.103    m.kerbrat    Lecture    /Projets/Concours/PL07-indiceC.pdf',
        'Fin du journal pour ce filtre : 7 événements'
      ] }
    },
    done: [
      { who: 'maelle', expr: 'neutre', text: 'Claire, Thibault et moi. Personne d\'autre n\'a ouvert l\'indice C. Les droits étaient une passoire, mais personne n\'est passé par là.' },
      { who: 'helene', expr: 'sourcil', text: 'Le plan est sorti quand même. Pas par les partages, pas par le vieux NAS, pas par le compte de Bastien. Il reste ce qu\'on n\'a pas encore regardé de près.' }
    ]
  });

  // ——— E30 : copier sans les vieux droits ———
  S.add({
    id: 'E30', act: 4,
    views: {
      commandes: { title: 'Les commandes préparées par Thibault (C:\\Migration\\copie-brive.txt)', lines: [
        '# Rappel /COPY: D = données, A = attributs, T = dates, S = ACL (sécurité), O = propriétaire, U = audit',
        '# /SEC équivaut à /COPY:DATS ; /COPYALL équivaut à /COPY:DATSOU',
        'robocopy \\\\nas-kerbrat\\Projets\\Chantiers D:\\Projets\\Brive /MIR /COPYALL /R:1 /W:1 /LOG:C:\\Migration\\brive.log',
        'robocopy \\\\nas-kerbrat\\Projets\\Chantiers D:\\Projets\\Brive /E /SEC /R:1 /W:1 /LOG:C:\\Migration\\brive.log',
        'robocopy \\\\nas-kerbrat\\Projets\\Chantiers D:\\Projets\\Brive /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 /MT:16 /LOG:C:\\Migration\\brive.log',
        'xcopy \\\\nas-kerbrat\\Projets\\Chantiers D:\\Projets\\Brive /E /H /K /O /X /Y'
      ] }
    }
  });

  // ——— E31 : le week-end des partages (plan de bascule) ———
  // Minutes counted from Saturday 12 December, 0 h.
  var SAM = 0, DIM = 1440, LUN = 2880, JEU = -2 * 1440, VEN = -1440;
  S.add({
    id: 'E31', act: 4, tool: 'plan',
    plan: {
      aim: 'Lundi 14 décembre à 8 h, P: ouvre \\\\delorme.local\\Projets sur les postes des deux sites, tous les fichiers y sont, à jour, avec les droits de la matrice. Et aucun plan ne s\'est perdu.',
      services: {
        fichiers: 'tous les fichiers sont dans \\\\delorme.local\\Projets, à jour',
        droits: 'chacun ouvre ce que la matrice lui donne, et rien de plus',
        lecteur: 'P: ouvre le nouveau partage sur les postes des deux sites',
        plans: 'les dessinateurs ouvrent leurs plans depuis P:'
      },
      init: function (st) { st.copied = null; st.short = false; st.acl = false; st.gpo = false; st.stale = false; st.earlyFreeze = false; },
      windows: [
        { id: 'jeu', label: 'Jeudi 18 h – 23 h', start: JEU + 1080, end: JEU + 1380, problem: 'jeudi soir, Sandrine boucle les factures de décembre dans l\'administratif' },
        { id: 'ven', label: 'Vendredi 20 h – 23 h 30', start: VEN + 1200, end: VEN + 1410 },
        { id: 'sam-mat', label: 'Samedi 8 h – 12 h', start: SAM + 480, end: SAM + 720, problem: 'Thibault travaille le samedi matin à Brive, sur P:' },
        { id: 'sam', label: 'Samedi 14 h – 22 h', start: SAM + 840, end: SAM + 1320 },
        { id: 'dim', label: 'Dimanche 9 h – 17 h', start: DIM + 540, end: DIM + 1020 },
        { id: 'lun', label: 'Lundi 6 h – 8 h', start: LUN + 360, end: LUN + 480, problem: 'à 8 h, les deux agences ouvrent : aucune marge pour revenir en arrière' }
      ],
      ops: [
        { id: 'copie1', label: 'Copie initiale vers SRV-DELORME : robocopy /E /COPY:DAT (1,4 To, environ 40 h par le tunnel)', dur: 2400, safe: true, run: function (st) { st.copied = 'dat'; } },
        { id: 'copieall', label: 'Copie initiale avec les droits : robocopy /E /COPYALL (1,4 To, environ 40 h)', dur: 2400, safe: true, run: function (st) { st.copied = 'all'; } },
        { id: 'raccourcir', label: 'Lister les chemins qui dépasseront 260 caractères sous P:\\Brive\\, et les faire raccourcir par leurs auteurs', dur: 240, safe: true, run: function (st, c) {
          if (c.phase === 'prep') { st.short = true; return; }
          st.svc.plans = false; st.event = 'Des dossiers renommés la nuit, sans leurs auteurs : les plans qui y renvoient (références externes) ne trouvent plus leurs fonds de plan';
        } },
        { id: 'espace', label: 'Vérifier l\'espace libre du volume D: de SRV-DELORME (3,1 To libres)', dur: 5, safe: true, run: function () { } },
        { id: 'releve', label: 'Enregistrer les autorisations actuelles des anciens partages (icacls /save, captures du NAS)', dur: 10, safe: true, run: function (st) { st.saved = true; } },
        { id: 'gel', label: 'Geler les anciens partages : lecture seule, et fermer les fichiers encore ouverts', dur: 10, undo: ['degel'], run: function (st, c) { if (c.phase === 'prep') st.earlyFreeze = true; } },
        { id: 'delta', label: 'Copie du delta : robocopy /MIR /COPY:DAT /R:1 /W:1', dur: 150, safe: true, run: function (st, c) {
          if (!st.copied) { st.svc.fichiers = false; st.event = 'Pas de copie initiale : le delta, c\'est 1,4 To. À la fin de la copie prévue, il en est à 6 %'; return; }
          if (c.flags.lock && !c.done.gel) {
            st.svc.fichiers = false;
            st.lost.push('PL12-plan-masse.dwg, enregistré automatiquement toute la nuit sur l\'ancien partage, après la dernière copie');
            st.event = 'robocopy : ERREUR 32 (0x00000020) Le processus ne peut pas accéder au fichier car il est utilisé par un autre processus. PL12-plan-masse.dwg est ouvert dans AutoCAD sur PC-BRI-02 depuis samedi midi, et s\'enregistre tout seul toutes les dix minutes';
          }
          if (c.flags.quota) { st.svc.fichiers = false; st.event = 'robocopy : ERREUR 112 (0x00000070) Espace insuffisant sur le disque. Le quota FSRM de 1,5 To posé en 2021 sur D:\\Projets est atteint : 214 fichiers non copiés'; }
        } },
        { id: 'acl', label: 'Poser les ACL de la matrice sur les dossiers cibles (groupes DL_, héritage activé)', dur: 60, safe: true, run: function (st) {
          st.acl = true;
          if (st.copied === 'all') { st.svc.droits = false; st.event = 'Les fichiers copiés avec /COPYALL gardent leurs anciennes ACL : « Tout le monde : contrôle total » est toujours sur les plans du concours'; }
        } },
        { id: 'gpo', label: 'Lier la GPO « Lecteur P: » (\\\\delorme.local\\Projets) aux postes des deux sites', dur: 10, undo: ['delier'], run: function (st, c) {
          if (c.phase === 'prep') { st.lost.push('une semaine de travail enregistrée sur le nouveau partage avant la nuit, écrasée par la copie du delta (/MIR)'); }
          st.gpo = true;
          if (!c.done.acl) { st.svc.droits = false; st.event = 'Les dossiers cibles n\'ont que les droits de la racine : Administrateurs seulement. Avec P:, personne n\'ouvre rien'; }
          if (c.flags.longpath && !st.short) { st.svc.plans = false; st.event = 'Les plans de Brive passent de P:\\ à P:\\Brive\\ : 37 chemins dépassent 260 caractères, AutoCAD ne les ouvre plus'; }
          if (c.flags.ou) { st.svc.lecteur = false; st.event = 'La GPO est liée à l\'OU Utilisateurs-Limoges. Les comptes de Brive, migrés en décembre, sont restés dans l\'OU Kerbrat : ils n\'auront pas de P:'; }
          if (c.flags.sessions) { st.stale = true; st.svc.lecteur = false; st.event = 'Six postes de Brive ont une session ouverte depuis vendredi : la stratégie de lecteur ne s\'applique qu\'à l\'ouverture de session. Lundi, ils garderont l\'ancien P:, gelé en lecture seule'; }
        } },
        { id: 'gpupdate', label: 'Forcer la mise à jour des stratégies sur tous les postes et fermer les sessions restées ouvertes', dur: 30, safe: true, run: function (st, c) {
          if (!c.done.gpo) return;
          if (st.stale) { st.stale = false; st.svc.lecteur = true; }
        } },
        { id: 'suppr', label: 'Supprimer les anciens partages, pour éviter les doublons', dur: 20, irreversible: true, run: function (st) {
          if (st.svc.fichiers === false) st.lost.push('les fichiers que la copie n\'a pas emportés : ils n\'existent plus nulle part');
        } }
      ],
      checks: [
        { id: 'log', label: 'Lire la fin du journal robocopy : la colonne « Échec » est à 0', sees: ['fichiers'] },
        { id: 'profils', label: 'Sur le poste de recette, ouvrir chaque dossier avec un compte de test de chaque profil', sees: ['droits'] },
        { id: 'gpresult', label: 'gpresult /r sur un poste de chaque site : la GPO « Lecteur P: » est appliquée', sees: ['lecteur'] },
        { id: 'ouvrir', label: 'Ouvrir dans AutoCAD, par P:, le plan le plus profond de chaque agence', sees: ['plans'] },
        { id: 'admin', label: 'Ouvrir \\\\delorme.local\\Projets avec le compte administrateur', sees: [] },
        { id: 'ping', label: 'SRV-DELORME répond au ping depuis Brive', sees: [] }
      ],
      backs: [
        { id: 'degel', label: 'Remettre les anciens partages en lecture-écriture (autorisations enregistrées)', dur: 15, needs: ['releve'] },
        { id: 'delier', label: 'Délier la GPO « Lecteur P: » : les postes retrouvent l\'ancien lecteur', dur: 10 },
        { id: 'vider', label: 'Vider les dossiers cibles', dur: 30 },
        { id: 'restaurer', label: 'Restaurer la sauvegarde de vendredi sur les anciens partages', dur: 180 }
      ],
      goal: function (st, done) {
        if (st.earlyFreeze) return 'les anciens partages ont été gelés huit jours avant la nuit : les deux agences n\'ont rien pu enregistrer de la semaine';
        if (done.delta !== 'night') return 'la dernière copie, celle du delta, doit se faire pendant la nuit, après le gel';
        if (!st.acl) return 'les droits de la matrice ne sont pas posés sur les dossiers cibles';
        if (!st.gpo) return 'les postes ouvrent toujours l\'ancien P:';
        return true;
      }
    },
    start: { window: '', prep: [], night: [], noReturn: null },
    ref: {
      window: 'sam', prep: ['copie1', 'raccourcir'],
      night: [{ op: 'releve' }, { op: 'gel', back: 'degel' }, { op: 'delta', check: 'log' }, { op: 'acl', check: 'profils' }, { op: 'gpo', back: 'delier' }, { op: 'gpupdate', check: 'gpresult' }],
      noReturn: null
    },
    cases: [
      { id: 'nuit', label: 'le week-end prévu, sans incident : lundi, tout le monde ouvre P:', visible: true, flags: {} },
      { id: 'verrou', label: 'un plan resté ouvert sur un poste de Brive', flags: { lock: true } },
      { id: 'chemins', label: 'des chemins de dossiers très longs', flags: { longpath: true } },
      { id: 'quota', label: 'un quota oublié sur le serveur cible', flags: { quota: true } },
      { id: 'sessions', label: 'des postes restés allumés tout le week-end', flags: { sessions: true } },
      { id: 'ou', label: 'une stratégie liée à une seule agence', flags: { ou: true } }
    ],
    hints: [
      { title: 'Fiche : migrer un partage', body: '1. Dans la semaine (avant la nuit) : la copie initiale, longue, et ce qui demande les utilisateurs (des chemins trop longs à raccourcir avec leurs auteurs).\n2. La nuit : relever l\'existant, puis geler l\'ancien partage (lecture seule, fichiers fermés), puis copier le delta. Après la copie, plus rien ne doit pouvoir s\'écrire derrière elle.\n3. Contrôler la copie par son journal, les droits par un compte de chaque profil, le lecteur par gpresult sur de vrais postes.\n4. Un poste qui garde sa session ne lit pas la nouvelle GPO : on force la mise à jour.\n5. On ne supprime jamais l\'ancien partage la nuit de la bascule.' },
      { title: 'La réponse', body: 'Avant la nuit : copie initiale robocopy /COPY:DAT ; faire raccourcir les chemins trop longs.\nFenêtre : samedi 14 h – 22 h (ou dimanche 9 h – 17 h).\n1. Enregistrer les autorisations des anciens partages.\n2. Geler les anciens partages — retour : les remettre en lecture-écriture.\n3. Copie du delta — contrôle : journal robocopy, 0 échec.\n4. Poser les ACL de la matrice — contrôle : un compte de test de chaque profil.\n5. Lier la GPO « Lecteur P: » — retour : délier la GPO.\n6. Forcer la mise à jour des stratégies — contrôle : gpresult sur un poste de chaque site.' }
    ],
    intro: [
      { who: 'helene', expr: 'neutre', text: 'Ce plan-ci a deux temps. Ce qui est long se fait dans la semaine, pendant que tout le monde travaille. La nuit, on ne fait que ce qui doit être fait d\'un coup.' },
      { who: 'julien', expr: 'sourcil', text: 'Et si on copiait tout samedi, simplement ? Les gens ne sont pas là.' },
      { who: 'helene', expr: 'sourire', text: '1,4 To par le tunnel, c\'est quarante heures. Ton samedi en a vingt-quatre.' }
    ],
    done: [
      { who: 'narr', text: 'Lundi 14 décembre, 8 h 05. À Brive comme à Limoges, P: s\'ouvre sur les mêmes dossiers.' },
      { who: 'thibault', expr: 'sourire', text: 'Mon plan de masse est là, enregistré samedi à midi. Et le vieux NAS est en lecture seule : plus de doute sur qui a la bonne version.' },
      { who: 'julien', expr: 'sourire', text: 'J\'ai compté. Pas un plan perdu, et personne n\'a rien remarqué. C\'était donc ça, l\'idée.' }
    ]
  });

  // ——— E32 : la recette (formulaire, table) ———
  var ACCT = {
    'p.morel': { profiles: ['dlim'], kind: 'dessinatrice', site: 'lim' },
    'k.benali': { profiles: ['dlim'], kind: 'dessinatrice', site: 'lim' },
    'c.nguyen': { profiles: ['dbri', 'conc'], kind: 'dessinatrice', site: 'bri' },
    'l.ferrand': { profiles: ['dbri', 'conc'], kind: 'dessinatrice', site: 'bri' },
    'h.martin': { profiles: ['dbri'], kind: 'dessinatrice', site: 'bri' },
    't.lacoste': { profiles: ['dbri', 'conc'], kind: 'dessinatrice', site: 'bri' },
    'm.kerbrat': { profiles: ['assoc', 'conc'], kind: 'associée' },
    'j.delorme': { profiles: ['assoc'], kind: 'associée' },
    's.aubry': { profiles: ['adm'], kind: 'assistante' },
    'e.marchal': { profiles: ['adm'], kind: 'assistante' },
    'test.stagiaire': { profiles: ['stag'], kind: 'stagiaire' }
  };
  var KINDS = ['dessinatrice', 'associée', 'assistante', 'stagiaire'];
  var ACTIONS = [['lire', 'ouvrir un fichier (lire)'], ['modifier', 'enregistrer un fichier (modifier)']];
  var EXPECT = [['ok', 'ça marche'], ['refus', 'accès refusé']];
  /** The effective right of an account on a folder: the best of its profiles. */
  function rightOf(acct, f) { var best = ''; (ACCT[acct] ? ACCT[acct].profiles : []).forEach(function (p) { var r = MATRIX[p][f]; if (r === 'M' || (r === 'L' && best === '')) best = r; }); return best; }
  function expected(acct, f, action) { var r = rightOf(acct, f); return (action === 'modifier' ? r === 'M' : r !== '') ? 'ok' : 'refus'; }
  function complete(r) { return !!(r.compte && r.dossier && r.action && r.attendu); }
  function tests(a, H) { return H.rows(a.tests).filter(complete); }
  function describe(r) { return r.compte + ', ' + label(FOLDERS, r.dossier) + ', ' + (r.action === 'modifier' ? 'enregistrer' : 'ouvrir'); }
  S.add({
    id: 'E32', act: 4, tool: 'form',
    form: {
      intro: 'Un test, c\'est un compte réel qui fait un geste réel sur un dossier, et le résultat qu\'on attend, écrit avant de le faire. Le compte test.stagiaire a été créé pour la recette, membre de G_Stagiaires ; il sera désactivé après.',
      docs: [
        { title: 'La matrice signée', lines: matrixLines() },
        { title: 'Les comptes et leurs groupes globaux', lines: [
          'p.morel, k.benali     G_Dessinateurs_Limoges',
          'c.nguyen, t.lacoste, l.ferrand  G_Dessinateurs_Brive, G_Equipe_Concours',
          'h.martin              G_Dessinateurs_Brive',
          'm.kerbrat             G_Associes, G_Equipe_Concours',
          'j.delorme             G_Associes',
          's.aubry, e.marchal    G_Administration',
          'test.stagiaire        G_Stagiaires (compte de recette)'
        ] }
      ],
      fields: [{ id: 'tests', type: 'table', label: 'Le cahier de recette', rows: 10, columns: [
        { id: 'compte', label: 'Compte', type: 'select', options: Object.keys(ACCT).map(function (k) { return [k, k + ' (' + ACCT[k].kind + ')']; }) },
        { id: 'dossier', label: 'Dossier', type: 'select', options: FOLDERS },
        { id: 'action', label: 'Action', type: 'select', options: ACTIONS },
        { id: 'attendu', label: 'Résultat attendu', type: 'select', options: EXPECT }
      ] }]
    },
    start: { tests: [] },
    ref: { tests: [
      { compte: 'c.nguyen', dossier: 'conc', action: 'modifier', attendu: 'ok' },
      { compte: 'p.morel', dossier: 'lim', action: 'modifier', attendu: 'ok' },
      { compte: 'p.morel', dossier: 'bri', action: 'modifier', attendu: 'refus' },
      { compte: 'm.kerbrat', dossier: 'adm', action: 'modifier', attendu: 'ok' },
      { compte: 'j.delorme', dossier: 'conc', action: 'modifier', attendu: 'refus' },
      { compte: 's.aubry', dossier: 'conc', action: 'lire', attendu: 'refus' },
      { compte: 's.aubry', dossier: 'arch', action: 'modifier', attendu: 'ok' },
      { compte: 'test.stagiaire', dossier: 'bri', action: 'lire', attendu: 'ok' },
      { compte: 'test.stagiaire', dossier: 'adm', action: 'lire', attendu: 'refus' }
    ] },
    cases: [
      { id: 'profils', label: 'chaque profil est testé : dessinatrice, associée, assistante, stagiaire', visible: true, check: function (a, H) {
        var t = tests(a, H), miss = KINDS.filter(function (k) { return !t.some(function (r) { return ACCT[r.compte] && ACCT[r.compte].kind === k; }); });
        return miss.length ? 'aucun test complet pour le profil : ' + miss.join(', ') : true;
      } },
      { id: 'complet', label: 'chaque ligne dit le compte, le dossier, le geste et le résultat attendu', check: function (a, H) {
        var bad = H.rows(a.tests).filter(function (r) { return !complete(r); });
        return bad.length ? 'une ligne est incomplète : un test sans résultat attendu ne prouve rien' : true;
      } },
      { id: 'attendus', label: 'chaque résultat attendu est celui de la matrice', check: function (a, H) {
        var t = tests(a, H);
        for (var i = 0; i < t.length; i++) { var e = expected(t[i].compte, t[i].dossier, t[i].action); if (t[i].attendu !== e) return describe(t[i]) + ' : la matrice dit « ' + label(EXPECT, e) + ' »'; }
        return true;
      } },
      { id: 'sandrine', label: 'Sandrine essaie d\'ouvrir le concours', check: function (a, H) {
        return tests(a, H).some(function (r) { return r.compte === 's.aubry' && r.dossier === 'conc' && r.action === 'lire' && r.attendu === 'refus'; }) ? true : 'aucun test ne vérifie que l\'assistante ne peut plus ouvrir le concours : c\'est ce qu\'on corrigeait';
      } },
      { id: 'lecture-seule', label: 'une lecture seule vérifiée en essayant d\'écrire', check: function (a, H) {
        return tests(a, H).some(function (r) { return rightOf(r.compte, r.dossier) === 'L' && r.action === 'modifier' && r.attendu === 'refus'; }) ? true : 'aucun test ne vérifie qu\'un droit de lecture interdit bien d\'enregistrer';
      } },
      { id: 'equipe', label: 'l\'équipe concours travaille encore', check: function (a, H) {
        return tests(a, H).some(function (r) { return ACCT[r.compte] && ACCT[r.compte].profiles.indexOf('conc') !== -1 && r.dossier === 'conc' && r.action === 'modifier' && r.attendu === 'ok'; }) ? true : 'aucun membre de l\'équipe concours n\'enregistre dans Concours : fermer la porte ne doit pas l\'enfermer dehors';
      } },
      { id: 'agences', label: 'une dessinatrice de chaque agence', check: function (a, H) {
        var t = tests(a, H), has = function (s) { return t.some(function (r) { return ACCT[r.compte] && ACCT[r.compte].site === s; }); };
        return has('lim') && has('bri') ? true : 'les dessinateurs des deux agences n\'ont pas les mêmes droits : teste un compte de chaque agence';
      } }
    ],
    hints: [
      { title: 'Fiche : un cahier de recette des droits', body: 'Pour chaque profil, au moins un test.\nPour chaque droit, le geste qui le prouve :\n- modification : enregistrer un fichier, attendu « ça marche » ;\n- lecture seule : enregistrer, attendu « accès refusé » ;\n- aucun accès : ouvrir, attendu « accès refusé ».\nLe résultat attendu se lit dans la matrice, avant le test. Et on teste ce qu\'on a corrigé.' },
      { title: 'La réponse', body: 'c.nguyen · Concours · enregistrer · ça marche\np.morel · Projets Limoges · enregistrer · ça marche\np.morel · Projets Brive · enregistrer · accès refusé\nm.kerbrat · Administratif · enregistrer · ça marche\nj.delorme · Concours · enregistrer · accès refusé\ns.aubry · Concours · ouvrir · accès refusé\ns.aubry · Archives · enregistrer · ça marche\ntest.stagiaire · Projets Brive · ouvrir · ça marche\ntest.stagiaire · Administratif · ouvrir · accès refusé' }
    ],
    done: [
      { who: 'sandrine', expr: 'sourire', text: '« Accès refusé. » Je ne pensais pas être si contente de lire ça.' },
      { who: 'maelle', expr: 'sourire', text: 'Neuf tests, neuf résultats attendus. Je signe. Les droits, c\'est réglé. Il reste la fuite.' }
    ]
  });
})(FSSteps);
