/* PHP pas à pas — the engine (design/validated/serious-games-php-pas-a-pas.md §6): runs the student's
   program in the frame on Service de nuit's interpreter (php.js, global SDNPhp), on the visible case and
   on the hidden ones, and compares what it prints with what the reference prints.

   A program never starts from nowhere: the variables the game prepares (`vars`, a plain JS object) are
   declared on the very first line, after `<?php`, so the student's first line is always line 2, whatever
   the case - a message naming a line names the same line on every case. The game draws them, pretty
   printed and greyed, between line 1 and line 2 (`prepared()`).

   PPPEngine.ready()                          → Promise (nothing to load: the interpreter is plain JS)
   PPPEngine.run(code, vars, {html, maxSteps, trace})
                                              → { output, errors: [{level, message, line, fr, raw}], fatal, trace }
   PPPEngine.check(def, code)                 → { passed, failed, criteria, error, deliverable, ir }
   PPPEngine.expected(def, vars)              → the reference's output for these variables
   PPPEngine.steps(code, vars, {html})        → the step through: [{ line, vars }], outputAt(k), final run
   PPPEngine.traceRows(def)                   → the rows of a « Suivre » table: the watched variables after
                                                each passage on line `after` of the snippet
   PPPEngine.literal(value) · prepared(vars) · norm(output, mode) · tokens(code)
*/
(function (root) {
  'use strict';
  var P = root.SDNPhp;
  var MAX_STEPS = 60000;

  /* ——— PHP literals of the prepared variables ——— */
  function str(s) {
    s = String(s);
    if (/[\n\t]/.test(s)) return '"' + s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\$/g, '\\$').replace(/\n/g, '\\n').replace(/\t/g, '\\t') + '"';
    return "'" + s.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
  }
  function isList(v) { return Array.isArray(v); }
  function compact(v) {
    if (v === null || v === undefined) return 'null';
    if (v === true) return 'true';
    if (v === false) return 'false';
    if (typeof v === 'number') return String(v);
    if (typeof v === 'string') return str(v);
    if (isList(v)) return '[' + v.map(compact).join(', ') + ']';
    return '[' + Object.keys(v).map(function (k) { return (/^-?\d+$/.test(k) ? k : str(k)) + ' => ' + compact(v[k]); }).join(', ') + ']';
  }
  /* Pretty printed for the grey lines: one element per line once the compact form is too wide. */
  function pretty(v, indent) {
    var c = compact(v);
    if (c.length + indent.length <= 44 || v === null || typeof v !== 'object') return c;
    var pad = indent + '    ';
    var items = isList(v) ? v.map(function (x) { return pad + pretty(x, pad); })
      : Object.keys(v).map(function (k) { return pad + (/^-?\d+$/.test(k) ? k : str(k)) + ' => ' + pretty(v[k], pad); });
    return '[\n' + items.join(',\n') + ',\n' + indent + ']';
  }
  function names(vars) { return Object.keys(vars || {}); }
  function declarations(vars) { return names(vars).map(function (k) { return '$' + k + ' = ' + compact(vars[k]) + ';'; }).join(' '); }
  /* The grey lines, as the student reads them. */
  function prepared(vars) {
    var out = [];
    names(vars).forEach(function (k) { pretty(vars[k], '').split('\n').forEach(function (l, i) { out.push((i ? '' : '$' + k + ' = ') + l); }); out[out.length - 1] += ';'; });
    return out;
  }

  /* ——— Running ——— */
  // A student who types `<?php` on top of the program loses nothing: it is already there.
  function clean(code) { return String(code || '').replace(/^\s*<\?php\b[ \t]*/, ''); }
  // In a page (chapter 8) the program starts in HTML: an opening `<?php` is the student's own, and kept.
  function source(code, vars, html) {
    var decl = declarations(vars);
    return html ? '<?php ' + decl + ' ?>\n' + String(code || '') : '<?php ' + decl + '\n' + clean(code);
  }
  function run(code, vars, opts) {
    opts = opts || {};
    var r = P.run({ files: { 'index.php': source(code, vars, opts.html) }, entry: 'index.php', maxSteps: opts.maxSteps || MAX_STEPS, trace: !!opts.trace });
    r.errors = r.errors.map(function (e) { return { level: e.level, message: e.message, line: e.line, fr: e.fr || '', raw: e.level + ': ' + e.message + ' in index.php on line ' + e.line }; });
    return r;
  }

  /* ——— Comparing outputs ———
     « words »: whitespace does not count (chapters 1 to 3: a beginner fights with the loop, not with a
     space); « lines »: line by line, trailing spaces and empty last lines ignored (from the arrays on);
     « html »: whitespace between tags ignored, the rest collapsed (the pages of chapter 8). */
  function norm(s, mode) {
    s = String(s == null ? '' : s).replace(/\r\n?/g, '\n');
    if (mode === 'lines') return s.split('\n').map(function (l) { return l.replace(/[ \t]+$/, ''); }).join('\n').replace(/\n+$/, '').replace(/^\n+/, '');
    if (mode === 'html') return s.replace(/>\s+/g, '>').replace(/\s+</g, '<').replace(/\s+/g, ' ').trim();
    return s.trim().split(/\s+/).filter(Boolean).join(' ');
  }
  function same(a, b, mode) { return norm(a, mode) === norm(b, mode); }
  function shownOut(s, mode) {
    var n = norm(s, mode);
    if (!n) return '<i>rien</i>';
    if (n.length > 160) n = n.slice(0, 160) + ' …';
    return '<code class="o">' + esc(n) + '</code>';
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function caseLabel(c, def) {
    if (c.label) return c.label;
    var base = def.vars || {}, diff = names(c.vars).filter(function (k) { return compact(c.vars[k]) !== compact(base[k]); });
    var keys = diff.length ? diff : names(c.vars);
    var text = keys.map(function (k) { return '$' + k + ' = ' + compact(c.vars[k]); }).join(', ');
    return text.length > 60 ? text.slice(0, 57) + '…' : text;
  }
  function casesOf(def) {
    return [{ vars: def.vars || {}, visible: true }].concat((def.hidden || []).map(function (h) { return { vars: Object.assign({}, def.vars || {}, h.vars || {}), label: h.label }; }));
  }
  var expectedCache = {};
  function expected(def, vars) {
    var key = (def.html ? 'h' : 'p') + def.ref + '\u0000' + declarations(vars);
    if (!(key in expectedCache)) expectedCache[key] = run(def.ref, vars, { html: def.html }).output;
    return expectedCache[key];
  }

  /* The misconceptions any exercise may show, recognised in the code. An exercise adds its own (`ir`:
     a regular expression on the code, or a test on the output, and the sentence). */
  var GENERIC = [
    { re: /\b(?:if|elseif|while)\s*\((?:[^()]|\([^()]*\))*?[^=!<>]=[^=>](?:[^()]|\([^()]*\))*\)/, text: 'Dans une condition, <code>=</code> range une valeur et la condition est alors presque toujours vraie : pour comparer, c’est <code>==</code> (ou <code>===</code>).' },
    { re: /\becho\b[^;]*'[^'\n]*\$[A-Za-z_][^'\n]*'/, text: 'Entre apostrophes, <code>$variable</code> reste du texte : PHP ne la remplace pas. Utilise des guillemets doubles, ou le point : <code>\'Total : \' . $total</code>.' },
    { re: /"[^"\n]*"\s*\+|\+\s*"[^"\n]*"/, text: '<code>+</code> calcule, <code>.</code> assemble du texte.' },
    { re: /<=\s*count\s*\(/, text: 'Les cases vont de 0 à <code>count($t) - 1</code> : avec <code>&lt;= count($t)</code>, le dernier tour lit une case qui n’existe pas.' },
    { re: /\$\w+\s*\[\s*count\s*\(\s*\$\w+\s*\)\s*\]/, text: 'La dernière case d’une liste est <code>count($t) - 1</code>, pas <code>count($t)</code> : les cases commencent à 0.' }
  ];
  function irFor(def, code, out) {
    var list = (def.ir || []).concat(GENERIC);
    for (var i = 0; i < list.length; i++) {
      var m = list[i];
      try {
        if ((m.re && m.re.test(code)) || (m.out && m.out(norm(out, def.compare), code))) return m.text;
      } catch (e) { /* a broken test says nothing */ }
    }
    return '';
  }

  /* A program measured on every case. A program that does not even run on the visible case is not a
     delivery (`deliverable: false`): its error is shown and nothing is sent. */
  function check(def, code) {
    var mode = def.compare || 'words', criteria = [], cases = casesOf(def), first = null;
    if (!String(code || '').trim()) return { passed: false, failed: ['Programme vide'], criteria: [{ label: 'Cas visible', ok: false, detail: 'Ton programme est vide.' }], deliverable: false };
    for (var i = 0; i < cases.length; i++) {
      var c = cases[i], r = run(code, c.vars, { html: def.html }), exp = expected(def, c.vars);
      var label = c.visible ? 'Cas visible' : 'Cas caché ' + i;
      if (c.visible && r.fatal) {
        return { passed: false, failed: ['Erreur PHP'], deliverable: false, error: r.errors[0], run: r, criteria: [] };
      }
      var crit = { label: label, ok: true, detail: '', got: r.output, exp: exp, hidden: !c.visible };
      var desc = c.visible ? '' : ' (avec <code>' + esc(caseLabel(c, def)) + '</code>)';
      if (r.errors.length) {
        crit.ok = false;
        var e = r.errors[0];
        crit.detail = (r.fatal ? 'Ton programme s’arrête sur une erreur' : 'PHP affiche un avertissement') + desc + ' : <span class="raw">' + esc(e.raw) + '</span> ' + esc(e.fr) + (r.fatal ? '' : ' Un programme qui affiche un avertissement n’est pas réussi.');
      } else if (!same(r.output, exp, mode)) {
        crit.ok = false;
        crit.detail = c.visible
          ? 'Ton programme affiche ' + shownOut(r.output, mode) + ' ; attendu : ' + shownOut(exp, mode) + '.'
          : 'Le cas visible passe, mais sur un autre cas ton programme se trompe' + desc + ' : il affiche ' + shownOut(r.output, mode) + ' au lieu de ' + shownOut(exp, mode) + '.';
      } else crit.detail = c.visible ? 'Exactement ce qui est attendu.' : 'Juste' + desc + '.';
      if (!crit.ok && !first) { first = crit; crit.ir = irFor(def, code, r.output); }
      criteria.push(crit);
    }
    // What the output cannot show: « écris une fonction », « avec return »… (`must`: a regular expression on
    // the code and the sentence that says what is missing).
    (def.mustNot || []).forEach(function (m) {
      if (!m.re.test(String(code))) return;
      var crit = { label: 'Consigne', ok: false, detail: m.text };
      if (!first) first = crit;
      criteria.push(crit);
    });
    (def.must || []).forEach(function (m) {
      if (m.re.test(String(code))) return;
      var crit = { label: 'Consigne', ok: false, detail: m.text };
      if (!first) first = crit;
      criteria.push(crit);
    });
    var failed = criteria.filter(function (x) { return !x.ok; }).map(function (x) { return x.label; });
    return { passed: !failed.length, failed: failed, criteria: criteria, deliverable: true, ir: first ? first.ir : '' };
  }

  /* ——— The step through ——— */
  function steps(code, vars, opts) {
    opts = opts || {};
    var limit = opts.limit || 600;
    var src = source(code, vars, opts.html);
    var full = P.run({ files: { 'index.php': src }, entry: 'index.php', trace: true, maxSteps: limit });
    var list = [];
    full.trace.forEach(function (t, k) { if (t.line > 1) list.push({ line: t.line, vars: t.vars, k: k }); });
    return {
      list: list,
      cut: full.steps >= limit,
      final: { output: full.output, errors: full.errors, fatal: full.fatal },
      outputAt: function (i) {
        var k = list[i] ? list[i].k : null;
        if (k === null) return full.output;
        if (k === 0) return '';
        return P.run({ files: { 'index.php': src }, entry: 'index.php', maxSteps: k }).output;
      }
    };
  }
  function bare(v) { v = String(v == null ? '' : v).trim(); if (/^"[\s\S]*"$/.test(v) || /^'[\s\S]*'$/.test(v)) v = v.slice(1, -1); return v; }
  /* The rows of a « Suivre » table: after each passage on line `after` of the snippet, the watched
     variables. The interpreter records the state *before* each statement, so a probe statement is slipped
     in on its own line right after line `after`: the state before the probe is the state after that line,
     before a for loop's step moves its counter. */
  function traceRows(def) {
    var lines = String(def.code).split('\n');
    lines.splice(def.after, 0, '$__sonde = 0;');
    var r = run(lines.join('\n'), def.vars || {}, { trace: true, maxSteps: 5000, html: def.html }), rows = [], probe = def.after + 2;
    r.trace.forEach(function (t) {
      if (t.line !== probe) return;
      rows.push(def.watch.map(function (w) { return t.vars[w] === undefined ? null : bare(t.vars[w]); }));
    });
    return rows;
  }
  /* A « Suivre » of the other kind: what the program prints for each value of one variable. */
  function caseRows(def) {
    return def.values.map(function (v) { var o = {}; o[def.var] = v; return norm(run(def.code, Object.assign({}, def.vars || {}, o), { html: def.html }).output, def.html ? 'html' : 'words'); });
  }
  function sameAnswer(a, b) {
    var f = function (s) { return bare(String(s)).replace(/,/g, '.').replace(/\s+/g, ' ').trim().toLowerCase(); };
    return f(a) === f(b);
  }

  root.PPPEngine = {
    ready: function () { return Promise.resolve(); },
    run: run,
    check: check,
    expected: expected,
    steps: steps,
    traceRows: traceRows,
    /* What a « Prédire » expects: the code's output, whitespace ignored (between tags too, in a page). */
    printed: function (def) { return norm(run(def.code + (def.expr ? '\necho ' + def.expr + ';' : ''), def.vars || {}, { html: def.html }).output, def.html ? 'html' : 'words'); },
    sameOutput: function (a, def) { return norm(a, def.html ? 'html' : 'words') === norm(run(def.code, def.vars || {}, { html: def.html }).output, def.html ? 'html' : 'words'); },
    caseRows: caseRows,
    sameAnswer: sameAnswer,
    literal: compact,
    prepared: prepared,
    declarations: declarations,
    casesOf: casesOf,
    caseLabel: caseLabel,
    norm: norm,
    same: same,
    tokens: function (code) { return P.tokens(code); },
    esc: esc
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
