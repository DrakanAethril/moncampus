/* Acte 5 — La nuit de 3 h (jeudi, 10 h 20 → 3 h). Réparer, protéger, restaurer : pipefail, garde-fous,
   simulation, flock, journal, find -print0, timers systemd, idempotence. Machine : `archives`.

   Le script de nettoyage grandit d'étape en étape : la référence de l'une est le départ de la suivante
   (E33 garde-fous → E34 --apply → E35 verrou → E36 journal → E37 noms piégés). E38 en fait un timer,
   E39 restaure ce que la purge a emporté, E40 en fait le rapport. */
(function (S) {
  'use strict';
  var B = S.B, DAY = 86400;
  var EXP = '/srv/data/exports', ARC = '/srv/data/archives', LOG = '/var/log/radio/nettoyage.log';
  var OLD1 = EXP + '/matin-2026-02-02.wav', OLD2 = EXP + '/Émission du soir 2026-01-15.wav', NEW1 = EXP + '/jazz-2026-03-10.wav';
  var ARC1 = ARC + '/2019/la-matinale/2019-11-04.wav', ARC2 = ARC + '/2026/jazz-de-nuit/2026-01-20.wav';

  // ——— Le script, par morceaux ———
  function join() { return Array.prototype.slice.call(arguments).join('\n') + '\n'; }

  var START33 = join(
    '#!/bin/bash',
    '# nettoyage-nuit.sh : supprime les anciens .wav des exports (Marc, retouché)',
    '. /etc/radio/chemins.conf',
    '',
    'RACINE="${EXPORT_ROOT:-/srv/data}"',
    'JOURS=${RETENTION:-30}',
    '',
    'purge() {',
    '    find "$1" -type f -name \'*.wav\' -mtime +"$2" -delete',
    '}',
    '',
    'purge "$RACINE" "$JOURS"');

  var HEAD = [
    '#!/bin/bash',
    '# nettoyage-nuit.sh : supprime les anciens .wav du dossier d\'exports',
    'set -euo pipefail',
    '. /etc/radio/chemins.conf',
    '',
    'ATTENDU=/srv/data/exports',
    'JOURS=${RETENTION:-30}',
    ''].join('\n');

  var ERR_PLAIN = [
    'erreur() {',
    '    echo "nettoyage : $*" >&2',
    '    exit 2',
    '}',
    ''].join('\n');

  var ERR_LOG = [
    'erreur() {',
    '    journal "REFUS : $*"',
    '    echo "nettoyage : $*" >&2',
    '    exit 2',
    '}',
    ''].join('\n');

  var CHECKS = [
    '[ -n "${EXPORT_ROOT:-}" ] || erreur "EXPORT_ROOT est vide"',
    'RACINE=$(realpath "$EXPORT_ROOT")',
    '[ -d "$RACINE" ] || erreur "$RACINE n\'existe pas"',
    'case $RACINE in',
    '    "$ATTENDU" | "$ATTENDU"/*) ;;',
    '    *) erreur "$RACINE n\'est pas sous $ATTENDU" ;;',
    'esac',
    'case $JOURS in',
    '    \'\' | *[!0-9]*) erreur "RETENTION doit être un nombre entier de jours : $JOURS" ;;',
    'esac',
    '[ "$JOURS" -gt 0 ] || erreur "RETENTION doit valoir au moins 1 jour"',
    ''].join('\n');

  var ARGS_OK = [
    'APPLY=0',
    'if [ $# -gt 1 ]; then',
    '    echo "usage : $0 [--apply]" >&2',
    '    exit 2',
    'fi',
    'case ${1:-} in',
    '    \'\') ;;',
    '    --apply) APPLY=1 ;;',
    '    *) echo "usage : $0 [--apply]" >&2; exit 2 ;;',
    'esac',
    ''].join('\n');

  var ARGS_BAD = [
    'APPLY=0',
    'if [ "${1:-}" = "--apply" ]; then',
    '    APPLY=1',
    'fi',
    ''].join('\n');

  var LOCK_OK = [
    'mkdir -p /var/lock',
    'exec 9>/var/lock/nettoyage.lock',
    'if ! flock -n 9; then',
    '    echo "nettoyage : déjà en cours"',
    '    exit 0',
    'fi',
    ''].join('\n');

  var LOCK_BAD = [
    'exec 9>/var/lock/nettoyage.lock',
    'flock 9',
    ''].join('\n');

  var LOGHEAD = [
    'LOG=/var/log/radio/nettoyage.log',
    'mkdir -p "$(dirname "$LOG")"',
    'journal() {',
    '    echo "$(date \'+%F %T\') $*" >> "$LOG"',
    '    logger -t nettoyage "$*"',
    '}',
    ''].join('\n');

  var LOG_BAD = [
    'LOG=/var/log/radio/nettoyage.log',
    'echo "début" > "$LOG"',
    ''].join('\n');

  var W_DEL = 'find "$RACINE" -type f -name \'*.wav\' -mtime +"$JOURS" -delete\n';

  var W_SIM = [
    'if [ "$APPLY" -eq 1 ]; then',
    '    find "$RACINE" -type f -name \'*.wav\' -mtime +"$JOURS" -delete',
    'else',
    '    find "$RACINE" -type f -name \'*.wav\' -mtime +"$JOURS" | while IFS= read -r f; do',
    '        echo "à supprimer : $f"',
    '    done',
    'fi',
    ''].join('\n');

  function wlog(robust) {
    return [
      'if [ "$APPLY" -eq 1 ]; then MODE=\'\'; else MODE=\' (SIMULATION)\'; fi',
      'journal "début$MODE"',
      'n=0',
      robust ? 'while IFS= read -r -d \'\' f; do' : 'while IFS= read -r f; do',
      '    if [ "$APPLY" -eq 1 ]; then',
      '        rm -f -- "$f"',
      '        journal "supprimé : $f"',
      '    else',
      '        echo "à supprimer : $f"',
      '        journal "SIMULATION : à supprimer : $f"',
      '    fi',
      '    n=$((n + 1))',
      robust ? 'done < <(find "$RACINE" -type f -iname \'*.wav\' -mtime +"$JOURS" -print0)' : 'done < <(find "$RACINE" -type f -name \'*.wav\' -mtime +"$JOURS")',
      'journal "fin : $n fichiers"',
      ''].join('\n');
  }

  var REF33 = HEAD + '\n' + ERR_PLAIN + '\n' + CHECKS + '\n' + W_DEL;
  var START34 = HEAD + '\n' + ERR_PLAIN + '\n' + CHECKS + '\n' + ARGS_BAD + '\n' + W_DEL;
  var REF34 = HEAD + '\n' + ARGS_OK + '\n' + ERR_PLAIN + '\n' + CHECKS + '\n' + W_SIM;
  var START35 = HEAD + '\n' + ARGS_OK + '\n' + ERR_PLAIN + '\n' + CHECKS + '\n' + LOCK_BAD + '\n' + W_SIM;
  var REF35 = HEAD + '\n' + ARGS_OK + '\n' + ERR_PLAIN + '\n' + CHECKS + '\n' + LOCK_OK + '\n' + W_SIM;
  var START36 = HEAD + '\n' + ARGS_OK + '\n' + ERR_PLAIN + '\n' + CHECKS + '\n' + LOCK_OK + '\n' + LOG_BAD + '\n' + W_SIM;
  var REF36 = HEAD + '\n' + ARGS_OK + '\n' + LOGHEAD + '\n' + ERR_LOG + '\n' + CHECKS + '\n' + LOCK_OK + '\n' + wlog(false);
  var REF37 = HEAD + '\n' + ARGS_OK + '\n' + LOGHEAD + '\n' + ERR_LOG + '\n' + CHECKS + '\n' + LOCK_OK + '\n' + wlog(true);

  // ——— Les mondes ———
  function conf(m, root, retention) {
    B.file(m, '/etc/radio/chemins.conf', '# /etc/radio/chemins.conf -- archives\nRADIO_ROOT=/srv/data\nLOG_DIR=/var/log/radio\nRETENTION=' + (retention === undefined ? '' : retention) + '\nARCHIVE_ROOT=/srv/data/archives\nEXPORT_ROOT=' + (root === undefined ? EXP : root) + '\n', 40);
  }
  function monde(m) {
    m.vfs.dir(EXP); m.vfs.dir('/var/lock'); m.vfs.dir('/var/log/radio');
    B.big(m, OLD1, 2e6, 38); B.big(m, OLD2, 2e6, 56); B.big(m, NEW1, 2e6, 2);
    B.big(m, ARC1, 3e6, 2320); B.big(m, ARC2, 3e6, 51);
    conf(m);
  }
  function all(list) { for (var i = 0; i < list.length; i++) if (list[i] !== true) return list[i]; return true; }
  function has(m, p) { return m.vfs.exists(p) || m.vfs.lexists(p); }
  function archivesOk(m) { return (has(m, ARC1) && has(m, ARC2)) || 'des archives de /srv/data/archives ont été supprimées'; }
  function exportsOk(m) { return (has(m, OLD1) && has(m, OLD2) && has(m, NEW1)) || 'des exports ont été supprimés alors que le script devait refuser'; }
  function purged(m) { return all([!has(m, OLD1) || 'matin-2026-02-02.wav (38 jours) n\'a pas été supprimé', !has(m, OLD2) || '« Émission du soir… » (56 jours) n\'a pas été supprimé', has(m, NEW1) || 'jazz-2026-03-10.wav (2 jours) a été supprimé à tort', archivesOk(m)]); }
  function untouched(m) { return all([has(m, OLD1) && has(m, OLD2) || 'des exports ont été supprimés sans --apply', has(m, NEW1), archivesOk(m)]); }
  function refuse(r, m) { return all([r.status === 2 || 'code de retour ' + r.status + ' au lieu de 2', r.err.trim() !== '' || 'aucun message sur la sortie d\'erreur', archivesOk(m), exportsOk(m)]); }
  function lines(s) { return s.split('\n').filter(Boolean); }
  function logText(m) { return m.vfs.isFile(LOG) ? m.vfs.read(LOG) : ''; }
  function announced(r) { return lines(r.out).filter(function (l) { return /^à supprimer : /.test(l); }); }

  // ——— E33 · Des garde-fous ———
  function cas33(id, label, root, ret, vis, extra) {
    return { id: id, visible: !!vis, label: label,
      setup: function (m) { conf(m, root, ret); if (extra) extra(m); }, run: 'bash nettoyage-nuit.sh', check: function (r, m) { return refuse(r, m); } };
  }
  S.add({
    id: 'E33', act: 5, machine: 'archives', edit: ['nettoyage-nuit.sh'], start: START33, ref: REF33, show: ['/etc/radio/chemins.conf'],
    base: function (m) { monde(m); },
    cases: [
      cas33('vide', 'EXPORT_ROOT est vide : le script refuse et ne touche pas aux archives', '', undefined, true),
      cas33('racine', 'la racine « / » est refusée', '/', undefined, false),
      cas33('large', '/srv/data est trop large : seul le dossier d\'exports est permis', '/srv/data', undefined, false),
      cas33('absent', 'un dossier qui n\'existe pas', EXP + '/nope', undefined, false),
      cas33('remonte', 'un chemin qui remonte (exports/../archives)', EXP + '/../archives', undefined, false),
      cas33('lien', 'un dossier d\'exports qui est un lien vers les archives', EXP + '/lien', undefined, false, function (m) { m.vfs.symlink(ARC, EXP + '/lien'); }),
      cas33('texte', 'RETENTION n\'est pas un nombre', EXP, 'abc', false),
      cas33('zero', 'RETENTION vaut 0 : on ne supprime pas tout', EXP, '0', false),
      { id: 'normal', label: 'cas normal : les vieux exports (avec espaces) disparaissent, les récents et les archives restent', visible: false,
        setup: function () { }, run: 'bash nettoyage-nuit.sh', check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], purged(m)]); } }
    ],
    hints: [
      { title: '${VAR:-défaut} : un repli dangereux', course: 'bash', body: '${EXPORT_ROOT:-/srv/data} remplace une variable vide par /srv/data : c\'est ce repli qui fait pointer la purge vers les archives. Pour un chemin qu\'on va vider, pas de valeur par défaut : on refuse. Teste [ -n "${EXPORT_ROOT:-}" ] || erreur "…".' },
      { title: 'Valider avant de supprimer', course: 'bash', body: 'Résous d\'abord le chemin réel avec realpath (il suit les liens et enlève les « .. »), puis vérifie qu\'il existe (-d) et qu\'il est bien sous /srv/data/exports avec un case : "$ATTENDU" | "$ATTENDU"/*) ;; *) erreur … ;;. Fais de même pour RETENTION : *[!0-9]*) refuse tout ce qui n\'est pas un entier, puis [ "$JOURS" -gt 0 ].' },
      { title: 'La solution', course: 'bash', body: 'set -euo pipefail\nerreur() { echo "nettoyage : $*" >&2; exit 2; }\n[ -n "${EXPORT_ROOT:-}" ] || erreur "EXPORT_ROOT est vide"\nRACINE=$(realpath "$EXPORT_ROOT")\n[ -d "$RACINE" ] || erreur "$RACINE n\'existe pas"\ncase $RACINE in\n    "$ATTENDU" | "$ATTENDU"/*) ;;\n    *) erreur "$RACINE n\'est pas sous $ATTENDU" ;;\nesac\ncase $JOURS in \'\' | *[!0-9]*) erreur "RETENTION invalide" ;; esac\n[ "$JOURS" -gt 0 ] || erreur "RETENTION doit valoir au moins 1 jour"\nfind "$RACINE" -type f -name \'*.wav\' -mtime +"$JOURS" -delete' }
    ],
    intro: [{ who: 'odile', expr: 'surprise', text: 'On sait ce qui supprime. Maintenant, je veux que ce script ne puisse plus jamais viser autre chose que le dossier des exports.' }],
    done: [{ who: 'colette', expr: 'sourire', text: 'Un script qui refuse, c\'est un script qui a compris ce qu\'il manipule.' }]
  });

  // ——— E34 · Simuler par défaut ———
  S.add({
    id: 'E34', act: 5, machine: 'archives', edit: ['nettoyage-nuit.sh'], start: START34, ref: REF34, show: ['/etc/radio/chemins.conf'],
    base: function (m) { monde(m); B.file(m, '/home/admin/nettoyage-nuit.sh', START34, 5, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'sans --apply, le script annonce les deux vieux exports et ne supprime rien',
        setup: function () { }, run: 'bash nettoyage-nuit.sh',
        check: function (r, m) { return all([r.status === 0 || 'code de retour ' + r.status + ' : ' + r.err.split('\n')[0], announced(r).length === 2 || announced(r).length + ' lignes « à supprimer : … » au lieu de 2', untouched(m)]); } },
      { id: 'apply', label: 'avec --apply, les suppressions ont lieu et rien n\'est annoncé',
        setup: function () { }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], purged(m), announced(r).length === 0 || 'le script annonce des suppressions qui ont déjà eu lieu']); } },
      { id: 'typo', label: 'une option inconnue (--appliquer) : usage, code 2, rien de supprimé',
        setup: function () { }, run: 'bash nettoyage-nuit.sh --appliquer',
        check: function (r, m) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, /usage/i.test(r.err) || 'pas de message « usage »', untouched(m)]); } },
      { id: 'deux', label: 'deux arguments : usage, code 2, rien de supprimé',
        setup: function () { }, run: 'bash nettoyage-nuit.sh --apply --apply',
        check: function (r, m) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, untouched(m)]); } },
      { id: 'espaces', label: 'un nom avec des espaces est annoncé en entier',
        setup: function () { }, run: 'bash nettoyage-nuit.sh',
        check: function (r) { return announced(r).indexOf('à supprimer : ' + OLD2) !== -1 || 'la ligne « à supprimer : ' + OLD2 + ' » manque'; } },
      { id: 'rien', label: 'rien d\'ancien : aucune ligne, code 0',
        setup: function (m) { m.vfs.remove(OLD1); m.vfs.remove(OLD2); }, run: 'bash nettoyage-nuit.sh',
        check: function (r) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], r.out.trim() === '' || 'le script annonce des suppressions alors qu\'il n\'y a rien d\'ancien']); } },
      { id: 'garde', label: 'les garde-fous restent actifs avec --apply (EXPORT_ROOT vide)',
        setup: function (m) { conf(m, ''); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return refuse(r, m); } }
    ],
    hints: [
      { title: 'Ne rien faire par défaut', course: 'bash', body: 'Une opération irréversible doit se demander explicitement. Ici : sans option, le script décrit ; seule l\'option --apply supprime. Un case sur ${1:-} traite les trois cas : rien (\'\'), --apply, et tout le reste (usage, exit 2).' },
      { title: 'Décrire sans supprimer', course: 'bash', body: 'La même commande find sert aux deux modes : -delete pour supprimer, ou sans action pour lister, suivi d\'une boucle while IFS= read -r f; do echo "à supprimer : $f"; done. IFS= et -r gardent les noms intacts (espaces, antislash).' },
      { title: 'La solution', course: 'bash', body: 'APPLY=0\ncase ${1:-} in\n    \'\') ;;\n    --apply) APPLY=1 ;;\n    *) echo "usage : $0 [--apply]" >&2; exit 2 ;;\nesac\nif [ "$APPLY" -eq 1 ]; then\n    find "$RACINE" -type f -name \'*.wav\' -mtime +"$JOURS" -delete\nelse\n    find … | while IFS= read -r f; do echo "à supprimer : $f"; done\nfi' }
    ],
    intro: [{ who: 'yanis', expr: 'sourcil', text: 'Ce soir, on ne supprime rien sans que quelqu\'un l\'ait demandé. Je veux voir la liste avant, toujours.' }],
    done: [{ who: 'yanis', expr: 'sourire', text: 'La liste d\'abord, la suppression ensuite. C\'est exactement ce que je voulais.' }]
  });

  // ——— E35 · Un seul à la fois ———
  function held(m) { m.locks.set('/var/lock/nettoyage.lock', 99999); }
  S.add({
    id: 'E35', act: 5, machine: 'archives', edit: ['nettoyage-nuit.sh'], start: START35, ref: REF35, show: [],
    base: function (m) { monde(m); B.file(m, '/home/admin/nettoyage-nuit.sh', START35, 5, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'une autre purge tient déjà le verrou : le script le dit, sort avec le code 0 et ne supprime rien',
        setup: held, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([r.status === 0 || 'code de retour ' + r.status + ' au lieu de 0', /déjà en cours/.test(r.all) || 'le message « déjà en cours » manque', untouched(m)]); } },
      { id: 'libre', label: 'verrou libre : la purge se fait et le verrou est libéré à la sortie',
        setup: function () { }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], purged(m), m.locks.size === 0 || 'le verrou n\'est pas libéré à la sortie']); } },
      { id: 'deux', label: 'deux lancements l\'un après l\'autre : le second n\'est pas bloqué par le premier',
        setup: function () { }, run: 'bash nettoyage-nuit.sh --apply; bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([r.status === 0 || 'échec du second lancement : ' + r.err.split('\n')[0], !/déjà en cours/.test(r.all) || 'le second lancement croit que le premier tourne encore', purged(m)]); } },
      { id: 'dossier', label: 'le dossier /var/lock n\'existe pas : le script le crée',
        setup: function (m) { m.vfs.remove('/var/lock', true); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], purged(m)]); } },
      { id: 'simulation', label: 'en simulation aussi, un verrou tenu arrête le script',
        setup: held, run: 'bash nettoyage-nuit.sh',
        check: function (r, m) { return all([r.status === 0 || 'code de retour ' + r.status, /déjà en cours/.test(r.all) || 'le message « déjà en cours » manque', announced(r).length === 0 || 'des lignes « à supprimer » sont apparues malgré le verrou']); } },
      { id: 'garde', label: 'les refus de l\'étape précédente tiennent toujours (EXPORT_ROOT vide)',
        setup: function (m) { conf(m, ''); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return refuse(r, m); } }
    ],
    hints: [
      { title: 'Deux purges en même temps', course: 'bash', body: 'Si un lancement manuel chevauche celui de 3 h, deux find suppriment en parallèle. Le verrou règle cela : un fichier qu\'un seul processus peut « tenir » à la fois. flock -n échoue tout de suite (sans attendre) quand quelqu\'un d\'autre le tient.' },
      { title: 'Le verrou d\'un descripteur', course: 'bash', body: 'exec 9>/var/lock/nettoyage.lock ouvre le fichier sur le descripteur 9 pour toute la durée du script ; flock -n 9 pose le verrou dessus. Il est relâché tout seul à la sortie du script. Pense à créer /var/lock (mkdir -p) avant. Si le verrou est pris : message, exit 0 — ce n\'est pas une erreur, l\'autre purge fera le travail.' },
      { title: 'La solution', course: 'bash', body: 'mkdir -p /var/lock\nexec 9>/var/lock/nettoyage.lock\nif ! flock -n 9; then\n    echo "nettoyage : déjà en cours"\n    exit 0\nfi' }
    ],
    intro: [{ who: 'odile', expr: 'neutre', text: 'Hier, quelqu\'un a lancé la purge à la main pendant celle de 3 h. Je veux qu\'une seule tourne à la fois.' }],
    done: [{ who: 'colette', expr: 'sourire', text: 'Un verrou, un flock -n, et plus personne ne se marche dessus.' }]
  });

  // ——— E36 · Garder une trace ———
  S.add({
    id: 'E36', act: 5, machine: 'archives', edit: ['nettoyage-nuit.sh'], start: START36, ref: REF36, show: [],
    base: function (m) { monde(m); B.file(m, '/home/admin/nettoyage-nuit.sh', START36, 5, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'avec --apply, le journal a une ligne « début », une ligne par fichier supprimé et « fin : 2 fichiers » ; le syslog aussi',
        setup: function () { }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) {
          var l = logText(m), ls = lines(l), sys = m.vfs.isFile('/var/log/syslog') ? m.vfs.read('/var/log/syslog') : '';
          return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], /^2026-03-12 10:20:00 début$/m.test(l) || 'la ligne « 2026-03-12 10:20:00 début » manque dans le journal', ls.filter(function (x) { return /^2026-03-12 10:20:00 supprimé : \/srv\/data\/exports\//.test(x); }).length === 2 || 'il faut une ligne « supprimé : chemin » par fichier supprimé (2)', /^2026-03-12 10:20:00 fin : 2 fichiers$/m.test(l) || 'la ligne « fin : 2 fichiers » manque', /nettoyage: .*fin : 2 fichiers/.test(sys) || 'logger -t nettoyage n\'a rien écrit dans /var/log/syslog']);
        } },
      { id: 'ancien', label: 'un journal existant est complété, pas écrasé',
        setup: function (m) { B.file(m, LOG, 'ligne d\'hier\n', 1); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { var l = lines(logText(m)); return l[0] === 'ligne d\'hier' && l.length > 3 ? true : 'le journal de la veille a été écrasé ou la suite manque'; } },
      { id: 'refus', label: 'un refus (EXPORT_ROOT vide) est écrit dans le journal',
        setup: function (m) { conf(m, ''); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([refuse(r, m), /REFUS : .*EXPORT_ROOT/.test(logText(m)) || 'le journal ne contient pas de ligne « REFUS : … »']); } },
      { id: 'dossier', label: 'le dossier du journal n\'existe pas : le script le crée',
        setup: function (m) { m.vfs.remove('/var/log/radio', true); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], m.vfs.isFile(LOG) || 'le journal n\'a pas été créé']); } },
      { id: 'simulation', label: 'en simulation, le journal le dit (SIMULATION) et rien n\'est supprimé',
        setup: function () { }, run: 'bash nettoyage-nuit.sh',
        check: function (r, m) { var l = logText(m); return all([/début \(SIMULATION\)/.test(l) || 'la ligne « début (SIMULATION) » manque', /SIMULATION : à supprimer : /.test(l) || 'les lignes « SIMULATION : à supprimer : … » manquent', !/supprimé : /.test(l) || 'le journal parle de suppressions en simulation', untouched(m)]); } },
      { id: 'rien', label: 'rien à supprimer : « fin : 0 fichiers »',
        setup: function (m) { m.vfs.remove(OLD1); m.vfs.remove(OLD2); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return /fin : 0 fichiers/.test(logText(m)) ? true : 'la ligne « fin : 0 fichiers » manque'; } }
    ],
    hints: [
      { title: 'Une fonction pour écrire le journal', course: 'bash', body: 'Écris une fois la manière de journaliser : journal() { echo "$(date \'+%F %T\') $*" >> "$LOG"; }. >> ajoute à la fin au lieu d\'écraser (>). Crée le dossier avec mkdir -p "$(dirname "$LOG")" avant la première écriture.' },
      { title: 'Compter dans une boucle', course: 'bash', body: 'Si tu boucles derrière un tube (find … | while read), la boucle tourne dans un sous-shell : le compteur n\'existe plus à la sortie. Écris plutôt while … done < <(find …) : la boucle reste dans le shell courant et n=$((n + 1)) survit. logger -t nettoyage "$*" copie la ligne dans le journal du système.' },
      { title: 'La solution', course: 'bash', body: 'journal() {\n    echo "$(date \'+%F %T\') $*" >> "$LOG"\n    logger -t nettoyage "$*"\n}\nerreur() { journal "REFUS : $*"; echo "nettoyage : $*" >&2; exit 2; }\n…\njournal "début$MODE"\nn=0\nwhile IFS= read -r f; do\n    …; journal "supprimé : $f"; n=$((n + 1))\ndone < <(find …)\njournal "fin : $n fichiers"' }
    ],
    intro: [{ who: 'odile', expr: 'sourcil', text: 'Demain matin, je veux pouvoir dire à Yanis ce qui a été supprimé cette nuit, fichier par fichier, et à quelle heure.' }],
    done: [{ who: 'odile', expr: 'sourire', text: 'Un journal qu\'on peut lire, avec la date de chaque ligne. Merci.' }]
  });

  // ——— E37 · L'épreuve des machines cachées ———
  function put(m, p, days, data) { m.vfs.dir(p.slice(0, p.lastIndexOf('/'))); m.vfs.file(p, data === undefined ? '' : data, { mtime: m.vfs.now - days * DAY }); }
  S.add({
    id: 'E37', act: 5, machine: 'archives', edit: ['nettoyage-nuit.sh'], start: REF36, ref: REF37, show: [],
    base: function (m) { monde(m); B.file(m, '/home/admin/nettoyage-nuit.sh', REF36, 5, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'un export « .WAV » en majuscules de 40 jours est supprimé comme les autres',
        setup: function (m) { put(m, EXP + '/Samir-EXPORT.WAV', 40); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], !has(m, EXP + '/Samir-EXPORT.WAV') || 'Samir-EXPORT.WAV (40 jours) est resté', purged(m), /fin : 3 fichiers/.test(logText(m)) || 'le journal devrait dire « fin : 3 fichiers »']); } },
      { id: 'ligne', label: 'un nom de fichier qui contient un retour à la ligne',
        setup: function (m) { put(m, EXP + '/a\nb.wav', 45); put(m, EXP + '/b.wav', 1); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([!has(m, EXP + '/a\nb.wav') || 'le fichier au nom sur deux lignes est resté', has(m, EXP + '/b.wav') || 'b.wav (récent) a été supprimé à tort : le nom coupé en deux a été pris pour deux fichiers', /fin : 3 fichiers/.test(logText(m)) || 'le journal devrait compter 3 fichiers']); } },
      { id: 'jours', label: '30 jours pile : on garde ; 31 jours : on supprime',
        setup: function (m) { put(m, EXP + '/trente.wav', 30); put(m, EXP + '/trente-et-un.wav', 31); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([has(m, EXP + '/trente.wav') || 'le fichier de 30 jours a été supprimé', !has(m, EXP + '/trente-et-un.wav') || 'le fichier de 31 jours est resté']); } },
      { id: 'dossier', label: 'un dossier qui s\'appelle « x.wav » n\'est pas supprimé',
        setup: function (m) { m.vfs.dir(EXP + '/x.wav'); m.vfs.get(EXP + '/x.wav', true).mtime = m.vfs.now - 90 * DAY; put(m, EXP + '/x.wav/dedans.wav', 90); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([m.vfs.isDir(EXP + '/x.wav') || 'le dossier x.wav a disparu', !has(m, EXP + '/x.wav/dedans.wav') || 'le vieux fichier du dossier x.wav est resté']); } },
      { id: 'liens', label: 'des liens vers les archives dans le dossier d\'exports ne sont pas suivis',
        setup: function (m) { m.vfs.symlink(ARC, EXP + '/lien-archives'); m.vfs.symlink(ARC1, EXP + '/lien.wav'); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([archivesOk(m), has(m, EXP + '/lien-archives') && has(m, EXP + '/lien.wav') || 'les liens ont été supprimés']); } },
      { id: 'simulation', label: 'en simulation, le nom sur deux lignes ne casse rien et rien n\'est supprimé',
        setup: function (m) { put(m, EXP + '/a\nb.wav', 45); put(m, EXP + '/b.wav', 1); }, run: 'bash nettoyage-nuit.sh',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], has(m, EXP + '/a\nb.wav') && has(m, EXP + '/b.wav') || 'des fichiers ont été supprimés en simulation', /fin : 3 fichiers/.test(logText(m)) || 'le journal devrait compter 3 fichiers']); } },
      { id: 'rien', label: 'rien à supprimer : code 0, « fin : 0 fichiers »',
        setup: function (m) { m.vfs.remove(OLD1); m.vfs.remove(OLD2); }, run: 'bash nettoyage-nuit.sh --apply',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], /fin : 0 fichiers/.test(logText(m)) || 'la ligne « fin : 0 fichiers » manque']); } }
    ],
    hints: [
      { title: 'Les noms que le shell ne voit pas venir', course: 'bash', body: 'Un nom de fichier peut contenir presque tout, y compris un retour à la ligne : une boucle qui lit « une ligne = un fichier » casse ce nom en deux, et peut supprimer un autre fichier qui s\'appelle comme la deuxième moitié. Le seul caractère interdit dans un nom est le zéro binaire : find -print0 sépare les noms par ce caractère, et read -r -d \'\' les relit un par un.' },
      { title: 'Majuscules et minuscules', course: 'bash', body: '-name \'*.wav\' ne retient pas « EXPORT.WAV ». -iname ignore la casse. Les liens symboliques ne sont pas suivis par find : -type f ne retient que les vrais fichiers, ce qui protège les archives. Ne change pas -mtime : +30 veut dire « plus de 30 jours entiers ».' },
      { title: 'La solution', course: 'bash', body: 'while IFS= read -r -d \'\' f; do\n    …\ndone < <(find "$RACINE" -type f -iname \'*.wav\' -mtime +"$JOURS" -print0)' }
    ],
    intro: [{ who: 'yanis', expr: 'surprise', text: 'Samir exporte en .WAV majuscules, et un stagiaire a un jour nommé un fichier sur deux lignes. Le script a intérêt à tenir.' }],
    done: [{ who: 'colette', expr: 'sourire', text: 'Vingt ans de nuits sur des disques d\'inconnus m\'ont appris une chose : on ne devine jamais les noms de fichiers.' }]
  });

  // ——— E38 · Un timer, pas un cron ———
  var SERVICE0 = join('[Unit]', 'Description=Nettoyage nocturne des exports', '', '[Service]', 'Type=forking', 'ExecStart=nettoyage-nuit.sh --apply');
  var TIMER0 = join('[Unit]', 'Description=Nettoyage nocturne des exports (3 h)', '', '[Timer]', 'OnCalendar=0 3 * * *');
  var SERVICE1 = join('[Unit]', 'Description=Nettoyage nocturne des exports', '', '[Service]', 'Type=oneshot', 'ExecStart=/usr/local/bin/nettoyage-nuit.sh --apply');
  var TIMER1 = join('[Unit]', 'Description=Nettoyage nocturne des exports (3 h)', '', '[Timer]', 'OnCalendar=*-*-* 03:00:00', 'Persistent=true', '', '[Install]', 'WantedBy=timers.target');
  var INSTALL = 'cp nettoyage.service nettoyage.timer /etc/systemd/system/ && systemctl daemon-reload && systemctl enable --now nettoyage.timer';
  S.add({
    id: 'E38', act: 5, machine: 'archives', edit: ['nettoyage.service', 'nettoyage.timer'],
    start: { 'nettoyage.service': SERVICE0, 'nettoyage.timer': TIMER0 }, ref: { 'nettoyage.service': SERVICE1, 'nettoyage.timer': TIMER1 }, show: ['/usr/local/bin/nettoyage-nuit.sh'],
    base: function (m) { monde(m); B.file(m, '/usr/local/bin/nettoyage-nuit.sh', REF37, 1, 493); },
    cases: [
      { id: 'vis', visible: true, label: 'le timer est installé, activé, et systemctl list-timers annonce la prochaine purge demain à 03:00:00',
        setup: function () { }, run: INSTALL + ' && systemctl list-timers',
        check: function (r) { return all([r.status === 0 || 'l\'installation échoue : ' + r.err.split('\n').filter(function (l) { return /Failed|Job|bad|Unit/.test(l); })[0], /nettoyage\.timer/.test(r.out) || 'nettoyage.timer n\'est pas dans la liste des timers actifs', /Fri 2026-03-13 03:00:00 UTC/.test(r.out) || 'la prochaine échéance doit être « Fri 2026-03-13 03:00:00 UTC »']); } },
      { id: 'actif', label: 'le timer est activé au démarrage (is-enabled)',
        setup: function () { }, run: '(' + INSTALL + ') >/dev/null 2>&1; systemctl is-enabled nettoyage.timer',
        check: function (r) { return r.out.trim() === 'enabled' ? true : 'systemctl is-enabled répond « ' + r.out.trim() + ' » : il manque [Install] WantedBy=timers.target'; } },
      { id: 'service', label: 'systemctl start nettoyage.service fait la purge',
        setup: function () { }, run: 'cp nettoyage.service nettoyage.timer /etc/systemd/system/ && systemctl daemon-reload && systemctl start nettoyage.service',
        check: function (r, m) { return all([r.status === 0 || 'le service échoue : ' + r.err.split('\n')[0], purged(m)]); } },
      { id: 'journal', label: 'le service laisse une trace dans le journal du système (journalctl -u)',
        setup: function () { }, run: 'cp nettoyage.service /etc/systemd/system/ && systemctl daemon-reload && systemctl start nettoyage.service; journalctl -u nettoyage',
        check: function (r) { return /Finished nettoyage\.service/.test(r.out) ? true : 'journalctl -u nettoyage ne montre pas « Finished nettoyage.service »'; } },
      { id: 'deux', label: 'deux démarrages de suite : le second réussit aussi',
        setup: function () { }, run: 'cp nettoyage.service /etc/systemd/system/ && systemctl daemon-reload && systemctl start nettoyage.service && systemctl start nettoyage.service',
        check: function (r) { return r.status === 0 ? true : 'le second démarrage échoue : ' + r.err.split('\n')[0]; } },
      { id: 'chemin', label: 'le service lance le script par son chemin absolu',
        setup: function () { }, run: 'grep -c "^ExecStart=/" nettoyage.service',
        check: function (r) { return r.out.trim() === '1' ? true : 'ExecStart doit commencer par un chemin absolu (/usr/local/bin/…)'; } }
    ],
    hints: [
      { title: 'Le calendrier d\'un timer', course: 'sys', body: 'cron s\'écrit « 0 3 * * * ». systemd veut une autre syntaxe : OnCalendar=*-*-* 03:00:00 (année-mois-jour heure:minute:seconde). *-*-* veut dire « tous les jours ». Pour vérifier : systemctl list-timers montre la prochaine échéance.' },
      { title: 'Un service qui se lance, un timer qui s\'installe', course: 'sys', body: 'Type=oneshot : le service lance une commande qui se termine (au contraire de forking, pour un démon qui se détache). ExecStart doit donner un chemin absolu. Le timer a besoin d\'une section [Install] avec WantedBy=timers.target pour être activé au démarrage ; après avoir copié les fichiers : systemctl daemon-reload, puis systemctl enable --now nettoyage.timer.' },
      { title: 'La solution', course: 'sys', body: '# nettoyage.service\n[Service]\nType=oneshot\nExecStart=/usr/local/bin/nettoyage-nuit.sh --apply\n\n# nettoyage.timer\n[Timer]\nOnCalendar=*-*-* 03:00:00\nPersistent=true\n[Install]\nWantedBy=timers.target' }
    ],
    intro: [{ who: 'odile', expr: 'neutre', text: 'La crontab de Marc, je ne la lis plus qu\'en cachant mes yeux. Colette dit qu\'un timer, au moins, dit s\'il a marché. On bascule ?' }],
    done: [{ who: 'colette', expr: 'sourire', text: 'Voilà. Le cron de 2019 peut dormir tranquille : c\'est systemd qui garde la porte.' }]
  });

  // ——— E39 · Restaurer les archives ———
  var RESTAURER0 = join('#!/bin/bash', '# restaurer.sh ARCHIVE DESTINATION : remet en place les fichiers d\'une sauvegarde', 'ARCHIVE=$1', 'DEST=$2', 'tar -xzf $ARCHIVE -C $DEST', 'echo "restauré"');
  var RESTAURER1 = join(
    '#!/bin/bash',
    '# restaurer.sh ARCHIVE DESTINATION : remet en place les fichiers d\'une sauvegarde',
    'set -euo pipefail',
    'if [ $# -ne 2 ]; then',
    '    echo "usage : $0 ARCHIVE DESTINATION" >&2',
    '    exit 2',
    'fi',
    'ARCHIVE=$1',
    'DEST=$2',
    '[ -f "$ARCHIVE" ] || { echo "restaurer : $ARCHIVE introuvable" >&2; exit 1; }',
    'liste=$(tar -tzf "$ARCHIVE") || { echo "restaurer : archive illisible : $ARCHIVE" >&2; exit 1; }',
    '',
    'tmp=$(mktemp -d)',
    'trap \'rm -rf "$tmp"\' EXIT',
    'tar -xzf "$ARCHIVE" -C "$tmp"',
    '',
    'restaures=0',
    'presents=0',
    'while IFS= read -r chemin; do',
    '    case $chemin in */) continue ;; esac',
    '    if [ -e "$DEST/$chemin" ]; then',
    '        presents=$((presents + 1))',
    '    else',
    '        mkdir -p "$(dirname "$DEST/$chemin")"',
    '        cp -p -- "$tmp/$chemin" "$DEST/$chemin"',
    '        restaures=$((restaures + 1))',
    '    fi',
    'done <<< "$liste"',
    '',
    'if [ "$restaures" -eq 0 ]; then',
    '    echo "rien à restaurer : $presents déjà présents"',
    'else',
    '    echo "$restaures fichiers restaurés, $presents déjà présents"',
    'fi');
  var F1 = 'archives/2019/la-matinale/2019-11-04.wav', F2 = 'archives/2019/jazz-de-nuit/2019-12-02.wav', F3 = 'archives/2019/le-grand-bal/2019-12-14.wav', F4 = 'archives/2020/l-heure bleue/2020-01-06.wav';
  function sauvegardeNuit(m, extra) {
    var ents = [{ p: F1, bytes: 3e6, days: 2300 }, { p: F2, bytes: 3e6, days: 2290 }, { p: F3, bytes: 3e6, days: 2280 }, { p: F4, bytes: 3e6, days: 2200 }].concat(extra || []);
    B.tgz(m, '/home/admin/archives-2026-03-11.tgz', ents, 1);
  }
  function pres(m, p) { return m.vfs.isFile('/srv/data/' + p); }
  S.add({
    id: 'E39', act: 5, machine: 'archives', edit: ['restaurer.sh'], start: RESTAURER0, ref: RESTAURER1, show: [],
    base: function (m) { m.vfs.dir('/srv/data'); m.vfs.dir('/tmp'); sauvegardeNuit(m); },
    cases: [
      { id: 'vis', visible: true, label: 'deux fichiers manquent, deux sont déjà là : 2 restaurés, 2 présents, et les fichiers présents ne sont pas remplacés',
        setup: function (m) { B.file(m, '/srv/data/' + F1, 'version locale plus récente\n', 3); B.file(m, '/srv/data/' + F3, 'autre version locale\n', 3); }, run: 'bash restaurer.sh archives-2026-03-11.tgz /srv/data',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], /2 fichiers restaurés, 2 déjà présents/.test(r.out) || 'le récapitulatif doit dire « 2 fichiers restaurés, 2 déjà présents »', pres(m, F2) && pres(m, F4) || 'les fichiers manquants n\'ont pas été restaurés', m.vfs.read('/srv/data/' + F1) === 'version locale plus récente\n' || 'un fichier déjà présent a été remplacé']); } },
      { id: 'deux', label: 'second lancement : « rien à restaurer », rien ne change',
        setup: function (m) { }, run: 'bash restaurer.sh archives-2026-03-11.tgz /srv/data >/dev/null; bash restaurer.sh archives-2026-03-11.tgz /srv/data',
        check: function (r, m) { return all([r.status === 0 || 'échec du second lancement : ' + r.err.split('\n')[0], /rien à restaurer/.test(r.out) || 'le second lancement doit dire « rien à restaurer »', pres(m, F1) && pres(m, F4)]); } },
      { id: 'illisible', label: 'archive illisible : échec propre, rien n\'est écrit',
        setup: function (m) { B.file(m, '/home/admin/mauvaise.tgz', 'ceci n\'est pas une archive\n', 1); }, run: 'bash restaurer.sh mauvaise.tgz /srv/data',
        check: function (r, m) { return all([r.status !== 0 || 'le script réussit alors que l\'archive est illisible', r.err.trim() !== '' || 'pas de message d\'erreur', m.run('find /srv/data -type f | wc -l').out.trim() === '0' || 'des fichiers ont été écrits dans /srv/data', m.vfs.list('/tmp').length === 0 || 'un dossier temporaire est resté dans /tmp']); } },
      { id: 'espaces', label: 'un nom avec des espaces (« l-heure bleue ») est restauré',
        setup: function () { }, run: 'bash restaurer.sh archives-2026-03-11.tgz /srv/data',
        check: function (r, m) { return pres(m, F4) ? true : 'le fichier « ' + F4 + ' » n\'a pas été restauré'; } },
      { id: 'dest', label: 'la destination n\'existe pas encore : elle est créée',
        setup: function (m) { m.vfs.remove('/srv/data', true); }, run: 'bash restaurer.sh archives-2026-03-11.tgz /srv/restauration',
        check: function (r, m) { return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], m.vfs.isFile('/srv/restauration/' + F2) || 'rien n\'a été restauré dans /srv/restauration', /4 fichiers restaurés, 0 déjà présents/.test(r.out) || 'le récapitulatif doit dire « 4 fichiers restaurés, 0 déjà présents »']); } },
      { id: 'usage', label: 'sans argument : usage et code 2',
        setup: function () { }, run: 'bash restaurer.sh',
        check: function (r) { return all([r.status === 2 || 'le code de retour doit être 2, pas ' + r.status, /usage/i.test(r.err) || 'pas de message « usage »']); } }
    ],
    hints: [
      { title: 'Ne jamais écraser', course: 'bash', body: 'tar -xzf remplace sans prévenir ce qui existe déjà. Pour ne restaurer que ce qui manque : extrais d\'abord dans un dossier temporaire (mktemp -d), puis pour chaque fichier de l\'archive teste [ -e "$DEST/$chemin" ] ; copie (cp -p) seulement ceux qui n\'existent pas.' },
      { title: 'Lire avant d\'écrire, nettoyer après', course: 'bash', body: 'tar -tzf liste le contenu : s\'il échoue, l\'archive est illisible — arrête-toi avant d\'écrire quoi que ce soit. trap \'rm -rf "$tmp"\' EXIT supprime le dossier temporaire quelle que soit la sortie du script. Les noms de la liste peuvent contenir des espaces : lis avec while IFS= read -r.' },
      { title: 'La solution', course: 'bash', body: 'liste=$(tar -tzf "$ARCHIVE") || { echo "illisible" >&2; exit 1; }\ntmp=$(mktemp -d); trap \'rm -rf "$tmp"\' EXIT\ntar -xzf "$ARCHIVE" -C "$tmp"\nwhile IFS= read -r chemin; do\n    case $chemin in */) continue ;; esac\n    if [ -e "$DEST/$chemin" ]; then presents=$((presents + 1))\n    else mkdir -p "$(dirname "$DEST/$chemin")"; cp -p -- "$tmp/$chemin" "$DEST/$chemin"; restaures=$((restaures + 1)); fi\ndone <<< "$liste"' }
    ],
    intro: [{ who: 'yanis', expr: 'sourire', text: 'La sauvegarde de cette nuit a tout ce que la purge a emporté. Remets-le en place, mais ne touche pas à ce que j\'ai déjà retrouvé.' }],
    done: [{ who: 'yanis', expr: 'sourire', text: 'Ma matinale de novembre 2019 est de retour. Et rien n\'a été écrasé.' }]
  });

  // ——— E40 · La nuit de 3 h (finale) ———
  var RAPPORT0 = join('#!/bin/bash', 'LOG=/var/log/radio/nettoyage.log', 'echo "Fichiers supprimés cette nuit : $(grep -c supprimé $LOG)"', 'echo "Fichiers restaurés : $(wc -l < /var/log/radio/restauration.log)"', 'echo "Dernière purge : $(tail -1 $LOG)"');
  var RAPPORT1 = join(
    '#!/bin/bash',
    '# rapport.sh : le rapport d\'Odile, trois lignes chiffrées',
    'set -euo pipefail',
    'LOG=/var/log/radio/nettoyage.log',
    'REST=/var/log/radio/restauration.log',
    'AUJOURDHUI=$(date +%F)',
    'supprimes=0',
    'restaures=0',
    'derniere=\'\'',
    'if [ -f "$LOG" ]; then',
    '    supprimes=$(grep -c "^$AUJOURDHUI [0-9:]* supprimé : " "$LOG" || true)',
    '    derniere=$(grep \' début$\' "$LOG" | tail -n 1 | cut -d\' \' -f1,2 || true)',
    'fi',
    'if [ -f "$REST" ]; then',
    '    restaures=$(grep -c "^$AUJOURDHUI [0-9:]* restauré : " "$REST" || true)',
    'fi',
    '[ -n "$derniere" ] || derniere=jamais',
    '{',
    '    echo "Fichiers supprimés cette nuit : $supprimes"',
    '    echo "Fichiers restaurés : $restaures"',
    '    echo "Dernière purge : $derniere"',
    '} | tee rapport.txt');
  var LOG40 = ['2026-03-11 03:00:01 début', '2026-03-11 03:00:02 supprimé : /srv/data/exports/a.wav', '2026-03-11 03:00:02 supprimé : /srv/data/exports/b.wav', '2026-03-11 03:00:03 fin : 2 fichiers',
    '2026-03-12 03:00:01 début', '2026-03-12 03:00:02 supprimé : /srv/data/exports/c.wav', '2026-03-12 03:00:02 supprimé : /srv/data/exports/d.wav', '2026-03-12 03:00:03 supprimé : /srv/data/exports/Émission du soir.wav', '2026-03-12 03:00:03 fin : 3 fichiers',
    '2026-03-12 10:12:30 début (SIMULATION)', '2026-03-12 10:12:30 SIMULATION : à supprimer : /srv/data/exports/e.wav', '2026-03-12 10:12:31 fin : 1 fichiers'].join('\n') + '\n';
  var REST40 = ['2026-03-11 22:00:01 restauré : archives/2019/x.wav', '2026-03-11 22:00:02 restauré : archives/2019/y.wav',
    '2026-03-12 10:18:01 restauré : archives/2019/la-matinale/2019-11-04.wav', '2026-03-12 10:18:02 restauré : archives/2019/jazz-de-nuit/2019-12-02.wav', '2026-03-12 10:18:02 restauré : archives/2019/le-grand-bal/2019-12-14.wav', '2026-03-12 10:18:03 restauré : archives/2020/l-heure bleue/2020-01-06.wav'].join('\n') + '\n';
  function r3(a, b, c) { return 'Fichiers supprimés cette nuit : ' + a + '\nFichiers restaurés : ' + b + '\nDernière purge : ' + c; }
  function rapportOk(r, m, a, b, c) {
    var want = r3(a, b, c);
    return all([r.status === 0 || 'échec : ' + r.err.split('\n')[0], r.out.replace(/\n$/, '') === want || 'le rapport devrait être :\n' + want + '\n— il est :\n' + r.out.replace(/\n$/, ''), (m.vfs.isFile('/home/admin/rapport.txt') && m.vfs.read('/home/admin/rapport.txt').replace(/\n$/, '') === want) || 'rapport.txt doit contenir le même rapport']);
  }
  S.add({
    id: 'E40', act: 5, machine: 'archives', edit: ['rapport.sh'], start: RAPPORT0, ref: RAPPORT1, show: ['/var/log/radio/nettoyage.log'],
    base: function (m) { m.vfs.dir('/var/log/radio'); B.file(m, LOG, LOG40, 0); B.file(m, '/var/log/radio/restauration.log', REST40, 0); },
    cases: [
      { id: 'vis', visible: true, label: '3 fichiers supprimés cette nuit, 4 restaurés aujourd\'hui, dernière purge à 03:00:01',
        setup: function () { }, run: 'bash rapport.sh',
        check: function (r, m) { return rapportOk(r, m, 3, 4, '2026-03-12 03:00:01'); } },
      { id: 'sanslog', label: 'pas de journal de purge : 0 supprimé, « jamais »',
        setup: function (m) { m.vfs.remove(LOG); }, run: 'bash rapport.sh',
        check: function (r, m) { return rapportOk(r, m, 0, 4, 'jamais'); } },
      { id: 'sansrest', label: 'pas de journal de restauration : 0 restauré',
        setup: function (m) { m.vfs.remove('/var/log/radio/restauration.log'); }, run: 'bash rapport.sh',
        check: function (r, m) { return rapportOk(r, m, 3, 0, '2026-03-12 03:00:01'); } },
      { id: 'hier', label: 'seulement des suppressions d\'hier : 0 cette nuit, la dernière purge reste celle d\'hier',
        setup: function (m) { B.file(m, LOG, LOG40.split('\n').slice(0, 4).join('\n') + '\n', 0); }, run: 'bash rapport.sh',
        check: function (r, m) { return rapportOk(r, m, 0, 4, '2026-03-11 03:00:01'); } },
      { id: 'simu', label: 'une simulation d\'aujourd\'hui n\'est ni une suppression ni une purge',
        setup: function (m) { B.file(m, LOG, LOG40.split('\n').slice(9).join('\n') + '\n', 0); }, run: 'bash rapport.sh',
        check: function (r, m) { return rapportOk(r, m, 0, 4, 'jamais'); } },
      { id: 'vide', label: 'deux journaux vides : tout à zéro',
        setup: function (m) { B.file(m, LOG, '', 0); B.file(m, '/var/log/radio/restauration.log', '', 0); }, run: 'bash rapport.sh',
        check: function (r, m) { return rapportOk(r, m, 0, 0, 'jamais'); } }
    ],
    hints: [
      { title: 'Compter ce qui compte', course: 'bash', body: 'grep -c compte les lignes qui correspondent, et renvoie 1 (donc échec sous set -e) quand il n\'y en a aucune : ajoute || true. Pour ne compter que les lignes d\'aujourd\'hui, ancre le motif sur la date : "^$AUJOURDHUI [0-9:]* supprimé : ". Une simulation écrit « SIMULATION : à supprimer », elle ne correspond pas.' },
      { title: 'La dernière purge', course: 'bash', body: 'La dernière purge est la dernière ligne qui se termine par « début » (grep \' début$\'), pas la dernière ligne du journal (c\'est « fin »). tail -n 1 la prend, cut -d\' \' -f1,2 garde la date et l\'heure. Sans journal : « jamais ». tee rapport.txt écrit le rapport à l\'écran et dans le fichier.' },
      { title: 'La solution', course: 'bash', body: 'supprimes=$(grep -c "^$AUJOURDHUI [0-9:]* supprimé : " "$LOG" || true)\nderniere=$(grep \' début$\' "$LOG" | tail -n 1 | cut -d\' \' -f1,2 || true)\n[ -n "$derniere" ] || derniere=jamais\n{ echo "Fichiers supprimés cette nuit : $supprimes"; … } | tee rapport.txt' }
    ],
    intro: [{ who: 'odile', expr: 'neutre', text: 'Dernière demande. Le conseil d\'administration ne lira pas un script : il lira trois lignes. Combien de fichiers ont disparu, combien sont revenus, et quand la purge a-t-elle tourné pour la dernière fois ?' }],
    done: [{ who: 'odile', expr: 'sourire', text: 'Trois lignes, trois chiffres. Je peux les poser sur la table du conseil.' }]
  });
})(RCSteps);
