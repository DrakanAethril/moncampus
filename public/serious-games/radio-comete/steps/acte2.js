/* Acte 2 — Les douze crontabs (jeudi, 11 h). Boucles sur des hôtes, ssh, codes de retour, comm, sort,
   uniq, awk, sed, getopts. Machine : `regie`, d'où l'on atteint les douze serveurs.

   Les scripts se suivent : rapatrier.sh (E10 → E11), ecart.sh (E12), rares.sh (E13), normaliser.sh (E14),
   puis compare-crontabs.sh (E16) qui les assemble. Les crontabs rapatriées vont dans crontabs/<hôte>.cron. */
(function (S) {
  'use strict';
  var B = S.B;
  var HEAD = '# m h dom mon dow command\n';
  var PING = '*/15 * * * * /usr/local/bin/supervision-ping';
  var SAUV = '30 2 * * * /usr/local/bin/sauvegarde-locale';
  var NETTOYAGE = '0 3 * * * /usr/local/bin/nettoyage-nuit';

  // ——— The scripts ———
  var RAPATRIER_E10 = [
    '#!/bin/bash',
    '# rapatrier.sh : copie la crontab de chaque serveur dans crontabs/',
    'for h in $(cat hotes.txt); do',
    '    ssh $h crontab -l > crontabs/cron',
    'done',
    ''
  ].join('\n');
  var RAPATRIER_E10_REF = [
    '#!/bin/bash',
    '# rapatrier.sh : copie la crontab de chaque serveur dans crontabs/',
    'mkdir -p crontabs',
    'while read -r h || [ -n "$h" ]; do',
    '    [ -n "$h" ] || continue',
    '    ssh "$h" crontab -l > "crontabs/$h.cron"',
    'done < hotes.txt',
    ''
  ].join('\n');
  var RAPATRIER_E11 = [
    '#!/bin/bash',
    '# rapatrier.sh : copie la crontab de chaque serveur dans crontabs/',
    'set -e',
    'mkdir -p crontabs',
    'while read -r h || [ -n "$h" ]; do',
    '    [ -n "$h" ] || continue',
    '    ssh "$h" crontab -l > "crontabs/$h.cron"',
    'done < hotes.txt',
    ''
  ].join('\n');
  var RAPATRIER_E11_REF = [
    '#!/bin/bash',
    '# rapatrier.sh : copie la crontab de chaque serveur dans crontabs/',
    '# code de retour : 0 si tous les serveurs ont répondu, 1 si au moins un est injoignable',
    'mkdir -p crontabs',
    ': > injoignables.txt',
    'while read -r h || [ -n "$h" ]; do',
    '    [ -n "$h" ] || continue',
    '    tmp="crontabs/$h.cron.tmp"',
    '    ssh "$h" crontab -l > "$tmp" 2> /dev/null',
    '    code=$?',
    '    if [ "$code" -eq 255 ]; then',
    '        rm -f "$tmp" "crontabs/$h.cron"',
    '        echo "$h" >> injoignables.txt',
    '    else',
    '        mv "$tmp" "crontabs/$h.cron"',
    '    fi',
    'done < hotes.txt',
    '[ ! -s injoignables.txt ]',
    ''
  ].join('\n');

  var ECART = [
    '#!/bin/bash',
    '# ecart.sh A B : les lignes qui sont dans B et pas dans A',
    'diff $1 $2 | grep \'^>\'',
    ''
  ].join('\n');
  var ECART_REF = [
    '#!/bin/bash',
    '# ecart.sh A B : les lignes qui sont dans B et pas dans A',
    'if [ $# -ne 2 ]; then',
    '    echo "usage : $0 A B" >&2',
    '    exit 2',
    'fi',
    'for f in "$1" "$2"; do',
    '    if [ ! -r "$f" ]; then',
    '        echo "$0 : $f illisible" >&2',
    '        exit 2',
    '    fi',
    'done',
    'comm -13 <(sort -u "$1") <(sort -u "$2")',
    ''
  ].join('\n');

  var RARES = [
    '#!/bin/bash',
    '# rares.sh : les lignes portées par un seul serveur',
    'cat crontabs/*.cron | sort | uniq -c | awk \'$1 == 1 {print $0}\'',
    ''
  ].join('\n');
  function raresRef(dirExpr) {
    return [
      '#!/bin/bash',
      '# rares.sh' + (dirExpr === 'crontabs' ? '' : ' [DOSSIER]') + ' : les lignes portées par un seul serveur, avec le nom du serveur',
      'for f in ' + (dirExpr === 'crontabs' ? 'crontabs' : '"${1:-crontabs}"') + '/*.cron; do',
      '    [ -e "$f" ] || continue',
      '    h=$(basename "$f" .cron)',
      '    sort -u "$f" | while IFS= read -r ligne; do',
      '        printf \'%s\\t%s\\n\' "$ligne" "$h"',
      '    done',
      'done | sort | awk -F\'\\t\' \'{ n[$1]++; h[$1] = $2 } END { for (l in n) if (n[l] == 1) printf "%s: %s\\n", h[l], l }\' | sort',
      ''
    ].join('\n');
  }
  var RARES_REF = raresRef('crontabs');
  var RARES_DIR = raresRef('dir');

  var NORMALISER = [
    '#!/bin/bash',
    '# normaliser.sh FICHIER : la crontab sans bruit',
    'grep -v \'^#\' $1 | grep -v \'^$\'',
    ''
  ].join('\n');
  var NORMALISER_REF = [
    '#!/bin/bash',
    '# normaliser.sh FICHIER : la crontab sans commentaire, sans ligne vide, sans fin de ligne Windows',
    '[ $# -eq 1 ] && [ -r "$1" ] || { echo "usage : $0 FICHIER" >&2; exit 2; }',
    'tr -d \'\\r\' < "$1" | sed -E -e \'s/[[:space:]]+/ /g\' -e \'s/^ //\' -e \'s/ $//\' -e \'/^#/d\' -e \'/^$/d\'',
    ''
  ].join('\n');

  var COMPARE = [
    '#!/bin/bash',
    '# compare-crontabs.sh [-q] [-o FICHIER] : le rapport d\'Odile sur les crontabs des douze serveurs',
    'while getopts qo: option; do',
    '    case $option in',
    '        q) SILENCE=1 ;;',
    '        o) SORTIE=$OPTARG ;;',
    '    esac',
    'done',
    '',
    'bash rapatrier.sh',
    'bash rares.sh crontabs > rapport.tmp',
    'cat rapport.tmp',
    ''
  ].join('\n');
  var COMPARE_REF = [
    '#!/bin/bash',
    '# compare-crontabs.sh [-q] [-o FICHIER] : le rapport d\'Odile sur les crontabs des douze serveurs',
    '# code de retour : 0 si tous les serveurs ont répondu, 1 si l\'un d\'eux est injoignable, 2 si l\'appel est mauvais',
    'set -u',
    'SILENCE=0',
    'SORTIE=""',
    'while getopts qo: option; do',
    '    case $option in',
    '        q) SILENCE=1 ;;',
    '        o) SORTIE=$OPTARG ;;',
    '        *) echo "usage : $0 [-q] [-o FICHIER]" >&2; exit 2 ;;',
    '    esac',
    'done',
    'shift $((OPTIND - 1))',
    'if [ $# -ne 0 ]; then',
    '    echo "usage : $0 [-q] [-o FICHIER]" >&2',
    '    exit 2',
    'fi',
    '',
    'propres=$(mktemp -d)',
    'trap \'rm -rf "$propres"\' EXIT',
    '',
    'bash rapatrier.sh',
    'echec=$?',
    'for f in crontabs/*.cron; do',
    '    [ -e "$f" ] || continue',
    '    bash normaliser.sh "$f" > "$propres/$(basename "$f")"',
    'done',
    '',
    'lus=$(ls crontabs/*.cron 2> /dev/null | wc -l)',
    'total=$(grep -c . hotes.txt)',
    'injoignables=$(paste -sd, injoignables.txt | sed \'s/,/, /g\')',
    'rares=$(bash rares.sh "$propres")',
    '',
    'rapport=$(',
    '    echo "Rapport des crontabs du $(date +%F)"',
    '    echo "Serveurs lus : $lus sur $total"',
    '    echo "Injoignables : ${injoignables:-aucun}"',
    '    if [ -n "$rares" ]; then',
    '        echo "Lignes propres à un seul serveur :"',
    '        echo "$rares"',
    '    else',
    '        echo "Aucun écart : tous les serveurs portent les mêmes lignes."',
    '    fi',
    ')',
    '',
    'if [ -n "$SORTIE" ]; then',
    '    printf \'%s\\n\' "$rapport" > "$SORTIE"',
    'fi',
    'if [ "$SILENCE" -eq 0 ]; then',
    '    printf \'%s\\n\' "$rapport"',
    'fi',
    'exit $((echec != 0))',
    ''
  ].join('\n');

  // ——— Helpers ———
  function crontabOf(fleet, h) { return fleet.get(h).vfs.read('/var/spool/cron/crontabs/admin'); }
  function cronFile(m, h) { var p = '/home/admin/crontabs/' + h + '.cron'; return m.vfs.isFile(p) ? m.vfs.read(p) : null; }
  function put(m, name, text) { B.file(m, '/home/admin/' + name, text, 1); }
  function lines(s) { return s.split('\n').filter(function (l) { return l !== ''; }); }
  function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
  function all(list) { for (var i = 0; i < list.length; i++) if (list[i] !== true) return list[i]; return true; }
  function crontabsCount(m) { return m.vfs.isDir('/home/admin/crontabs') ? m.vfs.list('/home/admin/crontabs').length : 0; }
  function allReachable(f) { f.get('relais').down = null; }
  function twelve(m, f, SERV) {
    for (var i = 0; i < SERV.length; i++) {
      var got = cronFile(m, SERV[i]);
      if (got === null) return 'pas de fichier crontabs/' + SERV[i] + '.cron';
      if (got !== crontabOf(f, SERV[i])) return 'crontabs/' + SERV[i] + '.cron ne contient pas la crontab de ' + SERV[i];
    }
    return true;
  }
  var SERV = RCWorld.SERVERS;

  // ——— E09 · Lire 0 3 * * * (questions seulement) ———

  // ——— E10 · Rapatrier les crontabs ———
  S.add({
    id: 'E10', act: 2, machine: 'regie', machines: 'all', edit: ['rapatrier.sh'], start: RAPATRIER_E10, ref: RAPATRIER_E10_REF,
    base: function (m, f) { allReachable(f); B.file(m, '/home/admin/rapatrier.sh', RAPATRIER_E10, 3, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'les douze crontabs sont rapatriées, une par serveur, dans crontabs/<serveur>.cron',
        setup: function () { }, run: 'bash rapatrier.sh',
        check: function (r, m, f) { return all([twelve(m, f, SERV), crontabsCount(m) === 12 || crontabsCount(m) + ' fichiers dans crontabs/ au lieu de 12']); } },
      { id: 'vide', label: 'une ligne vide dans hotes.txt, et pas de saut de ligne après le dernier nom',
        setup: function (m) { m.vfs.write('/home/admin/hotes.txt', 'antenne\n\nweb\narchives'); }, run: 'bash rapatrier.sh',
        check: function (r, m, f) { return all([twelve(m, { get: function (n) { return f.get(n); } }, ['antenne', 'web', 'archives']), crontabsCount(m) === 3 || 'il devait y avoir 3 fichiers dans crontabs/, il y en a ' + crontabsCount(m) + ' (' + m.vfs.list('/home/admin/crontabs').join(', ') + ')']); } },
      { id: 'dossier', label: 'le dossier crontabs/ n\'existe pas encore',
        setup: function (m) { m.vfs.remove('/home/admin/crontabs', true); }, run: 'bash rapatrier.sh',
        check: function (r, m, f) { return twelve(m, f, SERV); } },
      { id: 'ancien', label: 'un ancien fichier crontabs/antenne.cron est remplacé, pas complété',
        setup: function (m) { B.file(m, '/home/admin/crontabs/antenne.cron', 'ancien contenu\n', 30); }, run: 'bash rapatrier.sh',
        check: function (r, m, f) { return cronFile(m, 'antenne') === crontabOf(f, 'antenne') ? true : 'crontabs/antenne.cron n\'est pas celui du serveur : ' + JSON.stringify((cronFile(m, 'antenne') || '').slice(0, 60)); } },
      { id: 'muet', label: 'un serveur ne répond pas : les onze autres sont quand même rapatriés',
        setup: function (m, f) { f.get('relais').down = 'timeout'; }, run: 'bash rapatrier.sh',
        check: function (r, m, f) { return twelve(m, f, SERV.filter(function (h) { return h !== 'relais'; })); } },
      { id: 'cr', label: 'les fins de ligne de studio-b (Windows) sont gardées telles quelles',
        setup: function () { }, run: 'bash rapatrier.sh',
        check: function (r, m, f) { return cronFile(m, 'studio-b') === crontabOf(f, 'studio-b') && cronFile(m, 'studio-b').indexOf('\r') !== -1 ? true : 'la crontab de studio-b a été modifiée en route'; } }
    ],
    hints: [
      { title: 'Lire un fichier ligne à ligne', course: 'bash', body: 'for h in $(cat hotes.txt) découpe le fichier sur les espaces et ne voit pas les lignes mal terminées. while read -r h; do … done < hotes.txt lit une ligne entière par tour. Si la dernière ligne n\'a pas de saut de ligne, read échoue mais remplit quand même h : while read -r h || [ -n "$h" ].' },
      { title: 'Un fichier par serveur', course: 'bash', body: 'Avec > "crontabs/cron", chaque serveur écrase le précédent. Mets le nom du serveur dans le nom du fichier : "crontabs/$h.cron". Prépare le dossier avec mkdir -p, et ignore les lignes vides : [ -n "$h" ] || continue.' },
      { title: 'La solution', course: 'bash', body: 'mkdir -p crontabs\nwhile read -r h || [ -n "$h" ]; do\n    [ -n "$h" ] || continue\n    ssh "$h" crontab -l > "crontabs/$h.cron"\ndone < hotes.txt' }
    ],
    intro: [{ who: 'samir', expr: 'neutre', text: 'Pour savoir ce qui tourne la nuit sur nos douze machines, il me faut leurs crontabs au même endroit. À la main, c\'est douze connexions…' }],
    done: [{ who: 'samir', expr: 'sourire', text: 'Douze fichiers dans crontabs/, un par serveur. Je vais pouvoir comparer.' }]
  });

  // ——— E11 · L'hôte muet ———
  function lie(m, f, names, mode) { names.forEach(function (n, i) { f.get(n).down = Array.isArray(mode) ? mode[i] : mode; }); }
  S.add({
    id: 'E11', act: 2, machine: 'regie', machines: 'all', edit: ['rapatrier.sh'], start: RAPATRIER_E11, ref: RAPATRIER_E11_REF,
    base: function (m, f) { B.file(m, '/home/admin/rapatrier.sh', RAPATRIER_E11, 3, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'relais est muet : les onze autres sont rapatriés, relais est noté dans injoignables.txt, le code de retour est 1',
        setup: function () { }, run: 'bash rapatrier.sh',
        check: function (r, m, f) {
          var inj = m.vfs.isFile('/home/admin/injoignables.txt') ? m.vfs.read('/home/admin/injoignables.txt') : null;
          return all([twelve(m, f, SERV.filter(function (h) { return h !== 'relais'; })), cronFile(m, 'relais') === null || 'il reste un fichier crontabs/relais.cron, vide ou périmé, qui laisse croire que relais a répondu', inj === 'relais\n' || 'injoignables.txt devrait contenir « relais » : ' + JSON.stringify(inj), r.status === 1 || 'le code de retour doit être 1 (au moins un serveur muet), pas ' + r.status]);
        } },
      { id: 'tous', label: 'tous les serveurs répondent : injoignables.txt existe, vide, et le code de retour est 0',
        setup: function (m, f) { allReachable(f); }, run: 'bash rapatrier.sh',
        check: function (r, m, f) { return all([r.status === 0 || 'le code de retour doit être 0 quand tout répond, pas ' + r.status, twelve(m, f, SERV), (m.vfs.isFile('/home/admin/injoignables.txt') && m.vfs.read('/home/admin/injoignables.txt') === '') || 'injoignables.txt doit exister et être vide']); } },
      { id: 'plusieurs', label: 'quatre serveurs muets de quatre façons différentes (délai, refus, pas de route, clé refusée)',
        setup: function (m, f) { lie(m, f, ['web', 'mail', 'podcast', 'relais'], ['refused', 'noroute', 'denied', 'timeout']); }, run: 'bash rapatrier.sh',
        check: function (r, m, f) {
          var inj = m.vfs.isFile('/home/admin/injoignables.txt') ? m.vfs.read('/home/admin/injoignables.txt').split('\n').filter(Boolean) : [];
          return all([same(inj, ['web', 'podcast', 'mail', 'relais']) || 'injoignables.txt : ' + inj.join(', ') + ' au lieu de web, podcast, mail, relais (dans l\'ordre de hotes.txt)', crontabsCount(m) === 8 || crontabsCount(m) + ' fichiers dans crontabs/ au lieu de 8', r.status === 1 || 'le code de retour doit être 1']);
        } },
      { id: 'sans-crontab', label: 'un serveur joignable mais sans crontab : son fichier est vide, il n\'est pas « injoignable »',
        setup: function (m, f) { allReachable(f); f.get('antenne').vfs.remove('/var/spool/cron/crontabs/admin'); }, run: 'bash rapatrier.sh',
        check: function (r, m) { return all([cronFile(m, 'antenne') === '' || 'crontabs/antenne.cron devrait exister et être vide', m.vfs.read('/home/admin/injoignables.txt') === '' || 'antenne répond : elle ne doit pas être dans injoignables.txt', r.status === 0 || 'le code de retour doit être 0, pas ' + r.status]); } },
      { id: 'perime', label: 'un ancien crontabs/relais.cron d\'une précédente lecture ne reste pas quand relais est muet',
        setup: function (m) { B.file(m, '/home/admin/crontabs/relais.cron', '# vieille copie\n0 3 * * * /usr/local/bin/ancien\n', 20); }, run: 'bash rapatrier.sh',
        check: function (r, m) { return cronFile(m, 'relais') === null ? true : 'la vieille copie de relais.cron est restée : elle ferait croire que relais est lu'; } }
    ],
    hints: [
      { title: 'Le code 255 de ssh', course: 'bash', body: 'Quand la connexion échoue, ssh renvoie 255. Tout autre code est celui de la commande lancée à distance : ici, crontab -l renvoie 1 quand le serveur n\'a pas de crontab, mais le serveur, lui, a répondu. Lis $? juste après ssh : code=$?.' },
      { title: 'Ne pas laisser un fichier menteur', course: 'bash', body: 'La redirection > crée le fichier avant même que ssh réponde : un serveur muet laisse un fichier vide. Écris dans un fichier provisoire (crontabs/$h.cron.tmp), renomme avec mv si le serveur a répondu, sinon supprime-le et note le nom dans injoignables.txt.' },
      { title: 'La solution', course: 'bash', body: ': > injoignables.txt\nwhile read -r h || [ -n "$h" ]; do\n    [ -n "$h" ] || continue\n    tmp="crontabs/$h.cron.tmp"\n    ssh "$h" crontab -l > "$tmp" 2> /dev/null\n    code=$?\n    if [ "$code" -eq 255 ]; then\n        rm -f "$tmp" "crontabs/$h.cron"\n        echo "$h" >> injoignables.txt\n    else\n        mv "$tmp" "crontabs/$h.cron"\n    fi\ndone < hotes.txt\n[ ! -s injoignables.txt ]' }
    ],
    intro: [{ who: 'odile', expr: 'sourcil', text: 'Hier soir, ton script s\'est arrêté net : « relais » ne répond plus depuis une semaine, et les serveurs d\'après n\'ont pas été lus.' }],
    done: [{ who: 'odile', expr: 'sourire', text: 'Un serveur muet ne bloque plus personne, et je sais lequel. C\'est tout ce que je demandais.' }]
  });

  // ——— E12 · Deux crontabs ———
  S.add({
    id: 'E12', act: 2, machine: 'regie', edit: ['ecart.sh'], start: ECART, ref: ECART_REF,
    base: function (m) { B.file(m, '/home/admin/ecart.sh', ECART, 3, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'B porte une ligne de plus que A, mais dans un autre ordre : seule cette ligne est affichée, sans signe devant',
        setup: function (m) { put(m, 'A.cron', PING + '\n' + SAUV + '\n'); put(m, 'B.cron', SAUV + '\n*/5 * * * * /usr/local/bin/playlist-sync\n' + PING + '\n'); }, run: 'bash ecart.sh A.cron B.cron',
        check: function (r) { return all([r.out === '*/5 * * * * /usr/local/bin/playlist-sync\n' || 'la sortie devrait être exactement la ligne en plus : ' + JSON.stringify(r.out), r.status === 0 || 'code de retour ' + r.status]); } },
      { id: 'meme', label: 'les mêmes lignes dans un autre ordre : aucune sortie, code 0',
        setup: function (m) { put(m, 'A.cron', PING + '\n' + SAUV + '\n'); put(m, 'B.cron', SAUV + '\n' + PING + '\n'); }, run: 'bash ecart.sh A.cron B.cron',
        check: function (r) { return all([r.out === '' || 'des fichiers équivalents ne doivent rien afficher : ' + JSON.stringify(r.out), r.status === 0 || 'code de retour ' + r.status]); } },
      { id: 'sens', label: 'une ligne qui n\'est que dans A n\'est pas affichée',
        setup: function (m) { put(m, 'A.cron', PING + '\n' + NETTOYAGE + '\n'); put(m, 'B.cron', PING + '\n'); }, run: 'bash ecart.sh A.cron B.cron',
        check: function (r) { return r.out === '' ? true : 'seules les lignes de B absentes de A doivent sortir : ' + JSON.stringify(r.out); } },
      { id: 'doublon', label: 'une ligne en plus qui apparaît deux fois dans B n\'est affichée qu\'une fois',
        setup: function (m) { put(m, 'A.cron', PING + '\n'); put(m, 'B.cron', PING + '\n' + NETTOYAGE + '\n' + NETTOYAGE + '\n'); }, run: 'bash ecart.sh A.cron B.cron',
        check: function (r) { return r.out === NETTOYAGE + '\n' ? true : 'la ligne devrait sortir une seule fois : ' + JSON.stringify(r.out); } },
      { id: 'espaces', label: 'des noms de fichiers avec des espaces',
        setup: function (m) { put(m, 'ma crontab a.cron', PING + '\n'); put(m, 'ma crontab b.cron', PING + '\n' + NETTOYAGE + '\n'); }, run: 'bash ecart.sh "ma crontab a.cron" "ma crontab b.cron"',
        check: function (r) { return r.out === NETTOYAGE + '\n' ? true : 'sortie inattendue : ' + JSON.stringify(r.out) + ' ' + r.err.split('\n')[0]; } },
      { id: 'absent', label: 'un fichier qui n\'existe pas : message sur la sortie d\'erreur, code 2',
        setup: function (m) { put(m, 'A.cron', PING + '\n'); }, run: 'bash ecart.sh A.cron absent.cron',
        check: function (r) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, /absent\.cron/.test(r.err) || 'le message doit nommer absent.cron', r.out === '' || 'rien ne doit sortir sur la sortie normale']); } },
      { id: 'usage', label: 'sans les deux fichiers : usage et code 2',
        setup: function () { }, run: 'bash ecart.sh seul.cron',
        check: function (r) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, /usage/i.test(r.err) || 'pas de message « usage »']); } }
    ],
    hints: [
      { title: 'comm compare deux fichiers triés', course: 'bash', body: 'comm -13 A B affiche les lignes qui ne sont que dans B (les options retirent les colonnes : 1 = seulement dans A, 2 = seulement dans B, 3 = communes). Mais comm ne marche que sur des fichiers triés : sort -u les trie et retire les doublons. diff, lui, compare ligne à ligne dans l\'ordre et se laisse tromper par un simple changement d\'ordre.' },
      { title: 'Deux fichiers triés à la volée', course: 'bash', body: '<(commande) donne à une commande le nom d\'un fichier qui contient la sortie de cette commande : comm -13 <(sort -u "$1") <(sort -u "$2"). Guillemets obligatoires autour de "$1" et "$2" (noms avec espaces), et vérifie les arguments avant : $# doit valoir 2, et -r teste qu\'un fichier se lit.' },
      { title: 'La solution', course: 'bash', body: 'if [ $# -ne 2 ]; then echo "usage : $0 A B" >&2; exit 2; fi\nfor f in "$1" "$2"; do\n    [ -r "$f" ] || { echo "$0 : $f illisible" >&2; exit 2; }\ndone\ncomm -13 <(sort -u "$1") <(sort -u "$2")' }
    ],
    intro: [{ who: 'samir', expr: 'neutre', text: 'J\'aimerais voir ce que la crontab d\'antenne a de plus que celle de studio-a. diff m\'affiche tout le monde et même des lignes identiques…' }],
    done: [{ who: 'samir', expr: 'sourire', text: 'Seulement ce qui diffère, quel que soit l\'ordre. Ça, c\'est lisible.' }]
  });

  // ——— E13 · Les douze à la fois ———
  function crons(m, map) { m.vfs.remove('/home/admin/crontabs', true); m.vfs.dir('/home/admin/crontabs'); Object.keys(map).forEach(function (h) { B.file(m, '/home/admin/crontabs/' + h + '.cron', map[h], 1); }); }
  S.add({
    id: 'E13', act: 2, machine: 'regie', edit: ['rares.sh'], start: RARES, ref: RARES_REF,
    base: function (m) { B.file(m, '/home/admin/rares.sh', RARES, 3, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'quatre serveurs : chaque ligne portée par un seul serveur est affichée avec son serveur (« serveur: ligne »)',
        setup: function (m) { crons(m, { antenne: PING + '\n' + SAUV + '\n*/5 * * * * /usr/local/bin/playlist-sync\n', studio: PING + '\n' + SAUV + '\n', web: PING + '\n' + SAUV + '\n0 */6 * * * /usr/local/bin/logrotate-site\n', mail: PING + '\n' + SAUV + '\n' }); }, run: 'bash rares.sh',
        check: function (r) { var want = ['antenne: */5 * * * * /usr/local/bin/playlist-sync', 'web: 0 */6 * * * /usr/local/bin/logrotate-site']; return same(lines(r.out), want) ? true : 'attendu : ' + want.join(' | ') + ' — obtenu : ' + lines(r.out).join(' | '); } },
      { id: 'tirets', label: 'des noms de serveurs avec un tiret (studio-a, studio-b)',
        setup: function (m) { crons(m, { 'studio-a': PING + '\n', 'studio-b': PING + '\n' + SAUV + '\n' }); }, run: 'bash rares.sh',
        check: function (r) { var want = ['studio-b: ' + SAUV]; return same(lines(r.out), want) ? true : 'attendu : ' + want.join(' | ') + ' — obtenu : ' + lines(r.out).join(' | '); } },
      { id: 'partagee', label: 'une ligne portée par deux serveurs n\'est pas rare',
        setup: function (m) { crons(m, { a: PING + '\n' + NETTOYAGE + '\n', b: PING + '\n' + NETTOYAGE + '\n', c: PING + '\n' }); }, run: 'bash rares.sh',
        check: function (r) { return r.out === '' ? true : 'rien n\'est rare ici, mais le script affiche : ' + lines(r.out).join(' | '); } },
      { id: 'doublon', label: 'une ligne répétée deux fois dans un même fichier reste rare',
        setup: function (m) { crons(m, { a: PING + '\n' + NETTOYAGE + '\n' + NETTOYAGE + '\n', b: PING + '\n' }); }, run: 'bash rares.sh',
        check: function (r) { var want = ['a: ' + NETTOYAGE]; return same(lines(r.out), want) ? true : 'attendu : ' + want.join(' | ') + ' — obtenu : ' + lines(r.out).join(' | '); } },
      { id: 'vide', label: 'aucune crontab dans crontabs/ : rien à afficher, pas d\'erreur',
        setup: function (m) { crons(m, {}); }, run: 'bash rares.sh',
        check: function (r) { return all([r.out === '' || 'il ne devrait rien sortir : ' + JSON.stringify(r.out), r.err === '' || 'il ne devrait pas y avoir d\'erreur : ' + r.err.split('\n')[0]]); } },
      { id: 'espaces', label: 'des lignes avec des espaces et des étoiles (aucune ne doit être développée par le shell)',
        setup: function (m) { crons(m, { a: '*/10 * * * * /usr/local/bin/x --a --b\n', b: PING + '\n' }); }, run: 'bash rares.sh',
        check: function (r) { var want = ['a: */10 * * * * /usr/local/bin/x --a --b', 'b: ' + PING]; return same(lines(r.out), want) ? true : 'attendu : ' + want.join(' | ') + ' — obtenu : ' + lines(r.out).join(' | '); } }
    ],
    hints: [
      { title: 'uniq -c ne dit pas qui', course: 'bash', body: 'sort | uniq -c compte les lignes identiques, mais perd le nom du serveur, et compte une ligne répétée dans un même fichier comme deux serveurs. Pour garder l\'information, fabrique d\'abord des lignes « ligne<TAB>serveur », une par fichier et par ligne différente (sort -u).' },
      { title: 'Compter avec awk', course: 'bash', body: 'awk -F\'\\t\' coupe sur la tabulation. n[$1]++ compte combien de serveurs portent chaque ligne, h[$1] = $2 retient le dernier serveur vu. En fin de lecture (END), for (l in n) parcourt les lignes ; celles qui ont n[l] == 1 sont rares. Le nom du serveur se tire du nom du fichier : basename "$f" .cron.' },
      { title: 'La solution', course: 'bash', body: 'for f in crontabs/*.cron; do\n    [ -e "$f" ] || continue\n    h=$(basename "$f" .cron)\n    sort -u "$f" | while IFS= read -r ligne; do\n        printf \'%s\\t%s\\n\' "$ligne" "$h"\n    done\ndone | sort | awk -F\'\\t\' \'{ n[$1]++; h[$1] = $2 } END { for (l in n) if (n[l] == 1) printf "%s: %s\\n", h[l], l }\' | sort' }
    ],
    intro: [{ who: 'samir', expr: 'sourcil', text: 'À deux, ça va. Mais à douze, comment trouver la ligne qu\'un seul serveur porte ?' }],
    done: [{ who: 'samir', expr: 'sourire', text: 'Je vois d\'un coup d\'œil ce qui est propre à chaque machine.' }]
  });

  // ——— E14 · Commentaires et espaces ———
  var CRLF = PING + '\r\n' + SAUV + '\r\n\r\n';
  S.add({
    id: 'E14', act: 2, machine: 'regie', edit: ['normaliser.sh'], start: NORMALISER, ref: NORMALISER_REF,
    base: function (m) { B.file(m, '/home/admin/normaliser.sh', NORMALISER, 3, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'la crontab de studio-b (fins de ligne Windows, lignes vides) donne deux lignes propres',
        setup: function (m) { put(m, 'studio-b.cron', HEAD.replace(/\n$/, '\r\n') + CRLF); }, run: 'bash normaliser.sh studio-b.cron',
        check: function (r) { var want = PING + '\n' + SAUV + '\n'; return r.out === want ? true : 'attendu ' + JSON.stringify(want) + ' — obtenu ' + JSON.stringify(r.out); } },
      { id: 'commentaires', label: 'un commentaire indenté est retiré, un « # » au milieu d\'une commande est gardé',
        setup: function (m) { put(m, 'x.cron', '   # note\n0 1 * * * echo "a # b"\n# fin\n'); }, run: 'bash normaliser.sh x.cron',
        check: function (r) { return r.out === '0 1 * * * echo "a # b"\n' ? true : 'sortie : ' + JSON.stringify(r.out); } },
      { id: 'espaces', label: 'espaces multiples et tabulations réduits à une espace',
        setup: function (m) { put(m, 'x.cron', '30   2 *  * *\t/usr/local/bin/x\n'); }, run: 'bash normaliser.sh x.cron',
        check: function (r) { return r.out === '30 2 * * * /usr/local/bin/x\n' ? true : 'sortie : ' + JSON.stringify(r.out); } },
      { id: 'fin', label: 'espaces en début et en fin de ligne retirés',
        setup: function (m) { put(m, 'x.cron', '  0 3 * * * /usr/local/bin/y   \n'); }, run: 'bash normaliser.sh x.cron',
        check: function (r) { return r.out === '0 3 * * * /usr/local/bin/y\n' ? true : 'sortie : ' + JSON.stringify(r.out); } },
      { id: 'noms', label: 'un nom de fichier avec une espace',
        setup: function (m) { put(m, 'ma crontab.cron', PING + '\n'); }, run: 'bash normaliser.sh "ma crontab.cron"',
        check: function (r) { return r.out === PING + '\n' ? true : 'sortie : ' + JSON.stringify(r.out) + ' ' + r.err.split('\n')[0]; } },
      { id: 'que-des-commentaires', label: 'un fichier fait seulement de commentaires : aucune sortie, code 0',
        setup: function (m) { put(m, 'x.cron', '# rien\n# du tout\n'); }, run: 'bash normaliser.sh x.cron',
        check: function (r) { return all([r.out === '' || 'il ne devrait rien sortir', r.status === 0 || 'le code de retour doit être 0, pas ' + r.status]); } },
      { id: 'absent', label: 'un fichier absent ou pas d\'argument : usage et code 2',
        setup: function () { }, run: 'bash normaliser.sh absent.cron',
        check: function (r) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, /usage/i.test(r.err) || 'pas de message « usage »']); } }
    ],
    hints: [
      { title: 'Les fins de ligne Windows', course: 'bash', body: 'Un fichier écrit sous Windows termine chaque ligne par \\r\\n. Le \\r invisible rend deux lignes identiques différentes pour comm ou uniq. tr -d \'\\r\' le supprime.' },
      { title: 'Nettoyer ligne à ligne avec sed', course: 'bash', body: 'sed -E accepte plusieurs -e : s/[[:space:]]+/ /g réduit les blancs à une espace, s/^ // et s/ $// retirent celles du début et de la fin, /^#/d supprime les commentaires et /^$/d les lignes vides. Fais le ménage des blancs avant de chercher les commentaires, pour que « # » indenté soit vu.' },
      { title: 'La solution', course: 'bash', body: 'tr -d \'\\r\' < "$1" | sed -E -e \'s/[[:space:]]+/ /g\' -e \'s/^ //\' -e \'s/ $//\' -e \'/^#/d\' -e \'/^$/d\'' }
    ],
    intro: [{ who: 'samir', expr: 'surprise', text: 'studio-b sort toujours en premier dans ta liste de lignes rares, alors que je vois les mêmes lignes que studio-a. Il y a quelque chose d\'invisible.' }],
    done: [{ who: 'samir', expr: 'sourire', text: 'Des crontabs enfin comparables : ce qui reste, c\'est de vraies différences.' }]
  });

  // ——— E15 · La ligne en trop ———
  function e15base(m, f) {
    m.vfs.dir('/home/admin/crontabs');
    SERV.forEach(function (h) { B.file(m, '/home/admin/crontabs/' + h + '.cron', crontabOf(f, h), 1); });
    B.file(m, '/home/admin/normaliser.sh', NORMALISER_REF, 3, 493);
  }
  var E15_CMD = 'for f in crontabs/*.cron; do h=$(basename "$f" .cron); bash normaliser.sh "$f" | sort -u | while IFS= read -r l; do printf \'%s\\t%s\\n\' "$l" "$h"; done; done | sort | awk -F\'\\t\' \'{ n[$1]++; h[$1] = $2 } END { for (l in n) if (n[l] == 1) printf "%s: %s\\n", h[l], l }\' | sort';
  S.add({
    id: 'E15', act: 2, machine: 'regie', machines: 'all', base: e15base,
    views: { rares: { title: 'regie : les lignes propres à un seul serveur (crontabs normalisées)', machine: 'regie', cmd: E15_CMD } }
  });

  // ——— E16 · Le rapport d'Odile ———
  function e16base(m, f) {
    B.file(m, '/home/admin/compare-crontabs.sh', COMPARE, 3, 493);
    B.file(m, '/home/admin/rapatrier.sh', RAPATRIER_E11_REF, 20, 493);
    B.file(m, '/home/admin/normaliser.sh', NORMALISER_REF, 15, 493);
    B.file(m, '/home/admin/rares.sh', RARES_DIR, 10, 493);
  }
  function rapport(m, path) { return m.vfs.isFile(path) ? m.vfs.read(path) : null; }
  function identiques(f) { SERV.forEach(function (h) { f.get(h).vfs.file('/var/spool/cron/crontabs/admin', HEAD + PING + '\n' + SAUV + '\n', { mode: 384 }); }); }
  S.add({
    id: 'E16', act: 2, machine: 'regie', machines: 'all', edit: ['compare-crontabs.sh'], start: COMPARE, ref: COMPARE_REF,
    base: e16base,
    cases: [
      { id: 'vis', visible: true, label: 'rapport complet : serveurs lus, serveur muet, lignes propres à un seul serveur — sans les faux écarts de studio-b ; code 1 car relais est muet',
        setup: function () { }, run: 'bash compare-crontabs.sh',
        check: function (r) {
          var l = lines(r.out);
          return all([r.status === 1 || 'le code de retour doit être 1 (relais est muet), pas ' + r.status, l.indexOf('Serveurs lus : 11 sur 12') !== -1 || 'la ligne « Serveurs lus : 11 sur 12 » manque', l.indexOf('Injoignables : relais') !== -1 || 'la ligne « Injoignables : relais » manque', l.indexOf('archives: ' + NETTOYAGE) !== -1 || 'la ligne « archives: ' + NETTOYAGE + ' » manque', r.out.indexOf('\r') === -1 || 'des \\r traînent dans le rapport', l.every(function (x) { return !/^studio-b: /.test(x); }) || 'studio-b ressort comme différent : les crontabs doivent être normalisées avant comparaison', /^Rapport des crontabs du 2026-03-12$/.test(l[0] || '') || 'la première ligne doit être « Rapport des crontabs du 2026-03-12 »']);
        } },
      { id: 'fichier', label: '-q -o rapport.txt : rien à l\'écran, tout dans le fichier',
        setup: function () { }, run: 'bash compare-crontabs.sh -q -o rapport.txt',
        check: function (r, m) { var f = rapport(m, '/home/admin/rapport.txt'); return all([r.out === '' || 'avec -q, rien ne doit s\'afficher : ' + JSON.stringify(r.out.slice(0, 60)), (f !== null && f.indexOf('Serveurs lus : 11 sur 12') !== -1) || 'rapport.txt devrait contenir le rapport']); } },
      { id: 'dossier', label: 'un fichier de rapport dans un dossier dont le nom contient une espace',
        setup: function (m) { m.vfs.dir('/home/admin/mes rapports'); }, run: 'bash compare-crontabs.sh -o "mes rapports/crontabs.txt"',
        check: function (r, m) { var f = rapport(m, '/home/admin/mes rapports/crontabs.txt'); return all([(f !== null && f.indexOf('Injoignables : relais') !== -1) || 'le rapport devrait être dans « mes rapports/crontabs.txt »', r.out.indexOf('Serveurs lus') !== -1 || 'sans -q, le rapport s\'affiche aussi à l\'écran']); } },
      { id: 'option', label: 'une option inconnue : usage, code 2, rien d\'écrit',
        setup: function () { }, run: 'bash compare-crontabs.sh -z',
        check: function (r, m) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, /usage/i.test(r.err) || 'pas de message « usage »', !m.vfs.isFile('/home/admin/injoignables.txt') || 'le script a travaillé malgré l\'appel incorrect']); } },
      { id: 'sans-fichier', label: '-o sans nom de fichier, ou un argument en trop : usage et code 2',
        setup: function () { }, run: 'bash compare-crontabs.sh -o',
        check: function (r) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, /usage/i.test(r.err) || 'pas de message « usage »']); } },
      { id: 'aucun', label: 'tous les serveurs répondent et portent les mêmes lignes : « Aucun écart », code 0',
        setup: function (m, f) { allReachable(f); identiques(f); }, run: 'bash compare-crontabs.sh',
        check: function (r) { var l = lines(r.out); return all([r.status === 0 || 'le code de retour doit être 0, pas ' + r.status, l.indexOf('Serveurs lus : 12 sur 12') !== -1 || 'la ligne « Serveurs lus : 12 sur 12 » manque', l.indexOf('Injoignables : aucun') !== -1 || 'la ligne « Injoignables : aucun » manque', /^Aucun écart/.test(l[l.length - 1] || '') || 'la dernière ligne doit commencer par « Aucun écart »']); } },
      { id: 'propre', label: 'le script ne laisse rien derrière lui : ni dossier temporaire, ni fichier rapport.tmp',
        setup: function () { }, run: 'bash compare-crontabs.sh -q',
        check: function (r, m) { return all([m.vfs.list('/tmp').length === 0 || 'des fichiers traînent dans /tmp : ' + m.vfs.list('/tmp').join(', '), !m.vfs.isFile('/home/admin/rapport.tmp') || 'rapport.tmp est resté']); } }
    ],
    hints: [
      { title: 'Assembler plutôt que réécrire', course: 'bash', body: 'Tu as déjà trois outils : rapatrier.sh (les fichiers), normaliser.sh (les rendre comparables), rares.sh (les lignes propres à un serveur ; il accepte un dossier en argument). Le rapport les appelle dans l\'ordre. Garde le code de retour de rapatrier.sh dans une variable (echec=$?) avant de lancer autre chose.' },
      { title: 'Options et ménage', course: 'bash', body: 'getopts qo: lit -q et -o FICHIER ; la case * attrape les options inconnues et -o sans argument : message « usage » et exit 2. shift $((OPTIND - 1)) puis vérifie qu\'il ne reste aucun argument. Un dossier temporaire se crée avec mktemp -d, et trap \'rm -rf "$propres"\' EXIT le supprime quoi qu\'il arrive.' },
      { title: 'La solution', course: 'bash', body: 'bash rapatrier.sh; echec=$?\nfor f in crontabs/*.cron; do\n    [ -e "$f" ] || continue\n    bash normaliser.sh "$f" > "$propres/$(basename "$f")"\ndone\n…\nrapport=$( echo "Rapport des crontabs du $(date +%F)"; … )\n[ -n "$SORTIE" ] && printf \'%s\\n\' "$rapport" > "$SORTIE"\n[ "$SILENCE" -eq 0 ] && printf \'%s\\n\' "$rapport"\nexit $((echec != 0))' }
    ],
    intro: [{ who: 'odile', expr: 'neutre', text: 'Je ne lirai pas douze fichiers. Je veux une page : combien de serveurs répondent, lesquels sont muets, et ce que chaque serveur est seul à faire.' }],
    done: [{ who: 'odile', expr: 'sourire', text: 'Une page, claire. Je la garde dans mon dossier.' }]
  });
})(RCSteps);
