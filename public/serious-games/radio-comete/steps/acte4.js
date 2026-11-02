/* Acte 4 — Le parc (jeudi, 14 h). Contrôler la configuration des douze serveurs : lire un fichier de
   configuration ligne à ligne, extraire une clé, tester variables et dossiers, boucler sur le parc par ssh,
   dater un événement dans un journal, chiffrer ce qui a disparu.

   Les scripts se suivent : lire-conf.sh (E25) → valeur.sh (E26) → controle.sh (E27 → E28) →
   controle-parc.sh (E29, qui produit le tableau que l'élève lit à l'E30). Machines : `archives` (E25-E28,
   E31), `regie` + les douze (E29, E30), `sauvegarde` + `archives` (E32). */
(function (S) {
  'use strict';
  var B = S.B, MB = 1048576;

  // ——— The scripts ———
  var LIRE_START = [
    '#!/bin/bash',
    '# lire-conf.sh [FICHIER] : affiche CLE<TAB>VALEUR pour chaque variable du fichier de configuration',
    'FICHIER=$1',
    'cat $FICHIER | while read ligne; do',
    '    cle=$(echo $ligne | cut -d= -f1)',
    '    valeur=$(echo $ligne | cut -d= -f2)',
    '    printf \'%s\\t%s\\n\' "$cle" "$valeur"',
    'done',
    ''
  ].join('\n');

  var LIRE_REF = [
    '#!/bin/bash',
    '# lire-conf.sh [FICHIER] : affiche CLE<TAB>VALEUR pour chaque variable du fichier de configuration',
    'FICHIER=${1:-/etc/radio/chemins.conf}',
    '',
    'if [ ! -r "$FICHIER" ]; then',
    '    echo "lire-conf.sh : $FICHIER : fichier illisible" >&2',
    '    exit 1',
    'fi',
    '',
    'while IFS= read -r ligne || [ -n "$ligne" ]; do',
    '    case $ligne in',
    '        \'\'|\'#\'*) continue ;;',
    '        *=*) ;;',
    '        *) continue ;;',
    '    esac',
    '    cle=${ligne%%=*}',
    '    valeur=${ligne#*=}',
    '    valeur=$(printf \'%s\' "$valeur" | sed \'s/[[:space:]]*$//\')',
    '    printf \'%s\\t%s\\n\' "$cle" "$valeur"',
    'done < "$FICHIER"',
    ''
  ].join('\n');

  var VALEUR_START = [
    '#!/bin/bash',
    '# valeur.sh CLE [FICHIER] : affiche la valeur de CLE dans le fichier de configuration',
    'CLE=$1',
    'FICHIER=${2:-/etc/radio/chemins.conf}',
    'grep $CLE $FICHIER | cut -d= -f2',
    ''
  ].join('\n');

  var VALEUR_REF = [
    '#!/bin/bash',
    '# valeur.sh CLE [FICHIER] : affiche la valeur de CLE dans le fichier de configuration',
    'set -u',
    'CLE=${1:?usage : valeur.sh CLE [FICHIER]}',
    'FICHIER=${2:-/etc/radio/chemins.conf}',
    '',
    'awk -F= -v cle="$CLE" \'',
    '    $1 == cle { sub(/^[^=]*=/, ""); print; trouve = 1; exit }',
    '    END { exit trouve ? 0 : 1 }',
    '\' "$FICHIER"',
    ''
  ].join('\n');

  var CTRL_START = [
    '#!/bin/bash',
    '# controle.sh [FICHIER] : vérifie que les variables obligatoires sont renseignées',
    'FICHIER=${1:-/etc/radio/chemins.conf}',
    '. $FICHIER',
    '',
    'for cle in RADIO_ROOT LOG_DIR RETENTION; do',
    '    if [ $cle ]; then',
    '        echo "OK $cle"',
    '    fi',
    'done',
    ''
  ].join('\n');

  var CTRL_E27_REF = [
    '#!/bin/bash',
    '# controle.sh [FICHIER] : vérifie que les variables obligatoires sont renseignées',
    'FICHIER=${1:-/etc/radio/chemins.conf}',
    '',
    'if [ ! -r "$FICHIER" ]; then',
    '    echo "controle.sh : $FICHIER : fichier illisible" >&2',
    '    exit 2',
    'fi',
    'anomalies=0',
    '',
    'controle_cle() {',
    '    local cle=$1 ligne',
    '    if ! grep -q "^$cle=" "$FICHIER"; then',
    '        echo "ABSENT $cle"',
    '        return 1',
    '    fi',
    '    ligne=$(grep "^$cle=" "$FICHIER" | head -n 1)',
    '    if [ -z "${ligne#*=}" ]; then',
    '        echo "VIDE $cle"',
    '        return 1',
    '    fi',
    '    echo "OK $cle"',
    '}',
    '',
    'for cle in RADIO_ROOT LOG_DIR RETENTION; do',
    '    controle_cle "$cle" || anomalies=1',
    'done',
    'for cle in ARCHIVE_ROOT EXPORT_ROOT; do',
    '    if grep -q "^$cle=" "$FICHIER"; then',
    '        controle_cle "$cle" || anomalies=1',
    '    fi',
    'done',
    'exit "$anomalies"',
    ''
  ].join('\n');

  var CTRL_E28_REF = [
    '#!/bin/bash',
    '# controle.sh [FICHIER] : vérifie les variables obligatoires et les dossiers qu\'elles désignent',
    'FICHIER=${1:-/etc/radio/chemins.conf}',
    '',
    'if [ ! -r "$FICHIER" ]; then',
    '    echo "controle.sh : $FICHIER : fichier illisible" >&2',
    '    exit 2',
    'fi',
    'anomalies=0',
    '',
    'valeur_de() { grep "^$1=" "$FICHIER" | head -n 1 | cut -d= -f2-; }',
    '',
    'controle_cle() {',
    '    local cle=$1 valeur',
    '    if ! grep -q "^$cle=" "$FICHIER"; then',
    '        echo "ABSENT $cle"',
    '        return 1',
    '    fi',
    '    valeur=$(valeur_de "$cle")',
    '    if [ -z "$valeur" ]; then',
    '        echo "VIDE $cle"',
    '        return 1',
    '    fi',
    '    case $cle in',
    '        *_ROOT|*_DIR)',
    '            if [ ! -d "$valeur" ]; then',
    '                echo "INTROUVABLE $cle"',
    '                return 1',
    '            elif [ ! -w "$valeur" ]; then',
    '                echo "LECTURE-SEULE $cle"',
    '                return 1',
    '            fi',
    '            ;;',
    '    esac',
    '    echo "OK $cle"',
    '}',
    '',
    'cles="RADIO_ROOT LOG_DIR RETENTION"',
    'for cle in $(grep -o \'^[A-Z_]*\\(_ROOT\\|_DIR\\)=\' "$FICHIER" | tr -d =); do',
    '    case " $cles " in',
    '        *" $cle "*) ;;',
    '        *) cles="$cles $cle" ;;',
    '    esac',
    'done',
    'for cle in $cles; do',
    '    controle_cle "$cle" || anomalies=1',
    'done',
    'exit "$anomalies"',
    ''
  ].join('\n');

  var PARC_START = [
    '#!/bin/bash',
    '# controle-parc.sh : contrôle les chemins.conf des serveurs de hotes.txt',
    'for h in $(cat hotes.txt); do',
    '    ssh $h cat /etc/radio/chemins.conf | while read l; do',
    '        echo "$h $l"',
    '    done',
    'done',
    ''
  ].join('\n');

  var PARC_REF = [
    '#!/bin/bash',
    '# controle-parc.sh : contrôle les chemins.conf des serveurs de hotes.txt',
    '# sortie : un tableau HOTE CLE ETAT, puis les serveurs injoignables',
    'anomalies=0',
    'lignes=""',
    'injoignables=""',
    '',
    'etat() { # etat CONF CLE : OK, VIDE ou ABSENT',
    '    local ligne',
    '    ligne=$(printf \'%s\\n\' "$1" | grep "^$2=" | head -n 1)',
    '    if [ -z "$ligne" ]; then',
    '        echo ABSENT',
    '    elif [ -z "${ligne#*=}" ]; then',
    '        echo VIDE',
    '    else',
    '        echo OK',
    '    fi',
    '}',
    '',
    'while IFS= read -r hote || [ -n "$hote" ]; do',
    '    [ -n "$hote" ] || continue',
    '    if ! conf=$(ssh "$hote" cat /etc/radio/chemins.conf 2>/dev/null); then',
    '        injoignables="$injoignables $hote"',
    '        anomalies=1',
    '        continue',
    '    fi',
    '    for cle in RADIO_ROOT LOG_DIR RETENTION ARCHIVE_ROOT EXPORT_ROOT PARTAGES_ROOT; do',
    '        case $cle in',
    '            RADIO_ROOT|LOG_DIR|RETENTION) ;;',
    '            *) printf \'%s\\n\' "$conf" | grep -q "^$cle=" || continue ;;',
    '        esac',
    '        e=$(etat "$conf" "$cle")',
    '        [ "$e" = OK ] || anomalies=1',
    '        lignes="$lignes$hote $cle $e"$\'\\n\'',
    '    done',
    'done < hotes.txt',
    '',
    'printf \'%s\' "$lignes" | column -t',
    'for h in $injoignables; do',
    '    echo "INJOIGNABLE $h"',
    'done',
    'exit "$anomalies"',
    ''
  ].join('\n');

  var QUAND_START = [
    '#!/bin/bash',
    '# quand.sh [-i] MOTIF [FICHIER] : date de la première ligne du journal qui contient MOTIF',
    'MOTIF=$1',
    'FICHIER=${2:-/var/log/radio/migration-2025-09.log}',
    'grep $MOTIF $FICHIER | head -1 | cut -d\' \' -f1',
    ''
  ].join('\n');

  var QUAND_REF = [
    '#!/bin/bash',
    '# quand.sh [-i] MOTIF [FICHIER] : date de la première ligne du journal qui contient MOTIF',
    'OPT=-F',
    'if [ "${1:-}" = "-i" ]; then',
    '    OPT=-Fi',
    '    shift',
    'fi',
    'if [ $# -lt 1 ]; then',
    '    echo "usage : $0 [-i] MOTIF [FICHIER]" >&2',
    '    exit 2',
    'fi',
    'MOTIF=$1',
    'FICHIER=${2:-/var/log/radio/migration-2025-09.log}',
    '',
    'ligne=$(grep "$OPT" -- "$MOTIF" "$FICHIER" | head -n 1)',
    'if [ -z "$ligne" ]; then',
    '    exit 1',
    'fi',
    'echo "${ligne%% *}"',
    ''
  ].join('\n');

  var DEGATS_START = [
    '#!/bin/bash',
    '# degats.sh LISTE : combien de fichiers de LISTE n\'existent plus sur archives, et quel volume',
    'LISTE=$1',
    'ssh archives "find /srv/data/archives -type f -printf \'%s %p\\n\'" > actuel.txt',
    'manquants=$(comm -23 $LISTE actuel.txt)',
    'echo "$(echo $manquants | wc -w) fichiers"',
    ''
  ].join('\n');

  var DEGATS_REF = [
    '#!/bin/bash',
    '# degats.sh LISTE : combien de fichiers de LISTE n\'existent plus sur archives, et quel volume',
    'set -u',
    'LISTE=${1:?usage : degats.sh LISTE}',
    'if [ ! -r "$LISTE" ]; then',
    '    echo "degats.sh : $LISTE : fichier illisible" >&2',
    '    exit 1',
    'fi',
    'ACTUEL=$(mktemp)',
    'trap \'rm -f "$ACTUEL"\' EXIT',
    '',
    'ssh archives "find /srv/data/archives -type f -printf \'%s %p\\n\'" > "$ACTUEL"',
    '',
    'awk -v actuel="$ACTUEL" \'',
    '    FILENAME == actuel { sub(/^[0-9]+ /, ""); present[$0] = 1; next }',
    '    {',
    '        chemin = $0',
    '        sub(/^[0-9]+ /, "", chemin)',
    '        if (!(chemin in present)) { n++; octets += $1 }',
    '    }',
    '    END { printf "%d fichiers, %d Mo\\n", n, octets / 1048576 }',
    '\' "$ACTUEL" "$LISTE"',
    ''
  ].join('\n');

  // ——— Helpers ———
  function put(m, text) { m.vfs.file('/home/admin/essai.conf', text, { mtime: m.vfs.now - 3600 }); }
  function tsv(lines) { return lines.join('\n') + (lines.length ? '\n' : ''); }
  function same(r, expected, status) {
    if (status !== undefined && r.status !== status) return 'code de retour ' + r.status + ' au lieu de ' + status + (r.err ? ' : ' + r.err.split('\n')[0] : '');
    if (r.out === expected) return true;
    return 'sortie obtenue : ' + JSON.stringify(r.out.length > 160 ? r.out.slice(0, 160) + '…' : r.out) + ' ; attendue : ' + JSON.stringify(expected);
  }
  function lineSet(r) { return r.out.split('\n').filter(Boolean).map(function (l) { return l.replace(/\s+/g, ' ').trim(); }); }
  function has(r, line) { return lineSet(r).indexOf(line) !== -1; }
  function editConf(f, host, fn) { var m = f.get(host), p = '/etc/radio/chemins.conf'; m.vfs.write(p, fn(m.vfs.read(p))); }

  // ——— E25 · Lire chemins.conf ———
  S.add({
    id: 'E25', act: 4, machine: 'archives', edit: ['lire-conf.sh'], start: LIRE_START, ref: LIRE_REF,
    show: ['/etc/radio/chemins.conf'],
    cases: [
      { id: 'vis', visible: true, label: 'sans argument, le chemins.conf du serveur est lu : cinq variables, une par ligne, clé et valeur séparées par une tabulation',
        setup: function () { }, run: 'bash lire-conf.sh',
        check: function (r) { return same(r, tsv(['RADIO_ROOT\t/srv/data', 'LOG_DIR\t/var/log/radio', 'RETENTION\t30', 'ARCHIVE_ROOT\t/srv/data/archives', 'EXPORT_ROOT\t']), 0); } },
      { id: 'egal', label: 'une valeur qui contient elle-même un « = »',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/x\nURL=http://x/?a=b&c=d\n'); }, run: 'bash lire-conf.sh essai.conf',
        check: function (r) { return same(r, tsv(['RADIO_ROOT\t/srv/x', 'URL\thttp://x/?a=b&c=d']), 0); } },
      { id: 'sans-egal', label: 'une ligne qui n\'est ni un commentaire ni une variable est ignorée',
        setup: function (m) { put(m, '# commentaire\n\nceci n\'est pas une variable\nLOG_DIR=/var/log/radio\n'); }, run: 'bash lire-conf.sh essai.conf',
        check: function (r) { return same(r, tsv(['LOG_DIR\t/var/log/radio']), 0); } },
      { id: 'fin', label: 'des espaces à la fin d\'une valeur ne font pas partie de la valeur',
        setup: function (m) { put(m, 'LOG_DIR=/var/log/radio   \nRETENTION=30\n'); }, run: 'bash lire-conf.sh essai.conf',
        check: function (r) { return same(r, tsv(['LOG_DIR\t/var/log/radio', 'RETENTION\t30']), 0); } },
      { id: 'derniere', label: 'la dernière ligne n\'est pas terminée par un retour à la ligne',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/x\nLOG_DIR=/var/log/radio'); }, run: 'bash lire-conf.sh essai.conf',
        check: function (r) { return same(r, tsv(['RADIO_ROOT\t/srv/x', 'LOG_DIR\t/var/log/radio']), 0); } },
      { id: 'absent', label: 'fichier absent : un message sur la sortie d\'erreur, un code de retour en échec, rien sur la sortie',
        setup: function () { }, run: 'bash lire-conf.sh /etc/radio/inconnu.conf',
        check: function (r) { return all([r.status !== 0 || 'le script réussit alors que le fichier n\'existe pas', r.err.trim() !== '' || 'pas de message sur la sortie d\'erreur', r.out === '' || 'quelque chose est écrit sur la sortie : ' + JSON.stringify(r.out)]); } }
    ],
    hints: [
      { title: 'Lire un fichier ligne à ligne', course: 'bash', body: 'while IFS= read -r ligne; do … done < "$FICHIER" lit une ligne à la fois sans toucher aux espaces ni aux barres obliques inverses. Le fichier se donne à la boucle par la redirection < : une boucle placée après un tube tourne dans un sous-shell.' },
      { title: 'Couper au premier « = » seulement', course: 'bash', body: '${ligne%%=*} garde ce qui précède le premier « = » ; ${ligne#*=} garde ce qui le suit, « = » suivants compris. cut -d= -f2 s\'arrête au deuxième champ et perd la fin de l\'URL. Un case permet d\'écarter les lignes vides et les commentaires. Pour la dernière ligne sans retour à la ligne : while read -r ligne || [ -n "$ligne" ].' },
      { title: 'La solution', course: 'bash', body: 'FICHIER=${1:-/etc/radio/chemins.conf}\nwhile IFS= read -r ligne || [ -n "$ligne" ]; do\n    case $ligne in\n        \'\'|\'#\'*) continue ;;\n        *=*) ;;\n        *) continue ;;\n    esac\n    cle=${ligne%%=*}\n    valeur=${ligne#*=}\n    valeur=$(printf \'%s\' "$valeur" | sed \'s/[[:space:]]*$//\')\n    printf \'%s\\t%s\\n\' "$cle" "$valeur"\ndone < "$FICHIER"' }
    ],
    intro: [{ who: 'samir', expr: 'neutre', text: 'Pour contrôler le parc, il me faut d\'abord un script qui sache lire chemins.conf proprement. Celui que j\'ai bricolé affiche même les commentaires.' }],
    done: [{ who: 'samir', expr: 'sourire', text: 'Clé, tabulation, valeur : je peux brancher n\'importe quoi dessus maintenant.' }]
  });

  function all(list) { for (var i = 0; i < list.length; i++) if (list[i] !== true) return list[i]; return true; }

  // ——— E26 · Extraire une clé ———
  S.add({
    id: 'E26', act: 4, machine: 'archives', edit: ['valeur.sh'], start: VALEUR_START, ref: VALEUR_REF,
    show: ['/etc/radio/chemins.conf'],
    cases: [
      { id: 'vis', visible: true, label: 'RETENTION vaut 30 : ni « RETENTION_MAX », ni la ligne de commentaire ne doivent s\'y mêler',
        setup: function (m) { put(m, '# RETENTION en jours\nRETENTION_MAX=90\nRETENTION=30\n'); }, run: 'bash valeur.sh RETENTION essai.conf',
        check: function (r) { return same(r, '30\n', 0); } },
      { id: 'egal', label: 'une valeur qui contient un « = » est affichée en entier',
        setup: function (m) { put(m, 'URL=http://x/?a=b&c=d\nRETENTION=30\n'); }, run: 'bash valeur.sh URL essai.conf',
        check: function (r) { return same(r, 'http://x/?a=b&c=d\n', 0); } },
      { id: 'absente', label: 'une clé absente : rien sur la sortie, code de retour 1',
        setup: function (m) { put(m, 'RETENTION=30\n'); }, run: 'bash valeur.sh ARCHIVE_ROOT essai.conf',
        check: function (r) { return all([r.status === 1 || 'code de retour ' + r.status + ' au lieu de 1', r.out === '' || 'quelque chose est écrit : ' + JSON.stringify(r.out)]); } },
      { id: 'morceau', label: 'un morceau de nom (« ROOT ») n\'est pas une clé',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/x\nARCHIVE_ROOT=/srv/y\n'); }, run: 'bash valeur.sh ROOT essai.conf',
        check: function (r) { return all([r.status === 1 || 'code de retour ' + r.status + ' au lieu de 1', r.out === '' || 'quelque chose est écrit : ' + JSON.stringify(r.out)]); } },
      { id: 'vide', label: 'une clé présente mais vide : une ligne vide, code de retour 0',
        setup: function (m) { put(m, 'EXPORT_ROOT=\n'); }, run: 'bash valeur.sh EXPORT_ROOT essai.conf',
        check: function (r) { return same(r, '\n', 0); } },
      { id: 'defaut', label: 'sans second argument, le chemins.conf du serveur est lu',
        setup: function () { }, run: 'bash valeur.sh ARCHIVE_ROOT',
        check: function (r) { return same(r, '/srv/data/archives\n', 0); } },
      { id: 'sans-cle', label: 'sans clé : un message d\'usage et un code de retour en échec',
        setup: function () { }, run: 'bash valeur.sh',
        check: function (r) { return all([r.status !== 0 || 'le script réussit sans clé', r.err.trim() !== '' || 'pas de message sur la sortie d\'erreur', r.out === '' || 'quelque chose est écrit sur la sortie']); } }
    ],
    hints: [
      { title: 'grep trouve des morceaux, pas des clés', course: 'bash', body: 'grep RETENTION attrape toute ligne qui contient ce mot : RETENTION_MAX, un commentaire… Une clé est le texte qui précède le premier « = » de la ligne, rien d\'autre. awk -F= découpe chaque ligne sur « = » et $1 vaut exactement la clé : le test $1 == cle écarte tout le reste.' },
      { title: 'Le code de retour de awk', course: 'bash', body: 'Dans awk, exit termine la lecture et lance le bloc END ; exit N fixe le code de retour. Garde une variable « trouve » : END { exit trouve ? 0 : 1 }. Pour afficher la valeur en entier, retire seulement ce qui précède le premier « = » : sub(/^[^=]*=/, "").' },
      { title: 'La solution', course: 'bash', body: 'awk -F= -v cle="$CLE" \'\n    $1 == cle { sub(/^[^=]*=/, ""); print; trouve = 1; exit }\n    END { exit trouve ? 0 : 1 }\n\' "$FICHIER"' }
    ],
    intro: [{ who: 'samir', expr: 'neutre', text: 'J\'ai besoin de la valeur d\'une variable, pas de tout le fichier. Mon petit grep me renvoie parfois deux lignes.' }],
    done: [{ who: 'samir', expr: 'sourire', text: '« valeur.sh RETENTION » : une valeur ou rien. Exactement ça.' }]
  });

  // ——— E27 · Contrôler un serveur ———
  S.add({
    id: 'E27', act: 4, machine: 'archives', edit: ['controle.sh'], start: CTRL_START, ref: CTRL_E27_REF,
    show: ['/etc/radio/chemins.conf'],
    cases: [
      { id: 'vis', visible: true, label: 'RADIO_ROOT renseigné, LOG_DIR vide, RETENTION absente : OK, VIDE, ABSENT, et un code de retour en échec',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/diffusion\nLOG_DIR=\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'VIDE LOG_DIR', 'ABSENT RETENTION']), 1); } },
      { id: 'sain', label: 'tout est renseigné : trois OK, code de retour 0',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/diffusion\nLOG_DIR=/var/log/radio\nRETENTION=30\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION']), 0); } },
      { id: 'archives', label: 'ARCHIVE_ROOT et EXPORT_ROOT sont contrôlées quand elles figurent dans le fichier',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/data\nLOG_DIR=/var/log/radio\nRETENTION=30\nARCHIVE_ROOT=/srv/data/archives\nEXPORT_ROOT=\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION', 'OK ARCHIVE_ROOT', 'VIDE EXPORT_ROOT']), 1); } },
      { id: 'optionnelles', label: 'sans ARCHIVE_ROOT ni EXPORT_ROOT dans le fichier, elles ne sont pas reprochées',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/x\nLOG_DIR=/var/log/radio\nRETENTION=30\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return r.out.split('\n').filter(Boolean).length === 3 ? true : 'seules trois lignes étaient attendues : ' + JSON.stringify(r.out); } },
      { id: 'commentaire', label: 'une variable seulement citée dans un commentaire est absente',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/x\nLOG_DIR=/var/log/radio\n# RETENTION=30\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'ABSENT RETENTION']), 1); } },
      { id: 'prefixe', label: 'RETENTION_MAX ne tient pas lieu de RETENTION',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/x\nLOG_DIR=/var/log/radio\nRETENTION_MAX=90\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'ABSENT RETENTION']), 1); } },
      { id: 'fichier', label: 'fichier absent : un message d\'erreur et le code de retour 2',
        setup: function () { }, run: 'bash controle.sh /etc/radio/inconnu.conf',
        check: function (r) { return all([r.status === 2 || 'code de retour ' + r.status + ' au lieu de 2', r.err.trim() !== '' || 'pas de message d\'erreur']); } },
      { id: 'defaut', label: 'sans argument, le chemins.conf du serveur est contrôlé : EXPORT_ROOT y est vide',
        setup: function () { }, run: 'bash controle.sh',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION', 'OK ARCHIVE_ROOT', 'VIDE EXPORT_ROOT']), 1); } }
    ],
    hints: [
      { title: 'Tester le nom, ou la valeur ?', course: 'bash', body: '[ $cle ] teste le texte « RADIO_ROOT » lui-même : il n\'est jamais vide, donc c\'est toujours vrai. Pour savoir si la variable a une valeur il faut regarder ce que le fichier lui donne. Un fichier sourcé ne distingue pas « absente » de « vide » : grep -q "^$cle=" dit si la ligne existe, et ce qui suit le « = » dit si elle est vide.' },
      { title: 'Une fonction qui répond par un code', course: 'bash', body: 'Une fonction affiche son verdict et retourne 0 ou 1 : return 1. L\'appelant l\'utilise dans un test : controle_cle "$cle" || anomalies=1. À la fin, exit "$anomalies" donne au script un code qui résume tout. Avec local, la variable cle ne déborde pas de la fonction.' },
      { title: 'La solution', course: 'bash', body: 'controle_cle() {\n    local cle=$1 ligne\n    if ! grep -q "^$cle=" "$FICHIER"; then echo "ABSENT $cle"; return 1; fi\n    ligne=$(grep "^$cle=" "$FICHIER" | head -n 1)\n    if [ -z "${ligne#*=}" ]; then echo "VIDE $cle"; return 1; fi\n    echo "OK $cle"\n}\nfor cle in RADIO_ROOT LOG_DIR RETENTION; do controle_cle "$cle" || anomalies=1; done' }
    ],
    intro: [{ who: 'odile', expr: 'neutre', text: 'Je voudrais un script qui dise, pour un serveur, si ses variables de chemins sont renseignées. Un mot par variable : OK, VIDE ou ABSENT.' }],
    done: [{ who: 'odile', expr: 'sourire', text: 'Un verdict par ligne et un code de retour : je peux lire ça sans effort.' }]
  });

  // ——— E28 · Le répertoire existe-t-il ? ———
  function dirs(m) { ['/srv/essai', '/srv/essai-ro', '/srv/mes fichiers'].forEach(function (d) { m.vfs.dir(d); }); m.vfs.get('/srv/essai-ro', true).mode = 365; }
  S.add({
    id: 'E28', act: 4, machine: 'archives', edit: ['controle.sh'], start: CTRL_E27_REF, ref: CTRL_E28_REF,
    show: ['/etc/radio/chemins.conf'],
    base: function (m) { dirs(m); },
    cases: [
      { id: 'vis', visible: true, label: 'ARCHIVE_ROOT désigne un dossier qui n\'existe pas : INTROUVABLE',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/essai\nLOG_DIR=/var/log/radio\nRETENTION=30\nARCHIVE_ROOT=/srv/introuvable\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION', 'INTROUVABLE ARCHIVE_ROOT']), 1); } },
      { id: 'lecture', label: 'un dossier qu\'on ne peut pas écrire : LECTURE-SEULE',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/essai-ro\nLOG_DIR=/var/log/radio\nRETENTION=30\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['LECTURE-SEULE RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION']), 1); } },
      { id: 'vide', label: 'un chemin vide reste VIDE, sans test de dossier',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/essai\nLOG_DIR=/var/log/radio\nRETENTION=30\nEXPORT_ROOT=\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION', 'VIDE EXPORT_ROOT']), 1); } },
      { id: 'autres', label: 'toute variable en _ROOT ou _DIR est contrôlée, pas seulement les cinq connues',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/essai\nLOG_DIR=/var/log/radio\nRETENTION=30\nPARTAGES_ROOT=/srv/introuvable\nTMP_DIR=/srv/essai\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION', 'INTROUVABLE PARTAGES_ROOT', 'OK TMP_DIR']), 1); } },
      { id: 'espace', label: 'un chemin qui contient une espace',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/mes fichiers\nLOG_DIR=/var/log/radio\nRETENTION=30\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION']), 0); } },
      { id: 'retention', label: 'RETENTION n\'est pas un dossier : sa valeur n\'est pas testée comme un chemin',
        setup: function (m) { put(m, 'RADIO_ROOT=/srv/essai\nLOG_DIR=/var/log/radio\nRETENTION=30\n'); }, run: 'bash controle.sh essai.conf',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION']), 0); } },
      { id: 'fichier', label: 'fichier absent : code 2 et un message',
        setup: function () { }, run: 'bash controle.sh /etc/radio/inconnu.conf',
        check: function (r) { return all([r.status === 2 || 'code de retour ' + r.status + ' au lieu de 2', r.err.trim() !== '' || 'pas de message d\'erreur']); } },
      { id: 'defaut', label: 'le chemins.conf réel du serveur : seul EXPORT_ROOT pose problème',
        setup: function () { }, run: 'bash controle.sh',
        check: function (r) { return same(r, tsv(['OK RADIO_ROOT', 'OK LOG_DIR', 'OK RETENTION', 'OK ARCHIVE_ROOT', 'VIDE EXPORT_ROOT']), 1); } }
    ],
    hints: [
      { title: 'Tester un dossier', course: 'bash', body: '[ -d "$valeur" ] est vrai pour un dossier qui existe ; [ -w "$valeur" ] pour un dossier ou un fichier qu\'on peut écrire. Garde toujours les guillemets : un chemin avec une espace se coupe sinon. Avec case $cle in *_ROOT|*_DIR), on ne teste en dossier que les variables qui en désignent un.' },
      { title: 'Parcourir les variables du fichier', course: 'bash', body: 'grep -o \'^[A-Z_]*\\(_ROOT\\|_DIR\\)=\' liste les débuts de lignes qui se terminent par _ROOT= ou _DIR= ; tr -d = retire le signe. Ajoute à la liste obligatoire celles que le fichier contient en plus, sans doublon : case " $cles " in *" $cle "*) ;; esac.' },
      { title: 'La solution', course: 'bash', body: 'case $cle in\n    *_ROOT|*_DIR)\n        if [ ! -d "$valeur" ]; then echo "INTROUVABLE $cle"; return 1\n        elif [ ! -w "$valeur" ]; then echo "LECTURE-SEULE $cle"; return 1; fi ;;\nesac' }
    ],
    intro: [{ who: 'samir', expr: 'sourcil', text: 'Une variable renseignée, c\'est bien. Mais si elle pointe vers un dossier qui n\'existe pas, le script de nuit tombe quand même dans le vide.' }],
    done: [{ who: 'samir', expr: 'sourire', text: 'INTROUVABLE et LECTURE-SEULE : maintenant le contrôle voit ce que les scripts verront.' }]
  });

  // ——— E29 · Les douze serveurs ———
  function parcRows(r) { return r.out.split('\n').filter(function (l) { return /^\S+\s+\S+\s+(OK|VIDE|ABSENT)$/.test(l); }); }
  function aligned(rows) { var w = rows.map(function (l) { return /^(\S+\s+)/.exec(l)[1].length; }); return w.every(function (x) { return x === w[0]; }); }
  function noRelais(rows) { return rows.every(function (l) { return !/^relais\s/.test(l); }); }
  S.add({
    id: 'E29', act: 4, machine: 'regie', machines: 'all', edit: ['controle-parc.sh'], start: PARC_START, ref: PARC_REF,
    cases: [
      { id: 'vis', visible: true, label: 'un tableau HOTE CLE ETAT aligné pour les onze serveurs qui répondent, « relais » signalé à part, code 1',
        setup: function () { }, run: 'bash controle-parc.sh',
        check: function (r) {
          var rows = parcRows(r);
          return all([r.status === 1 || 'code de retour ' + r.status + ' au lieu de 1', rows.length === 36 || rows.length + ' lignes de tableau au lieu de 36', aligned(rows) || 'les colonnes ne sont pas alignées (column -t)', has(r, 'archives EXPORT_ROOT VIDE') || 'il manque « archives EXPORT_ROOT VIDE »', has(r, 'jingles RETENTION VIDE') || 'il manque « jingles RETENTION VIDE »', noRelais(rows) || 'relais ne répond pas : il n\'a pas sa place dans le tableau', /relais/.test(r.out) || 'relais n\'est pas signalé comme injoignable']);
        } },
      { id: 'absente', label: 'un serveur sans RETENTION : la ligne ABSENT apparaît',
        setup: function (m, f) { editConf(f, 'web', function (t) { return t.split('\n').filter(function (l) { return !/^RETENTION=/.test(l); }).join('\n'); }); }, run: 'bash controle-parc.sh',
        check: function (r) { return all([has(r, 'web RETENTION ABSENT') || 'il manque « web RETENTION ABSENT »', r.status === 1 || 'code de retour ' + r.status]); } },
      { id: 'deux', label: 'deux serveurs injoignables : les deux sont signalés, les dix autres sont contrôlés',
        setup: function (m, f) { f.get('mail').down = 'refused'; }, run: 'bash controle-parc.sh',
        check: function (r) { var rows = parcRows(r); return all([rows.length === 33 || rows.length + ' lignes de tableau au lieu de 33', /mail/.test(r.out.split('\n').filter(function (l) { return !/^(\S+)\s+\S+\s+(OK|VIDE|ABSENT)$/.test(l); }).join('\n')) || 'mail n\'est pas signalé injoignable', /relais/.test(r.out) || 'relais n\'est pas signalé injoignable']); } },
      { id: 'liste', label: 'hotes.txt avec une ligne vide et sans retour à la ligne final : aucun serveur oublié',
        setup: function (m) { m.vfs.write('/home/admin/hotes.txt', 'antenne\n\nstudio-a\narchives'); }, run: 'bash controle-parc.sh',
        check: function (r) { var rows = parcRows(r); return all([has(r, 'antenne RADIO_ROOT OK') || 'antenne manque', has(r, 'archives EXPORT_ROOT VIDE') || 'le dernier serveur (sans retour à la ligne) est oublié', rows.length === 3 + 3 + 5 || rows.length + ' lignes au lieu de 11']); } },
      { id: 'sain', label: 'tout est en ordre et relais répond : trente-neuf lignes OK, code 0',
        setup: function (m, f) { editConf(f, 'jingles', function (t) { return t.replace('RETENTION=', 'RETENTION=30'); }); editConf(f, 'archives', function (t) { return t.replace('EXPORT_ROOT=', 'EXPORT_ROOT=/srv/data/exports'); }); f.get('relais').down = null; }, run: 'bash controle-parc.sh',
        check: function (r) { var rows = parcRows(r); return all([r.status === 0 || 'code de retour ' + r.status + ' au lieu de 0', rows.length === 39 || rows.length + ' lignes au lieu de 39', rows.every(function (l) { return /OK$/.test(l); }) || 'une ligne n\'est pas OK', !/INJOIGNABLE/.test(r.out) || 'un serveur est signalé injoignable alors que tous répondent']); } }
    ],
    hints: [
      { title: 'Boucler sur des serveurs sans casser la boucle', course: 'sys', body: 'ssh "$hote" commande renvoie le code de la commande distante, ou 255 si le serveur ne répond pas. Dans un if, ce code décide : if ! conf=$(ssh "$hote" cat /etc/radio/chemins.conf 2>/dev/null); then … continue; fi. Lis la liste avec while IFS= read -r hote … done < hotes.txt, en gardant read -r hote || [ -n "$hote" ] pour la dernière ligne.' },
      { title: 'Construire un tableau', course: 'bash', body: 'Assemble les lignes dans une variable (lignes="$lignes$hote $cle $e"$\'\\n\') plutôt que dans un sous-shell qui perdrait ses variables, puis aligne tout d\'un coup : printf \'%s\' "$lignes" | column -t. Les serveurs injoignables sont notés dans une autre variable et listés après le tableau ; un injoignable compte comme une anomalie.' },
      { title: 'La solution', course: 'bash', body: 'if ! conf=$(ssh "$hote" cat /etc/radio/chemins.conf 2>/dev/null); then\n    injoignables="$injoignables $hote"; anomalies=1; continue\nfi\nfor cle in RADIO_ROOT LOG_DIR RETENTION …; do\n    e=$(etat "$conf" "$cle")\n    [ "$e" = OK ] || anomalies=1\n    lignes="$lignes$hote $cle $e"$\'\\n\'\ndone\n…\nprintf \'%s\' "$lignes" | column -t' }
    ],
    intro: [{ who: 'odile', expr: 'neutre', text: 'Je ne veux pas contrôler douze serveurs un par un. Un seul tableau, une seule commande, depuis la régie.' }],
    done: [{ who: 'odile', expr: 'sourire', text: 'Un coup d\'œil et je vois où ça cloche. Voilà ce que j\'appelle de l\'exploitation.' }]
  });

  // ——— E30 · Le serveur au chemin vide ———
  S.add({
    id: 'E30', act: 4, machine: 'regie', machines: 'all',
    base: function (m) { B.file(m, '/home/admin/controle-parc.sh', PARC_REF, 1, 493); },
    views: { parc: { title: 'regie : bash controle-parc.sh', machine: 'regie', cmd: 'cd /home/admin && bash controle-parc.sh | tr -s " "' } }
  });

  // ——— E31 · Depuis quand ? ———
  function migrationLog() {
    var l = [];
    function a(t, msg) { l.push(t + ' ' + msg); }
    a('2025-09-02 08:00:03', 'migration : début, tâches planifiées suspendues');
    a('2025-09-02 08:00:05', 'crontab : ligne nettoyage-nuit mise en commentaire');
    a('2025-09-02 08:05:11', 'rsync /srv/archives -> /srv/data/archives : début');
    for (var i = 1; i <= 12; i++) a('2025-09-02 ' + ('0' + (8 + Math.floor(i / 4))).slice(-2) + ':' + ('0' + (5 + i * 3)).slice(-2) + ':00', 'rsync : ' + (i * 100) + ' fichiers copiés');
    a('2025-09-02 11:42:30', 'rsync /srv/archives -> /srv/data/archives : terminé (1204 fichiers)');
    a('2025-09-02 11:45:00', 'chemins.conf : ARCHIVE_ROOT=/srv/data/archives');
    a('2025-09-03 09:10:02', 'rsync /srv/exports -> /srv/data/exports : début');
    for (var j = 1; j <= 8; j++) a('2025-09-03 09:' + ('0' + (10 + j * 2)).slice(-2) + ':00', 'rsync : ' + (j * 40) + ' fichiers copiés');
    a('2025-09-03 09:31:00', 'rsync /srv/exports -> /srv/data/exports : terminé (322 fichiers)');
    a('2025-09-03 09:35:12', 'chemins.conf : EXPORT_ROOT laissé vide (à renseigner)');
    a('2025-09-05 14:20:00', 'vérification des partages : ok');
    a('2025-09-14 18:02:44', 'crontab restaurée depuis la sauvegarde du 2025-08-30');
    a('2025-09-14 18:02:45', 'tâches planifiées réactivées');
    a('2025-09-14 18:05:00', 'migration : terminée');
    return l.join('\n') + '\n';
  }
  function logWorld(m) { B.file(m, '/var/log/radio/migration-2025-09.log', migrationLog(), 178); }
  function essaiLog(m, text) { m.vfs.file('/home/admin/essai.log', text, { mtime: m.vfs.now - 3600 }); }
  S.add({
    id: 'E31', act: 4, machine: 'archives', edit: ['quand.sh'], start: QUAND_START, ref: QUAND_REF,
    show: ['/var/log/radio/migration-2025-09.log'],
    base: function (m) { logWorld(m); },
    cases: [
      { id: 'vis', visible: true, label: 'la date de « tâches planifiées réactivées » est le 2025-09-14',
        setup: function () { }, run: 'bash quand.sh "tâches planifiées réactivées"',
        check: function (r) { return same(r, '2025-09-14\n', 0); } },
      { id: 'premiere', label: 'plusieurs lignes contiennent le motif : c\'est la date de la première qui compte',
        setup: function () { }, run: 'bash quand.sh "chemins.conf"',
        check: function (r) { return same(r, '2025-09-02\n', 0); } },
      { id: 'casse', label: 'avec -i, la casse est ignorée',
        setup: function () { }, run: 'bash quand.sh -i "CRONTAB RESTAURÉE"',
        check: function (r) { return same(r, '2025-09-14\n', 0); } },
      { id: 'sans-i', label: 'sans -i, la casse compte : « CRONTAB RESTAURÉE » ne correspond à rien',
        setup: function () { }, run: 'bash quand.sh "CRONTAB RESTAURÉE"',
        check: function (r) { return all([r.status === 1 || 'code de retour ' + r.status + ' au lieu de 1', r.out === '' || 'quelque chose est écrit : ' + JSON.stringify(r.out)]); } },
      { id: 'special', label: 'un motif aux caractères spéciaux se cherche tel quel (« a.b » ne correspond pas à « axb »)',
        setup: function (m) { essaiLog(m, '2025-01-01 00:00:01 fichier axb ignoré\n2025-01-02 00:00:01 fichier a.b ignoré\n'); }, run: 'bash quand.sh "a.b" essai.log',
        check: function (r) { return same(r, '2025-01-02\n', 0); } },
      { id: 'etoile', label: 'un motif avec « * » et « [ » : copie de *.tmp [12 fichiers]',
        setup: function (m) { essaiLog(m, '2025-02-01 10:00:00 copie de x.tmp ignorée\n2025-02-02 10:00:00 copie de *.tmp [12 fichiers]\n'); }, run: 'bash quand.sh "*.tmp [12" essai.log',
        check: function (r) { return same(r, '2025-02-02\n', 0); } },
      { id: 'absent', label: 'un motif qui n\'apparaît nulle part : rien sur la sortie, code 1',
        setup: function () { }, run: 'bash quand.sh "intrus"',
        check: function (r) { return all([r.status === 1 || 'code de retour ' + r.status + ' au lieu de 1', r.out === '' || 'quelque chose est écrit : ' + JSON.stringify(r.out)]); } },
      { id: 'sans-motif', label: 'sans motif : usage et code 2',
        setup: function () { }, run: 'bash quand.sh',
        check: function (r) { return all([r.status === 2 || 'code de retour ' + r.status + ' au lieu de 2', /usage/i.test(r.err) || 'pas de message « usage »']); } }
    ],
    hints: [
      { title: 'Un motif n\'est pas forcément une expression régulière', course: 'bash', body: 'grep lit son motif comme une expression régulière : le point remplace n\'importe quel caractère, « * » et « [ » ont un sens. Avec grep -F, le motif est un texte à chercher tel quel. Entre guillemets, un motif à espaces reste un seul argument ; -- protège un motif qui commencerait par un tiret.' },
      { title: 'Le premier, puis son premier mot', course: 'bash', body: 'grep … | head -n 1 garde la première ligne qui correspond. La date est le premier mot de la ligne : ${ligne%% *} retire tout à partir de la première espace. Une ligne vide veut dire « introuvable » : exit 1. Pour -i : teste $1 avant tout, et fais un shift pour que $1 devienne le motif.' },
      { title: 'La solution', course: 'bash', body: 'OPT=-F\nif [ "${1:-}" = "-i" ]; then OPT=-Fi; shift; fi\nMOTIF=$1\nligne=$(grep "$OPT" -- "$MOTIF" "$FICHIER" | head -n 1)\n[ -n "$ligne" ] || exit 1\necho "${ligne%% *}"' }
    ],
    intro: [{ who: 'odile', expr: 'sourcil', text: 'Le journal de la migration de septembre est là, long de quarante lignes. Je voudrais pouvoir demander « quand a eu lieu ceci ? » sans le relire.' }],
    done: [{ who: 'odile', expr: 'surprise', text: 'Réactivées le 14 septembre, alors que la configuration n\'a jamais été terminée. Ça commence à ressembler à une histoire.' }]
  });

  // ——— E32 · Chiffrer les dégâts ———
  var EMIS = ['la-matinale', 'jazz-de-nuit', 'le-grand-bal', 'l-heure-bleue'];
  function entries(n) {
    var out = [];
    for (var i = 0; i < n; i++) {
      var yr = 2019 + (i % 4), em = EMIS[i % 4], mo = ('0' + (1 + Math.floor(i / 28) + (i % 4) * 2)).slice(-2), da = ('0' + (1 + i % 28)).slice(-2);
      out.push({ p: yr + '/' + em + '/' + yr + '-' + mo + '-' + da + '.wav', mb: 30 + (i * 7) % 31 });
    }
    return out;
  }
  function wav(m, e) { B.big(m, '/srv/data/archives/' + e.p, e.mb * MB, 400); }
  function liste(m, es) { m.vfs.file('/home/admin/liste-2026-03-11.txt', es.map(function (e) { return (e.mb * MB) + ' /srv/data/archives/' + e.p; }).join('\n') + (es.length ? '\n' : ''), { mtime: m.vfs.now - 40000 }); }
  function expected(gone) { var mb = 0; gone.forEach(function (e) { mb += e.mb; }); return gone.length + ' fichiers, ' + mb + ' Mo\n'; }
  S.add({
    id: 'E32', act: 4, machine: 'sauvegarde', machines: ['sauvegarde', 'archives'], edit: ['degats.sh'], start: DEGATS_START, ref: DEGATS_REF,
    cases: [
      { id: 'vis', visible: true, label: 'sur quarante fichiers listés hier soir, six ont disparu : « 6 fichiers, … Mo », au Mo près',
        setup: function (m, f) { var es = entries(40), a = f.get('archives'); es.forEach(function (e, i) { if (i % 7 !== 3) wav(a, e); }); liste(m, es); this.exp = expected(es.filter(function (e, i) { return i % 7 === 3; })); this.es = es; },
        run: 'bash degats.sh liste-2026-03-11.txt',
        check: function (r, m, f) { var es = entries(40); return same(r, expected(es.filter(function (e, i) { return i % 7 === 3; })), 0); } },
      { id: 'aucun', label: 'rien n\'a disparu : « 0 fichiers, 0 Mo »',
        setup: function (m, f) { var es = entries(12); es.forEach(function (e) { wav(f.get('archives'), e); }); liste(m, es); }, run: 'bash degats.sh liste-2026-03-11.txt',
        check: function (r) { return same(r, '0 fichiers, 0 Mo\n', 0); } },
      { id: 'espaces', label: 'des fichiers dont le chemin contient des espaces disparaissent aussi',
        setup: function (m, f) { var es = entries(6); es.push({ p: '2019/la matinale/2019 11 04.wav', mb: 44 }, { p: '2019/jazz de nuit/2019 12 02.wav', mb: 31 }); es.slice(0, 6).forEach(function (e) { wav(f.get('archives'), e); }); liste(m, es); }, run: 'bash degats.sh liste-2026-03-11.txt',
        check: function (r) { return same(r, '2 fichiers, 75 Mo\n', 0); } },
      { id: 'nouveaux', label: 'des fichiers apparus depuis hier soir et absents de la liste sont ignorés',
        setup: function (m, f) { var es = entries(10), a = f.get('archives'); es.forEach(function (e, i) { if (i !== 4) wav(a, e); }); wav(a, { p: '2026/la-matinale/2026-03-10.wav', mb: 55 }); wav(a, { p: '2026/jazz-de-nuit/2026-03-11.wav', mb: 61 }); liste(m, es); this.gone = es[4].mb; }, run: 'bash degats.sh liste-2026-03-11.txt',
        check: function (r) { var e4 = entries(10)[4]; return same(r, '1 fichiers, ' + e4.mb + ' Mo\n', 0); } },
      { id: 'vide', label: 'une liste vide : « 0 fichiers, 0 Mo », sans erreur',
        setup: function (m, f) { entries(5).forEach(function (e) { wav(f.get('archives'), e); }); liste(m, []); }, run: 'bash degats.sh liste-2026-03-11.txt',
        check: function (r) { return same(r, '0 fichiers, 0 Mo\n', 0); } },
      { id: 'tout', label: 'archives est vide : tous les fichiers de la liste ont disparu',
        setup: function (m, f) { liste(m, entries(8)); }, run: 'bash degats.sh liste-2026-03-11.txt',
        check: function (r) { return same(r, expected(entries(8)), 0); } },
      { id: 'absente', label: 'liste introuvable : un message et un code en échec',
        setup: function () { }, run: 'bash degats.sh inconnue.txt',
        check: function (r) { return all([r.status !== 0 || 'le script réussit alors que la liste n\'existe pas', r.err.trim() !== '' || 'pas de message d\'erreur']); } }
    ],
    hints: [
      { title: 'Comparer deux listes sans se tromper', course: 'bash', body: 'comm -23 suppose deux listes triées avec les mêmes lignes ; or la liste d\'hier soir porte une taille devant chaque chemin, et une taille a pu changer. Compare les chemins, pas les lignes entières : awk range les chemins de la liste actuelle dans un tableau associatif (present[chemin] = 1), puis parcourt la liste d\'hier et compte ceux qui n\'y sont pas.' },
      { title: 'awk avec deux fichiers', course: 'bash', body: 'awk reçoit les deux fichiers l\'un après l\'autre. FILENAME donne le fichier en cours de lecture : FILENAME == actuel { … ; next } traite le premier fichier, le bloc suivant le second. N\'utilise pas NR == FNR : il se trompe quand le premier fichier est vide. sub(/^[0-9]+ /, "", chemin) retire la taille ; $1 est la taille en octets. Division par 1048576 pour les Mo, et %d dans printf pour tronquer.' },
      { title: 'La solution', course: 'bash', body: 'ssh archives "find /srv/data/archives -type f -printf \'%s %p\\n\'" > "$ACTUEL"\nawk -v actuel="$ACTUEL" \'\n    FILENAME == actuel { sub(/^[0-9]+ /, ""); present[$0] = 1; next }\n    { chemin = $0; sub(/^[0-9]+ /, "", chemin)\n      if (!(chemin in present)) { n++; octets += $1 } }\n    END { printf "%d fichiers, %d Mo\\n", n, octets / 1048576 }\n\' "$ACTUEL" "$LISTE"' }
    ],
    intro: [{ who: 'yanis', expr: 'sourcil', text: 'Odile veut un chiffre pour la direction : combien de fichiers ont disparu, et combien de Mo ça représente. Je n\'ai que la liste d\'hier soir.' }],
    done: [{ who: 'yanis', expr: 'surprise', text: 'Voilà le chiffre. Je comprends mieux pourquoi mes cassettes ne sont plus nulle part…' }]
  });
})(RCSteps);
