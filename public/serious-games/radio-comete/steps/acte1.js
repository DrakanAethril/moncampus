/* Acte 1 — Le mot de Marc (jeudi, 10 h 20). Les sauvegardes : guillemets, variables, boucles,
   tests, codes de retour, getopts, trap. Machine : `sauvegarde`.

   Les scripts se suivent : le script de référence d'une étape est le script de départ de la suivante
   (sauvegarde.sh : E02 → E03 → E06 ; rotation.sh : E04 → E05, appelé par sauvegarde.sh à l'E06). */
(function (S) {
  'use strict';
  var B = S.B, DAY = 86400;
  var TODAY = '2026-03-12';

  // ——— The scripts ———
  var SAV_E02 = [
    '#!/bin/bash',
    '# sauvegarde.sh : une archive par émission, rangée dans le dossier de sauvegarde',
    '# (Marc, 2019)',
    'DEST=/srv/sauvegardes',
    'SRC=/srv/emissions',
    'DATE=$(date +%F)',
    '',
    'mkdir -p $DEST',
    'for dossier in $(ls $SRC); do',
    '    tar -czf $DEST/$dossier-$DATE.tgz -C $SRC $dossier',
    '    echo "sauvegardé : $dossier"',
    'done',
    ''
  ].join('\n');

  var SAV_E02_REF = [
    '#!/bin/bash',
    '# sauvegarde.sh : une archive par émission, rangée dans le dossier de sauvegarde',
    '# (Marc, 2019)',
    'DEST=/srv/sauvegardes',
    'SRC=/srv/emissions',
    'DATE=$(date +%F)',
    '',
    'mkdir -p "$DEST"',
    'for dossier in "$SRC"/*; do',
    '    [ -d "$dossier" ] || continue',
    '    nom=$(basename "$dossier")',
    '    tar -czf "$DEST/$nom-$DATE.tgz" -C "$SRC" "$nom"',
    '    echo "sauvegardé : $nom"',
    'done',
    ''
  ].join('\n');

  var SAV_E03 = [
    '#!/bin/bash',
    '# sauvegarde.sh DESTINATION : une archive par émission dans DESTINATION',
    'SRC=/srv/emissions',
    'DATE=$(date +%F)',
    'DEST=$1',
    '',
    'mkdir -p "$DEST"',
    'for dossier in "$SRC"/*; do',
    '    [ -d "$dossier" ] || continue',
    '    nom=$(basename "$dossier")',
    '    tar -czf "$DEST/$nom-$DATE.tgz" -C "$SRC" "$nom"',
    '    echo "sauvegardé : $nom"',
    'done',
    ''
  ].join('\n');

  var SAV_E03_REF = [
    '#!/bin/bash',
    '# sauvegarde.sh DESTINATION : une archive par émission dans DESTINATION',
    'set -u',
    'SRC=/srv/emissions',
    'DATE=$(date +%F)',
    '',
    'if [ $# -ne 1 ] || [ -z "$1" ]; then',
    '    echo "usage : $0 DESTINATION" >&2',
    '    exit 2',
    'fi',
    'DEST=$1',
    '',
    'mkdir -p "$DEST"',
    'for dossier in "$SRC"/*; do',
    '    [ -d "$dossier" ] || continue',
    '    nom=$(basename "$dossier")',
    '    tar -czf "$DEST/$nom-$DATE.tgz" -C "$SRC" "$nom"',
    '    echo "sauvegardé : $nom"',
    'done',
    ''
  ].join('\n');

  var ROT_E04 = [
    '#!/bin/bash',
    '# rotation.sh DESTINATION : ne garder que les 7 archives les plus récentes de chaque émission',
    'DEST=$1',
    'cd $DEST',
    'for f in $(ls *.tgz); do',
    '    nom=${f%-*-*-*.tgz}',
    '    ls -t $nom-*.tgz | head -7 | xargs rm',
    'done',
    ''
  ].join('\n');

  var ROT_BODY = [
    'while IFS= read -r nom; do',
    '    ls -1t -- "$nom"-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].tgz | tail -n +$((GARDER + 1)) | while IFS= read -r vieille; do',
    '        ACTION',
    '    done',
    'done < <(ls -1 | sed -n \'s/-[0-9]\\{4\\}-[0-9][0-9]-[0-9][0-9]\\.tgz$//p\' | sort -u)'
  ].join('\n');

  var ROT_E04_REF = [
    '#!/bin/bash',
    '# rotation.sh DESTINATION : ne garder que les 7 archives les plus récentes de chaque émission',
    'set -euo pipefail',
    'GARDER=7',
    'DEST=${1:?usage : rotation.sh DESTINATION}',
    'cd "$DEST"',
    '',
    ROT_BODY.replace('ACTION', 'rm -f -- "$vieille"'),
    ''
  ].join('\n');

  var ROT_E05 = [
    '#!/bin/bash',
    '# rotation.sh [-n] DESTINATION : ne garder que les 7 archives les plus récentes de chaque émission',
    '#   -n : ne rien supprimer, dire seulement ce qui le serait',
    'set -euo pipefail',
    'GARDER=7',
    '',
    'while getopts n option; do',
    '    case $option in',
    '        n) SIMULATION=1 ;;',
    '    esac',
    'done',
    'DEST=${1:?usage : rotation.sh [-n] DESTINATION}',
    'cd "$DEST"',
    '',
    ROT_BODY.replace('ACTION', 'if [ "$SIMULATION" = 1 ]; then echo "à supprimer : $vieille"; else rm -f -- "$vieille"; fi'),
    ''
  ].join('\n');

  var ROT_E05_REF = [
    '#!/bin/bash',
    '# rotation.sh [-n] DESTINATION : ne garder que les 7 archives les plus récentes de chaque émission',
    '#   -n : ne rien supprimer, dire seulement ce qui le serait',
    'set -euo pipefail',
    'GARDER=7',
    'SIMULATION=0',
    '',
    'while getopts n option; do',
    '    case $option in',
    '        n) SIMULATION=1 ;;',
    '        *) echo "usage : $0 [-n] DESTINATION" >&2; exit 2 ;;',
    '    esac',
    'done',
    'shift $((OPTIND - 1))',
    '',
    'if [ $# -ne 1 ]; then',
    '    echo "usage : $0 [-n] DESTINATION" >&2',
    '    exit 2',
    'fi',
    'DEST=$1',
    'cd "$DEST"',
    '',
    ROT_BODY.replace('ACTION', 'if [ "$SIMULATION" = 1 ]; then echo "à supprimer : $vieille"; else rm -f -- "$vieille"; fi'),
    ''
  ].join('\n');

  var SAV_E06 = [
    '#!/bin/bash',
    '# sauvegarde.sh DESTINATION : une archive par émission dans DESTINATION, puis la rotation',
    'set -u',
    'SRC=/srv/emissions',
    'DATE=$(date +%F)',
    '',
    'if [ $# -ne 1 ] || [ -z "$1" ]; then',
    '    echo "usage : $0 DESTINATION" >&2',
    '    exit 2',
    'fi',
    'DEST=$1',
    '',
    'mkdir -p "$DEST"',
    'for dossier in "$SRC"/*; do',
    '    [ -d "$dossier" ] || continue',
    '    nom=$(basename "$dossier")',
    '    tar -czf "$DEST/$nom-$DATE.tgz" -C "$SRC" "$nom"',
    '    echo "sauvegardé : $nom"',
    'done',
    '',
    'bash "$(dirname "$0")/rotation.sh" "$DEST"',
    ''
  ].join('\n');

  var SAV_E06_REF = [
    '#!/bin/bash',
    '# sauvegarde.sh DESTINATION : une archive par émission dans DESTINATION, puis la rotation',
    'set -u',
    'SRC=/srv/emissions',
    'DATE=$(date +%F)',
    '',
    'if [ $# -ne 1 ] || [ -z "$1" ]; then',
    '    echo "usage : $0 DESTINATION" >&2',
    '    exit 2',
    'fi',
    'DEST=$1',
    '',
    'echec=0',
    'mkdir -p "$DEST"',
    'for dossier in "$SRC"/*; do',
    '    [ -d "$dossier" ] || continue',
    '    nom=$(basename "$dossier")',
    '    tmp="$DEST/.$nom-$DATE.tgz.part"',
    '    if tar -czf "$tmp" -C "$SRC" "$nom"; then',
    '        mv "$tmp" "$DEST/$nom-$DATE.tgz"',
    '        echo "sauvegardé : $nom"',
    '    else',
    '        rm -f "$tmp"',
    '        echo "ÉCHEC : $nom" >&2',
    '        echec=1',
    '    fi',
    'done',
    '',
    '# on ne fait tourner les anciennes copies que si toutes les nouvelles sont là',
    'if [ "$echec" -eq 0 ]; then',
    '    bash "$(dirname "$0")/rotation.sh" "$DEST"',
    'fi',
    'exit "$echec"',
    ''
  ].join('\n');

  var VERIF = [
    '#!/bin/bash',
    '# verifier.sh DESTINATION : vérifie que chaque archive se relit, écrit rapport.txt',
    'DEST=$1',
    'for a in $(ls $DEST/*.tgz); do',
    '    tar -tzf $a',
    '    echo "OK $a" >> rapport.txt',
    'done',
    ''
  ].join('\n');

  var VERIF_REF = [
    '#!/bin/bash',
    '# verifier.sh DESTINATION : vérifie que chaque archive se relit, écrit rapport.txt',
    'set -u',
    'DEST=${1:?usage : verifier.sh DESTINATION}',
    ': > rapport.txt',
    'mauvaises=0',
    'for archive in "$DEST"/*.tgz; do',
    '    [ -e "$archive" ] || continue',
    '    if tar -tzf "$archive" > /dev/null 2>&1; then',
    '        echo "OK $(basename "$archive")" >> rapport.txt',
    '    else',
    '        echo "KO $(basename "$archive")" >> rapport.txt',
    '        mauvaises=$((mauvaises + 1))',
    '    fi',
    'done',
    '[ "$mauvaises" -eq 0 ]',
    ''
  ].join('\n');

  // ——— Shared worlds ———
  function world(m) {
    m.vfs.dir('/srv/emissions'); m.vfs.dir('/srv/sauvegardes');
    B.file(m, '/home/admin/LISEZMOI', 'Serveur de sauvegardes de Radio Comète.\nsauvegarde.sh : le script de Marc (une archive par émission).\nLes archives sont rangées dans /srv/sauvegardes.\n', 40);
  }
  function emissions(m, names) { names.forEach(function (n) { B.emission(m, n, 2); }); }
  function sav(m, dest) { return m.run('ls -1 ' + dest).out.split('\n').filter(Boolean); }
  function lists(m, path) { var r = m.run('tar -tzf "' + path + '"'); return r.status === 0 ? r.out.split('\n').filter(Boolean) : null; }
  function archiveOf(m, dest, nom) {
    var p = dest + '/' + nom + '-' + TODAY + '.tgz';
    if (!m.vfs.isFile(p)) return 'pas d\'archive pour « ' + nom + ' »';
    var l = lists(m, p);
    if (!l || l.indexOf(nom + '/episode-1.wav') === -1) return 'l\'archive de « ' + nom + ' » ne contient pas ses épisodes';
    return true;
  }
  function all(list) { for (var i = 0; i < list.length; i++) if (list[i] !== true) return list[i]; return true; }
  function dated(name, days) { var d = new Date((RCVfs.NOW - days * DAY) * 1000); return name + '-' + d.getUTCFullYear() + '-' + ('0' + (d.getUTCMonth() + 1)).slice(-2) + '-' + ('0' + d.getUTCDate()).slice(-2) + '.tgz'; }
  function archives(m, dir, name, count, from) { for (var i = 0; i < count; i++) B.archive(m, dir, name, (from || 1) + i); }
  function present(m, dir, name, days) { return m.vfs.isFile(dir + '/' + dated(name, days)); }

  // ——— E01 · Le mot et le script ———
  S.add({
    id: 'E01', act: 1, machine: 'sauvegarde',
    views: { script: { title: 'sauvegarde.sh', lines: SAV_E02.replace(/\n$/, '').split('\n') } }
  });

  // ——— E02 · « Émission du matin » ———
  S.add({
    id: 'E02', act: 1, machine: 'sauvegarde', edit: ['sauvegarde.sh'], start: SAV_E02, ref: SAV_E02_REF,
    show: [],
    base: function (m) { world(m); },
    cases: [
      { id: 'vis', visible: true, label: '« Émission du matin » et « jazz-de-nuit » ont chacune leur archive du jour',
        setup: function (m) { emissions(m, ['Émission du matin', 'jazz-de-nuit']); }, run: 'bash sauvegarde.sh',
        check: function (r, m) { return all([r.status === 0 || 'le script se termine en erreur : ' + r.err.split('\n')[0], archiveOf(m, '/srv/sauvegardes', 'Émission du matin'), archiveOf(m, '/srv/sauvegardes', 'jazz-de-nuit')]); } },
      { id: 'apostrophe', label: 'un nom avec une apostrophe et des accents',
        setup: function (m) { emissions(m, ['L\'heure bleue', 'le-grand-bal']); }, run: 'bash sauvegarde.sh',
        check: function (r, m) { return all([archiveOf(m, '/srv/sauvegardes', 'L\'heure bleue'), archiveOf(m, '/srv/sauvegardes', 'le-grand-bal')]); } },
      { id: 'espaces', label: 'un nom avec plusieurs espaces',
        setup: function (m) { emissions(m, ['Nuit  blanche - spéciale']); }, run: 'bash sauvegarde.sh',
        check: function (r, m) { return archiveOf(m, '/srv/sauvegardes', 'Nuit  blanche - spéciale'); } },
      { id: 'vide', label: 'aucune émission : pas d\'archive fantôme',
        setup: function () { }, run: 'bash sauvegarde.sh',
        check: function (r, m) { return all([r.status === 0 || 'le script échoue alors qu\'il n\'y a rien à faire', sav(m, '/srv/sauvegardes').length === 0 || 'une archive a été créée alors qu\'il n\'y a aucune émission : ' + sav(m, '/srv/sauvegardes').join(', ')]); } },
      { id: 'fichier', label: 'un fichier isolé dans le dossier des émissions',
        setup: function (m) { emissions(m, ['la-matinale']); B.file(m, '/srv/emissions/LISEZMOI.txt', 'notes\n', 3); }, run: 'bash sauvegarde.sh',
        check: function (r, m) { return all([archiveOf(m, '/srv/sauvegardes', 'la-matinale'), sav(m, '/srv/sauvegardes').length === 1 || 'seules les émissions (les dossiers) doivent être sauvegardées : ' + sav(m, '/srv/sauvegardes').join(', ')]); } }
    ],
    hints: [
      { title: 'Les noms avec des espaces', course: 'bash', body: 'Le shell coupe un mot en deux à chaque espace, sauf s\'il est entre guillemets. « Émission du matin » devient trois arguments. Mets des guillemets doubles autour de chaque variable : "$dossier", "$DEST/$nom-$DATE.tgz".' },
      { title: 'Ne pas parser la sortie de ls', course: 'bash', body: 'for dossier in $(ls $SRC) coupe aussi les noms à chaque espace. Laisse le shell développer lui-même la liste : for dossier in "$SRC"/*; do … done. Quand le dossier est vide, le motif reste tel quel : teste [ -d "$dossier" ] || continue.' },
      { title: 'La solution', course: 'bash', body: 'for dossier in "$SRC"/*; do\n    [ -d "$dossier" ] || continue\n    nom=$(basename "$dossier")\n    tar -czf "$DEST/$nom-$DATE.tgz" -C "$SRC" "$nom"\ndone' }
    ],
    intro: [{ who: 'yanis', expr: 'sourcil', text: 'Ma matinale n\'est pas dans la sauvegarde. Les noms avec des espaces, le script ne les digère pas.' }],
    done: [{ who: 'yanis', expr: 'sourire', text: 'Je retrouve « Émission du matin » dans la liste. Merci !' }]
  });

  // ——— E03 · La destination oubliée ———
  S.add({
    id: 'E03', act: 1, machine: 'sauvegarde', edit: ['sauvegarde.sh'], start: SAV_E03, ref: SAV_E03_REF,
    base: function (m) { world(m); B.file(m, '/home/admin/sauvegarde.sh', SAV_E03, 5, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'lancé sans destination, le script le dit, s\'arrête, et n\'écrit rien',
        setup: function (m) { emissions(m, ['la-matinale']); }, run: 'bash sauvegarde.sh',
        check: function (r, m) { return all([r.status === 2 || 'le code de retour doit être 2 (erreur d\'usage), pas ' + r.status, /usage/i.test(r.err) || 'pas de message « usage » sur la sortie d\'erreur', m.vfs.list('/').filter(function (n) { return /\.tgz$/.test(n); }).length === 0 || 'des archives sont tombées dans / : ' + m.vfs.list('/').filter(function (n) { return /\.tgz$/.test(n); }).join(', ')]); } },
      { id: 'vide', label: 'une destination vide ("") est refusée comme une destination absente',
        setup: function (m) { emissions(m, ['la-matinale']); }, run: 'bash sauvegarde.sh ""',
        check: function (r, m) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, m.vfs.list('/').filter(function (n) { return /\.tgz$/.test(n); }).length === 0 || 'des archives sont tombées dans /']); } },
      { id: 'normal', label: 'avec une destination, les archives sont faites',
        setup: function (m) { emissions(m, ['la-matinale', 'jazz-de-nuit']); }, run: 'bash sauvegarde.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], archiveOf(m, '/srv/sauvegardes', 'la-matinale'), archiveOf(m, '/srv/sauvegardes', 'jazz-de-nuit')]); } },
      { id: 'espace', label: 'une destination avec une espace est créée au besoin',
        setup: function (m) { emissions(m, ['la-matinale']); }, run: 'bash sauvegarde.sh "/srv/mes sauvegardes"',
        check: function (r, m) { return archiveOf(m, '/srv/mes sauvegardes', 'la-matinale'); } },
      { id: 'deux', label: 'deux arguments : usage, et rien n\'est écrit',
        setup: function (m) { emissions(m, ['la-matinale']); }, run: 'bash sauvegarde.sh /srv/sauvegardes /srv/autre',
        check: function (r, m) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, sav(m, '/srv/sauvegardes').length === 0 || 'une archive a été écrite malgré les deux arguments']); } }
    ],
    hints: [
      { title: 'Une variable vide fait de gros dégâts', course: 'bash', body: 'Sans argument, $1 est vide : "$DEST/$nom.tgz" devient "/nom.tgz", donc la racine du disque. Il faut refuser de travailler tant que la destination n\'est pas donnée. Teste le nombre d\'arguments ($#) et le contenu de $1 ([ -z "$1" ]).' },
      { title: 'Message d\'usage et code de retour', course: 'bash', body: 'Par convention, un script mal appelé écrit « usage : … » sur la sortie d\'erreur (>&2) et sort avec le code 2 : exit 2.' },
      { title: 'La solution', course: 'bash', body: 'set -u\nif [ $# -ne 1 ] || [ -z "$1" ]; then\n    echo "usage : $0 DESTINATION" >&2\n    exit 2\nfi\nDEST=$1' }
    ],
    intro: [{ who: 'odile', expr: 'surprise', text: 'Quelqu\'un a lancé le script sans rien derrière. Il y a des archives à la racine du disque !' }],
    done: [{ who: 'colette', expr: 'sourire', text: 'Un script qui refuse de travailler à l\'aveugle : c\'est le début de la sagesse.' }]
  });

  // ——— E04 · Garder les sept dernières ———
  function rotWorld(m) { world(m); B.file(m, '/home/admin/rotation.sh', ROT_E04, 5, 493); }
  S.add({
    id: 'E04', act: 1, machine: 'sauvegarde', edit: ['rotation.sh'], start: ROT_E04, ref: ROT_E04_REF,
    base: rotWorld,
    cases: [
      { id: 'vis', visible: true, label: '« la-matinale » a dix archives : il en reste les sept plus récentes',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 10); }, run: 'bash rotation.sh /srv/sauvegardes',
        check: function (r, m) {
          var bad = [];
          for (var d = 1; d <= 10; d++) if (present(m, '/srv/sauvegardes', 'la-matinale', d) !== (d <= 7)) bad.push(d <= 7 ? 'la copie d\'il y a ' + d + ' jours a disparu' : 'la copie d\'il y a ' + d + ' jours est restée');
          return r.status === 0 && !bad.length ? true : (bad[0] || 'code de retour ' + r.status);
        } },
      { id: 'peu', label: 'trois archives seulement : aucune n\'est supprimée',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 3); }, run: 'bash rotation.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 0 || 'échec avec peu d\'archives : ' + r.err.split('\n')[0], sav(m, '/srv/sauvegardes').length === 3 || 'il devait rester 3 archives']); } },
      { id: 'rien', label: 'un dossier sans aucune archive : pas d\'erreur',
        setup: function () { }, run: 'bash rotation.sh /srv/sauvegardes',
        check: function (r) { return r.status === 0 || 'échec quand il n\'y a rien à ranger : ' + r.err.split('\n')[0]; } },
      { id: 'prefixe', label: 'deux émissions dont l\'une commence comme l\'autre (« jazz » et « jazz-de-nuit »)',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'jazz', 9); archives(m, '/srv/sauvegardes', 'jazz-de-nuit', 3); }, run: 'bash rotation.sh /srv/sauvegardes',
        check: function (r, m) {
          var j = sav(m, '/srv/sauvegardes').filter(function (n) { return /^jazz-\d{4}/.test(n); }).length, jn = sav(m, '/srv/sauvegardes').filter(function (n) { return /^jazz-de-nuit/.test(n); }).length;
          return j === 7 && jn === 3 ? true : '« jazz » : ' + j + ' archives (7 attendues), « jazz-de-nuit » : ' + jn + ' (3 attendues)';
        } },
      { id: 'espaces', label: 'une émission avec des espaces dans son nom',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'Émission du matin', 9); }, run: 'bash rotation.sh /srv/sauvegardes',
        check: function (r, m) { var n = sav(m, '/srv/sauvegardes').length; return n === 7 ? true : n + ' archives restent au lieu de 7'; } },
      { id: 'autres', label: 'les fichiers qui ne sont pas des archives du jour ne sont pas touchés',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 9); B.file(m, '/srv/sauvegardes/LISEZMOI.txt', 'ne pas effacer\n', 90); B.file(m, '/srv/sauvegardes/notes.tgz', 'GZ1\nTAR1\n[]', 80); }, run: 'bash rotation.sh /srv/sauvegardes',
        check: function (r, m) { return all([m.vfs.isFile('/srv/sauvegardes/LISEZMOI.txt') || 'LISEZMOI.txt a été supprimé', m.vfs.isFile('/srv/sauvegardes/notes.tgz') || 'notes.tgz a été supprimé']); } }
    ],
    hints: [
      { title: 'Les sept plus récentes', course: 'bash', body: 'ls -t trie du plus récent au plus ancien. head -7 garde les sept premières lignes : c\'est exactement ce qu\'on veut conserver, pas supprimer. Pour supprimer le reste : tail -n +8, qui commence à la huitième ligne.' },
      { title: 'Chaque émission son tour', course: 'bash', body: 'Les archives s\'appellent nom-AAAA-MM-JJ.tgz. Retire le suffixe avec sed (ou ${f%-*-*-*.tgz}), garde les noms distincts (sort -u), puis traite chaque nom. Attention au motif : nom-*.tgz attrape aussi « nom-de-nuit-… » ; le motif nom-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].tgz est précis.' },
      { title: 'La solution', course: 'bash', body: 'while IFS= read -r nom; do\n    ls -1t -- "$nom"-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].tgz | tail -n +8 | while IFS= read -r vieille; do\n        rm -f -- "$vieille"\n    done\ndone < <(ls -1 | sed -n \'s/-[0-9]\\{4\\}-[0-9][0-9]-[0-9][0-9]\\.tgz$//p\' | sort -u)' }
    ],
    intro: [{ who: 'odile', expr: 'sourcil', text: 'Le disque des sauvegardes se remplit : personne ne supprime les vieilles archives. Marc avait écrit quelque chose, mais il supprime les mauvaises.' }],
    done: [{ who: 'colette', expr: 'sourire', text: 'Sept jours de recul par émission, pas un de plus. Voilà un disque qui respire.' }]
  });

  // ——— E05 · À blanc ———
  S.add({
    id: 'E05', act: 1, machine: 'sauvegarde', edit: ['rotation.sh'], start: ROT_E05, ref: ROT_E05_REF,
    base: function (m) { world(m); B.file(m, '/home/admin/rotation.sh', ROT_E05, 5, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'avec -n, le script annonce les trois suppressions de « la-matinale » et ne supprime rien',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 10); }, run: 'bash rotation.sh -n /srv/sauvegardes',
        check: function (r, m) {
          var annonces = r.out.split('\n').filter(function (l) { return /^à supprimer : /.test(l); });
          return all([r.status === 0 || 'code de retour ' + r.status + ' : ' + r.err.split('\n')[0], annonces.length === 3 || annonces.length + ' lignes « à supprimer : … » au lieu de 3', sav(m, '/srv/sauvegardes').length === 10 || 'des archives ont été supprimées malgré -n']);
        } },
      { id: 'reel', label: 'sans -n, les suppressions ont lieu',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 10); }, run: 'bash rotation.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 0 || 'échec sans -n : ' + r.err.split('\n')[0], sav(m, '/srv/sauvegardes').length === 7 || sav(m, '/srv/sauvegardes').length + ' archives restent au lieu de 7', r.out.indexOf('à supprimer') === -1 || 'le mode à blanc parle alors qu\'on ne l\'a pas demandé']); } },
      { id: 'option', label: 'une option inconnue : usage, code 2, rien de supprimé',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 10); }, run: 'bash rotation.sh -x /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, sav(m, '/srv/sauvegardes').length === 10 || 'des archives ont été supprimées']); } },
      { id: 'rien', label: 'rien à supprimer : le script ne dit rien et réussit',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 4); }, run: 'bash rotation.sh -n /srv/sauvegardes',
        check: function (r) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], r.out.trim() === '' || 'le script annonce des suppressions alors qu\'il n\'y a rien à supprimer']); } },
      { id: 'sans', label: 'sans destination : usage et code 2',
        setup: function () { }, run: 'bash rotation.sh',
        check: function (r) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, /usage/i.test(r.err) || 'pas de message « usage »']); } },
      { id: 'espaces', label: 'une destination avec une espace et le mode à blanc',
        setup: function (m) { m.vfs.dir('/srv/mes sauvegardes'); archives(m, '/srv/mes sauvegardes', 'la-matinale', 9); }, run: 'bash rotation.sh -n "/srv/mes sauvegardes"',
        check: function (r, m) { return all([r.out.split('\n').filter(function (l) { return /^à supprimer/.test(l); }).length === 2 || 'il fallait annoncer 2 suppressions', m.vfs.list('/srv/mes sauvegardes').length === 9 || 'des archives ont été supprimées']); } }
    ],
    hints: [
      { title: 'getopts lit les options, pas les arguments', course: 'bash', body: 'getopts parcourt les options (-n) une à une et laisse $1 inchangé. Quand elle a fini, $OPTIND indique le premier argument qui n\'est pas une option : shift $((OPTIND - 1)) fait disparaître les options, et $1 devient la destination.' },
      { title: 'Une valeur par défaut avant la boucle', course: 'bash', body: 'Avec set -u, une variable jamais affectée fait échouer le script. Donne SIMULATION=0 avant la boucle, et traite les options inconnues (le cas *) ou \\?) par un message d\'usage et exit 2.' },
      { title: 'La solution', course: 'bash', body: 'SIMULATION=0\nwhile getopts n option; do\n    case $option in\n        n) SIMULATION=1 ;;\n        *) echo "usage : $0 [-n] DESTINATION" >&2; exit 2 ;;\n    esac\ndone\nshift $((OPTIND - 1))' }
    ],
    intro: [{ who: 'yanis', expr: 'sourcil', text: 'Je n\'ose pas lancer la rotation si je ne sais pas ce qu\'elle va supprimer. Tu peux faire en sorte qu\'on voie avant ?' }],
    done: [{ who: 'yanis', expr: 'sourire', text: 'Là, je lance. Je regarde la liste, et seulement ensuite je confirme.' }]
  });

  // ——— E06 · Un code de retour qui dit vrai ———
  function fullDisk(m, emis, cap) { m.vfs.mount('/srv/sauvegardes', cap || 10e6); emis.forEach(function (e) { if (typeof e === 'string') B.emission(m, e, 2); else B.big(m, '/srv/emissions/' + e.name + '/enregistrement.wav', e.bytes, 2); }); }
  function leftovers(m) { return m.vfs.list('/srv/sauvegardes').filter(function (n) { return /\.part$/.test(n); }); }
  S.add({
    id: 'E06', act: 1, machine: 'sauvegarde', edit: ['sauvegarde.sh'], start: SAV_E06, ref: SAV_E06_REF,
    show: [],
    base: function (m) { world(m); B.file(m, '/home/admin/rotation.sh', ROT_E05_REF, 5, 493); B.file(m, '/home/admin/sauvegarde.sh', SAV_E06, 5, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'disque plein : le script échoue, ne laisse pas d\'archive à moitié écrite et ne touche pas aux anciennes copies',
        setup: function (m) { fullDisk(m, [{ name: 'la-matinale', bytes: 40e6 }]); archives(m, '/srv/sauvegardes', 'la-matinale', 8, 1); }, run: 'bash sauvegarde.sh /srv/sauvegardes',
        check: function (r, m) {
          var tgz = sav(m, '/srv/sauvegardes').filter(function (n) { return /\.tgz$/.test(n); });
          return all([r.status !== 0 || 'le script réussit alors que l\'archive n\'a pas pu être écrite', r.out.indexOf('sauvegardé : la-matinale') === -1 || 'il annonce « sauvegardé » pour une archive qui n\'existe pas', !m.vfs.isFile('/srv/sauvegardes/la-matinale-' + TODAY + '.tgz') || 'une archive du jour existe alors que l\'écriture a échoué', leftovers(m).length === 0 && m.run('ls -a /srv/sauvegardes').out.indexOf('.part') === -1 || 'un fichier .part est resté sur le disque', tgz.length === 8 || 'les anciennes copies ont été supprimées (' + tgz.length + ' restent sur 8) alors que la nouvelle n\'existe pas']);
        } },
      { id: 'normal', label: 'tout va bien : code 0, archives du jour, rotation faite',
        setup: function (m) { B.emission(m, 'la-matinale', 2); archives(m, '/srv/sauvegardes', 'la-matinale', 9, 1); }, run: 'bash sauvegarde.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], archiveOf(m, '/srv/sauvegardes', 'la-matinale'), sav(m, '/srv/sauvegardes').length === 7 || sav(m, '/srv/sauvegardes').length + ' archives après la rotation au lieu de 7']); } },
      { id: 'moitie', label: 'une émission sur deux ne tient pas sur le disque : l\'autre est sauvegardée, l\'échec est signalé',
        setup: function (m) { fullDisk(m, ['jazz-de-nuit', { name: 'la-grosse', bytes: 40e6 }]); }, run: 'bash sauvegarde.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status !== 0 || 'le script réussit alors qu\'une émission n\'a pas été sauvegardée', archiveOf(m, '/srv/sauvegardes', 'jazz-de-nuit'), /la-grosse/.test(r.err) || 'l\'émission en échec n\'est pas nommée sur la sortie d\'erreur', leftovers(m).length === 0 || 'un fichier .part est resté']); } },
      { id: 'espace', label: 'une émission avec une espace et un disque correct',
        setup: function (m) { B.emission(m, 'Émission du matin', 2); }, run: 'bash sauvegarde.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], archiveOf(m, '/srv/sauvegardes', 'Émission du matin')]); } },
      { id: 'usage', label: 'sans destination : usage et code 2',
        setup: function () { }, run: 'bash sauvegarde.sh',
        check: function (r) { return r.status === 2 ? true : 'le code de retour doit rester 2 (usage), pas ' + r.status; } },
      { id: 'rien', label: 'aucune émission : code 0, rien d\'écrit',
        setup: function () { }, run: 'bash sauvegarde.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], sav(m, '/srv/sauvegardes').length === 0 || 'quelque chose a été écrit']); } }
    ],
    hints: [
      { title: 'Un échec doit se voir', course: 'bash', body: 'Une commande qui échoue renvoie un code non nul, mais le script continue et sort avec le code de sa dernière commande. Teste chaque tar : if tar … ; then … else … fi. Garde un compteur d\'échecs et sors avec exit "$echec" à la fin.' },
      { title: 'Écrire sous un nom provisoire', course: 'bash', body: 'Écris l\'archive sous un nom provisoire (.nom-date.tgz.part) et ne la renomme (mv) qu\'une fois tar terminé avec succès. En cas d\'échec : rm -f du fichier provisoire. Ne lance la rotation qu\'après un sans-faute : supprimer des copies anciennes quand la nouvelle manque, c\'est perdre des données.' },
      { title: 'La solution', course: 'bash', body: 'echec=0\n…\n    tmp="$DEST/.$nom-$DATE.tgz.part"\n    if tar -czf "$tmp" -C "$SRC" "$nom"; then\n        mv "$tmp" "$DEST/$nom-$DATE.tgz"\n        echo "sauvegardé : $nom"\n    else\n        rm -f "$tmp"; echo "ÉCHEC : $nom" >&2; echec=1\n    fi\n…\nif [ "$echec" -eq 0 ]; then bash "$(dirname "$0")/rotation.sh" "$DEST"; fi\nexit "$echec"' }
    ],
    intro: [{ who: 'odile', expr: 'surprise', text: 'Ce matin, le disque était plein, le script a dit « sauvegardé » et la rotation a quand même supprimé de vieilles copies. On n\'a plus rien de propre pour la matinale de lundi.' }],
    done: [{ who: 'colette', expr: 'sourire', text: 'Un script qui dit la vérité : « ÉCHEC » quand c\'est un échec. C\'est ce qu\'on attend d\'un administrateur.' }]
  });

  // ——— E07 · Vérifier une sauvegarde ———
  S.add({
    id: 'E07', act: 1, machine: 'sauvegarde', edit: ['verifier.sh'], start: VERIF, ref: VERIF_REF,
    base: function (m) { world(m); B.file(m, '/home/admin/verifier.sh', VERIF, 5, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'trois bonnes archives et une corrompue : rapport.txt dit lesquelles, le code de retour est 1',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 3); B.file(m, '/srv/sauvegardes/jazz-de-nuit-2026-03-01.tgz', 'ceci n\'est pas une archive\n', 11); }, run: 'bash verifier.sh /srv/sauvegardes',
        check: function (r, m) { var rap = (m.vfs.isFile('/home/admin/rapport.txt') ? m.vfs.read('/home/admin/rapport.txt') : '').split('\n').filter(Boolean).sort();
          return all([r.status === 1 || 'le code de retour doit être 1, pas ' + r.status, rap.length === 4 || 'rapport.txt devrait avoir 4 lignes, il en a ' + rap.length, rap.filter(function (l) { return /^OK /.test(l); }).length === 3 || 'trois lignes OK attendues', rap.indexOf('KO jazz-de-nuit-2026-03-01.tgz') !== -1 || 'la ligne « KO jazz-de-nuit-2026-03-01.tgz » manque (le nom seul, sans le dossier)', r.out.trim() === '' || 'le script bavarde : la liste des fichiers de tar ne doit pas s\'afficher']); } },
      { id: 'sains', label: 'toutes les archives sont bonnes : code 0',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 2); }, run: 'bash verifier.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 0 || 'code de retour ' + r.status + ' alors que tout est sain', m.vfs.read('/home/admin/rapport.txt').split('\n').filter(Boolean).length === 2 || 'rapport.txt devrait avoir 2 lignes']); } },
      { id: 'aucune', label: 'aucune archive : rapport vide, code 0, pas d\'erreur',
        setup: function () { }, run: 'bash verifier.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 0 || 'échec quand il n\'y a aucune archive', (m.vfs.isFile('/home/admin/rapport.txt') && m.vfs.read('/home/admin/rapport.txt') === '') || 'rapport.txt doit exister et être vide']); } },
      { id: 'espaces', label: 'une archive dont le nom contient des espaces',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'Émission du matin', 2); }, run: 'bash verifier.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], m.vfs.read('/home/admin/rapport.txt').split('\n').filter(Boolean).every(function (l) { return /^OK Émission du matin-/.test(l); }) || 'les lignes du rapport sont fausses']); } },
      { id: 'ancien', label: 'un ancien rapport.txt est remplacé, pas complété',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 2); B.file(m, '/home/admin/rapport.txt', 'KO ancien\nKO ancien\n', 5); }, run: 'bash verifier.sh /srv/sauvegardes',
        check: function (r, m) { return m.vfs.read('/home/admin/rapport.txt').indexOf('ancien') === -1 ? true : 'l\'ancien contenu de rapport.txt est resté'; } },
      { id: 'vide', label: 'un fichier .tgz vide compte comme corrompu',
        setup: function (m) { archives(m, '/srv/sauvegardes', 'la-matinale', 1); B.file(m, '/srv/sauvegardes/jazz-2026-03-02.tgz', '', 10); }, run: 'bash verifier.sh /srv/sauvegardes',
        check: function (r, m) { return all([r.status === 1 || 'le code de retour doit être 1, pas ' + r.status, m.vfs.read('/home/admin/rapport.txt').indexOf('KO jazz-2026-03-02.tgz') !== -1 || 'l\'archive vide doit être marquée KO']); } },
      { id: 'sans', label: 'sans destination : le script s\'arrête avec un message',
        setup: function () { }, run: 'bash verifier.sh',
        check: function (r) { return r.status !== 0 && r.err.trim() !== '' ? true : 'sans destination, le script doit échouer en le disant'; } }
    ],
    hints: [
      { title: 'Savoir si une archive se relit', course: 'bash', body: 'tar -tzf ARCHIVE liste le contenu et renvoie un code non nul si l\'archive est illisible. Dans un if, le code de retour suffit : if tar -tzf "$archive" > /dev/null 2>&1; then … else … fi. Les > /dev/null 2>&1 font taire la commande.' },
      { title: 'Le rapport et le code de retour', course: 'bash', body: 'Vide d\'abord le rapport (: > rapport.txt) pour ne pas empiler les anciens résultats. Compte les archives mauvaises. La dernière ligne du script peut être un test : [ "$mauvaises" -eq 0 ] — son code de retour devient celui du script.' },
      { title: 'La solution', course: 'bash', body: ': > rapport.txt\nmauvaises=0\nfor archive in "$DEST"/*.tgz; do\n    [ -e "$archive" ] || continue\n    if tar -tzf "$archive" > /dev/null 2>&1; then\n        echo "OK $(basename "$archive")" >> rapport.txt\n    else\n        echo "KO $(basename "$archive")" >> rapport.txt\n        mauvaises=$((mauvaises + 1))\n    fi\ndone\n[ "$mauvaises" -eq 0 ]' }
    ],
    intro: [{ who: 'odile', expr: 'neutre', text: 'Une sauvegarde qu\'on n\'a jamais relue n\'existe pas. Je veux un rapport : quelles archives se lisent, lesquelles non.' }],
    done: [{ who: 'odile', expr: 'sourire', text: 'Un fichier OK / KO, je sais le lire. Merci.' }]
  });

  // ——— E08 · Que dit le journal ? ———
  var AUDIT = [
    '2026-03-12 02:30:01 exec    comm=bash     ppid=cron   /usr/local/bin/export-emissions',
    '2026-03-12 02:30:04 write   /srv/data/exports/matin-2026-03-12.wav',
    '2026-03-12 02:30:09 write   /srv/data/exports/jazz-2026-03-12.wav',
    '2026-03-12 03:00:01 exec    comm=bash     ppid=cron',
    '2026-03-12 03:00:01 exec    comm=find     ppid=bash',
    '2026-03-12 03:00:02 unlink  /srv/data/exports/matin-2026-02-02.wav  comm=find',
    '2026-03-12 03:00:02 unlink  /srv/data/archives/2019/la-matinale/2019-11-04.wav  comm=find',
    '2026-03-12 03:00:02 unlink  /srv/data/archives/2019/la-matinale/2019-11-11.wav  comm=find',
    '2026-03-12 03:00:03 unlink  /srv/data/archives/2019/jazz-de-nuit/2019-12-02.wav  comm=find',
    '2026-03-12 03:00:03 unlink  /srv/data/archives/2019/le-grand-bal/2019-12-14.wav  comm=find',
    '2026-03-12 03:00:04 exec    comm=bash     ppid=cron   (sauvegarde-locale suivante)',
    '2026-03-12 07:58:12 open    /srv/data/archives/2020/la-matinale/2020-01-06.wav  comm=samba',
    '2026-03-12 09:41:30 open    /srv/data/archives/2025/la-matinale/2025-12-01.wav  comm=samba'
  ];
  S.add({
    id: 'E08', act: 1, machine: 'archives', machines: ['archives', 'sauvegarde'],
    views: { audit: { title: 'archives : /var/log/audit.log (extrait de ce matin)', lines: AUDIT } }
  });
})(RCSteps);
