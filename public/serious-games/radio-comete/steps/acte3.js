/* Acte 3 — Un script qui n'est pas le sien. Lire le script de Marc (nettoyage-nuit, sur `archives`),
   le rejouer à blanc, comparer sa liste à celle de Yanis, trouver d'où vient la valeur vide.
   Notions : fonctions, substitutions ${A:-B}, find -delete, sed, comm, process substitution. */
(function (S) {
  'use strict';
  var B = S.B, H = S.helpers, W = RCWorld, V = RCVfs, DAY = 86400;

  var NETTOYAGE_LINES = W.NETTOYAGE.replace(/\n$/, '').split('\n');

  function all(list) { for (var i = 0; i < list.length; i++) if (list[i] !== true) return list[i]; return true; }

  // ——— The data of `archives` ———
  var OLD_AND_NEW = [
    ['/srv/data/archives/2019/la-matinale/2019-11-04.wav', 2300], ['/srv/data/archives/2019/la-matinale/2019-11-11.wav', 2293],
    ['/srv/data/archives/2019/jazz-de-nuit/2019-12-02.wav', 2250], ['/srv/data/archives/2019/le-grand-bal/2019-12-14.wav', 2240],
    ['/srv/data/archives/2025/la-matinale/2025-12-01.wav', 101], ['/srv/data/archives/2026/la-matinale/2026-01-12.wav', 59],
    ['/srv/data/archives/2026/la-matinale/2026-03-10.wav', 2], ['/srv/data/archives/2026/jazz-de-nuit/2026-02-10.wav', 30],
    ['/srv/data/archives/2026/jazz-de-nuit/2026-02-09.wav', 31],
    ['/srv/data/exports/matin-2026-02-02.wav', 38], ['/srv/data/exports/matin-2026-03-11.wav', 1], ['/srv/data/exports/jazz-2026-03-12.wav', 0]
  ];
  function tree(m) {
    OLD_AND_NEW.forEach(function (f) { B.big(m, f[0], 30e6, f[1]); });
    B.file(m, '/srv/data/archives/2019/la-matinale/notes.txt', 'notes de Yanis\n', 2300);
    B.file(m, '/home/admin/LISEZMOI', 'Serveur archives de Radio Comète.\nnettoyage-nuit : le script de Marc (/usr/local/bin).\n', 20);
  }
  /** What nettoyage-nuit removes with RETENTION=30 and no EXPORT_ROOT: every .wav older than 30 whole days under /srv/data. */
  function expectedOld(m) {
    return m.vfs.walk('/srv/data').filter(function (p) {
      var n = m.vfs.get(p, false);
      return n.t === 'f' && /\.wav$/.test(p) && Math.floor((m.vfs.now - n.mtime) / DAY) > 30;
    }).sort();
  }
  function fresh(m) { m.vfs.walk('/srv/data').forEach(function (p) { var n = m.vfs.get(p, false); if (n.t === 'f') n.mtime = m.vfs.now - DAY; }); }
  function sameList(got, exp) {
    var a = got.slice().sort(), b = exp.slice().sort();
    if (a.length !== b.length) return a.length + ' lignes affichées au lieu de ' + b.length + (a.length ? ' (première : ' + a[0] + ')' : '');
    for (var i = 0; i < a.length; i++) if (a[i] !== b[i]) return 'la ligne « ' + a[i] + ' » ne devrait pas être là (ou « ' + b[i] + ' » manque)';
    return true;
  }
  function installScript(m, path) { B.file(m, path, W.NETTOYAGE, 100, 493); }

  // ——— E17 · Ouvrir nettoyage-nuit ———
  S.add({
    id: 'E17', act: 3, machine: 'archives',
    views: { script: { title: 'nettoyage-nuit', lines: NETTOYAGE_LINES } }
  });

  // ——— E21 · Rejouer à blanc ———
  var BLANC = [
    '#!/bin/bash',
    '# a-blanc.sh SCRIPT : montre ce que SCRIPT supprimerait, sans rien supprimer',
    'cp $1 $1.blanc',
    'sed -i \'s/-delete/-print/\' $1.blanc',
    'bash $1.blanc',
    ''
  ].join('\n');
  var BLANC_REF = [
    '#!/bin/bash',
    '# a-blanc.sh SCRIPT : montre ce que SCRIPT supprimerait, sans rien supprimer',
    'set -u',
    'if [ $# -ne 1 ] || [ ! -f "$1" ]; then',
    '    echo "usage : $0 SCRIPT" >&2',
    '    exit 2',
    'fi',
    'script=$1',
    '',
    'if ! grep -q -- \'-delete\' "$script"; then',
    '    echo "$script ne contient pas de -delete : rien à simuler" >&2',
    '    exit 1',
    'fi',
    '',
    'copie="$script.blanc"',
    'trap \'rm -f "$copie"\' EXIT',
    '# la copie affiche au lieu de supprimer, et son journal va dans /tmp',
    'sed -e \'s/-delete/-print/\' -e \'s|/var/log/radio|/tmp|g\' "$script" > "$copie"',
    'bash "$copie"',
    ''
  ].join('\n');

  function blancCheck(script, orig) {
    return function (r, m) {
      return all([
        r.status === 0 || 'code de retour ' + r.status + ' : ' + r.err.split('\n')[0],
        sameList(H.lines(r.out), expectedOld(m)),
        expectedOldStillThere(m) || 'des fichiers ont été supprimés : la simulation ne doit rien supprimer',
        H.file(m, script) === orig || 'le script d\'origine a été modifié',
        !m.vfs.exists('/var/log/radio/nettoyage.log') || 'le journal /var/log/radio/nettoyage.log a été écrit : une simulation ne laisse pas de trace là',
        !m.vfs.exists(script + '.blanc') || 'la copie ' + script + '.blanc traîne encore après la simulation'
      ]);
    };
  }
  function expectedOldStillThere(m) { return OLD_AND_NEW.every(function (f) { return m.vfs.isFile(f[0]); }); }

  S.add({
    id: 'E21', act: 3, machine: 'archives', edit: ['a-blanc.sh'], start: BLANC, ref: BLANC_REF,
    base: function (m) { tree(m); installScript(m, '/usr/local/bin/nettoyage-nuit'); B.file(m, '/home/admin/a-blanc.sh', BLANC, 2, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'la simulation de nettoyage-nuit affiche les vieux .wav, ne supprime rien, ne touche ni au script ni au journal',
        setup: function () { }, run: 'bash a-blanc.sh /usr/local/bin/nettoyage-nuit',
        check: blancCheck('/usr/local/bin/nettoyage-nuit', W.NETTOYAGE) },
      { id: 'espaces', label: 'des noms de fichiers avec des espaces, des accents, et une extension .WAV en majuscules',
        setup: function (m) { B.big(m, '/srv/data/archives/2019/Émission du matin/2019 11 04.wav', 10e6, 2000); B.big(m, '/srv/data/archives/2019/Émission du matin/ancien.WAV', 10e6, 2000); }, run: 'bash a-blanc.sh /usr/local/bin/nettoyage-nuit',
        check: function (r, m) { return all([blancCheck('/usr/local/bin/nettoyage-nuit', W.NETTOYAGE)(r, m), r.out.indexOf('Émission du matin/2019 11 04.wav') !== -1 || 'le fichier « 2019 11 04.wav » n\'est pas dans la liste']); } },
      { id: 'rien', label: 'aucun fichier assez ancien : la liste est vide et le script réussit',
        setup: function (m) { fresh(m); }, run: 'bash a-blanc.sh /usr/local/bin/nettoyage-nuit',
        check: blancCheck('/usr/local/bin/nettoyage-nuit', W.NETTOYAGE) },
      { id: 'chemin', label: 'un script dont le chemin contient une espace',
        setup: function (m) { installScript(m, '/home/admin/mes scripts/nettoyage.sh'); }, run: 'bash a-blanc.sh "/home/admin/mes scripts/nettoyage.sh"',
        check: blancCheck('/home/admin/mes scripts/nettoyage.sh', W.NETTOYAGE) },
      { id: 'sans-delete', label: 'un script qui ne contient pas de -delete : refusé, et il n\'est pas exécuté',
        setup: function (m) { B.file(m, '/home/admin/rien.sh', '#!/bin/bash\necho bonjour\n', 3, 493); }, run: 'bash a-blanc.sh rien.sh',
        check: function (r, m) { return all([r.status !== 0 || 'le script réussit alors qu\'il n\'y a rien à simuler', r.out.indexOf('bonjour') === -1 || 'le script a été exécuté', r.err.trim() !== '' || 'pas de message sur la sortie d\'erreur', !m.vfs.exists('/home/admin/rien.sh.blanc') || 'une copie .blanc traîne']); } },
      { id: 'usage', label: 'sans argument : usage et code 2',
        setup: function () { }, run: 'bash a-blanc.sh',
        check: function (r) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, /usage/i.test(r.err) || 'pas de message « usage »']); } }
    ],
    hints: [
      { title: 'Même filtre, sans l\'effet', course: 'bash', body: 'find applique ses conditions (-type, -name, -mtime) puis une action. -delete supprime, -print affiche. Remplace l\'action et tu obtiens la liste de ce qui aurait été supprimé, avec exactement le même filtre.' },
      { title: 'Travailler sur une copie, sans traces', course: 'bash', body: 'sed s/ancien/nouveau/ lit un fichier et écrit le résultat sur sa sortie : redirige-la dans une copie (> "$script.blanc"). Le journal du script visait /var/log/radio : remplace ce chemin par /tmp dans la copie (sed -e … -e …, avec | comme séparateur : s|/var/log/radio|/tmp|g). Ferme la copie avec trap \'rm -f "$copie"\' EXIT, et mets des guillemets partout : le chemin du script peut contenir des espaces.' },
      { title: 'La solution', course: 'bash', body: 'copie="$script.blanc"\ntrap \'rm -f "$copie"\' EXIT\nsed -e \'s/-delete/-print/\' -e \'s|/var/log/radio|/tmp|g\' "$script" > "$copie"\nbash "$copie"\n# avant : refuser un script sans -delete (grep -q -- \'-delete\' "$script")' }
    ],
    intro: [{ who: 'odile', expr: 'sourcil', text: 'Je ne veux pas lancer le script de Marc pour voir ce qu\'il fait : il supprime ! Tu peux le rejouer « à blanc » ?' }],
    done: [{ who: 'colette', expr: 'sourire', text: 'Le même filtre, mais en lecture seule. On peut regarder le piège sans le déclencher.' }]
  });

  // ——— E22 · Une liste trop longue ———
  var CMP = [
    '#!/bin/bash',
    '# comparer.sh A_SUPPRIMER MANQUANTS : ce que Yanis a perdu vient-il de la purge ?',
    'sort $1 > /tmp/a',
    'sort $2 > /tmp/b',
    'n=$(wc -l < /tmp/a)',
    'm=$(wc -l < /tmp/b)',
    'k=$(comm -12 /tmp/a /tmp/b | wc -l)',
    'echo "$n supprimés cette nuit, $m dans la liste de Yanis, $k communs"',
    ''
  ].join('\n');
  var CMP_REF = [
    '#!/bin/bash',
    '# comparer.sh A_SUPPRIMER MANQUANTS : ce que Yanis a perdu vient-il de la purge ?',
    'set -u',
    'if [ $# -ne 2 ] || [ ! -r "$1" ] || [ ! -r "$2" ]; then',
    '    echo "usage : $0 A_SUPPRIMER MANQUANTS" >&2',
    '    exit 2',
    'fi',
    '',
    '# on ne compare que « émission/fichier » : les deux derniers morceaux de chaque chemin',
    'purge=$(tr -d \'\\r\' < "$1" | awk -F/ \'NF >= 2 { print $(NF-1) "/" $NF }\' | sort -u)',
    'perdus=$(tr -d \'\\r\' < "$2" | awk -F/ \'NF >= 2 { print $(NF-1) "/" $NF }\' | sort -u)',
    '',
    'n=$(grep -c . <<< "$purge")',
    'm=$(grep -c . <<< "$perdus")',
    'k=$(comm -12 <(echo "$purge") <(echo "$perdus") | grep -c .)',
    'echo "$n supprimés cette nuit, $m dans la liste de Yanis, $k communs"',
    '',
    'if [ "$k" -eq "$m" ]; then',
    '    echo "Tout ce que Yanis a perdu correspond à la purge."',
    'else',
    '    echo "Pas expliqués par la purge :"',
    '    comm -13 <(echo "$purge") <(echo "$perdus") | grep . | sed \'s/^/  /\'',
    '    exit 1',
    'fi',
    ''
  ].join('\n');

  var A_LIST = [
    '/srv/data/archives/2019/la-matinale/2019-11-04.wav', '/srv/data/archives/2019/la-matinale/2019-11-11.wav',
    '/srv/data/archives/2019/jazz-de-nuit/2019-12-02.wav', '/srv/data/exports/matin-2026-02-02.wav'
  ].join('\n') + '\n';
  function files(m, a, y) { B.file(m, '/home/admin/a-supprimer.txt', a, 1); B.file(m, '/home/admin/manquants.txt', y, 1); }
  var RUN_CMP = 'bash comparer.sh a-supprimer.txt manquants.txt';

  S.add({
    id: 'E22', act: 3, machine: 'archives', edit: ['comparer.sh'], start: CMP, ref: CMP_REF,
    base: function (m) { tree(m); B.file(m, '/home/admin/comparer.sh', CMP, 2, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'les trois fichiers de Yanis sont dans la purge : le script le dit et réussit',
        setup: function (m) { files(m, A_LIST, 'la-matinale/2019-11-04.wav\nla-matinale/2019-11-11.wav\njazz-de-nuit/2019-12-02.wav\n'); }, run: RUN_CMP,
        check: function (r) { var l = H.lines(r.out); return all([r.status === 0 || 'code de retour ' + r.status + ' au lieu de 0', l[0] === '4 supprimés cette nuit, 3 dans la liste de Yanis, 3 communs' || 'première ligne : « ' + (l[0] || '') + ' »', l[1] === 'Tout ce que Yanis a perdu correspond à la purge.' || 'phrase de conclusion absente']); } },
      { id: 'inconnu', label: 'un fichier de Yanis n\'est pas dans la purge : il est nommé, le code de retour est 1',
        setup: function (m) { files(m, A_LIST, 'la-matinale/2019-11-04.wav\nle-grand-bal/2019-12-14.wav\n'); }, run: RUN_CMP,
        check: function (r) { var l = H.lines(r.out); return all([r.status === 1 || 'code de retour ' + r.status + ' au lieu de 1', l[0] === '4 supprimés cette nuit, 2 dans la liste de Yanis, 1 communs' || 'première ligne : « ' + (l[0] || '') + ' »', r.out.indexOf('le-grand-bal/2019-12-14.wav') !== -1 || 'le fichier non expliqué n\'est pas nommé', r.out.indexOf('correspond à la purge') === -1 || 'il annonce que tout correspond']); } },
      { id: 'windows', label: 'la liste de Yanis vient de Windows : fins de ligne CRLF, lignes vides, doublons',
        setup: function (m) { files(m, A_LIST, 'la-matinale/2019-11-04.wav\r\n\r\nla-matinale/2019-11-04.wav\r\njazz-de-nuit/2019-12-02.wav\r\n\r\n'); }, run: RUN_CMP,
        check: function (r) { var l = H.lines(r.out); return all([r.status === 0 || 'code de retour ' + r.status, l[0] === '4 supprimés cette nuit, 2 dans la liste de Yanis, 2 communs' || 'première ligne : « ' + (l[0] || '') + ' »']); } },
      { id: 'espaces', label: 'des noms avec des espaces et des accents',
        setup: function (m) { files(m, '/srv/data/archives/2019/Émission du matin/2019 11 04.wav\n/srv/data/exports/matin-2026-02-02.wav\n', 'Émission du matin/2019 11 04.wav\n'); }, run: RUN_CMP,
        check: function (r) { var l = H.lines(r.out); return all([r.status === 0 || 'code de retour ' + r.status, l[0] === '2 supprimés cette nuit, 1 dans la liste de Yanis, 1 communs' || 'première ligne : « ' + (l[0] || '') + ' »']); } },
      { id: 'vides', label: 'deux listes vides : zéro partout, et rien à reprocher à la purge',
        setup: function (m) { files(m, '', ''); }, run: RUN_CMP,
        check: function (r) { var l = H.lines(r.out); return all([r.status === 0 || 'code de retour ' + r.status, l[0] === '0 supprimés cette nuit, 0 dans la liste de Yanis, 0 communs' || 'première ligne : « ' + (l[0] || '') + ' »']); } },
      { id: 'arguments', label: 'un argument manquant ou un fichier illisible : usage et code 2',
        setup: function (m) { files(m, A_LIST, 'x/y.wav\n'); }, run: 'bash comparer.sh a-supprimer.txt; echo "[$?]"; bash comparer.sh a-supprimer.txt nexiste-pas.txt; echo "[$?]"',
        check: function (r) { return all([r.out.split('\n').filter(function (l) { return l === '[2]'; }).length === 2 || 'les deux appels fautifs doivent finir avec le code 2 (obtenu : ' + r.out.replace(/\n/g, ' ') + ')', /usage/i.test(r.err) || 'pas de message « usage »']); } }
    ],
    hints: [
      { title: 'Comparer deux listes triées', course: 'bash', body: 'comm compare deux fichiers triés : -12 garde les lignes communes, -13 celles qui ne sont que dans le second. Les deux listes doivent être triées de la même façon : sort -u les trie et retire les doublons. Avec <(commande), la sortie d\'une commande sert de fichier à comm, sans fichier temporaire.' },
      { title: 'Rendre les listes comparables', course: 'bash', body: 'La purge affiche des chemins complets, Yanis note « émission/fichier ». Garde les deux derniers morceaux avec awk -F/ \'{ print $(NF-1) "/" $NF }\'. Enlève les retours chariot Windows (tr -d \'\\r\'). Pour compter les lignes non vides : grep -c .' },
      { title: 'La solution', course: 'bash', body: 'purge=$(tr -d \'\\r\' < "$1" | awk -F/ \'NF >= 2 { print $(NF-1) "/" $NF }\' | sort -u)\nperdus=$(tr -d \'\\r\' < "$2" | awk -F/ \'NF >= 2 { print $(NF-1) "/" $NF }\' | sort -u)\nk=$(comm -12 <(echo "$purge") <(echo "$perdus") | grep -c .)\n# puis : k = m → tout correspond ; sinon comm -13 liste le reste, exit 1' }
    ],
    intro: [{ who: 'yanis', expr: 'sourcil', text: 'La liste de ce qui serait supprimé est immense. Je veux savoir si tout ce que j\'ai perdu s\'y trouve : ma liste à moi, c\'est « émission/fichier ».' }],
    done: [{ who: 'yanis', expr: 'surprise', text: 'Tout y est. Ce n\'est pas un hasard : c\'est bien cette purge qui mange mes émissions.' }]
  });

  // ——— E23 · D'où vient $EXPORT_ROOT ? ———
  S.add({
    id: 'E23', act: 3, machine: 'archives',
    views: { conf: { title: 'archives : /etc/radio/chemins.conf', machine: 'archives', cmd: 'cat /etc/radio/chemins.conf' } }
  });
})(RCSteps);
