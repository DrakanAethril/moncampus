/* Radio-Comète — the steps, on the game's side.

   The platform half (title, request, the steps of the step and their right answers, the graph, the
   clues) is in host/scenario/. Here, what the game shows and checks by itself, for the steps where
   the student writes a script (a « fix » step), reads a view (a « line » step) or both:

     RCSteps.add({
       id: 'E02', act: 1,
       machine: 'sauvegarde',                 // the machine the console opens on
       machines: ['sauvegarde'],              // the machines built (default: [machine]; 'all' = the thirteen)
       edit: ['sauvegarde.sh'],               // files of /home/admin the student writes (one editor tab each)
       start: { 'sauvegarde.sh': '...' },     // their content at the start (the script with its bug)
       ref:   { 'sauvegarde.sh': '...' },     // the reference repair (only the bench reads it)
       show:  ['/etc/radio/chemins.conf'],    // files of the machine shown read-only, as tabs
       base:  function (m, fleet) { ... },    // the machine as the step finds it (every case starts from it)
       cases: [{ id, label, visible: true|false, setup: function (m, fleet) {}, run: 'bash sauvegarde.sh /srv/x',
                 check: function (r, m, fleet, H) { return true | 'what went wrong' } }],
       views: { id: { title, lines: [...] } | { title, machine, cmd } },   // what a « line » step shows
       hints: [{ title, body, course }],      // Colette's articles, after her guided questions; the last gives the solution
       intro: [{ who, expr, text }], done: [{ who, expr, text }]            // scenes
     });

   A script is delivered: every case runs in a fresh fleet (the world of world.js, then base, then the
   case's setup), the student's files written with the executable bit, and the case's command is run.
   Cases with visible: true are the machine the student sees and the criteria the requester states;
   the others are « machines que vous ne voyez pas »: when one fails, only its label is told. */
