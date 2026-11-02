/* Radio-Comète — the world: the thirteen machines of the radio, as they are on Thursday 12 March
   2026 at 10:20 (UTC). One poste (`regie`, where the student works) and twelve servers.

   RCWorld.fleet([names]) builds a fresh fleet (every machine, or the ones listed). Steps change it
   after that (a step's `base`, a case's `setup`). What is written here is what the story says:

   - the twelve servers: antenne, studio-a, studio-b, jingles, planning, web, podcast, mail, stockage,
     sauvegarde, relais, archives; `regie` is the administrator's workstation;
   - /etc/radio/chemins.conf on each server (sourced by the scripts of the radio);
   - the crontab of the user admin on each server (/var/spool/cron/crontabs/admin);
   - on `archives`: Marc's nightly script /usr/local/bin/nettoyage-nuit, the crontab line that runs
     it, and the configuration that makes it dangerous (EXPORT_ROOT is empty there);
   - on `regie`: /home/admin/hotes.txt (the list of the twelve), the folder crontabs/, bin/. */
(function (root) {
  'use strict';
  var V = root.RCVfs, F = root.RCFleet, DAY = V.DAY;

  var SERVERS = ['antenne', 'studio-a', 'studio-b', 'jingles', 'planning', 'web', 'podcast', 'mail', 'stockage', 'sauvegarde', 'relais', 'archives'];
  var ROLES = { antenne: 'diffusion', 'studio-a': 'studio', 'studio-b': 'studio', jingles: 'jingles', planning: 'planning', web: 'web', podcast: 'podcast', mail: 'mail', stockage: 'partages', sauvegarde: 'sauvegardes', relais: 'relais', archives: 'data' };

  var NETTOYAGE = [
    '#!/bin/bash',
    '# nettoyage des exports -- M. (2019)',
    '. /etc/radio/chemins.conf',
    '',
    'RACINE="${EXPORT_ROOT:-/srv/data}"',
    'JOURS=${RETENTION:-30}',
    'LOG=/var/log/radio/nettoyage.log',
    '',
    'journal() { echo "$(date \'+%F %T\') $*" >> "$LOG"; }',
    '',
    'purge() {',
    '    find "$1" -type f -name \'*.wav\' -mtime +"$2" -delete',
    '}',
    '',
    'journal "purge de $RACINE (plus de $JOURS jours)"',
    'purge "$RACINE" "$JOURS"',
    'journal "termine"',
    ''
  ].join('\n');

  var PING = '#!/bin/bash\n# supervision : une ligne par quart d\'heure dans le journal du serveur\necho "$(date +%F\\ %T) $(hostname) ok" >> /var/log/radio/supervision.log\n';
  var LOCAL = '#!/bin/bash\n# copie locale de /etc/radio vers /var/backups\nmkdir -p /var/backups\ncp -a /etc/radio /var/backups/\n';

  var CRON_HEAD = '# m h dom mon dow command\n';
  var CRONS = {
    antenne: '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n*/5 * * * * /usr/local/bin/playlist-sync\n',
    'studio-a': '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n',
    'studio-b': '*/15 * * * * /usr/local/bin/supervision-ping\r\n30 2 * * * /usr/local/bin/sauvegarde-locale\r\n\r\n',
    jingles: '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n',
    planning: '*/15 * * * * /usr/local/bin/supervision-ping\n45 2 * * * /usr/local/bin/sauvegarde-locale\n',
    web: '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n0 */6 * * * /usr/local/bin/logrotate-site\n',
    podcast: '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n15 5 * * * /usr/local/bin/podcast-rss\n',
    mail: '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n',
    stockage: '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n',
    sauvegarde: '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n10 3 * * * /home/admin/sauvegarde.sh /srv/sauvegardes\n',
    relais: '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n',
    archives: '*/15 * * * * /usr/local/bin/supervision-ping\n30 2 * * * /usr/local/bin/sauvegarde-locale\n30 2 * * 1 /usr/local/bin/export-emissions\n0 3 * * * /usr/local/bin/nettoyage-nuit\n'
  };

  function conf(name) {
    var role = ROLES[name], root_ = name === 'archives' ? '/srv/data' : '/srv/' + role;
    var s = '# /etc/radio/chemins.conf -- ' + name + '\n# sourcé par les scripts de la radio : une variable par ligne, pas d\'espace autour du =\nRADIO_ROOT=' + root_ + '\nLOG_DIR=/var/log/radio\nRETENTION=' + (name === 'jingles' ? '' : '30') + '\n';
    if (name === 'archives') s += 'ARCHIVE_ROOT=/srv/data/archives\nEXPORT_ROOT=\n';
    if (name === 'stockage') s += 'PARTAGES_ROOT=/srv/partages\n';
    return s;
  }

  function machine(name) {
    var v = new V.Vfs();
    ['/home/admin/bin', '/tmp', '/var/log/radio', '/var/spool/cron/crontabs', '/usr/local/bin', '/etc/radio', '/etc/systemd/system', '/var/lock', '/var/backups'].forEach(function (d) { v.dir(d); });
    var m = new F.Machine(name, v);
    if (name === 'regie') {
      v.file('/home/admin/hotes.txt', SERVERS.join('\n') + '\n');
      v.dir('/home/admin/crontabs');
      v.file('/home/admin/LISEZMOI', 'Poste de la régie de Radio Comète.\nhotes.txt : les douze serveurs, un par ligne.\nLes scripts de Marc sont sur chaque serveur, dans /usr/local/bin.\n');
      return m;
    }
    v.file('/etc/hostname', name + '\n');
    v.file('/etc/radio/chemins.conf', conf(name));
    v.file('/usr/local/bin/supervision-ping', PING, { mode: 493 });
    v.file('/usr/local/bin/sauvegarde-locale', LOCAL, { mode: 493 });
    v.file('/var/spool/cron/crontabs/admin', CRON_HEAD + CRONS[name], { mode: 384 });
    v.file('/var/log/radio/supervision.log', '2026-03-12 10:15:01 ' + name + ' ok\n', { mtime: v.now - 300 });
    v.file('/var/log/syslog', 'Mar 12 02:30:01 ' + name + ' CRON[1201]: (admin) CMD (/usr/local/bin/sauvegarde-locale)\n');
    var role = ROLES[name];
    if (name !== 'archives') { v.dir('/srv/' + role); }
    if (name === 'archives') {
      v.file('/usr/local/bin/nettoyage-nuit', NETTOYAGE, { mode: 493, mtime: v.now - 2400 * DAY });
      v.file('/usr/local/bin/export-emissions', '#!/bin/bash\n# exporte les émissions de la veille vers /srv/data/exports\n. /etc/radio/chemins.conf\nmkdir -p "${EXPORT_ROOT:-/srv/data/exports}"\n', { mode: 493 });
      v.dir('/srv/data/exports'); v.dir('/srv/data/archives');
    }
    if (name === 'relais') m.down = 'timeout';
    return m;
  }

  /** A fresh fleet. names: the machines to build (default: all thirteen). */
  function fleet(names) {
    var f = new F.Fleet(), list = names && names.length ? names : ['regie'].concat(SERVERS);
    list.forEach(function (n) { f.add(machine(n)); });
    return f;
  }

  root.RCWorld = { fleet: fleet, machine: machine, SERVERS: SERVERS, ROLES: ROLES, NETTOYAGE: NETTOYAGE, CRONS: CRONS };
})(typeof globalThis !== 'undefined' ? globalThis : window);
