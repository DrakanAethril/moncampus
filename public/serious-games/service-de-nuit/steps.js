/* Service de nuit — the nights, on the game's side.

   The platform half (title, request, the steps and their right answers, the graph, the clues) is in
   host/scenario/. Here, what the game shows and checks by itself:

     SDNSteps.add({
       id: 'N02', act: 1,
       learn: ['variables'],                  // the notion this night is about: its notebook page opens with the night,
                                              // its memo stays beside the code, and serving the night lights its letter
       notions: ['variables', 'echo'],        // the notions it exercises (the bench checks each has its page)
       page: 'accueil.php',                   // the file the editor opens on
       show: { before, after, note },         // what the browser shows now, and what it must show once served
       add: { 'chambres.php': '…' },          // files the night brings, added to the site when missing
       ref: { 'accueil.php': '…' },           // the reference: the files it changes (only the bench reads it)
       cases: [                               // the visitors, and the checks on the source
         { id, who, page, at: '2026-11-05 23:12', get: {}, reg: {}, visible, label, check(res, H) },
         { id, label, visible, source: true, check(files, H, base) }
       ],
       traps: [{ label, files: { … } }],      // invisible mistakes: pass the visible visitors, fail a hidden one (bench)
       views: { id: { title, lines: […], run: { at, reg, get } } },  // what a « veille » or « line » step shows
       hints: [{ title, body }],              // Sami's cards, after his guided questions; the last gives the solution
       intro: [{ who, expr, text }], done: [{ who, expr, text }]      // scenes: one line each, two at most
     });

   The site the student builds is one, from the first night to the last: it lives in the game's display
   state. A night starts from it; « Reprendre la version propre » starts from the night's own start,
   which is the reference of the night before plus what the night adds - the bench checks that this start
   fails a visible check and that the reference passes every visitor, hidden ones included, without a
   single PHP warning.

   Before each visit the hotel prepares a few variables ($garde, $client, $arrivees… : the « registre »).
   They are never hidden: the editor shows them, greyed and locked, at the top of the file that reads them,
   with the values of the visitor chosen - what an include of the hotel's registre.php would do. */