(function (root) {
  'use strict';
  var V = root.RCVfs, W = root.RCWorld;
  var list = [], byId = {};

  var COURSES = {
    bash: { label: 'Bash · variables, guillemets, boucles', url: 'https://cours.sautour.fr/' },
    sys: { label: 'SIO · Administration système', url: 'https://cours.sautour.fr/' }
  };

  var H = {
    exists: function (m, p) { return m.vfs.exists(p); },
    file: function (m, p) { try { return m.vfs.read(p); } catch (e) { return null; } },
    ls: function (m, p) { try { return m.vfs.list(p); } catch (e) { return []; } },
    lines: function (s) { return s === '' ? [] : s.replace(/\n$/, '').split('\n'); },
    ok: function (r) { return r.status === 0; },
    status: function (r, n) { return r.status === n ? true : 'code de retour ' + r.status + ' au lieu de ' + n; }
  };

  function normalize(def) {
    def.machine = def.machine || 'sauvegarde';
    if (def.machines === 'all') def.machines = undefined; else def.machines = def.machines || [def.machine];
    if (typeof def.edit === 'string') def.edit = [def.edit];
    def.edit = def.edit || [];
    if (typeof def.start === 'string') { var o = {}; o[def.edit[0]] = def.start; def.start = o; }
    if (typeof def.ref === 'string') { var o2 = {}; o2[def.edit[0]] = def.ref; def.ref = o2; }
    def.start = def.start || {}; def.ref = def.ref || {}; def.show = def.show || []; def.cases = def.cases || []; def.hints = def.hints || []; def.views = def.views || {};
    return def;
  }

  function fleetFor(def) { return W.fleet(def.machines); }
  function writeFiles(m, files) {
    Object.keys(files).forEach(function (name) { m.vfs.file(V.resolve(m.home, name), files[name], { mode: 493, mtime: m.vfs.now - 60 }); });
  }

  /** The world the student sees: the step's base, the first visible case's setup, the files as the student has them. */
  function consoleFleet(def, files) {
    var f = fleetFor(def), m = f.get(def.machine);
    if (def.base) def.base(m, f);
    var first = def.cases.filter(function (c) { return c.visible; })[0];
    if (first && first.setup) first.setup(m, f);
    writeFiles(m, files || def.start);
    return f;
  }

  /** Runs every case with the student's files. Returns { passed, results:[{ id, label, visible, ok, detail, out }], failed:[labels] }. */
  function runCases(def, files) {
    var results = [];
    def.cases.forEach(function (c) {
      var f = fleetFor(def), m = f.get(def.machine), res = { id: c.id, label: c.label, visible: !!c.visible, ok: false, detail: '', out: '' };
      try {
        if (def.base) def.base(m, f);
        if (c.setup) c.setup(m, f);
        writeFiles(m, files);
        var r = m.run(c.run, { cwd: m.home });
        res.out = r.all; res.status = r.status;
        var v = c.check(r, m, f, H);
        res.ok = v === true; if (v !== true) res.detail = typeof v === 'string' ? v : '';
      } catch (e) { res.ok = false; res.detail = 'erreur du simulateur : ' + (e && e.message); }
      results.push(res);
    });
    var failed = results.filter(function (r) { return !r.ok; });
    return { passed: !failed.length, results: results, failed: failed.map(function (r) { return r.label; }) };
  }

  /** The lines a « line » step shows. */
  function view(def, id) {
    var v = def.views[id];
    if (!v) return null;
    if (v.lines) return { title: v.title, lines: v.lines.slice() };
    var f = consoleFleet(def), m = f.get(v.machine || def.machine), r = m.run(v.cmd, { cwd: m.home });
    var out = r.all.replace(/\n$/, '');
    return { title: v.title, lines: out === '' ? [] : out.split('\n') };
  }

  /** Builders for the worlds of the steps. */
  var B = {
    /** A file written `days` days ago. */
    file: function (m, p, content, days, mode) { m.vfs.file(p, content === undefined ? '' : content, { mtime: m.vfs.now - (days || 0) * V.DAY - 60, mode: mode === undefined ? 420 : mode }); return m; },
    /** A big file (size only), written `days` days ago. */
    big: function (m, p, bytes, days) { m.vfs.dir(V.dirname(p)); m.vfs.big(p, bytes, days || 0, { seed: p }); return m; },
    /** A backup archive (the format of tar -czf), written `days` days ago, holding `entries` = [{ p: 'archives/2019/la-matinale/2019-11-04.wav', bytes: 3e6, days: 40 }]
        (a size and an age: the content is not kept, but it keeps its size, which is what a restoration and a disk count). Folders are added. */
    tgz: function (m, path, entries, days) {
      var list = [], seen = {}, total = 0;
      entries.forEach(function (e) {
        var parts = e.p.split('/'), acc = '';
        for (var i = 0; i < parts.length - 1; i++) { acc += (i ? '/' : '') + parts[i]; if (!seen[acc]) { seen[acc] = 1; list.push({ p: acc + '/', t: 'd', m: 493, mt: m.vfs.now - (days || 0) * V.DAY }); } }
        var it = { p: e.p, t: 'f', m: 420, mt: m.vfs.now - (e.days || 0) * V.DAY - 60, d: e.data || '' };
        if (e.bytes) { it.sp = e.bytes; it.seed = e.seed || ('/' + e.p); total += e.bytes; }
        list.push(it);
      });
      m.vfs.dir(V.dirname(path));
      m.vfs.file(path, 'GZ1\nTAR1\n' + JSON.stringify(list), { mtime: m.vfs.now - (days || 0) * V.DAY - 60, mode: 420, sparse: total + 512 });
      return m;
    },
    /** An emission folder with a few episodes (each a small file). */
    emission: function (m, name, episodes) { var n = episodes || 2; for (var i = 1; i <= n; i++) B.file(m, '/srv/emissions/' + name + '/episode-' + i + '.wav', 'son ' + name + ' ' + i + '\n', i); return m; },
    /** A backup archive of an emission, as the script of the step 2 makes them, dated `days` days ago. */
    archive: function (m, dir, name, days) {
      var d = new Date((m.vfs.now - days * V.DAY) * 1000), t = d.getUTCFullYear() + '-' + ('0' + (d.getUTCMonth() + 1)).slice(-2) + '-' + ('0' + d.getUTCDate()).slice(-2);
      var entries = [{ p: name + '/', t: 'd', m: 493, mt: m.vfs.now - days * V.DAY }, { p: name + '/episode-1.wav', t: 'f', m: 420, mt: m.vfs.now - days * V.DAY, d: 'son ' + name + '\n' }];
      B.file(m, dir + '/' + name + '-' + t + '.tgz', 'GZ1\nTAR1\n' + JSON.stringify(entries), days);
      return m;
    },
    /** A crontab of the user admin. */
    cron: function (m, text) { m.vfs.file('/var/spool/cron/crontabs/admin', text, { mode: 384 }); return m; }
  };

  root.RCSteps = {
    B: B,
    courses: COURSES, helpers: H,
    add: function (def) { if (byId[def.id]) throw new Error('Étape en double : ' + def.id); normalize(def); list.push(def); byId[def.id] = def; },
    get: function (id) { return byId[id]; }, all: function () { return list; },
    consoleFleet: consoleFleet, runCases: runCases, view: view, writeFiles: writeFiles
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
