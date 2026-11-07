/* PHP pas à pas — the game's screens (design/validated/serious-games-php-pas-a-pas.md): the map of the
   levels, a level's lessons and steps, the step through, the mastery challenges, the « lectures éclair »
   and the final test, the end of a level and its rewards, the showcase of the notions.

   The platform is reached through the SDK only (sdk.js). A measured step sends {passed, failed} - the
   labels of the failed criteria - and never the program; « Tester » runs it on the visible case and costs
   nothing, only « Vérifier » counts. A program that does not even run is not a delivery: its error is
   explained and nothing is sent. The display state (saveProgress) keeps the lessons seen, the drafts, the
   hints shown, the reminders drawn, the mistakes per notion (which the reminders aim at), the best score
   of the final test and of each « lecture éclair » - which is never sent at all. */
(function () {
  'use strict';
  var C = window.PPPContent, E = window.PPPEngine, SND = window.PPPSound;
  var api = function (m, p) { return window.SGSdk.call(m, p); };
  var app = document.getElementById('app');
  var FINAL_PASS = 10;

  var G = { user: null, prog: null, sc: null, data: {}, cards: {}, view: 'map', lv: null, ticket: null, session: null, step: 0, hints: 0, run: null, rewards: [], opened: {}, busy: false };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function $(sel, root) { return (root || app).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); }
  var saveTimer = null;
  function save(now) {
    clearTimeout(saveTimer);
    var go = function () { api('saveProgress', { data: G.data }).catch(function () { }); };
    if (now) go(); else saveTimer = setTimeout(go, 900);
  }
  function plural(n, one, many) { return n + ' ' + (n > 1 ? many : one); }
  function starLine(n) { n = n || 0; return '★'.repeat(n) + '☆'.repeat(Math.max(0, 3 - n)); }
  function shuffle(a) { a = a.slice(); for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), t = a[i]; a[i] = a[j]; a[j] = t; } return a; }
  // A stable order for a step's options: the same session and step always show them the same way.
  function seeded(a, seed) {
    var h = 2166136261;
    for (var i = 0; i < seed.length; i++) { h ^= seed.charCodeAt(i); h = Math.imul(h, 16777619); }
    var out = a.slice();
    for (var k = out.length - 1; k > 0; k--) { h = Math.imul(h ^ (h >>> 15), 2246822519); var j = (h >>> 0) % (k + 1), t = out[k]; out[k] = out[j]; out[j] = t; }
    return out;
  }

  var ICON = {
    vit: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><rect x="3" y="2.5" width="14" height="15" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M3 10h14" stroke="currentColor" stroke-width="1.6"/><circle cx="7" cy="6.5" r="1.6" fill="currentColor"/><circle cx="13" cy="14" r="1.6" fill="currentColor"/></svg>',
    back: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M12.5 4 6.5 10l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    book: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M3 4.5Q6.5 3 10 5q3.5-2 7-.5V16q-3.5-1.5-7 .5-3.5-2-7-.5Z M10 5v11.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
    sound: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M3 8h3l4-3v10l-4-3H3Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13 7.5q1.5 2.5 0 5M15.5 5.5q3 4.5 0 9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
    mute: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M3 8h3l4-3v10l-4-3H3Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13 8l4 4M17 8l-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
    lock: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><rect x="4" y="9" width="12" height="8" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>',
    check: '<svg viewBox="0 0 20 20" width="22" height="22" aria-hidden="true"><path d="M4 10.5l4 4 8-9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    bolt: '<svg viewBox="0 0 20 20" width="22" height="22" aria-hidden="true"><path d="M11.5 2 4.5 11h5l-1 7 7-9h-5Z" fill="currentColor"/></svg>',
    clock: '<svg viewBox="0 0 20 20" width="22" height="22" aria-hidden="true"><circle cx="10" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.9"/><path d="M10 7.5V11l2.4 1.6M8 2.5h4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>',
    star: '<svg viewBox="0 0 20 20" width="24" height="24" aria-hidden="true"><path d="M10 2l2.4 5.2 5.6.6-4.2 3.8 1.2 5.6L10 14.4 5 17.2l1.2-5.6L2 7.8l5.6-.6Z" fill="currentColor"/></svg>'
  };

  // ——— The board: what the server says of each ticket ———
  function closed(id) { var c = G.cards[id]; return !!c && (c.status === 'done' || c.status === 'reassigned'); }
  function stateOf(lv) { var c = G.cards[lv.id]; if (!c) return 'locked'; return closed(lv.id) ? 'done' : 'open'; }
  function qualityOf(id) { var c = G.cards[id]; return c && c.quality ? c.quality : 0; }
  function refresh() {
    return api('getScenario').then(function (sc) {
      G.sc = sc; G.cards = {};
      (sc.cards || []).forEach(function (c) { G.cards[c.id] = c; });
      return sc;
    });
  }
  function levels() { return C.all(); }
  function chapterOf(n) { return C.chapter(n); }
  function nextOpen() { return levels().filter(function (lv) { return stateOf(lv) === 'open' && lv.kind === 'level'; })[0] || levels().filter(function (lv) { return stateOf(lv) === 'open' && lv.kind === 'final'; })[0] || null; }
  function reachedChapter() { var max = 1; levels().forEach(function (lv) { if (stateOf(lv) !== 'locked') max = Math.max(max, lv.chapter); }); return max; }
  function totalStars() { var s = 0; Object.keys(G.cards).forEach(function (id) { s += qualityOf(id); }); return s; }
  function masteryOf(levelId) { return levels().filter(function (m) { return m.kind === 'mastery' && (m.covers || []).indexOf(levelId) !== -1; })[0] || null; }
  function flashOpen(f) { return (f.covers || []).every(closed); }
  function flashBest(f) { var b = G.data.flash[f.id]; return typeof b === 'number' ? b : null; }

  // ——— Notions: vue, pratiquée (a level done), maîtrisée (its mastery won), ⚡ (a lecture éclair at 7/8) ———
  function notionState(id) {
    var mastered = false, practiced = false;
    levels().forEach(function (lv) {
      if (lv.kind === 'level' && (lv.notions || []).indexOf(id) !== -1 && closed(lv.id)) practiced = true;
      if (lv.kind === 'mastery' && closed(lv.id) && (lv.covers || []).some(function (lid) { var l = C.get(lid); return l && (l.notions || []).indexOf(id) !== -1; })) mastered = true;
    });
    if (mastered) return 'maitrisee';
    if (practiced) return 'pratiquee';
    return G.data.nseen[id] ? 'vue' : '';
  }
  function notionFlash(id) {
    return C.flashes().some(function (f) { return (flashBest(f) || 0) >= 7 && (f.covers || []).some(function (lid) { var l = C.get(lid); return l && (l.notions || []).indexOf(id) !== -1; }); });
  }
  var STATE_LABEL = { '': 'à découvrir', vue: 'vue', pratiquee: 'pratiquée', maitrisee: 'maîtrisée' };
  var lastNotion = {};
  function snapshotNotions() { C.notions.forEach(function (n) { lastNotion[n.id] = notionState(n.id); }); }
  function seenNotions() { var o = {}; C.notions.forEach(function (n) { if (notionState(n.id)) o[n.id] = 1; }); return o; }

  // ——— Frame ———
  app.innerHTML = '<div class="app"><header class="top" id="top"></header><div class="scroll sky" id="scroll"><div class="body"><aside class="side" id="side"></aside><main class="main" id="main"></main></div></div><footer class="foot" id="foot"></footer><div class="toast" id="toast" role="status" aria-live="polite"></div>' +
    '<div class="sheet" id="sheet" aria-hidden="true"><div class="sheet-back" data-close></div><div class="sheet-panel" role="dialog" aria-modal="true" aria-labelledby="sheet-title"><div class="sheet-handle"></div><div class="sheet-head"><h4 id="sheet-title"></h4><button class="ico dark" type="button" data-close aria-label="Fermer">✕</button></div><div id="sheet-body"></div><div id="sheet-foot"></div></div></div></div>';
  var top = $('#top'), main = $('#main'), foot = $('#foot'), scroller = $('#scroll'), side = $('#side'), sheet = $('#sheet');
  var sheetKind = null;
  function openSheet(kind, title, body, footHtml) {
    sheetKind = kind;
    $('#sheet-title').textContent = title;
    $('#sheet-body').innerHTML = body;
    $('#sheet-foot').innerHTML = footHtml || '';
    sheet.classList.toggle('wide', kind === 'trace');
    sheet.classList.add('open'); sheet.setAttribute('aria-hidden', 'false');
    $('.ico[data-close]', sheet).focus();
  }
  function closeSheet() { sheet.classList.remove('open'); sheet.setAttribute('aria-hidden', 'true'); sheetKind = null; }
  $$('[data-close]', sheet).forEach(function (e) { e.addEventListener('click', closeSheet); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheetKind) closeSheet(); });
  function openVitrine() {
    openSheet('vit', 'Vitrine des notions', '<div class="vit">' + vitHtml() + '</div><p class="note">Vue : cours ouvert. Pratiquée : niveau réussi. Maîtrisée : défi de maîtrise réussi. ⚡ : lecture éclair à 7 sur 8 ou plus.</p>');
    var d = $('#open-vit .dot'); if (d) d.hidden = true;
  }
  function openLessons() {
    var lv = G.lv;
    openSheet('lessons', 'Les cours du niveau', lv && Object.keys(lv.lessons).length ? '<div class="lessons-list">' + Object.keys(lv.lessons).map(function (k) { return '<button type="button" class="opt" data-lesson="' + esc(k) + '">' + esc(lv.lessons[k].title) + '</button>'; }).join('') + '</div>' : '<p class="note">Aucun cours ici.</p>');
    $$('[data-lesson]', sheet).forEach(function (b) { b.addEventListener('click', function () { closeSheet(); showLesson(b.dataset.lesson, true); }); });
  }

  function vitHtml(pulse) {
    var reached = reachedChapter();
    return C.chapters.filter(function (ch) { return ch.n <= Math.max(reached, 1); }).map(function (ch) {
      return '<p class="vit-ch">' + esc(ch.title) + '</p>' + C.notions.filter(function (n) { return n.ch === ch.n; }).map(function (n) {
        var st = notionState(n.id), fl = notionFlash(n.id);
        return '<div class="vit-item ' + (pulse && pulse[n.id] ? 'pulse' : '') + '" data-s="' + st + '"><span class="glyph">' + esc(n.g) + '</span><span><span class="lab">' + esc(n.label) + (fl ? ' <span class="bolt" title="Lue en un éclair">⚡</span>' : '') + '</span><span class="st">' + STATE_LABEL[st] + '</span></span></div>';
      }).join('');
    }).join('');
  }
  var toastTimer = null;
  function sideAndNews() {
    var changed = C.notions.filter(function (n) { return lastNotion[n.id] !== undefined && lastNotion[n.id] !== notionState(n.id); }), pulse = {};
    changed.forEach(function (n) { pulse[n.id] = 1; });
    snapshotNotions();
    side.innerHTML = '<div><h4>Vitrine des notions</h4><div class="vit">' + vitHtml(pulse) + '</div></div>';
    if (changed.length) {
      var d = $('#open-vit .dot'); if (d) d.hidden = false;
      var same = changed.every(function (n) { return notionState(n.id) === notionState(changed[0].id); }), t = $('#toast');
      t.innerHTML = changed.length > 2 && same ? '<span><b>' + changed.length + ' notions</b> ' + STATE_LABEL[notionState(changed[0].id)] + 's</span>' : changed.map(function (n) { return '<span><b>' + esc(n.label) + '</b> ' + STATE_LABEL[notionState(n.id)] + '</span>'; }).join('');
      t.style.bottom = (foot.offsetHeight + 10) + 'px';
      t.classList.add('show');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }
  }
  function toastText(text) { var t = $('#toast'); t.textContent = text; t.style.bottom = (foot.offsetHeight + 10) + 'px'; t.classList.add('show'); clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.classList.remove('show'); }, 3200); }
  function icoBtn(id, svg, label, extra, cls) { return '<button class="ico ' + (cls || '') + '" id="' + id + '" type="button" aria-label="' + label + '" title="' + label + '">' + svg + (extra || '') + '</button>'; }
  function sideButtons() { return icoBtn('open-vit', ICON.vit, 'Vitrine des notions', '<span class="dot" hidden></span>', 'sidebar-only'); }
  function bindTop() {
    var b;
    if ((b = $('#back'))) b.addEventListener('click', function () { stopTimer(); G.run = null; showMap(); });
    if ((b = $('#open-vit'))) b.addEventListener('click', openVitrine);
    if ((b = $('#open-lessons'))) b.addEventListener('click', openLessons);
    if ((b = $('#mute'))) b.addEventListener('click', function () { G.data.mute = !G.data.mute; SND.setMuted(G.data.mute); save(); b.innerHTML = G.data.mute ? ICON.mute : ICON.sound; b.setAttribute('aria-label', G.data.mute ? 'Activer le son' : 'Couper le son'); });
  }

  // ——— Code: highlighted, the prepared variables in grey between line 1 and line 2 ———
  var HEAD = '<?php\n';
  function hlLine(line, html) {
    var src = html ? line : HEAD + line, skip = html ? 0 : HEAD.length, pos = 0, out = '';
    E.tokens(src).forEach(function (t) {
      var text = t.text, start = pos; pos += text.length;
      if (pos <= skip) return;
      if (start < skip) text = text.slice(skip - start);
      out += t.type === 'space' ? esc(text) : '<span class="tk-' + t.type + '">' + esc(text) + '</span>';
    });
    return out;
  }
  function hlCode(code, html) { return String(code).split('\n').map(function (l) { return hlLine(l, html); }).join('\n'); }
  function preparedHtml(vars, html) {
    var lines = E.prepared(vars || {});
    var out = '<div class="cl head"><span class="n">1</span><span class="c"><span class="tk-tag">&lt;?php</span></span></div>';
    if (lines.length) out += '<div class="prep" title="Préparé par le jeu"><span class="prep-k">' + ICON.lock + ' préparé par le jeu</span>' + lines.map(function (l) { return '<div class="cl"><span class="n"></span><span class="c">' + hlLine(l, false) + '</span></div>'; }).join('') + '</div>';
    if (html) out += '<div class="cl head"><span class="n"></span><span class="c"><span class="tk-tag">?&gt;</span></span></div>';
    return out;
  }
  function codeHtml(code, vars, opts) {
    opts = opts || {};
    return '<div class="code">' + preparedHtml(vars, opts.html) + String(code).split('\n').map(function (l, i) {
      return '<div class="cl' + (opts.cur === i + 2 ? ' cur' : '') + '"><span class="n">' + (i + 2) + '</span><span class="c">' + (hlLine(l, opts.html) || ' ') + '</span></div>';
    }).join('') + '</div>';
  }
  function editorHtml(value, vars, opts) {
    opts = opts || {};
    return '<div class="ed">' + (opts.note ? '<div class="ed-bar">' + opts.note + '</div>' : '') + '<div class="code ed-code">' + preparedHtml(vars, opts.html) +
      '<div class="ed-body"><div class="gut" aria-hidden="true"></div><div class="area"><pre aria-hidden="true"></pre><textarea id="php" spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" aria-label="Ton programme PHP" placeholder="' + esc(opts.placeholder || 'Tape, ou assemble avec les mots du bas') + '">' + esc(value || '') + '</textarea></div></div></div></div>';
  }
  function bindEditor(html, onChange) {
    var ta = $('#php', main), pre = $('.ed .area pre', main), gut = $('.ed .gut', main);
    if (!ta) return null;
    var paint = function () {
      pre.innerHTML = hlCode(ta.value, html) + '\n';
      var n = Math.max(3, ta.value.split('\n').length), nums = [];
      for (var i = 0; i < n; i++) nums.push(i + 2);
      gut.textContent = nums.join('\n');
    };
    ta.addEventListener('input', function () {
      // iOS smart punctuation: PHP refuses “ ” and ‘ ’.
      if (/[“”«»‘’]/.test(ta.value)) {
        var p = ta.selectionStart;
        ta.value = ta.value.replace(/[“”«»]/g, '"').replace(/[‘’]/g, "'");
        ta.setSelectionRange(p, p);
        toastText('Les guillemets typographiques ont été remplacés : PHP ne connaît que " et \'.');
      }
      paint(); if (onChange) onChange(ta.value);
    });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Tab' && !e.shiftKey) { e.preventDefault(); insertInto(ta, '⇥'); }
    });
    paint();
    lastField = ta;
    return ta;
  }

  function consoleHtml(code, vars, html) {
    var r = E.run(code, vars || {}, { html: html }), out = r.output;
    if (out.length > 600) out = out.slice(0, 600) + ' … (sortie coupée)';
    return '<div class="con"><div class="con-h"><span>Sortie</span><span>cas visible</span></div><pre>' + (out === '' ? '<span class="empty">(rien n’est affiché)</span>' : esc(out)) + '</pre>' + r.errors.map(errHtml).join('') + '</div>';
  }
  function errHtml(e) { return '<div class="err"><div class="raw">' + esc(e.raw) + '</div><div class="fr"><span class="ex">Explication</span><span>' + esc(e.fr) + '</span></div></div>'; }
  function ask(kind, label, text) { return '<div class="ask ' + kind + '"><span class="ask-k">' + label + '</span><p>' + text + '</p></div>'; }
  function reveal(el) {
    if (!el) return;
    setTimeout(function () { scroller.scrollTo({ top: scroller.scrollTop + el.getBoundingClientRect().top - scroller.getBoundingClientRect().top - 10, behavior: 'smooth' }); }, 30);
  }
  function expectHtml(def) {
    var exp = E.expected(def, def.vars || {}), n = E.norm(exp, def.compare === 'html' ? 'html' : 'lines');
    return '<div class="expect"><span class="out-h">' + (Object.keys(def.vars || {}).length ? 'Attendu, avec les variables préparées' : 'Attendu') + '</span><pre>' + (n ? esc(n) : '<span class="empty">(rien)</span>') + '</pre></div>';
  }

  // ——— The step through ———
  function openTrace(code, vars, html) {
    var t = E.steps(code, vars || {}, { html: html }), list = t.list, total = list.length, k = 0;
    var body = '<div id="tr-body"></div>';
    var footHtml = '<input type="range" id="tr-range" min="0" max="' + total + '" value="0" aria-label="Avancer dans l’exécution"><div class="acts"><button type="button" class="btn" data-tr="first" aria-label="Au début">⏮</button><button type="button" class="btn" data-tr="prev">◀ Recule</button><button type="button" class="btn primary" data-tr="next">Avance ▶</button><button type="button" class="btn" data-tr="last" aria-label="À la fin">⏭</button></div>';
    openSheet('trace', 'Pas à pas', body, footHtml);
    var box = $('#tr-body', sheet), range = $('#tr-range', sheet);
    function paint() {
      var end = k >= total, st = end ? list[total - 1] : list[k], prev = k > 0 ? list[k - 1] : null;
      var out = end ? t.final.output : t.outputAt(k), vs = st ? st.vars : {}, names = Object.keys(vs);
      var rows = names.length ? names.map(function (v) {
        var chg = !end && prev && prev.vars[v] !== vs[v];
        return '<tr class="' + (chg ? 'chg' : '') + '"><td>' + esc(v) + '</td><td>' + esc(vs[v]) + '</td></tr>';
      }).join('') : '<tr><td colspan="2" class="none">aucune variable pour l’instant</td></tr>';
      var headLine = end
        ? (t.final.fatal ? '<div class="banner bad">Le programme s’arrête sur une erreur.</div>' + t.final.errors.slice(0, 1).map(errHtml).join('') : (t.cut ? '<div class="banner info">Le pas à pas s’arrête après 600 pas.</div>' : '<p class="note"><b>Fin du programme</b>, en ' + plural(total, 'pas', 'pas') + '.</p>'))
        : '<p class="note">Étape ' + (k + 1) + ' sur ' + total + ' : la ligne <b>' + st.line + '</b> va s’exécuter. Les variables ont leur valeur juste avant elle.</p>';
      box.innerHTML = headLine + codeHtml(code, vars, { html: html, cur: end ? null : st.line }) +
        '<div class="out-h">Les variables</div><table class="vars"><tbody>' + rows + '</tbody></table>' +
        '<div class="con"><div class="con-h"><span>Sortie jusqu’ici</span></div><pre>' + (out === '' ? '<span class="empty">(rien pour l’instant)</span>' : esc(out.length > 600 ? out.slice(0, 600) + ' …' : out)) + '</pre></div>';
      range.value = k;
      var cur = $('.cl.cur', box); if (cur) cur.scrollIntoView({ block: 'nearest' });
    }
    range.addEventListener('input', function () { k = +range.value; paint(); });
    $$('[data-tr]', sheet).forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.dataset.tr;
        k = a === 'first' ? 0 : a === 'last' ? total : a === 'prev' ? Math.max(0, k - 1) : Math.min(total, k + 1);
        paint();
      });
    });
    if (!total) k = 0;
    paint();
  }

  // ——— The bar of words ———
  var lastField = null;
  main.addEventListener('focusin', function (e) { if (e.target.matches('textarea, .blank, .val')) lastField = e.target; });
  var BASE = ['$', ';', '(', ')', '{', '}', '[', ']', '"…"', '.', '=', '" "'];
  var KEYWORD = /^(echo|if|else|elseif|while|for|foreach|as|function|return|match|default|true|false|continue|break)$/;
  function varsIn(code) { var o = []; try { E.tokens(HEAD + code).forEach(function (t) { if (t.type === 'variable' && o.indexOf(t.text) === -1 && t.text !== '$__sonde') o.push(t.text); }); } catch (e) { /* nothing to offer */ } return o; }
  function keysOf(v, out) { if (v && typeof v === 'object') { if (!Array.isArray(v)) Object.keys(v).forEach(function (k) { var c = "['" + k + "']"; if (out.indexOf(c) === -1) out.push(c); keysOf(v[k], out); }); else v.forEach(function (x) { keysOf(x, out); }); } return out; }
  function chipList(def, lv) {
    var vars = [];
    Object.keys(def.vars || {}).forEach(function (k) { vars.push('$' + k); });
    varsIn((def.ref || '') + '\n' + (def.starter || '') + '\n' + (def.template || '').replace(/\[\[\]\]/g, '') + '\n' + (def.example || '')).forEach(function (v) { if (vars.indexOf(v) === -1) vars.push(v); });
    var keys = []; Object.keys(def.vars || {}).forEach(function (k) { keysOf(def.vars[k], keys); });
    var words = C.chipsFor(seenNotions(), lv && lv.notions);
    var out = [['ctl', '↵']].concat(vars.map(function (v) { return ['var', v]; }), keys.map(function (k) { return ['key', k]; }), words.map(function (w) { return [KEYWORD.test(w) ? 'kw' : 'sym', w]; }), BASE.map(function (w) { return ['sym', w]; }));
    var have = {};
    return out.filter(function (x) { if (have[x[1]]) return false; have[x[1]] = 1; return true; });
  }
  var SPACE_AFTER = /^(echo|if|elseif|while|for|foreach|as|function|return|match)$/;
  function insertInto(f, tok) {
    var v = f.value, s = f.selectionStart == null ? v.length : f.selectionStart, e = f.selectionEnd == null ? v.length : f.selectionEnd, ins, caret;
    if (tok === '⌫') {
      if (s === e && s > 0) { var m = /(\s*(\$\w+|\w+\(?|"[^"]*"?|'[^']*'?|\[\s*'[^']*'\s*\]|<=|>=|==|!=|===|!==|&&|\|\||\+\+|--|=>|\S))$/.exec(v.slice(0, s)); s -= m ? m[1].length : 1; }
      f.value = v.slice(0, s) + v.slice(e); caret = s;
    } else {
      if (tok === '↵') { var ls = v.lastIndexOf('\n', s - 1) + 1, before = v.slice(ls, s), indent = before.match(/^ */)[0]; ins = '\n' + indent + (/[{\[(]\s*$/.test(before) ? '    ' : ''); }
      else if (tok === '⇥') ins = '    ';
      else if (tok === '"…"') ins = '""';
      else if (tok === '}') { var ls2 = v.lastIndexOf('\n', s - 1) + 1; if (/^ {4,}$/.test(v.slice(ls2, s))) { v = v.slice(0, s - 4) + v.slice(s); s -= 4; e -= 4; } ins = '}'; }
      else ins = tok + (SPACE_AFTER.test(tok) ? ' ' : '');
      f.value = v.slice(0, s) + ins + v.slice(e);
      caret = s + ins.length - (tok === '"…"' ? 1 : 0);
    }
    f.setSelectionRange(caret, caret);
    f.dispatchEvent(new Event('input'));
  }
  function insertToken(tok) {
    var f = (lastField && main.contains(lastField)) ? lastField : $('textarea, .blank', main);
    if (!f) return;
    if (f.classList.contains('blank')) {
      f.value = tok === '⌫' ? '' : tok.replace('…', '');
      if (tok !== '⌫') { var bl = $$('.blank', main), nx = bl.filter(function (b) { return !b.value; })[0]; lastField = nx || f; }
      markBlanks(); f.dispatchEvent(new Event('input'));
      return;
    }
    insertInto(f, tok);
    lastField = f;
  }
  function markBlanks() { $$('.blank', main).forEach(function (b) { b.classList.toggle('target', b === lastField); }); }
  function setFoot(actions, chips) {
    foot.innerHTML = (chips ? '<div class="chips" role="toolbar" aria-label="Mots PHP à insérer">' + chips.map(function (c) { return '<button type="button" class="chip ' + c[0] + '" data-t="' + esc(c[1]) + '">' + esc(c[1] === '↵' ? '↵ ligne' : c[1]) + '</button>'; }).join('') + '<button type="button" class="chip del" data-t="⌫" aria-label="Effacer le dernier mot">⌫</button></div>' : '') +
      (actions.length ? '<div class="acts">' + actions.map(function (a, i) { return '<button type="button" class="btn ' + (a.cls || '') + '" data-a="' + i + '"' + (a.disabled ? ' disabled' : '') + '>' + a.label + '</button>'; }).join('') + '</div>' : '');
    $$('.chip', foot).forEach(function (c) {
      c.addEventListener('pointerdown', function (e) { e.preventDefault(); }); // keep the caret, and the soft keyboard, where they are
      c.addEventListener('mousedown', function (e) { e.preventDefault(); });
      c.addEventListener('click', function () { SND.click(); insertToken(c.dataset.t); });
    });
    $$('[data-a]', foot).forEach(function (b) { b.addEventListener('click', function () { if (!G.busy) actions[+b.dataset.a].fn(); }); });
  }
  function bindRunKey(fn) { $$('textarea', main).forEach(function (t) { t.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); fn(); } }); }); }

  // ——— The map of the levels ———
  function mapItems(ch) {
    var items = levels().filter(function (lv) { return lv.chapter === ch.n; }), out = [];
    var fl = C.flashes().filter(function (f) { return f.chapter === ch.n; });
    items.forEach(function (lv) {
      out.push(lv);
      fl.forEach(function (f) {
        var last = f.covers[f.covers.length - 1], m = masteryOf(last);
        if ((m && m.id === lv.id) || (!m && lv.id === last)) out.push(f);
      });
    });
    return out;
  }
  function showMap() {
    G.view = 'map'; G.lv = null; G.ticket = null; G.session = null; G.run = null; stopTimer();
    var done = levels().filter(function (lv) { return lv.kind === 'level' && closed(lv.id); }).length, total = levels().filter(function (lv) { return lv.kind === 'level'; }).length;
    top.innerHTML = '<div class="row"><div class="brand">PHP pas à pas<small>' + esc(G.prog && G.prog.grade || '') + '</small></div><span class="stars" title="Étoiles">★ ' + totalStars() + '</span>' + sideButtons() + icoBtn('mute', G.data.mute ? ICON.mute : ICON.sound, G.data.mute ? 'Activer le son' : 'Couper le son') + '</div>' +
      '<div class="where"><b>' + done + '/' + total + '</b> niveaux · ' + C.notions.filter(function (n) { return notionState(n.id) === 'maitrisee'; }).length + ' notions maîtrisées</div>';
    bindTop();
    var nl = nextOpen(), cur = nl ? nl.chapter : reachedChapter();
    var html = '<div class="lvmap">';
    if (!G.data.intro) html += '<div class="hello"><h2>Bienvenue' + (G.user && G.user.firstName ? ', ' + esc(G.user.firstName) : '') + '</h2><p>Chaque niveau t\'apprend une notion de PHP : un cours court, puis des exercices de plus en plus libres, jusqu\'à écrire tes propres programmes. Les conditions, les boucles et les tableaux reviennent sans cesse, dans des rappels, pour que ça tienne. Les défis de maîtrise déclarent une notion acquise. « Pas à pas » te montre ton programme s\'exécuter, ligne par ligne.</p></div>';
    if (closed('FIN')) html += '<div class="trophy-band">' + (window.SGRewards ? window.SGRewards.art('trophy', 'prisme', { size: 72, finish: finishOf('prisme') }) : '') + '<div><h3>L\'épreuve finale est réussie</h3><p>Le prisme est dans ta vitrine.</p></div></div>';
    C.chapters.forEach(function (ch) {
      var items = mapItems(ch), tickets = items.filter(function (x) { return x.kind !== 'flash'; });
      if (!tickets.length) return;
      var anyOpen = tickets.some(function (lv) { return stateOf(lv) !== 'locked'; });
      var lvls = tickets.filter(function (i) { return i.kind === 'level'; });
      if (!anyOpen) {
        html += '<div class="teaser"><span class="chap-k">Chapitre ' + ch.n + ' · ' + ICON.lock + '</span><b>' + esc(ch.title) + '</b><span class="lv-sub">' + (lvls.length ? plural(lvls.length, 'niveau', 'niveaux') + ' (' + lvls[0].num + (lvls.length > 1 ? ' à ' + lvls[lvls.length - 1].num : '') + ')' : 'l\'épreuve finale') + ' · ' + esc(ch.time) + '</span></div>';
        return;
      }
      var doneN = tickets.filter(function (lv) { return closed(lv.id); }).length;
      var expanded = G.opened[ch.n] !== undefined ? G.opened[ch.n] : ch.n === cur;
      html += '<section class="chap"><button type="button" class="chap-h" data-chap="' + ch.n + '" aria-expanded="' + expanded + '"><span class="chap-k">Chapitre ' + ch.n + ' · ' + doneN + '/' + tickets.length + '</span><h3>' + esc(ch.title) + '</h3><div class="chap-bar"><span style="width:' + Math.round(doneN / tickets.length * 100) + '%"></span></div></button>';
      if (expanded) html += '<ol class="path">' + items.map(function (lv) { return lv.kind === 'flash' ? flashRow(lv) : levelRow(lv, nl); }).join('') + '</ol>';
      html += '</section>';
    });
    html += '<button type="button" class="btn ghost small" id="restart">Recommencer le jeu depuis le début</button></div>';
    main.innerHTML = html;
    $$('[data-chap]', main).forEach(function (b) { b.addEventListener('click', function () { var n = +b.dataset.chap; G.opened[n] = b.getAttribute('aria-expanded') !== 'true'; showMap(); }); });
    $$('.lv[data-lv]', main).forEach(function (b) { b.addEventListener('click', function () { openLevel(C.get(b.dataset.lv)); }); });
    $$('.lv[data-flash]', main).forEach(function (b) { b.addEventListener('click', function () { showFlashIntro(C.get(b.dataset.flash)); }); });
    var rs = $('#restart');
    rs.addEventListener('click', function () {
      if (!rs.dataset.armed) { rs.dataset.armed = '1'; rs.textContent = 'Confirmer : tout recommencer (les récompenses restent)'; return; }
      api('newGame', {}).then(function () { location.reload(); });
    });
    setFoot(nl ? [{ label: (G.cards[nl.id] && G.cards[nl.id].status === 'doing' ? 'Reprendre · ' : 'Commencer · ') + (nl.kind === 'final' ? 'épreuve finale' : 'niveau ' + nl.num), cls: 'primary', fn: function () { openLevel(nl); } }] : [], null);
    sideAndNews();
    scroller.scrollTop = 0;
  }
  function finishOf(code) { var r = (G.prog && G.prog.rewards || []).concat(G.rewards).filter(function (x) { return x.code === code; }); return r.length ? r[r.length - 1].finish || 'brass' : 'brass'; }
  function coversText(lv) { return lv.covers.map(function (id) { return C.get(id) ? 'niveau ' + C.get(id).num : id; }).join(', ').replace(/, ([^,]*)$/, ' et $1'); }
  function levelRow(lv, nl) {
    var st = stateOf(lv), isCur = nl && nl.id === lv.id, chal = lv.kind !== 'level';
    var cls = 'lv ' + (chal ? 'chal ' : '') + (lv.kind === 'final' ? 'final ' : '') + (st === 'locked' ? 'locked' : (st === 'done' ? 'done' : (isCur ? 'cur' : 'open')));
    var node = st === 'locked' ? ICON.lock : (lv.kind === 'final' ? ICON.star : (chal ? ICON.check : lv.num));
    var k = lv.kind === 'final' ? 'Épreuve finale' : (chal ? 'Défi de maîtrise · ' + coversText(lv) : (lv.atelier ? 'Niveau ' + lv.num + ' · atelier' : 'Niveau ' + lv.num));
    var sub = lv.kind === 'final' ? lv.draw + ' programmes · ' + Math.round(lv.seconds / 60) + ' min · tous les chapitres' : (chal ? lv.draw + ' programmes · sans indice · sans chrono' : lv.summary);
    var meta = '';
    if (st === 'done') meta = '<span class="st">' + starLine(qualityOf(lv.id)) + '</span>' + (chal ? (lv.kind === 'final' ? ' · réussie' : ' · maîtrisé') : '');
    else if (lv.kind === 'final' && G.data.best[lv.id] !== undefined) meta = 'Meilleur : ' + G.data.best[lv.id] + '/' + lv.draw;
    else if (chal && st === 'open') meta = lv.kind === 'mastery' ? 'Non tenté · ne bloque pas la suite' : 'Ouverte';
    else if (st === 'open' && G.cards[lv.id] && G.cards[lv.id].status === 'doing') meta = 'Étape ' + (G.cards[lv.id].step + 1) + ' sur ' + G.cards[lv.id].steps;
    else if (st === 'open') meta = Object.keys(lv.steps).length + ' étapes · 20 min environ';
    return '<li><button type="button" class="' + cls + '" data-lv="' + lv.id + '"' + (st === 'locked' ? ' disabled aria-disabled="true"' : '') + '><span class="lv-node">' + node + '</span><span class="lv-text"><span class="lv-k">' + esc(k) + '</span><b>' + esc(lv.title) + '</b><span class="lv-sub">' + esc(sub) + '</span>' + (meta ? '<span class="lv-meta">' + meta + '</span>' : '') + '</span></button></li>';
  }
  function flashRow(f) {
    var open = flashOpen(f), best = flashBest(f), win = (best || 0) >= 7;
    var cls = 'lv flash ' + (!open ? 'locked' : (win ? 'done' : 'open'));
    var meta = best !== null ? 'Meilleur : ' + best + '/' + f.draw + (win ? ' · ⚡' : '') : (open ? 'Facultatif · ne compte pas dans les étoiles' : '');
    return '<li><button type="button" class="' + cls + '" data-flash="' + f.id + '"' + (!open ? ' disabled aria-disabled="true"' : '') + '><span class="lv-node">' + (open ? ICON.bolt : ICON.lock) + '</span><span class="lv-text"><span class="lv-k">Lecture éclair · ' + coversText(f) + '</span><b>' + esc(f.title) + '</b><span class="lv-sub">' + f.draw + ' codes à lire · ' + Math.round(f.seconds / 60) + ' min</span>' + (meta ? '<span class="lv-meta">' + meta + '</span>' : '') + '</span></button></li>';
  }

  // ——— A level ———
  function openLevel(lv) {
    if (!lv || stateOf(lv) === 'locked' || G.busy) return;
    if (!G.data.intro) { G.data.intro = true; save(); }
    if (stateOf(lv) === 'done') return lv.kind === 'level' ? showDone(lv) : showChallengeDone(lv);
    G.busy = true;
    api('startTicket', { ticket: lv.id }).then(function (r) {
      G.busy = false;
      G.lv = lv; G.ticket = r.ticket; G.session = r.session; G.step = r.step; G.hints = r.hints || 0; G.run = null;
      if (lv.kind === 'mastery') { G.view = 'mastery'; return showMasteryIntro(); }
      if (lv.kind === 'final') { G.view = 'final'; return showFinalIntro(); }
      G.view = 'level';
      if (G.step >= G.ticket.steps.length) return endLevel();
      showStep();
    }).catch(function (e) { G.busy = false; toastText('Impossible d\'ouvrir ce niveau : ' + e.message); });
  }
  var KIND = {
    choose: ['k-pred', 'Prédire'], value: ['k-pred', 'Prédire'], trace: ['k-sui', 'Suivre'], cases: ['k-sui', 'Suivre'], aim: ['k-vis', 'Viser'], aimw: ['k-vis', 'Viser'],
    complete: ['k-comp', 'Compléter'], repair: ['k-rep', 'Réparer'], write: ['k-ecr', 'Écrire'], recall: ['k-rap', 'Rappel']
  };
  function kindClass(def, server) { if (!def || server.kind === 'explain' || server.kind === 'choice') return 'k-qcm'; return (KIND[def.kind] || ['k-comp'])[0]; }
  function kindLabel(def, server) { if (!def || server.kind === 'explain' || server.kind === 'choice') return 'Comprendre'; return (KIND[def.kind] || ['', 'Défi'])[1]; }
  function levelTop(title) {
    var lv = G.lv, steps = G.ticket.steps, n = steps.length;
    top.innerHTML = '<div class="row">' + icoBtn('back', ICON.back, 'Retour à la carte des niveaux') + '<div class="ttl"><small>Niveau ' + lv.num + ' · ' + esc(chapterOf(lv.chapter).title) + '</small><b>' + esc(lv.title) + '</b></div>' + (Object.keys(lv.lessons).length ? icoBtn('open-lessons', ICON.book, 'Revoir les cours du niveau') : '') + sideButtons() + '</div>' +
      '<div class="prog" style="grid-template-columns:repeat(' + n + ',1fr)">' + steps.map(function (s, i) { return '<span class="seg ' + kindClass(lv.steps[s.id], s) + (i < G.step ? ' done' : '') + (i === G.step ? ' cur' : '') + '"><span></span></span>'; }).join('') + '</div>' +
      '<div class="where"><b>' + Math.min(G.step + 1, n) + '/' + n + '</b> · ' + esc(title || '') + '</div>';
    bindTop();
  }
  function showStep() {
    var s = G.ticket.steps[G.step], def = G.lv.steps[s.id] || {};
    if (def.before && !G.data.seen[G.lv.id + ':' + def.before]) return showLesson(def.before, false);
    lastField = null;
    levelTop(kindLabel(def, s));
    if (s.kind === 'explain' || s.kind === 'choice') renderQuestion(s);
    else if (def.kind === 'choose') renderChoose(s, def);
    else if (def.kind === 'value') renderValue(s, def);
    else if (def.kind === 'trace' || def.kind === 'cases') renderTable(s, def);
    else if (def.kind === 'aim' || def.kind === 'aimw') renderAim(s, def);
    else if (def.kind === 'recall') renderProgram(s, recallDef(s.id));
    else renderProgram(s, def);
    sideAndNews();
    scroller.scrollTop = 0;
  }
  function showLesson(key, review) {
    var lv = G.lv, l = lv.lessons[key];
    if (!l) return showStep();
    (lv.notions || []).forEach(function (n) { G.data.nseen[n] = 1; });
    G.data.seen[lv.id + ':' + key] = 1; save();
    lastField = null;
    levelTop('Cours');
    lessonScreen(l, review ? 'Retour à l\'étape' : 'À l\'exercice →', showStep);
  }
  function lessonScreen(l, label, back) {
    main.innerHTML = '<div class="kind k-cap"><span class="pill">Cours</span><h3>' + esc(l.title) + '</h3></div><div class="lesson">' + l.html + '</div>' +
      (l.example ? editorHtml(l.example, l.vars, { html: isHtml(), note: 'exemple à modifier, rien n\'est noté' }) + (l.tryit ? '<p class="try">' + esc(l.tryit) + '</p>' : '') + '<div id="res" class="out"></div>' : '');
    if (l.example) bindEditor(isHtml());
    var run = function (scroll) { var res = $('#res', main); res.innerHTML = consoleHtml($('#php', main).value, l.vars, isHtml()); if (scroll) reveal(res); };
    if (l.example) { run(false); bindRunKey(function () { run(true); }); }
    setFoot([].concat(l.example ? [{ label: 'Pas à pas', fn: function () { openTrace($('#php', main).value, l.vars, isHtml()); } }, { label: '▶ Exécuter', fn: function () { run(true); } }] : [], [{ label: label, cls: 'primary', fn: back }]), l.example ? chipList({ example: l.example, vars: l.vars }, G.lv) : null);
    sideAndNews();
    scroller.scrollTop = 0;
  }
  function isHtml() { var ch = G.lv && chapterOf(G.lv.chapter); return !!(ch && ch.compare === 'html'); }

  // Corrected by the server: an explanation or a choice, its options in the order the server shuffled.
  function renderQuestion(s) {
    main.innerHTML = '<div class="kind k-qcm"><span class="pill">Comprendre</span><h3>Une question</h3></div>' + ask('k-qcm', 'Question', esc(s.prompt)) + '<div class="opts">' + s.options.map(function (o, i) { return '<button type="button" class="opt" data-i="' + i + '">' + esc(o) + '</button>'; }).join('') + '</div><div id="res" class="out"></div>';
    $$('.opt', main).forEach(function (b) {
      b.addEventListener('click', function () {
        if (G.busy || b.disabled) return;
        G.busy = true;
        api('submitStep', { session: G.session, step: s.id, input: +b.dataset.i }).then(function (r) {
          G.busy = false;
          var res = $('#res', main), react = r.reaction && r.reaction.text ? '<div class="react">' + esc(r.reaction.text) + '</div>' : '';
          if (r.ok) {
            SND.ok(); b.classList.add('right'); $$('.opt', main).forEach(function (o) { o.disabled = true; });
            res.innerHTML = '<div class="banner good">Exactement.</div>' + react;
            stepDone(r);
          } else {
            SND.ko(); b.classList.add('wrong'); b.disabled = true;
            res.innerHTML = '<div class="banner bad">Pas tout à fait.</div>' + react;
          }
          reveal(res);
        }).catch(function (e) { G.busy = false; toastText(e.message); });
      });
    });
    setFoot([], null);
  }
  function stepDone(r, extra) {
    G.step = r.next;
    var acts = (extra || []).slice();
    if (r.done) acts.push({ label: 'Terminer le niveau →', cls: 'primary', fn: endLevel });
    else acts.push({ label: 'Étape suivante →', cls: 'primary', fn: showStep });
    setFoot(acts, null);
  }

  // The written hints of a measured step, one rung at a time; each one asked counts in the stars.
  function hintsShown(sid) { var t = G.data.hints[G.lv.id] || {}; return t[sid] || 0; }
  function helpHtml(def, sid) {
    var n = hintsShown(sid);
    if (!n) return '';
    return '<div class="helpbox">' + def.hints.slice(0, n).map(function (h, i) { return i === 0 ? '<p><b>Indice :</b> ' + esc(h) + '</p>' : '<pre>' + esc(h) + '</pre>'; }).join('') + '</div>';
  }
  function hintAction(def, sid, rerender) {
    var n = hintsShown(sid), max = (def.hints || []).length;
    return { label: n >= max ? 'Plus d\'indice' : 'Indice' + (max > 1 ? ' ' + (n + 1) + '/' + max : ''), cls: 'help', disabled: n >= max, fn: function () {
      if (G.busy || hintsShown(sid) >= max) return;
      G.busy = true;
      api('useHint', { session: G.session }).then(function (r) {
        G.busy = false; G.hints = r.hints;
        G.data.hints[G.lv.id] = G.data.hints[G.lv.id] || {};
        G.data.hints[G.lv.id][sid] = hintsShown(sid) + 1; save();
        rerender();
        reveal($('#help', main));
      }).catch(function (e) { G.busy = false; toastText(e.message); });
    } };
  }
  /* A measured step's delivery: the labels of the failed criteria, nothing of the answer. A refused one
     counts a mistake against the notion, which the reminders will aim at. */
  function deliver(sid, result, onDone, missOn) {
    G.busy = true;
    return api('submitStep', { session: G.session, step: sid, input: { passed: !!result.passed, failed: result.failed || [] } }).then(function (r) {
      G.busy = false;
      if (r.ok) { SND.ok(); onDone(r); } else { SND.ko(); noteMiss(missOn); }
      return r;
    }).catch(function (e) { G.busy = false; toastText(e.message); });
  }
  function noteMiss(mid) {
    var m = mid || (G.lv && masteryOf(G.lv.id) && masteryOf(G.lv.id).id);
    if (!m) return;
    G.data.miss[m] = (G.data.miss[m] || 0) + 1; save();
  }
  function draftOf(sid) { var d = G.data.drafts[G.lv.id]; return d ? d[sid] : undefined; }
  function setDraft(sid, v) { G.data.drafts[G.lv.id] = G.data.drafts[G.lv.id] || {}; G.data.drafts[G.lv.id][sid] = v; save(); }

  /* ——— The reminders: drawn from the mastery pools of the notions already passed, the ones where the
     student went wrong first, then the core (conditions, loops, arrays). Kept once drawn. */
  function recallDef(sid) {
    var lv = G.lv, mine = G.data.recall[lv.id] = G.data.recall[lv.id] || {}, ref = mine[sid];
    var item = ref && C.get(ref.m) && C.get(ref.m).pool[ref.i];
    if (!item) {
      var cands = levels().filter(function (m) { return m.kind === 'mastery' && m.covers.every(function (id) { var c = C.get(id); return c && c.num < lv.num; }); });
      var other = Object.keys(mine).map(function (k) { return mine[k].m; });
      var weights = cands.map(function (m) { var core = chapterOf(m.chapter).core ? 3 : 0; return (other.indexOf(m.id) === -1 ? 1 : 0.2) * (1 + core + 4 * (G.data.miss[m.id] || 0)); });
      var sum = weights.reduce(function (a, b) { return a + b; }, 0), x = Math.random() * sum, pick = cands[0];
      for (var i = 0; i < cands.length; i++) { x -= weights[i]; if (x <= 0) { pick = cands[i]; break; } }
      var used = G.data.recallUsed || [], idx = shuffle(pick.pool.map(function (_, j) { return j; })), choice = idx.filter(function (j) { return used.indexOf(pick.id + '/' + j) === -1; })[0];
      if (choice === undefined) choice = idx[0];
      mine[sid] = ref = { m: pick.id, i: choice };
      G.data.recallUsed = used.concat([pick.id + '/' + choice]).slice(-40);
      save(true); // at once: a reload a second later must find the same reminder
      item = pick.pool[choice];
    }
    return Object.assign({}, item, { id: sid, kind: 'recall', recallOf: ref.m });
  }

  // ——— Predict: what does this code print? ———
  function renderChoose(s, def) {
    var rerender = function () { renderChoose(s, def); };
    var opts = seeded(def.options, G.session + ':' + s.id), out = E.printed(def), mode = def.html ? 'html' : 'words';
    main.innerHTML = '<div class="kind k-pred"><span class="pill">Prédire</span><h3>Avant d\'exécuter</h3></div>' + ask('k-pred', 'Consigne', esc(def.ask)) + codeHtml(def.code, def.vars, { html: def.html }) +
      '<div id="help">' + helpHtml(def, s.id) + '</div><div class="opts">' + opts.map(function (o) { return '<button type="button" class="opt mono" data-o="' + esc(o) + '">' + esc(o) + '</button>'; }).join('') + '</div><div id="res" class="out"></div>';
    $$('.opt', main).forEach(function (b) {
      b.addEventListener('click', function () {
        if (G.busy || b.disabled) return;
        var right = E.norm(b.dataset.o, mode) === out, box = $('#res', main);
        deliver(s.id, { passed: right, failed: right ? [] : ['Prédiction'] }, function (r) {
          b.classList.add('right'); $$('.opt', main).forEach(function (o) { o.disabled = true; });
          box.innerHTML = '<div class="banner good">Bien vu : c\'est ce qu\'il affiche.</div>';
          stepDone(r, [{ label: 'Pas à pas', fn: function () { openTrace(def.code, def.vars, def.html); } }]);
        }).then(function (r) { if (r && !r.ok) { b.classList.add('wrong'); b.disabled = true; box.innerHTML = '<div class="banner bad">Pas tout à fait. Relis le code ligne par ligne, puis choisis à nouveau.</div>'; } reveal(box); });
      });
    });
    setFoot([hintAction(def, s.id, rerender)], null);
  }
  function renderValue(s, def) {
    var rerender = function () { var v = $('#val', main) ? $('#val', main).value : ''; renderValue(s, def); $('#val', main).value = v; };
    main.innerHTML = '<div class="kind k-pred"><span class="pill">Prédire</span><h3>Avant d\'exécuter</h3></div>' + ask('k-pred', 'Consigne', esc(def.ask)) + codeHtml(def.code, def.vars) +
      '<div id="help">' + helpHtml(def, s.id) + '</div><label class="out-h" for="val">Ta réponse</label><input id="val" class="val" type="text" autocomplete="off" autocapitalize="off" spellcheck="false"><div id="res" class="out"></div>';
    var expected = E.printed(def);
    var check = function () {
      var input = $('#val', main).value, box = $('#res', main);
      if (!input.trim()) { box.innerHTML = '<div class="banner info">Écris ta réponse.</div>'; return; }
      var ok = E.sameAnswer(input, expected);
      deliver(s.id, { passed: ok, failed: ok ? [] : ['Valeur'] }, function (r) { box.innerHTML = '<div class="banner good">Exactement : ' + esc(expected) + '.</div>'; $('#val', main).disabled = true; stepDone(r, [{ label: 'Pas à pas', fn: function () { openTrace(def.code, def.vars); } }]); })
        .then(function (r) { if (r && !r.ok) box.innerHTML = '<div class="banner bad">Ce n\'est pas ça. Suis le programme ligne par ligne, une variable après l\'autre.</div>'; reveal(box); });
    };
    $('#val', main).addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); check(); } });
    setFoot([hintAction(def, s.id, rerender), { label: 'Vérifier', cls: 'primary', fn: check }], null);
  }

  // ——— Follow: the table of the loop's turns, or of the cases ———
  function renderTable(s, def) {
    var isTrace = def.kind === 'trace', rows = isTrace ? E.traceRows(def) : E.caseRows(def), cols = isTrace ? def.watch : ['affiche'];
    // A table of cases shows its first value on the grey line; the game tries every one of them.
    var shown = isTrace ? def.vars : (function () { var o = Object.assign({}, def.vars || {}); o[def.var] = def.values[0]; return o; })();
    var saved = draftOf(s.id) || {}, wrongOnce = !!(G.data.drafts[G.lv.id] && G.data.drafts[G.lv.id][s.id + ':ko']);
    var rerender = function () { renderTable(s, def); };
    var head = isTrace ? '<th>tour</th>' + cols.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') : '<th>' + esc('$' + def.var) + '</th><th>affiche</th>';
    var body = rows.map(function (row, r) {
      var first = isTrace ? '<td class="t">' + (r + 1) + '</td>' : '<td class="t">' + esc(E.literal(def.values[r])) + '</td>';
      var cells = (isTrace ? row : [row]).map(function (v, c) { var key = r + ':' + c; return '<td><input class="cell" data-cell="' + key + '" value="' + esc(saved[key] || '') + '" autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="' + (isTrace ? 'decimal' : 'text') + '" aria-label="' + (isTrace ? 'Tour ' + (r + 1) + ', ' + esc(cols[c]) : 'Affichage pour ' + esc(String(def.values[r]))) + '"></td>'; }).join('');
      return '<tr>' + first + cells + '</tr>';
    }).join('');
    main.innerHTML = '<div class="kind k-sui"><span class="pill">Suivre</span><h3>' + (isTrace ? 'Le tableau de suivi' : 'Le tableau de cas') + '</h3></div>' + ask('k-sui', 'Consigne', esc(def.ask)) +
      codeHtml(def.code, shown, { cur: isTrace ? def.after + 1 : null, html: def.html }) +
      '<p class="note">' + (isTrace ? 'Une ligne par passage sur la ligne <b>' + (def.after + 1) + '</b> : la valeur des variables juste après elle.' : 'La ligne grise montre la première valeur de <code>$' + esc(def.var) + '</code> ; pour chaque valeur du tableau, écris ce que le programme affiche.') + '</p>' +
      '<div class="tbl-wrap"><table class="follow"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div><div id="help">' + helpHtml(def, s.id) + '</div><div id="res" class="out"></div>';
    $$('.cell', main).forEach(function (i) { i.addEventListener('input', function () { saved[i.dataset.cell] = i.value; setDraft(s.id, saved); }); });
    var check = function () {
      var box = $('#res', main), bad = [];
      rows.forEach(function (row, r) { (isTrace ? row : [row]).forEach(function (v, c) { var key = r + ':' + c, input = saved[key] || ''; var ok = isTrace ? E.sameAnswer(input, v) : E.norm(input, def.html ? 'html' : 'words') === v; if (!ok) bad.push(key); }); });
      $$('.cell', main).forEach(function (i) { i.classList.toggle('bad', bad.indexOf(i.dataset.cell) !== -1); });
      if ($$('.cell', main).some(function (i) { return !i.value.trim(); })) { box.innerHTML = '<div class="banner info">Remplis toutes les cases, puis vérifie.</div>'; reveal(box); return; }
      deliver(s.id, { passed: !bad.length, failed: bad.length ? ['Tableau : ' + bad.length + ' case(s)'] : [] }, function (r) {
        $$('.cell', main).forEach(function (i) { i.disabled = true; i.classList.add('good'); });
        box.innerHTML = '<div class="banner good">Tableau juste.</div>';
        stepDone(r, [{ label: 'Comparer au pas à pas', fn: function () { openTrace(def.code, shown, def.html); } }]);
      }).then(function (r) {
        if (!r || r.ok) return reveal(box);
        G.data.drafts[G.lv.id][s.id + ':ko'] = 1; save();
        box.innerHTML = '<div class="banner bad">' + plural(bad.length, 'case à revoir', 'cases à revoir') + ' (en rouge).</div><p class="hint-p">« Pas à pas » montre maintenant le programme ligne par ligne : suis-le, puis corrige.</p>';
        footer(true); reveal(box);
      });
    };
    var footer = function (traceOk) { setFoot([hintAction(def, s.id, rerender)].concat(traceOk ? [{ label: 'Pas à pas', fn: function () { openTrace(def.code, shown, def.html); } }] : []).concat([{ label: 'Vérifier', cls: 'primary', fn: check }]), null); };
    footer(wrongOnce);
  }

  // ——— Aim: a box in an array drawn as nested boxes ———
  function boxesHtml(v, path, opts) {
    if (v !== null && typeof v === 'object') {
      var list = Array.isArray(v), keys = list ? v.map(function (_, i) { return i; }) : Object.keys(v);
      return '<div class="box ' + (list ? 'list' : 'assoc') + '"><span class="box-k">' + (list ? 'liste' : 'clés') + '</span>' + keys.map(function (k) {
        var p = path + (list || /^-?\d+$/.test(String(k)) ? '[' + k + ']' : "['" + k + "']");
        return '<div class="slot"><span class="slot-k">' + (list ? '[' + k + ']' : "'" + esc(k) + "'") + '</span>' + boxesHtml(v[k], p, opts) + '</div>';
      }).join('') + '</div>';
    }
    var lit = esc(E.literal(v)), hot = opts.target === path;
    return opts.tap ? '<button type="button" class="leaf" data-path="' + esc(path) + '">' + lit + '</button>' : '<span class="leaf' + (hot ? ' hot' : '') + '">' + lit + '</span>';
  }
  function renderAim(s, def) {
    var tap = def.kind === 'aim', rerender = function () { renderAim(s, def); }, data = (def.vars || {})[def.var];
    main.innerHTML = '<div class="kind k-vis"><span class="pill">Viser</span><h3>' + (tap ? 'Touche la bonne case' : 'Écris le chemin de la case allumée') + '</h3></div>' + ask('k-vis', 'Consigne', esc(def.ask) + (tap ? '<br><code class="big">$' + esc(def.var) + esc(def.target) + '</code>' : '')) +
      '<div class="boxes"><span class="box-name">$' + esc(def.var) + '</span>' + boxesHtml(data, '', { tap: tap, target: def.target }) + '</div>' +
      (tap ? '' : '<label class="out-h" for="val">Ton accès</label><input id="val" class="val mono" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="$' + esc(def.var) + '[…]">') +
      '<div id="help">' + helpHtml(def, s.id) + '</div><div id="res" class="out"></div>';
    var picked = null;
    $$('.leaf[data-path]', main).forEach(function (b) { b.addEventListener('click', function () { SND.click(); picked = b.dataset.path; $$('.leaf', main).forEach(function (x) { x.classList.toggle('sel', x === b); }); }); });
    var check = function () {
      var box = $('#res', main), ok, failed;
      if (tap) {
        if (!picked) { box.innerHTML = '<div class="banner info">Touche une case, puis vérifie.</div>'; return; }
        ok = picked === def.target; failed = ['Case visée'];
      } else {
        var expr = $('#val', main).value.trim().replace(/;$/, '').replace(/[“”]/g, '"').replace(/[‘’]/g, "'");
        if (!expr) { box.innerHTML = '<div class="banner info">Écris l\'accès, puis vérifie.</div>'; return; }
        var shape = new RegExp('^\\$' + def.var + '(\\s*\\[\\s*(\\d+|\'[^\']*\'|"[^"]*")\\s*\\])+$').test(expr);
        var mine = shape ? E.run('echo json_encode(' + expr + ');', def.vars) : null, want = E.run('echo json_encode($' + def.var + def.target + ');', def.vars);
        ok = !!mine && !mine.errors.length && mine.output === want.output;
        failed = ['Accès écrit'];
        if (!shape) { box.innerHTML = '<div class="banner info">Un accès commence par <code>$' + esc(def.var) + '</code>, suivi de crochets : <code>[2]</code> pour une liste, <code>[\'nom\']</code> pour une clé.</div>'; return; }
        if (mine.errors.length) box.innerHTML = errHtml(mine.errors[0]);
      }
      deliver(s.id, { passed: ok, failed: ok ? [] : failed }, function (r) {
        box.innerHTML = '<div class="banner good">C\'est la bonne case : <code>$' + esc(def.var) + esc(def.target) + '</code>.</div>';
        $$('.leaf', main).forEach(function (x) { x.disabled = true; });
        stepDone(r);
      }).then(function (r) { if (r && !r.ok) box.innerHTML += '<div class="banner bad">Pas cette case. Le premier crochet choisit dans la boîte extérieure, le suivant dans celle qu\'il a choisie.</div>'; reveal(box); });
    };
    if (!tap) $('#val', main).addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); check(); } });
    setFoot([hintAction(def, s.id, rerender), { label: 'Vérifier', cls: 'primary', fn: check }], tap ? null : [['var', '$' + def.var]].concat(keysOf(data, []).map(function (k) { return ['key', k]; }), ['[0]', '[1]', '[2]', '[3]'].map(function (k) { return ['key', k]; })));
  }

  // ——— Complete, repair, write, a reminder: a program measured on the visible case and the hidden ones ———
  function renderProgram(s, def) {
    var kind = def.kind, isComp = kind === 'complete', rec = kind === 'recall';
    var pill = { complete: 'Compléter', repair: 'Réparer', write: 'Écrire', recall: 'Rappel' }[kind] || 'Défi';
    var writes = Object.keys(G.lv.steps).filter(function (k) { return G.lv.steps[k].kind === 'write'; }), wi = writes.indexOf(s.id);
    var repairs = Object.keys(G.lv.steps).filter(function (k) { return G.lv.steps[k].kind === 'repair'; }), ri = repairs.indexOf(s.id);
    var title = kind === 'write' ? 'Programme ' + (wi + 1) + ' sur ' + writes.length : (kind === 'repair' ? (repairs.length > 1 ? 'Réparation ' + (ri + 1) + ' sur ' + repairs.length : 'Un programme à réparer') : (rec ? 'Retour sur « ' + esc(C.get(def.recallOf).title) + ' »' : 'Un programme à trous'));
    var ed, html = !!def.html;
    if (isComp) {
      var n = 0, saved = draftOf(s.id) || [];
      ed = '<div class="code">' + preparedHtml(def.vars, html) + def.template.split('\n').map(function (l, i) {
        var out = esc(l).replace(/\[\[\]\]/g, function () { var v = saved[n] || ''; return '<input class="blank" data-b="' + (n++) + '" aria-label="Trou ' + n + '" spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" inputmode="none" placeholder="?" value="' + esc(v) + '">'; });
        return '<div class="cl"><span class="n">' + (i + 2) + '</span><span class="c">' + out + '</span></div>';
      }).join('') + '</div>';
    } else ed = editorHtml(draftOf(s.id) !== undefined ? draftOf(s.id) : (def.starter || ''), def.vars, { html: html, placeholder: kind === 'repair' ? '' : 'Écris ton programme ici, ou assemble-le avec les mots du bas' });
    main.innerHTML = '<div class="kind ' + KIND[kind][0] + '"><span class="pill">' + pill + '</span><h3>' + title + '</h3></div>' +
      (rec ? '<p class="hint-p">Un rappel revient sur une notion déjà vue, choisie parmi celles où tu t\'es trompé, sinon parmi les conditions, les boucles et les tableaux.</p>' : '') +
      ask(KIND[kind][0], 'Consigne', esc(def.ask)) + expectHtml(def) + ed + '<div id="help">' + helpHtml(def, s.id) + '</div><div id="res" class="out"></div>';
    var code = function () {
      if (!isComp) return $('#php', main).value;
      var vals = $$('.blank', main).map(function (b) { return b.value.trim(); }), i = 0;
      return def.template.replace(/\[\[\]\]/g, function () { return vals[i++] || ''; });
    };
    if (isComp) {
      lastField = $('.blank', main);
      var fit = function (b) { b.style.width = Math.max(4, b.value.length + 2) + 'ch'; };
      $$('.blank', main).forEach(function (b) {
        fit(b);
        b.addEventListener('input', function () { fit(b); setDraft(s.id, $$('.blank', main).map(function (x) { return x.value; })); });
        b.addEventListener('focus', function () { lastField = b; markBlanks(); });
        b.addEventListener('click', function () { lastField = b; markBlanks(); });
        b.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); verify(); } });
      });
      markBlanks();
    } else bindEditor(html, function (v) { setDraft(s.id, v); });
    var run = function () { var box = $('#res', main); box.innerHTML = consoleHtml(code(), def.vars, html); reveal(box); };
    var verify = function () {
      var text = code(), box = $('#res', main);
      if (!text.trim() || (isComp && $$('.blank', main).some(function (b) { return !b.value.trim(); }))) { box.innerHTML = '<div class="banner info">' + (isComp ? 'Remplis tous les trous, puis vérifie.' : 'Écris ton programme, puis vérifie.') + '</div>'; reveal(box); return; }
      var res = E.check(def, text);
      if (!res.deliverable) { box.innerHTML = (res.error ? errHtml(res.error) : '') + '<p class="hint-p">Ce programme ne s\'exécute pas : rien n\'est compté. Corrige l\'erreur (« Tester » ne coûte rien), puis vérifie.</p>'; SND.ko(); reveal(box); return; }
      box.innerHTML = verdictHtml(res);
      deliver(s.id, res, function (r) {
        box.insertAdjacentHTML('afterbegin', '<div class="banner good">Exactement ce qui était demandé, sur le cas visible et ' + plural(res.criteria.length - 1, 'cas caché', 'cas cachés') + '.</div>');
        $('#help', main).innerHTML = '';
        $$('.blank', main).forEach(function (b) { b.disabled = true; });
        if ($('#php', main)) $('#php', main).readOnly = true;
        stepDone(r, [{ label: 'Pas à pas', fn: function () { openTrace(code(), def.vars, html); } }]);
      }, rec ? def.recallOf : null).then(function () { reveal(box); });
    };
    var footer = function () { setFoot([hintAction(def, s.id, function () { renderProgram(s, rec ? recallDef(s.id) : def); }), { label: 'Pas à pas', fn: function () { openTrace(code(), def.vars, html); } }, { label: '▶ Tester', fn: run }, { label: 'Vérifier', cls: 'primary', fn: verify }], isComp ? (def.words || []).map(function (w) { return [/^\$/.test(w) ? 'var' : (KEYWORD.test(w) ? 'kw' : 'sym'), w]; }) : chipList(def, G.lv)); };
    footer();
    bindRunKey(run);
  }
  function verdictHtml(res) {
    return '<ul class="crit">' + res.criteria.map(function (c) { return '<li class="' + (c.ok ? 'ok' : 'ko') + '"><span><span class="base">' + esc(c.label) + '</span>' + c.detail + (c.ir ? '<span class="ir">' + c.ir + '</span>' : '') + '</span></li>'; }).join('') + '</ul>';
  }

  // ——— The end of a level ———
  function endLevel() {
    if (G.busy) return;
    G.busy = true;
    api('endTicket', { session: G.session }).then(function (r) {
      G.busy = false;
      SND.level();
      var lv = G.lv;
      if (r.rewards && r.rewards.length) G.rewards = G.rewards.concat(r.rewards);
      return refresh().then(function () { return api('getProgress'); }).then(function (p) { G.prog = p; showEnd(lv, r); });
    }).catch(function (e) { G.busy = false; toastText(e.message); });
  }
  function rewardsHtml(list) {
    if (!list || !list.length || !window.SGRewards) return '';
    var FIN = { brass: 'laiton', silver: 'argent', gold: 'or' };
    return '<div class="out-h">Dans ta vitrine</div><div class="rewards">' + list.map(function (r) { return '<figure>' + window.SGRewards.art(r.kind, r.code, { size: r.kind === 'card' ? 84 : 72, finish: r.finish || undefined }) + '<figcaption>' + esc(r.name) + (r.finish ? ' · ' + (FIN[r.finish] || r.finish) : '') + (r.raised ? ' (finition relevée)' : '') + '</figcaption></figure>'; }).join('') + '</div>';
  }
  function uniqueNotions(ch) { var o = []; (ch.covers || []).forEach(function (id) { (C.get(id).notions || []).forEach(function (n) { if (o.indexOf(n) === -1) o.push(n); }); }); return o; }
  function showEnd(lv, r) {
    G.view = 'end';
    var q = r.quality || 0, nl = nextOpen(), chal = lv.kind !== 'level';
    top.innerHTML = '<div class="row">' + icoBtn('back', ICON.back, 'Retour à la carte des niveaux') + '<div class="ttl"><small>' + (chal ? (lv.kind === 'final' ? 'Épreuve finale' : 'Défi de maîtrise') : 'Niveau ' + lv.num) + '</small><b>' + esc(lv.title) + '</b></div>' + sideButtons() + '</div>';
    bindTop();
    var title = lv.kind === 'final' ? 'Épreuve finale réussie' : (chal ? 'Défi de maîtrise réussi' : 'Niveau ' + lv.num + ' réussi');
    var open = C.flashes().filter(function (f) { return flashOpen(f) && flashBest(f) === null && f.covers.indexOf(lv.id) !== -1; })[0] || C.flashes().filter(function (f) { return flashOpen(f) && flashBest(f) === null && masteryOf(f.covers[f.covers.length - 1]) && masteryOf(f.covers[f.covers.length - 1]).id === lv.id; })[0];
    main.innerHTML = '<div class="lvdone"><span class="lvdone-n' + (chal ? ' chal' : '') + '">' + (chal ? (lv.kind === 'final' ? ICON.star : ICON.check) : lv.num) + '</span><h3>' + esc(title) + '</h3><p>' + esc(lv.title) + '</p><span class="s">' + starLine(q) + '</span><p>' + (q === 3 ? 'Sans erreur et sans indice.' : (q === 2 ? 'Avec une ou deux erreurs ou indices.' : 'Avec plusieurs erreurs ou indices : les rappels y reviendront, et le défi de maîtrise te dira si c\'est acquis.')) + '</p></div>' +
      (lv.kind === 'mastery' ? '<div class="banner good">Notions maîtrisées : ' + uniqueNotions(lv).map(function (n) { return esc(C.notion(n).label); }).join(', ') + '.</div>' : '') +
      (lv.kind === 'final' ? '<div class="banner good">Tu sais afficher, calculer, décider, répéter, ranger dans des tableaux, écrire des fonctions et des pages. Bravo.</div>' : '') +
      rewardsHtml(r.rewards) +
      (nl ? '<p class="hint-p">Ensuite : <b>' + (nl.kind === 'final' ? 'l\'épreuve finale' : 'niveau ' + nl.num + ', ' + esc(nl.title)) + '</b>.' + (masteryOpen().length ? ' Un défi de maîtrise t\'attend aussi sur la carte : il ne bloque rien.' : '') + '</p>' : '') +
      (open ? '<p class="hint-p">Une lecture éclair est ouverte : <b>' + esc(open.title) + '</b>, huit codes à lire en deux minutes, pour le réflexe.</p>' : '');
    setFoot([{ label: 'Carte des niveaux', fn: showMap }].concat(nl ? [{ label: nl.kind === 'final' ? 'Épreuve finale →' : 'Niveau ' + nl.num + ' →', cls: 'primary', fn: function () { openLevel(nl); } }] : []), null);
    sideAndNews();
    scroller.scrollTop = 0;
  }
  function masteryOpen() { return levels().filter(function (lv) { return lv.kind === 'mastery' && stateOf(lv) === 'open'; }); }
  function showDone(lv) {
    G.lv = lv; G.view = 'done';
    top.innerHTML = '<div class="row">' + icoBtn('back', ICON.back, 'Retour à la carte des niveaux') + '<div class="ttl"><small>Niveau ' + lv.num + ' · réussi</small><b>' + esc(lv.title) + '</b></div>' + sideButtons() + '</div>';
    bindTop();
    main.innerHTML = '<div class="lvdone"><span class="lvdone-n">' + lv.num + '</span><h3>Niveau réussi</h3><p>' + esc(lv.title) + '</p><span class="s">' + starLine(qualityOf(lv.id)) + '</span></div><div class="out-h">Les cours du niveau</div><div class="lessons-list">' + Object.keys(lv.lessons).map(function (k) { return '<button type="button" class="opt" data-lesson="' + esc(k) + '">' + esc(lv.lessons[k].title) + '</button>'; }).join('') + '</div>';
    $$('[data-lesson]', main).forEach(function (b) { b.addEventListener('click', function () { lessonScreen(lv.lessons[b.dataset.lesson], 'Retour', function () { showDone(lv); }); }); });
    setFoot([{ label: 'Carte des niveaux', cls: 'primary', fn: showMap }], null);
    sideAndNews();
  }
  function showChallengeDone(lv) {
    G.lv = lv; G.view = 'done';
    top.innerHTML = '<div class="row">' + icoBtn('back', ICON.back, 'Retour à la carte des niveaux') + '<div class="ttl"><small>' + (lv.kind === 'final' ? 'Épreuve finale · réussie' : 'Défi de maîtrise · réussi') + '</small><b>' + esc(lv.title) + '</b></div>' + sideButtons() + '</div>';
    bindTop();
    main.innerHTML = '<div class="lvdone"><span class="lvdone-n chal">' + (lv.kind === 'final' ? ICON.star : ICON.check) + '</span><h3>' + (lv.kind === 'final' ? 'Épreuve réussie' : 'Défi réussi') + '</h3><p>' + esc(lv.title) + '</p><span class="s">' + starLine(qualityOf(lv.id)) + '</span>' + (lv.kind === 'final' && G.data.best[lv.id] !== undefined ? '<p>Meilleur score : ' + G.data.best[lv.id] + '/' + lv.draw + '</p>' : '') + '</div>' +
      (lv.kind === 'mastery' ? '<div class="banner good">Notions maîtrisées : ' + uniqueNotions(lv).map(function (n) { return esc(C.notion(n).label); }).join(', ') + '.</div>' : '');
    setFoot([{ label: 'Carte des niveaux', cls: 'primary', fn: showMap }], null);
    sideAndNews();
  }

  // ——— A mastery challenge: programs drawn from its pool, untimed, without a hint ———
  function challengeTop(sub) {
    var lv = G.lv, r = G.run, timed = lv.kind === 'final' || lv.kind === 'flash';
    top.innerHTML = '<div class="row">' + icoBtn('back', ICON.back, r && !r.over ? 'Abandonner et revenir à la carte' : 'Retour à la carte des niveaux') + '<div class="ttl"><small>' + (lv.kind === 'final' ? 'Épreuve finale' : (lv.kind === 'flash' ? 'Lecture éclair' : 'Défi de maîtrise')) + '</small><b>' + esc(sub || lv.title) + '</b></div>' + (timed ? '<span class="timer" id="timer">' + fmt(r ? remaining() : limitOf(lv)) + '</span>' : '') + '</div>' +
      (timed ? '<div class="tbar" id="tbar"><span style="width:' + (r ? Math.max(0, remaining() / r.limit * 100) : 100) + '%"></span></div>' : '') +
      (r && !r.over ? '<div class="prog" style="grid-template-columns:repeat(' + r.qs.length + ',1fr)">' + r.qs.map(function (q, i) { return '<span class="seg k-chal' + (r.results[i] === true ? ' done' : '') + (i === r.i ? ' cur' : '') + '"><span></span></span>'; }).join('') + '</div>' : '');
    bindTop();
  }
  function showMasteryIntro() {
    var lv = G.lv;
    G.run = null; challengeTop();
    main.innerHTML = '<div class="kind k-chal"><span class="pill">Défi de maîtrise</span><h3>' + esc(lv.title) + '</h3></div><ul class="rules">' +
      '<li><b>' + lv.draw + ' programmes</b> à écrire seul, tirés au hasard parmi ' + lv.pool.length + ' : à chaque essai, d\'autres programmes.</li>' +
      '<li><b>Pas de chrono, pas d\'indice.</b> « Tester » et « Pas à pas » restent permis, et une erreur PHP reste expliquée.</li>' +
      '<li>Les ' + lv.draw + ' réussis : les notions des niveaux ' + coversText(lv).replace(/niveau /g, '') + ' passent à « maîtrisée ». Les essais faux comptent seulement dans les étoiles du défi.</li>' +
      '<li>Tu peux revenir à la carte quand tu veux : rien ne baisse, et tu recommenceras avec d\'autres programmes.</li></ul>';
    setFoot([{ label: 'Carte', fn: function () { showMap(); } }, { label: 'Commencer', cls: 'primary', fn: startMastery }], null);
    sideAndNews(); scroller.scrollTop = 0;
  }
  function startMastery() {
    var lv = G.lv;
    G.run = { qs: shuffle(lv.pool).slice(0, lv.draw), i: 0, results: [], errors: 0, over: false, mastery: true };
    showChallengeQuestion();
  }
  function showChallengeQuestion() {
    var r = G.run, q = r.qs[r.i], lv = G.lv, timed = lv.kind === 'final';
    lastField = null; challengeTop((timed ? 'Question ' : 'Programme ') + (r.i + 1) + ' sur ' + r.qs.length);
    main.innerHTML = ask('k-chal', (timed ? 'Question ' : 'Programme ') + (r.i + 1), esc(q.ask)) + expectHtml(q) + editorHtml(r.drafts && r.drafts[r.i] || '', q.vars, { html: q.html }) + '<div id="res" class="out"></div>';
    bindEditor(q.html, function (v) { r.drafts = r.drafts || {}; r.drafts[r.i] = v; });
    var code = function () { return $('#php', main).value; };
    var run = function () { var box = $('#res', main); box.innerHTML = consoleHtml(code(), q.vars, q.html); reveal(box); };
    var valid = function () {
      if (!G.run || G.run.over) return;
      var text = code(), box = $('#res', main);
      if (!text.trim()) return;
      var res = E.check(q, text);
      if (res.passed) { r.results[r.i] = true; SND.ok(); box.innerHTML = '<div class="flash ok">Juste, sur tous les cas.</div>'; setTimeout(advance, 600); return; }
      SND.ko();
      if (!res.deliverable) { box.innerHTML = (res.error ? errHtml(res.error) : '') + '<p class="hint-p">Ce programme ne s\'exécute pas : corrige-le, rien n\'est compté.</p>'; reveal(box); return; }
      r.errors++;
      var c = res.criteria.filter(function (x) { return !x.ok; })[0];
      box.innerHTML = '<div class="flash ko">' + (c ? c.detail : 'Pas encore.') + (c && c.ir ? '<span class="ir">' + c.ir + '</span>' : '') + '</div>';
      reveal(box);
    };
    var acts = [{ label: 'Pas à pas', fn: function () { openTrace(code(), q.vars, q.html); } }, { label: '▶ Tester', fn: run }, { label: timed ? 'Valider' : 'Vérifier', cls: 'primary', fn: valid }];
    if (timed) acts.unshift({ label: 'Passer', fn: function () { r.results[r.i] = false; advance(); } });
    setFoot(acts, chipList(q, null));
    bindRunKey(valid);
    scroller.scrollTop = 0;
  }
  function advance() {
    var r = G.run; if (!r || r.over) return;
    if (r.mastery) { r.i++; if (r.i >= r.qs.length) finishMastery(); else showChallengeQuestion(); return; }
    r.i++; if (r.i >= r.qs.length) finishFinal(); else showChallengeQuestion();
  }
  function finishMastery() {
    var r = G.run, stepId = G.ticket.steps[0].id;
    r.over = true;
    challengeTop();
    main.innerHTML = '<div class="score win"><span class="out-h">' + esc(G.lv.title) + '</span><b>' + r.qs.length + '/' + r.qs.length + '</b><span>' + (r.errors ? plural(r.errors, 'essai faux', 'essais faux') + ' en chemin' : 'sans un essai faux') + '</span></div><div id="res" class="out"></div>';
    setFoot([], null);
    // The wrong tries are told to the server as refused deliveries (three at most: the stars stop at one), then the win.
    var fails = Math.min(r.errors, 3), chain = Promise.resolve();
    for (var i = 0; i < fails; i++) chain = chain.then(function () { return api('submitStep', { session: G.session, step: stepId, input: { passed: false, failed: ['Défi de maîtrise : essai faux'] } }); });
    chain.then(function () { return deliver(stepId, { passed: true, failed: [] }, function () { endLevel(); }); }).catch(function (e) { toastText(e.message); });
  }

  // ——— The final test: timed, every chapter ———
  var timer = null;
  function fmt(sec) { var t = Math.max(0, Math.ceil(sec)); return Math.floor(t / 60) + ':' + String(t % 60).padStart(2, '0'); }
  function limitOf(lv) { var pct = G.user && typeof G.user.extraTimePercent === 'number' ? G.user.extraTimePercent : 0; return Math.round(lv.seconds * (1 + pct / 100)); }
  function remaining() { var r = G.run; return r ? r.limit - (Date.now() - r.start) / 1000 : 0; }
  function stopTimer() { clearInterval(timer); timer = null; }
  function tick() {
    var r = G.run;
    if (!r || r.over) return stopTimer();
    var left = remaining(), t = $('#timer'), bar = $('#tbar');
    if (t) { t.textContent = fmt(left); t.classList.toggle('hurry', left <= 30); }
    if (bar) { bar.firstElementChild.style.width = Math.max(0, left / r.limit * 100) + '%'; bar.classList.toggle('hurry', left <= 30); }
    if (left <= 0) (r.flash ? finishFlash : finishFinal)();
  }
  function showFinalIntro() {
    var lv = G.lv, limit = limitOf(lv), pct = G.user && G.user.extraTimePercent || 0;
    G.run = null; challengeTop();
    main.innerHTML = '<div class="kind k-fin"><span class="pill">Épreuve finale</span><h3>Tout le PHP du jeu</h3></div><ul class="rules">' +
      '<li><b>' + lv.draw + ' programmes</b> à écrire seul, de tous les chapitres, tirés au hasard : chaque essai pose d\'autres questions.</li>' +
      '<li><b>' + fmt(limit) + ' au total</b>' + (pct ? ', ton temps majoré de ' + pct + ' % compris' : '') + '. Le temps court dès le lancement.</li>' +
      '<li><b>Pas d\'indice.</b> « Tester » et « Pas à pas » restent permis. Tu peux passer une question.</li>' +
      '<li><b>' + FINAL_PASS + ' sur ' + lv.draw + '</b> : l\'épreuve est réussie et le prisme rejoint ta vitrine. Sinon, rien ne baisse : recommence quand tu veux.</li></ul>' +
      (G.data.best[lv.id] !== undefined ? '<p class="hint-p">Meilleur score : <b>' + G.data.best[lv.id] + '/' + lv.draw + '</b>.</p>' : '');
    setFoot([{ label: 'Carte', fn: function () { showMap(); } }, { label: '⏱ Lancer', cls: 'primary', fn: startFinal }], null);
    sideAndNews(); scroller.scrollTop = 0;
  }
  function startFinal() {
    var lv = G.lv, qs = [];
    Object.keys(lv.perChapter).forEach(function (ch) { qs = qs.concat(shuffle(lv.pool.filter(function (q) { return String(q.chapter) === ch; })).slice(0, lv.perChapter[ch])); });
    G.run = { qs: qs, i: 0, results: [], start: Date.now(), limit: limitOf(lv), over: false };
    stopTimer(); timer = setInterval(tick, 250);
    showChallengeQuestion();
  }
  function finishFinal() {
    var r = G.run, lv = G.lv;
    if (!r || r.over) return;
    stopTimer(); r.over = true; r.used = Math.min(r.limit, (Date.now() - r.start) / 1000);
    var ok = r.results.filter(function (x) { return x === true; }).length;
    G.data.best[lv.id] = Math.max(G.data.best[lv.id] || 0, ok); save();
    var win = ok >= FINAL_PASS, stepId = G.ticket.steps[0].id;
    challengeTop();
    main.innerHTML = '<div class="score' + (win ? ' win' : '') + '"><span class="out-h">' + esc(lv.title) + '</span><b>' + ok + '/' + r.qs.length + '</b><span>en ' + fmt(r.used) + (r.used >= r.limit ? ' · temps écoulé' : '') + '</span></div>' +
      '<ul class="recap">' + r.qs.map(function (q, i) { return '<li><span>' + esc(q.ask) + '</span><b>' + (r.results[i] ? '✓' : (i < r.results.length ? 'passée' : '—')) + '</b></li>'; }).join('') + '</ul><div id="res" class="out"></div>';
    setFoot([], null);
    deliver(stepId, { passed: win, failed: win ? [] : ['Épreuve finale : ' + ok + '/' + r.qs.length] }, function () { endLevel(); }).then(function (res) {
      if (!res || res.ok) return;
      $('#res', main).innerHTML = '<p class="hint-p">Il en fallait ' + FINAL_PASS + '. Rien ne baisse : ton meilleur score est <b>' + G.data.best[lv.id] + '/' + lv.draw + '</b>. Recommence quand tu veux, les questions changent.</p>';
      setFoot([{ label: 'Carte des niveaux', fn: function () { G.run = null; showMap(); } }, { label: 'Recommencer', cls: 'primary', fn: startFinal }], null);
    });
  }

  // ——— A « lecture éclair »: read and touch what the code prints. Never sent: only its best is kept ———
  function showFlashIntro(f) {
    if (!f || !flashOpen(f)) return;
    G.lv = f; G.view = 'flash'; G.run = null; stopTimer();
    var limit = limitOf(f), pct = G.user && G.user.extraTimePercent || 0, best = flashBest(f);
    challengeTop();
    main.innerHTML = '<div class="kind k-fla"><span class="pill">Lecture éclair</span><h3>' + esc(f.title) + '</h3></div><ul class="rules fla">' +
      '<li><b>' + f.draw + ' petits codes</b> à lire : touche ce qu\'ils affichent. Tirés au hasard parmi ' + f.pool.length + '.</li>' +
      '<li><b>' + fmt(limit) + '</b>' + (pct ? ', ton temps majoré de ' + pct + ' % compris' : '') + '. Le seul moment chronométré du jeu, et il ne fait rien écrire : lire une condition ou une boucle d\'un coup d\'œil, c\'est le réflexe qui libère la tête pour écrire.</li>' +
      '<li><b>7 sur 8</b> : l\'éclair ⚡ s\'ajoute aux notions dans ta vitrine.</li>' +
      '<li><b>Facultatif</b> : elle n\'ouvre et ne ferme rien, et ne compte pas dans les étoiles.</li></ul>' +
      (best !== null ? '<p class="hint-p">Ton meilleur score : <b>' + best + '/' + f.draw + '</b>.</p>' : '');
    setFoot([{ label: 'Carte', fn: showMap }, { label: '⚡ Lancer', cls: 'primary', fn: startFlash }], null);
    sideAndNews(); scroller.scrollTop = 0;
  }
  function startFlash() {
    var f = G.lv;
    G.run = { flash: true, qs: shuffle(f.pool).slice(0, f.draw).map(function (q) { return { code: q.code, vars: q.vars, opts: shuffle(q.options), out: E.norm(E.run(q.code, q.vars || {}).output, 'words') }; }), i: 0, results: [], start: Date.now(), limit: limitOf(f), over: false, lock: false };
    stopTimer(); timer = setInterval(tick, 250);
    showFlashQuestion();
  }
  function showFlashQuestion() {
    var r = G.run, q = r.qs[r.i];
    challengeTop('Code ' + (r.i + 1) + ' sur ' + r.qs.length);
    main.innerHTML = '<div class="kind k-fla"><span class="pill">Qu\'affiche ce code ?</span></div>' + codeHtml(q.code, q.vars) + '<div class="opts">' + q.opts.map(function (o) { return '<button type="button" class="opt mono" data-o="' + esc(o) + '">' + esc(o) + '</button>'; }).join('') + '</div>';
    $$('.opt', main).forEach(function (b) {
      b.addEventListener('click', function () {
        if (r.lock || r.over) return; r.lock = true;
        var right = E.norm(b.dataset.o, 'words') === q.out;
        r.results.push(right);
        b.classList.add(right ? 'right' : 'wrong');
        if (right) SND.ok(); else { SND.ko(); $$('.opt', main).forEach(function (x) { if (E.norm(x.dataset.o, 'words') === q.out) x.classList.add('right'); }); }
        setTimeout(function () { r.lock = false; if (r.over || G.run !== r) return; r.i++; if (r.i >= r.qs.length) finishFlash(); else showFlashQuestion(); }, right ? 450 : 1200);
      });
    });
    setFoot([], null);
    scroller.scrollTop = 0;
  }
  function finishFlash() {
    var r = G.run, f = G.lv;
    if (!r || r.over) return;
    stopTimer(); r.over = true; r.used = Math.min(r.limit, (Date.now() - r.start) / 1000);
    var ok = r.results.filter(Boolean).length, before = flashBest(f);
    G.data.flash[f.id] = Math.max(before || 0, ok); save(true);
    var win = ok >= 7;
    challengeTop();
    main.innerHTML = '<div class="score' + (win ? ' win' : '') + '"><span class="out-h">' + esc(f.title) + '</span><b>' + ok + '/' + r.qs.length + '</b><span>' + plural(r.results.length, 'code lu', 'codes lus') + ' en ' + fmt(r.used) + (r.used >= r.limit ? ' · temps écoulé' : '') + '</span></div>' +
      '<p class="hint-p">' + (win ? 'Lu en un éclair : ⚡ s\'ajoute aux notions de ces niveaux.' : 'Rien ne baisse. Les codes changent à chaque partie.') + ' Meilleur score : <b>' + G.data.flash[f.id] + '/' + f.draw + '</b>.</p>';
    if (win) SND.level();
    setFoot([{ label: 'Carte des niveaux', fn: showMap }, { label: 'Rejouer', cls: 'primary', fn: function () { startFlash(); } }], null);
    sideAndNews();
  }

  // ——— Start ———
  window.SGSdk.onReward(function (rewards) { G.rewards = G.rewards.concat(rewards); });
  Promise.all([api('getUser'), api('getProgress'), refresh()]).then(function (res) {
    G.user = res[0]; G.prog = res[1];
    var d = G.prog && G.prog.data;
    G.data = d && typeof d === 'object' && !Array.isArray(d) ? d : {};
    ['seen', 'nseen', 'drafts', 'hints', 'best', 'flash', 'miss', 'recall'].forEach(function (k) { if (!G.data[k] || typeof G.data[k] !== 'object' || Array.isArray(G.data[k])) G.data[k] = {}; });
    if (!Array.isArray(G.data.recallUsed)) G.data.recallUsed = [];
    SND.setMuted(!!G.data.mute);
    return E.ready();
  }).then(function () {
    snapshotNotions();
    var go = /open=([A-Z0-9]+)/.exec(location.hash); // a shortcut for the tests and the screenshots
    showMap();
    if (go && C.get(go[1])) { var t = C.get(go[1]); if (t.kind === 'flash') showFlashIntro(t); else openLevel(t); }
  }).catch(function (e) { app.textContent = 'Erreur de chargement : ' + e.message; });
})();