(function (root) {
  'use strict';
  var list = [], byId = {};

  function decode(s) {
    return s.replace(/&(amp|lt|gt|quot|#0?39|apos|nbsp|euro);/g, function (m, e) {
      return { amp: '&', lt: '<', gt: '>', quot: '"', '#039': "'", '#39': "'", apos: "'", nbsp: ' ', euro: '€' }[e];
    });
  }
  /** What the browser shows of an HTML page, as one line: tags out, entities decoded, spaces collapsed,
   *  typographic apostrophes read as straight ones (a student may type either). */
  function text(html) {
    return decode(String(html || '').replace(/<head[\s\S]*?<\/head>/i, ' ').replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]*>/g, ' ')).replace(/[’‘]/g, '\'').replace(/\s+/g, ' ').trim();
  }
  function count(hay, needle) { var n = 0, i = -1; hay = String(hay); while ((i = hay.indexOf(needle, i + 1)) !== -1) n++; return n; }
  /** The source without its comments, for the meters (a commented-out line is not code). */
  function code(src) {
    return String(src || '').replace(/<\?php|<\?=|\?>/g, function (t) { return ' ' + t + ' '; }).replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"])\/\/[^\n]*/g, '$1').replace(/(^|\s)#[^\n]*/g, '$1');
  }

  var H = {
    text: text,
    decode: decode,
    count: count,
    code: code,
    /** The page's <title>. */
    title: function (html) { var m = /<title>([\s\S]*?)<\/title>/i.exec(html || ''); return m ? decode(m[1]).replace(/\s+/g, ' ').trim() : ''; },
    /** The texts of the <li> of a page, in order. */
    items: function (html) { var out = [], re = /<li\b[^>]*>([\s\S]*?)<\/li>/gi, m; while ((m = re.exec(html || ''))) out.push(text(m[1])); return out; },
    /** Whether the visible text holds s (spaces collapsed, case kept). */
    has: function (res, s) { return text(res.output).indexOf(String(s).replace(/\s+/g, ' ')) !== -1; },
    /** French money, as number_format($x, 2, ',', ' ') . ' €' writes it. */
    euros: function (x) {
      var neg = x < 0, s = Math.abs(Math.round(x * 100)) + '';
      while (s.length < 3) s = '0' + s;
      var ent = s.slice(0, -2), dec = s.slice(-2), out = '';
      while (ent.length > 3) { out = ' ' + ent.slice(-3) + out; ent = ent.slice(0, -3); }
      return (neg ? '-' : '') + ent + out + ',' + dec + ' €';
    },
    pad: function (n) { return (n < 10 ? '0' : '') + n; }
  };

  /** Runs one visitor's request against the site, with the variables the hotel prepared for them. */
  function serve(files, c) {
    var site = {}, k;
    for (k in files) site[k] = files[k];
    return root.SDNPhp.run({ files: site, entry: c.page || 'accueil.php', now: c.at, get: c.get || {}, globals: c.reg || {}, seed: 7 });
  }

  /** What went wrong with a page that errs: the first error, as the student will read it. */
  function errorDetail(res) {
    var e = res.errors[0];
    return 'la page affiche une erreur : ' + e.level + ' : ' + e.message + ' (' + e.file + ', ligne ' + e.line + ')';
  }

  /** Runs every case on the student's site. `base` is the site as the night found it (source checks may
   *  compare with it); the night's clean start when omitted. Returns { passed, results: [{ id, label,
   *  visible, ok, detail, res }], failed: [labels] }. */
  function runCases(def, files, base) {
    base = base || startOf(def.id);
    var results = def.cases.map(function (c) {
      var r = { id: c.id, label: c.label, visible: !!c.visible, who: c.who || null, at: c.at || null, ok: false, detail: '' };
      try {
        if (c.source) {
          var v = c.check(files, H, base);
          r.ok = v === true; if (v !== true) r.detail = typeof v === 'string' ? v : '';
        } else {
          var res = serve(files, c);
          r.res = res;
          if (res.errors.length) { r.detail = errorDetail(res); }
          else {
            var w = c.check(res, H);
            r.ok = w === true; if (w !== true) r.detail = typeof w === 'string' ? w : '';
          }
        }
      } catch (e) { r.ok = false; r.detail = 'erreur du jeu : ' + (e && e.message); }
      return r;
    });
    var failed = results.filter(function (r) { return !r.ok; });
    return { passed: !failed.length, results: results, failed: failed.map(function (r) { return r.label; }) };
  }

  /** The files a night starts from when the student asks for the clean version: the reference of every
   *  night before it, plus what each one added. */
  function startOf(id) {
    var files = {};
    for (var i = 0; i < list.length; i++) {
      var d = list[i];
      var k;
      for (k in d.add || {}) if (!(k in files)) files[k] = d.add[k];
      if (d.id === id) return files;
      for (k in d.ref || {}) files[k] = d.ref[k];
    }
    return files;
  }
  /** The reference site of a night: its start with its reference applied. */
  function refOf(id) { var f = startOf(id), d = byId[id]; for (var k in (d && d.ref) || {}) f[k] = d.ref[k]; return f; }
  /** The site with a trap's files in place of the reference's. */
  function withFiles(base, patch) { var f = {}, k; for (k in base) f[k] = base[k]; for (k in patch) f[k] = patch[k]; return f; }

  /** The lines a « veille » or « line » step shows. */
  function view(def, id) {
    var v = def.views[id];
    if (!v) return null;
    return { title: v.title, lines: v.lines.slice(), mono: v.mono !== false, run: v.run || null };
  }
  /** What a « veille » snippet shows in a browser - what its right option must say (bench). */
  function viewOutput(def, id) {
    var v = def.views[id];
    if (!v || !v.run) return null;
    var res = root.SDNPhp.run({ files: { 'veille.php': v.lines.join('\n') }, entry: 'veille.php', now: v.run.at || '2026-11-05 23:12:00', get: v.run.get || {}, globals: v.run.reg || {}, seed: 7 });
    return { res: res, text: text(res.output) };
  }

  /** A visitor's check: the page shows every one of these phrases. */
  function sees() {
    var phrases = Array.prototype.slice.call(arguments);
    return function (res, H2) {
      for (var i = 0; i < phrases.length; i++) if (!H2.has(res, phrases[i])) return 'la page n\'affiche pas « ' + phrases[i] + ' »';
      return true;
    };
  }
  /** What the hotel's registre holds on a night: the defaults every page of the site relies on, plus the night's own. */
  var REG = { garde: 'Sami', client: 'Mme Roux', chambre: 12, nuits: 1, tarif: 78, personnes: 1, ouvertes: 40, travaux: 0,
    arrivees: ['Mme Roux', 'M. Diaz'], tarifs: { Simple: 62, Double: 78, Suite: 120 }, reservations: { Roux: 12, Diaz: 3, Okafor: 21, Lina: 5 },
    veilleurs: { Sami: 8, Nora: 12, 'Théo': 10 } };
  function reg(o) { var r = {}, k; for (k in REG) r[k] = REG[k]; for (k in o || {}) r[k] = o[k]; return r; }
  /** The registre's variables a piece of code reads, in the registre's order: what the locked lines at the
   *  top of a file show. */
  function prepared(src, names) {
    var code = H.code(src || '');
    return (names || Object.keys(REG)).filter(function (k) { return new RegExp('\\$' + k + '(?![A-Za-z0-9_])').test(code); });
  }
  /** A value as PHP writes it in code: 'Nora', 12, ['a', 'b'], ['Simple' => 62]. */
  function literal(v) {
    if (typeof v === 'string') return "'" + v.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    if (Array.isArray(v)) return '[' + v.map(literal).join(', ') + ']';
    if (v && typeof v === 'object') return '[' + Object.keys(v).map(function (k) { return literal(k) + ' => ' + literal(v[k]); }).join(', ') + ']';
    return String(v);
  }

  root.SDNSteps = {
    helpers: H,
    sees: sees,
    reg: reg,
    prepared: prepared,
    literal: literal,
    add: function (def) {
      if (byId[def.id]) throw new Error('Nuit en double : ' + def.id);
      def.cases = def.cases || []; def.hints = def.hints || []; def.views = def.views || {}; def.traps = def.traps || [];
      def.learn = def.learn || []; def.notions = def.notions || []; def.page = def.page || 'accueil.php';
      list.push(def); byId[def.id] = def;
    },
    get: function (id) { return byId[id]; },
    all: function () { return list; },
    serve: serve, runCases: runCases, startOf: startOf, refOf: refOf, withFiles: withFiles, view: view, viewOutput: viewOutput,
    /** Augustin's file, read in the optional epilogue only: it is no longer appended to the pages. It speaks
     *  on Thursdays only, at night. */
    PIED: [
      '<?php',
      '// Pour le veilleur qui viendra après moi. A.',
      '$heure = (int) date(\'G\');',
      '$minute = (int) date(\'i\');',
      '$jour = date(\'N\');',
      'if ($jour == 4 && $heure == 3 && $minute >= 12 && $minute < 15) {',
      '    echo \'<p class="veille">La 12 attend toujours son veilleur.</p>\';',
      '} elseif ($jour == 4 && $heure == 23 && $minute >= 12 && $minute < 15) {',
      '    echo \'<p class="veille">23 h 12. Regarde qui arrive à la 12.</p>\';',
      '} elseif ($jour == 5 && $heure == 4 && $minute == 4) {',
      '    echo \'<p class="veille">Le carnet rouge dort au quatrième, chambre 404.</p>\';',
      '}',
      ''
    ].join('\n')
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
