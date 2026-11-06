/* SQL pas à pas — the game's screens (design/validated/serious-games-sql-pas-a-pas.md): the map of the
   levels, a level's lessons and steps, the timed challenges and the final test, the end of a level and
   its rewards, the showcase of the notions and the map of the base.

   The platform is reached through the SDK only (sdk.js). A measured step sends {passed, failed} - the
   labels of the failed criteria - and never the query; « Tester » runs the query on the visible base
   and costs nothing, only « Vérifier » counts. A query that does not even run is not a delivery: its
   error is explained and nothing is sent. The display state (saveProgress) keeps the lessons seen, the
   drafts, the hints shown and the best score of each challenge. */
(function () {
  'use strict';
  var C = window.SPPContent, E = window.SPPEngine, B = window.SPPBase, SND = window.SPPSound;
  var api = function (m, p) { return window.SGSdk.call(m, p); };
  var app = document.getElementById('app');

  var G = { user: null, prog: null, sc: null, data: {}, cards: {}, view: 'map', lv: null, ticket: null, session: null, step: 0, hints: 0, lesson: null, run: null, rewards: [], opened: {}, busy: false };

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

  var ICON = {
    map: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><rect x="2" y="3" width="7" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="11" y="11" width="7" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="11" y="3" width="7" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="1.6" stroke-dasharray="2 2"/><path d="M9 6h2M14.5 9v2" stroke="currentColor" stroke-width="1.6"/></svg>',
    vit: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><rect x="3" y="2.5" width="14" height="15" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M3 10h14" stroke="currentColor" stroke-width="1.6"/><circle cx="7" cy="6.5" r="1.6" fill="currentColor"/><circle cx="13" cy="14" r="1.6" fill="currentColor"/></svg>',
    back: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M12.5 4 6.5 10l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    book: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M3 4.5Q6.5 3 10 5q3.5-2 7-.5V16q-3.5-1.5-7 .5-3.5-2-7-.5Z M10 5v11.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
    sound: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M3 8h3l4-3v10l-4-3H3Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13 7.5q1.5 2.5 0 5M15.5 5.5q3 4.5 0 9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
    mute: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M3 8h3l4-3v10l-4-3H3Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13 8l4 4M17 8l-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
    lock: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><rect x="4" y="9" width="12" height="8" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>',
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
  function chapterOf(n) { return C.chapters.filter(function (c) { return c.n === n; })[0]; }
  function nextOpen() { return levels().filter(function (lv) { return stateOf(lv) === 'open' && lv.kind === 'level'; })[0] || levels().filter(function (lv) { return stateOf(lv) === 'open' && lv.kind === 'final'; })[0] || null; }
  function reachedChapter() { var max = 1; levels().forEach(function (lv) { if (stateOf(lv) !== 'locked') max = Math.max(max, lv.chapter); }); return max; }
  function totalStars() { var s = 0; Object.keys(G.cards).forEach(function (id) { s += qualityOf(id); }); return s; }

  // ——— Notions: vue (a lesson opened), pratiquée (a level done), maîtrisée (its challenge won) ———
  function notionState(id) {
    var mastered = false, practiced = false;
    levels().forEach(function (lv) {
      if (lv.kind === 'level' && (lv.notions || []).indexOf(id) !== -1 && closed(lv.id)) practiced = true;
      if (lv.kind === 'chrono' && closed(lv.id) && (lv.covers || []).some(function (lid) { var l = C.get(lid); return l && (l.notions || []).indexOf(id) !== -1; })) mastered = true;
    });
    if (mastered) return 'maitrisee';
    if (practiced) return 'pratiquee';
    return G.data.nseen[id] ? 'vue' : '';
  }
  var STATE_LABEL = { '': 'à découvrir', vue: 'vue', pratiquee: 'pratiquée', maitrisee: 'maîtrisée' };
  var lastNotion = {};
  function snapshotNotions() { C.notions.forEach(function (n) { lastNotion[n.id] = notionState(n.id); }); }
  function seenNotions() { var o = {}; C.notions.forEach(function (n) { if (notionState(n.id)) o[n.id] = 1; }); return o; }

  // ——— Frame ———
  app.innerHTML = '<div class="app"><header class="top" id="top"></header><div class="scroll sky" id="scroll"><div class="body"><aside class="side" id="side"></aside><main class="main" id="main"></main></div></div><footer class="foot" id="foot"></footer><div class="toast" id="toast" role="status" aria-live="polite"></div>' +
    '<div class="sheet" id="sheet" aria-hidden="true"><div class="sheet-back" data-close></div><div class="sheet-panel" role="dialog" aria-modal="true" aria-labelledby="sheet-title"><div class="sheet-handle"></div><div class="sheet-head"><h4 id="sheet-title"></h4><button class="ico dark" type="button" data-close aria-label="Fermer">✕</button></div><div id="sheet-body"></div></div></div></div>';
  var top = $('#top'), main = $('#main'), foot = $('#foot'), scroller = $('#scroll'), side = $('#side'), sheet = $('#sheet');
  var sheetKind = null;
  function openSheet(kind) {
    sheetKind = kind;
    $('#sheet-title').textContent = kind === 'base' ? 'Carte de la base' : (kind === 'vit' ? 'Vitrine des notions' : 'Les cours du niveau');
    var body = $('#sheet-body');
    if (kind === 'base') body.innerHTML = baseHtml() + '<p class="note">Les tables grisées apparaîtront au chapitre 5, « Relier les tables ».</p>';
    else if (kind === 'vit') body.innerHTML = '<div class="vit">' + vitHtml() + '</div><p class="note">Vue : cours ouvert. Pratiquée : niveau réussi. Maîtrisée : défi chrono réussi.</p>';
    else body.innerHTML = lessonsListHtml();
    bindBase(body);
    $$('[data-lesson]', body).forEach(function (b) { b.addEventListener('click', function () { closeSheet(); showLesson(b.dataset.lesson, true); }); });
    sheet.classList.add('open'); sheet.setAttribute('aria-hidden', 'false');
    if (kind === 'vit') { var d = $('#open-vit .dot'); if (d) d.hidden = true; }
    $('.ico[data-close]', sheet).focus();
  }
  function closeSheet() { sheet.classList.remove('open'); sheet.setAttribute('aria-hidden', 'true'); sheetKind = null; }
  $$('[data-close]', sheet).forEach(function (e) { e.addEventListener('click', closeSheet); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheetKind) closeSheet(); });

  function baseHtml() {
    var reached = reachedChapter();
    return '<div class="schema">' + B.TABLES.map(function (t) {
      var fog = t.from > reached;
      return '<div class="tbl' + (fog ? ' fog' : '') + '"><div class="tbl-h"><span>' + (fog ? '???' : esc(t.name)) + '</span><small>' + (fog ? 'chapitre ' + t.from : t.columns.length + ' colonnes') + '</small></div>' +
        (fog ? '' : '<ul>' + t.columns.map(function (c) { var k = t.keys[c]; return '<li>' + (k === 'pk' ? '<span class="k" title="clé primaire">🔑</span>' : (k === 'fk' ? '<span class="f" title="clé étrangère">→</span>' : '')) + esc(c) + '</li>'; }).join('') + '</ul><button type="button" class="btn small" data-peek="' + esc(t.name) + '">Voir 5 lignes</button><div class="peek"></div>') + '</div>';
    }).join('') + '</div>';
  }
  function bindBase(root) {
    $$('[data-peek]', root).forEach(function (b) {
      b.addEventListener('click', function () {
        var r = E.result('SELECT * FROM ' + b.dataset.peek + ' LIMIT 5'), box = b.nextElementSibling;
        box.innerHTML = r.select ? grid(r.select.columns, r.select.rows) : '';
        b.remove();
      });
    });
  }
  function vitHtml(pulse) {
    var reached = reachedChapter();
    return C.chapters.filter(function (ch) { return ch.n <= Math.max(reached, 1); }).map(function (ch) {
      return '<p class="vit-ch">' + esc(ch.title) + '</p>' + C.notions.filter(function (n) { return n.ch === ch.n; }).map(function (n) {
        var st = notionState(n.id);
        return '<div class="vit-item ' + (pulse && pulse[n.id] ? 'pulse' : '') + '" data-s="' + st + '"><span class="glyph">' + esc(n.g) + '</span><span><span class="lab">' + esc(n.label) + '</span><span class="st">' + STATE_LABEL[st] + '</span></span></div>';
      }).join('');
    }).join('');
  }
  function lessonsListHtml() {
    var lv = G.lv;
    if (!lv || !lv.lessons) return '<p class="note">Aucun cours ici.</p>';
    return '<div class="lessons-list">' + Object.keys(lv.lessons).map(function (k) { return '<button type="button" class="opt" data-lesson="' + esc(k) + '">' + esc(lv.lessons[k].title) + '</button>'; }).join('') + '</div>';
  }
  var toastTimer = null;
  function sideAndNews() {
    var changed = C.notions.filter(function (n) { return lastNotion[n.id] !== undefined && lastNotion[n.id] !== notionState(n.id); }), pulse = {};
    changed.forEach(function (n) { pulse[n.id] = 1; });
    snapshotNotions();
    side.innerHTML = '<div><h4>Vitrine des notions</h4><div class="vit">' + vitHtml(pulse) + '</div></div><div><h4>Carte de la base</h4>' + baseHtml() + '</div>';
    bindBase(side);
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
  function icoBtn(id, svg, label, extra, cls) { return '<button class="ico ' + (cls || '') + '" id="' + id + '" type="button" aria-label="' + label + '" title="' + label + '">' + svg + (extra || '') + '</button>'; }
  function sideButtons() { return icoBtn('open-base', ICON.map, 'Carte de la base', '', 'sidebar-only') + icoBtn('open-vit', ICON.vit, 'Vitrine des notions', '<span class="dot" hidden></span>', 'sidebar-only'); }
  function bindTop() {
    var b;
    if ((b = $('#back'))) b.addEventListener('click', function () { stopTimer(); G.run = null; showMap(); });
    if ((b = $('#open-base'))) b.addEventListener('click', function () { openSheet('base'); });
    if ((b = $('#open-vit'))) b.addEventListener('click', function () { openSheet('vit'); });
    if ((b = $('#open-lessons'))) b.addEventListener('click', function () { openSheet('lessons'); });
    if ((b = $('#mute'))) b.addEventListener('click', function () { G.data.mute = !G.data.mute; SND.setMuted(G.data.mute); save(); b.innerHTML = G.data.mute ? ICON.mute : ICON.sound; b.setAttribute('aria-label', G.data.mute ? 'Activer le son' : 'Couper le son'); });
  }

  // ——— Rendering helpers ———
  function grid(columns, rows, cls) {
    var cl = cls || [];
    return '<div class="grid-wrap"><table class="g"><thead><tr>' + columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>' +
      rows.map(function (r, i) { return '<tr class="' + (cl[i] || '') + '">' + r.map(function (v) { return v === null ? '<td class="null">NULL</td>' : '<td>' + esc(typeof v === 'number' ? E.norm(v) : v) + '</td>'; }).join('') + '</tr>'; }).join('') +
      (rows.length ? '' : '<tr><td colspan="' + columns.length + '" class="null">(aucune ligne)</td></tr>') + '</tbody></table></div>';
  }
  function errBox(err) { return '<div class="err">' + (err.mysql ? '<div class="raw">' + esc(err.mysql) + '</div>' : '') + '<div class="fr"><span class="ex">Explication</span><span>' + err.fr + '</span></div></div>'; }
  function ask(kind, label, text) { return '<div class="ask ' + kind + '"><span class="ask-k">' + label + '</span><p>' + text + '</p></div>'; }
  function reveal(el) {
    if (!el) return;
    setTimeout(function () { scroller.scrollTo({ top: scroller.scrollTop + el.getBoundingClientRect().top - scroller.getBoundingClientRect().top - 10, behavior: 'smooth' }); }, 30);
  }
  function editorHtml(value, note) { return '<div class="ed"><div class="ed-bar"><span class="db">collection</span>' + (note ? ' · ' + note : '') + '</div><textarea id="sql" rows="4" spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" aria-label="Requête SQL" placeholder="Tape, ou assemble avec les mots du bas">' + esc(value || '') + '</textarea></div>'; }
  function runHtml(text, tables) {
    var r = E.result(text, tables);
    if (r.error) return errBox(r.error);
    if (r.select) return '<div class="out-h">Résultat <b>' + plural(r.select.rows.length, 'ligne', 'lignes') + '</b></div>' + grid(r.select.columns, r.select.rows);
    if (r.after) return '<div class="out-h">Requête exécutée <b>' + plural(r.changes, 'ligne modifiée', 'lignes modifiées') + (r.rolledBack ? ' · transaction annulée' : '') + '</b></div>' + Object.keys(r.after).map(function (t) { var a = r.after[t]; return a ? '<div class="out-h">Table ' + esc(t) + ' après la requête</div>' + grid(a.columns, a.rows) : ''; }).join('');
    return '<div class="banner info">Requête exécutée sans erreur.' + (r.tables ? ' Tables de la base : ' + r.tables.map(esc).join(', ') + '.' : '') + '</div>';
  }
  function tablesOf(def) {
    var src = (def.ref || '') + ' ' + (def.template || ''), out = [];
    ['specimen', 'salle', 'institution', 'pret'].forEach(function (t) { if (new RegExp('\\b' + t + '\\b', 'i').test(src)) out.push(t); });
    return out.length ? out : ['specimen'];
  }

  // ——— The bar of words ———
  var lastField = null;
  main.addEventListener('focusin', function (e) { if (e.target.matches('textarea, .blank')) lastField = e.target; });
  function chipList(def, lv) {
    var seen = seenNotions(), words = C.chipsFor(seen, lv && lv.notions);
    var out = words.map(function (w) { return [/^[A-Z][A-Z_ ]*\(?\)?$|^\(SELECT$|^COUNT\(\*\)$|^CURDATE\(\)$/.test(w) ? 'kw' : 'sym', w]; });
    var tables = tablesOf(def || {});
    tables.forEach(function (t) { out.push(['tb', t]); });
    tables.forEach(function (t) { var tb = B.TABLES.filter(function (x) { return x.name === t; })[0]; if (tb) tb.columns.forEach(function (c) { out.push(['col', c]); }); });
    (def && def.expect || []).forEach(function (e) { out.push(['tb', e.table]); e.columns.forEach(function (c) { out.push(['col', c.name]); }); });
    if (!words.length) out = out.concat([['sym', '*']]);
    out.push(['sym', ';']);
    var have = {};
    return out.filter(function (x) { var k = x[0] + x[1]; if (have[k]) return false; have[k] = 1; return true; });
  }
  function insertToken(tok) {
    var f = (lastField && main.contains(lastField)) ? lastField : $('textarea, .blank', main);
    if (!f) return;
    if (f.classList.contains('blank')) {
      f.value = tok === '⌫' ? '' : tok.replace('…', '');
      if (tok !== '⌫') { var bl = $$('.blank', main); lastField = bl[bl.indexOf(f) + 1] || f; }
      markBlanks(); f.dispatchEvent(new Event('input'));
      return;
    }
    var v = f.value, a = f.selectionStart == null ? v.length : f.selectionStart, b = f.selectionEnd == null ? v.length : f.selectionEnd, left = v.slice(0, a), pos;
    if (tok === '⌫') {
      left = left.replace(/\s*('[^']*'?|\w+\(?|<>|<=|>=|\S)\s*$/, '');
      f.value = left + v.slice(b).replace(/^\s+/, left ? ' ' : '');
      pos = left.length;
    } else {
      var t = tok.replace('…', ''), back = tok.indexOf('…') !== -1 ? tok.length - tok.indexOf('…') - 1 : 0;
      var tight = t === ',' || t === ';' || t === ')';
      var base = tight ? left.replace(/\s+$/, '') : left;
      var glue = /\($/.test(t) || back > 0 || t === '(';
      var ins = (base && !/[\s(]$/.test(base) && !tight ? ' ' : '') + t + (glue ? '' : ' ');
      f.value = base + ins + v.slice(b);
      pos = base.length + ins.length - back;
    }
    f.setSelectionRange(pos, pos);
    lastField = f;
    f.dispatchEvent(new Event('input'));
  }
  function markBlanks() { $$('.blank', main).forEach(function (b) { b.classList.toggle('target', b === lastField); }); }
  function setFoot(actions, chips) {
    foot.innerHTML = (chips ? '<div class="chips" role="toolbar" aria-label="Mots SQL à insérer">' + chips.map(function (c) { return '<button type="button" class="chip ' + c[0] + '" data-t="' + esc(c[1]) + '">' + esc(c[1]) + '</button>'; }).join('') + '<button type="button" class="chip del" data-t="⌫" aria-label="Effacer le dernier mot">⌫</button></div>' : '') +
      (actions.length ? '<div class="acts">' + actions.map(function (a, i) { return '<button type="button" class="btn ' + (a.cls || '') + '" data-a="' + i + '"' + (a.disabled ? ' disabled' : '') + '>' + a.label + '</button>'; }).join('') + '</div>' : '');
    $$('.chip', foot).forEach(function (c) {
      c.addEventListener('pointerdown', function (e) { e.preventDefault(); }); // keep the caret, and the soft keyboard, where they are
      c.addEventListener('click', function () { SND.click(); insertToken(c.dataset.t); });
    });
    $$('[data-a]', foot).forEach(function (b) { b.addEventListener('click', function () { if (!G.busy) actions[+b.dataset.a].fn(); }); });
  }
  function bindRunKey(fn) { $$('textarea', main).forEach(function (t) { t.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); fn(); } }); }); }

  // ——— The map of the levels ———
  function showMap() {
    G.view = 'map'; G.lv = null; G.ticket = null; G.session = null;
    var done = levels().filter(function (lv) { return lv.kind === 'level' && closed(lv.id); }).length, total = levels().filter(function (lv) { return lv.kind === 'level' && lv.chapter <= 7; }).length;
    top.innerHTML = '<div class="row"><div class="brand">SQL pas à pas<small>' + esc(G.prog && G.prog.grade || '') + '</small></div><span class="stars" title="Étoiles">★ ' + totalStars() + '</span>' + sideButtons() + icoBtn('mute', G.data.mute ? ICON.mute : ICON.sound, G.data.mute ? 'Activer le son' : 'Couper le son') + '</div>' +
      '<div class="where"><b>' + done + '/' + total + '</b> niveaux · ' + C.notions.filter(function (n) { return notionState(n.id) === 'maitrisee'; }).length + ' notions maîtrisées</div>';
    bindTop();
    var nl = nextOpen(), cur = nl ? nl.chapter : reachedChapter();
    var html = '<div class="lvmap">';
    if (!G.data.intro) html += '<div class="hello"><h2>Bienvenue' + (G.user && G.user.firstName ? ', ' + esc(G.user.firstName) : '') + '</h2><p>Chaque niveau t\'apprend une notion de SQL : un cours court, puis des défis de plus en plus libres, jusqu\'à écrire tes propres requêtes. Les défis chrono, en fin de notion, la déclarent maîtrisée. Rien ne casse : chaque requête part d\'une base neuve.</p></div>';
    if (closed('FIN')) html += '<div class="trophy-band">' + (window.SGRewards ? window.SGRewards.art('trophy', 'globe-celeste', { size: 72, finish: finishOf('globe-celeste') }) : '') + '<div><h3>L\'épreuve finale est réussie</h3><p>Le globe céleste est dans ta vitrine, et le chapitre bonus est ouvert.</p></div></div>';
    C.chapters.forEach(function (ch) {
      var items = levels().filter(function (lv) { return lv.chapter === ch.n; });
      var anyOpen = items.some(function (lv) { return stateOf(lv) !== 'locked'; });
      if (!anyOpen) {
        var lvls = items.filter(function (i) { return i.kind === 'level'; });
        html += '<div class="teaser"><span class="chap-k">' + (ch.bonus ? 'Bonus' : 'Chapitre ' + ch.n) + ' · ' + ICON.lock + '</span><b>' + esc(ch.title) + '</b><span class="lv-sub">' + lvls.length + ' niveaux (' + lvls[0].num + ' à ' + lvls[lvls.length - 1].num + ')' + (items.length > lvls.length ? ' · ' + (items.length - lvls.length) + ' défi' + (items.length - lvls.length > 1 ? 's' : '') + ' chrono' : '') + '</span></div>';
        return;
      }
      var doneN = items.filter(function (lv) { return closed(lv.id); }).length;
      var expanded = G.opened[ch.n] !== undefined ? G.opened[ch.n] : ch.n === cur;
      html += '<section class="chap"><button type="button" class="chap-h" data-chap="' + ch.n + '" aria-expanded="' + expanded + '" style="appearance:none;background:none;border:0;padding:0;text-align:left;color:inherit;cursor:pointer"><span class="chap-k">' + (ch.bonus ? 'Bonus' : 'Chapitre ' + ch.n) + ' · ' + doneN + '/' + items.length + '</span><h3>' + esc(ch.title) + '</h3><div class="chap-bar"><span style="width:' + Math.round(doneN / items.length * 100) + '%"></span></div></button>';
      if (expanded) html += '<ol class="path">' + items.map(function (lv) { return levelRow(lv, nl); }).join('') + '</ol>';
      html += '</section>';
    });
    html += '<button type="button" class="btn ghost small" id="restart">Recommencer le jeu depuis le début</button></div>';
    main.innerHTML = html;
    $$('[data-chap]', main).forEach(function (b) { b.addEventListener('click', function () { var n = +b.dataset.chap; G.opened[n] = b.getAttribute('aria-expanded') !== 'true'; showMap(); }); });
    $$('.lv[data-lv]', main).forEach(function (b) { b.addEventListener('click', function () { openLevel(C.get(b.dataset.lv)); }); });
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
  function levelRow(lv, nl) {
    var st = stateOf(lv), isCur = nl && nl.id === lv.id, chrono = lv.kind !== 'level';
    var cls = 'lv ' + (chrono ? 'chrono ' : '') + (lv.kind === 'final' ? 'final ' : '') + (st === 'locked' ? 'locked' : (st === 'done' ? 'done' : (isCur ? 'cur' : 'open')));
    var node = st === 'locked' ? ICON.lock : (lv.kind === 'final' ? ICON.star : (chrono ? ICON.clock : lv.num));
    var k = lv.kind === 'final' ? 'Épreuve finale' : (chrono ? 'Défi chrono · ' + lv.covers.map(function (id) { return 'niveau ' + C.get(id).num; }).join(', ').replace(/, ([^,]*)$/, ' et $1') : 'Niveau ' + lv.num);
    var sub = lv.kind === 'final' ? '12 requêtes · 30 min · tous les chapitres' : (chrono ? lv.draw + ' requêtes · ' + Math.round(lv.seconds / 60 * 10) / 10 + ' min · sans indice' : lv.summary);
    var meta = '';
    if (st === 'done') meta = '<span class="st">' + starLine(qualityOf(lv.id)) + '</span>' + (chrono ? ' · maîtrisé' : '');
    else if (chrono && G.data.best[lv.id] !== undefined) meta = 'Meilleur : ' + G.data.best[lv.id] + '/' + lv.draw + (lv.kind === 'chrono' ? ' · ne bloque pas la suite' : '');
    else if (chrono && st === 'open') meta = lv.kind === 'chrono' ? 'Non tenté · ne bloque pas la suite' : 'Ouverte';
    else if (st === 'open' && G.cards[lv.id] && G.cards[lv.id].status === 'doing') meta = 'Étape ' + (G.cards[lv.id].step + 1) + ' sur ' + G.cards[lv.id].steps;
    else if (st === 'open') meta = Object.keys(lv.steps).length + ' étapes · 15 min environ';
    return '<li><button type="button" class="' + cls + '" data-lv="' + lv.id + '"' + (st === 'locked' ? ' disabled aria-disabled="true"' : '') + '><span class="lv-node">' + node + '</span><span class="lv-text"><span class="lv-k">' + esc(k) + '</span><b>' + esc(lv.kind === 'level' ? lv.title : lv.title) + '</b><span class="lv-sub">' + esc(sub) + '</span>' + (meta ? '<span class="lv-meta">' + meta + '</span>' : '') + '</span></button></li>';
  }

  // ——— A level ———
  function openLevel(lv) {
    if (!lv || stateOf(lv) === 'locked' || G.busy) return;
    if (!G.data.intro) { G.data.intro = true; save(); }
    if (stateOf(lv) === 'done') return lv.kind === 'level' ? showDone(lv) : showChronoDone(lv);
    G.busy = true;
    api('startTicket', { ticket: lv.id }).then(function (r) {
      G.busy = false;
      G.lv = lv; G.ticket = r.ticket; G.session = r.session; G.step = r.step; G.hints = r.hints || 0; G.run = null;
      if (lv.kind !== 'level') { G.view = 'chrono'; return showChronoIntro(); }
      G.view = 'level';
      if (G.step >= G.ticket.steps.length) return endLevel();
      showStep();
    }).catch(function (e) { G.busy = false; toastText('Impossible d\'ouvrir ce niveau : ' + e.message); });
  }
  function toastText(text) { var t = $('#toast'); t.textContent = text; t.style.bottom = (foot.offsetHeight + 10) + 'px'; t.classList.add('show'); clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.classList.remove('show'); }, 3200); }
  function kindClass(def, server) {
    if (!def || server.kind === 'explain' || server.kind === 'choice') return 'k-qcm';
    return { pick: 'k-pred', value: 'k-pred', complete: 'k-comp', repair: 'k-rep', write: 'k-ecr' }[def.kind] || 'k-comp';
  }
  var KIND_LABEL = { pick: 'Prédire', value: 'Prédire', complete: 'Compléter', repair: 'Réparer', write: 'Écrire' };
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
    var label = s.kind === 'explain' || s.kind === 'choice' ? 'Comprendre' : (KIND_LABEL[def.kind] || 'Défi');
    levelTop(label);
    if (s.kind === 'explain' || s.kind === 'choice') renderQuestion(s);
    else if (def.kind === 'pick') renderPick(s, def);
    else if (def.kind === 'value') renderValue(s, def);
    else renderQuery(s, def);
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
    main.innerHTML = '<div class="kind k-cap"><span class="pill">Cours</span><h3>' + esc(l.title) + '</h3></div><div class="lesson">' + l.html + '</div>' +
      (l.example ? editorHtml(l.example, 'exemple à modifier, rien n\'est noté') + (l.tryit ? '<p class="try">' + esc(l.tryit) + '</p>' : '') + '<div id="res" class="out"></div>' : '');
    var run = function (scroll) { var res = $('#res', main); res.innerHTML = runHtml($('#sql', main).value); if (scroll) reveal(res); };
    if (l.example) { run(false); bindRunKey(function () { run(true); }); }
    var go = function () { if (review) showStep(); else showStep(); };
    setFoot([].concat(l.example ? [{ label: '▶ Tester', fn: function () { run(true); } }] : [], [{ label: review ? 'Retour au défi' : 'Au défi →', cls: 'primary', fn: go }]), l.example ? chipList({ ref: l.example }, lv) : null);
    sideAndNews();
    scroller.scrollTop = 0;
  }

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
  function stepDone(r) {
    G.step = r.next;
    if (r.done) return setFoot([{ label: 'Terminer le niveau →', cls: 'primary', fn: endLevel }], null);
    setFoot([{ label: 'Étape suivante →', cls: 'primary', fn: showStep }], null);
  }

  // The written hints of a measured step, one rung at a time; each one asked counts in the stars.
  function hintsShown(sid) { var t = G.data.hints[G.lv.id] || {}; return t[sid] || 0; }
  function helpHtml(def, sid) {
    var n = hintsShown(sid);
    if (!n) return '';
    // The first rung says how to go about it, the next ones show the shape of the query.
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
  // A measured step's delivery: the labels of the failed criteria, nothing of the answer.
  function deliver(sid, result, onDone) {
    G.busy = true;
    return api('submitStep', { session: G.session, step: sid, input: { passed: !!result.passed, failed: result.failed || [] } }).then(function (r) {
      G.busy = false;
      if (r.ok) { SND.ok(); onDone(r); } else SND.ko();
      return r;
    }).catch(function (e) { G.busy = false; toastText(e.message); });
  }
  function draftOf(sid) { var d = G.data.drafts[G.lv.id]; return d ? d[sid] : undefined; }
  function setDraft(sid, v) { G.data.drafts[G.lv.id] = G.data.drafts[G.lv.id] || {}; G.data.drafts[G.lv.id][sid] = v; save(); }

  function specimenCards(show, pickable) {
    var cols = B.TABLES[0].columns;
    return '<div class="cards">' + B.SPECIMENS.visible.map(function (r) {
      var extra = (show || []).map(function (c) { var v = r[cols.indexOf(c)]; return c === 'classe' ? esc(v) : esc(c) + ' ' + (v === null ? 'NULL' : esc(v)); }).join(' · ');
      return '<' + (pickable ? 'button type="button" aria-pressed="false"' : 'div') + ' class="fiche' + (pickable ? '' : ' ro') + '" data-id="' + r[0] + '"><span class="n">' + esc(r[2]) + '</span><span class="y">' + extra + '</span></' + (pickable ? 'button' : 'div') + '>';
    }).join('') + '</div>';
  }
  function renderPick(s, def) {
    var rerender = function () { renderPick(s, def); };
    main.innerHTML = '<div class="kind k-pred"><span class="pill">Prédire</span><h3>Avant d\'exécuter</h3></div>' + ask('k-pred', 'Consigne', esc(def.ask)) +
      '<div class="ed"><div class="ed-bar"><span class="db">collection</span> · à lire, pas à exécuter</div><div class="ro">' + esc(def.query) + '</div></div>' +
      '<div id="help">' + helpHtml(def, s.id) + '</div><div class="out-h">Les spécimens <b>touche ceux qui sortiront</b></div>' + specimenCards(def.show, true) + '<div id="res" class="out"></div>';
    var cards = $$('.fiche', main), checked = false;
    var picked = function () { return cards.filter(function (c) { return c.getAttribute('aria-pressed') === 'true'; }).map(function (c) { return +c.dataset.id; }); };
    var footer = function () { setFoot([hintAction(def, s.id, rerender), { label: 'Vérifier · ' + picked().length, cls: 'primary', fn: check }], null); };
    cards.forEach(function (c) {
      c.addEventListener('click', function () {
        if (checked) { checked = false; cards.forEach(function (x) { x.classList.remove('r-ok', 'r-extra', 'r-miss'); var t = $('.tag', x); if (t) t.remove(); }); }
        c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
        SND.click(); footer();
      });
    });
    var check = function () {
      var res = E.check({ check: 'pick', pick: def.pick }, picked()), ids = {};
      res.shown.ids.forEach(function (id) { ids[id] = 1; });
      checked = true;
      cards.forEach(function (c) {
        var id = +c.dataset.id, sel = c.getAttribute('aria-pressed') === 'true';
        if (sel && ids[id]) c.classList.add('r-ok');
        else if (sel) { c.classList.add('r-extra'); c.insertAdjacentHTML('beforeend', '<span class="tag">en trop</span>'); }
        else if (ids[id]) { c.classList.add('r-miss'); c.insertAdjacentHTML('beforeend', '<span class="tag">oubliée</span>'); }
      });
      var box = $('#res', main);
      deliver(s.id, res, function (r) {
        var real = E.result(def.query);
        box.innerHTML = '<div class="banner good">Exactement les ' + plural(res.shown.ids.length, 'ligne', 'lignes') + '.</div>' + (real.select ? '<div class="out-h">Ce que renvoie la requête</div>' + grid(real.select.columns, real.select.rows) : '');
        cards.forEach(function (c) { c.disabled = true; });
        stepDone(r);
      }).then(function (r) { if (r && !r.ok) box.innerHTML = '<div class="banner bad">' + res.criteria[0].detail + ' Touche pour corriger, puis vérifie de nouveau.</div>'; reveal(box); });
    };
    footer();
  }
  function renderValue(s, def) {
    var rerender = function () { var v = $('.val', main) ? $('.val', main).value : ''; renderValue(s, def); $('.val', main).value = v; };
    main.innerHTML = '<div class="kind k-pred"><span class="pill">Prédire</span><h3>Avant d\'exécuter</h3></div>' + ask('k-pred', 'Consigne', esc(def.ask)) +
      '<div class="ed"><div class="ed-bar"><span class="db">collection</span> · à lire, pas à exécuter</div><div class="ro">' + esc(def.query) + '</div></div>' +
      '<div id="help">' + helpHtml(def, s.id) + '</div>' + (def.show ? '<div class="out-h">Les spécimens</div>' + specimenCards(def.show, false) : '') +
      '<label class="out-h" for="val">Ta réponse</label><input id="val" class="val" type="text" inputmode="decimal" autocomplete="off" autocapitalize="off" spellcheck="false"><div id="res" class="out"></div>';
    var check = function () {
      var input = $('#val', main).value, box = $('#res', main);
      if (!input.trim()) { box.innerHTML = '<div class="banner info">Écris ta réponse, un nombre ou un mot.</div>'; return; }
      var res = E.check({ check: 'value', value: def.value }, input);
      deliver(s.id, res, function (r) { box.innerHTML = '<div class="banner good">' + res.criteria[0].detail + '</div>'; $('#val', main).disabled = true; stepDone(r); })
        .then(function (r) { if (r && !r.ok) box.innerHTML = '<div class="banner bad">' + res.criteria[0].detail + '</div>'; reveal(box); });
    };
    $('#val', main).addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); check(); } });
    setFoot([hintAction(def, s.id, rerender), { label: 'Vérifier', cls: 'primary', fn: check }], null);
  }

  // Complete, repair, write: a query measured on the visible base and on the hidden one.
  function targetHtml(def) {
    if (def.check === 'create') return '';
    if (def.check === 'modify') {
      if (def.kind === 'write' && !def.head) return '';
      var m = E.result(def.ref, def.tables);
      return m.after ? '<details class="target"><summary>État attendu <b>' + def.tables.join(', ') + '</b></summary>' + def.tables.map(function (t) { return m.after[t] ? grid(m.after[t].columns, m.after[t].rows) : ''; }).join('') + '</details>' : '';
    }
    var exp = E.result(def.ref);
    if (!exp.select) return '';
    var head = def.kind === 'write' ? (def.head === undefined ? 3 : def.head) : null;
    if (head === 0) return '<div class="out-h">Résultat attendu <b>' + plural(exp.select.rows.length, 'ligne', 'lignes') + ' · colonnes : ' + exp.select.columns.map(esc).join(', ') + '</b></div>';
    var shown = head ? exp.select.rows.slice(0, head) : exp.select.rows;
    return '<details class="target"' + (def.kind === 'write' ? ' open' : '') + '><summary>Résultat attendu <b>' + (head ? 'le début · ' + plural(exp.select.rows.length, 'ligne', 'lignes') + ' en tout' : plural(exp.select.rows.length, 'ligne', 'lignes')) + '</b></summary>' + grid(exp.select.columns, shown) + '</details>';
  }
  function renderQuery(s, def) {
    var kind = def.kind, isComp = kind === 'complete';
    var pill = { complete: 'Compléter', repair: 'Réparer', write: 'Écrire' }[kind] || 'Défi';
    var writes = Object.keys(G.lv.steps).filter(function (k) { return G.lv.steps[k].kind === 'write'; }), wi = writes.indexOf(s.id);
    var title = kind === 'write' ? 'Requête ' + (wi + 1) + ' sur ' + writes.length : (kind === 'repair' ? 'Une requête à réparer' : 'Une requête à trous');
    var ed;
    if (isComp) {
      var n = 0, saved = draftOf(s.id) || [];
      ed = '<div class="ed"><div class="ed-bar"><span class="db">collection</span> · touche un trou, puis un mot en bas</div><div class="ro">' + esc(def.template).replace(/\[\[\]\]/g, function () { var v = saved[n] || ''; return '<input class="blank" data-b="' + (n++) + '" aria-label="Trou ' + n + '" spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" placeholder="?" value="' + esc(v) + '">'; }) + '</div></div>';
    } else ed = editorHtml(draftOf(s.id) !== undefined ? draftOf(s.id) : (def.starter || ''), 'console MySQL');
    main.innerHTML = '<div class="kind ' + kindClass(def, s) + '"><span class="pill">' + pill + '</span><h3>' + esc(title) + '</h3></div>' + ask(kindClass(def, s), 'Consigne', esc(def.ask)) + targetHtml(def) + ed +
      '<div id="help">' + helpHtml(def, s.id) + '</div><div id="res" class="out"></div>';
    var query = function () {
      if (!isComp) return $('#sql', main).value;
      var vals = $$('.blank', main).map(function (b) { return b.value.trim(); }), i = 0;
      return def.template.replace(/\[\[\]\]/g, function () { return vals[i++] || ''; });
    };
    if (isComp) {
      lastField = $('.blank', main);
      var fit = function (b) { b.style.width = Math.max(7, b.value.length + 2) + 'ch'; };
      $$('.blank', main).forEach(function (b) {
        fit(b);
        b.addEventListener('input', function () { fit(b); });
        b.addEventListener('focus', function () { lastField = b; markBlanks(); });
        b.addEventListener('input', function () { setDraft(s.id, $$('.blank', main).map(function (x) { return x.value; })); });
        b.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); verify(); } });
      });
      markBlanks();
    } else $('#sql', main).addEventListener('input', function () { setDraft(s.id, $('#sql', main).value); });
    var run = function () { var box = $('#res', main); box.innerHTML = runHtml(query(), def.check === 'modify' ? def.tables : null); reveal(box); };
    var verify = function () {
      var text = query(), box = $('#res', main);
      if (!text.trim() || (isComp && $$('.blank', main).some(function (b) { return !b.value.trim(); }))) { box.innerHTML = '<div class="banner info">' + (isComp ? 'Remplis tous les trous, puis vérifie.' : 'Écris ta requête, puis vérifie.') + '</div>'; reveal(box); return; }
      var res = E.check(def, text);
      if (res.error) { box.innerHTML = errBox(res.error) + '<p class="hint-p">Cette requête ne s\'exécute pas : rien n\'est compté. Corrige l\'erreur (« Tester » ne coûte rien), puis vérifie.</p>'; SND.ko(); reveal(box); return; }
      box.innerHTML = verdictHtml(def, res);
      deliver(s.id, res, function (r) {
        box.insertAdjacentHTML('afterbegin', '<div class="banner good">Exactement ce qui était demandé.</div>');
        $('#help', main).innerHTML = '';
        $$('.blank', main).forEach(function (b) { b.disabled = true; });
        if ($('#sql', main)) $('#sql', main).readOnly = true;
        stepDone(r);
      }).then(function () { reveal(box); });
    };
    var footer = function () { setFoot([hintAction(def, s.id, function () { renderQuery(s, def); }), { label: '▶ Tester', fn: run }, { label: 'Vérifier', cls: 'primary', fn: verify }], chipList(def, G.lv)); };
    footer();
    bindRunKey(run);
  }
  function verdictHtml(def, res) {
    var html = '<ul class="crit">' + res.criteria.map(function (c) { return '<li class="' + (c.wait ? 'wait' : (c.ok ? 'ok' : 'ko')) + '"><span><span class="base">' + esc(c.label) + '</span>' + c.detail + (c.ir ? '<span class="ir">' + c.ir + '</span>' : '') + '</span></li>'; }).join('') + '</ul>';
    var sh = res.shown || {};
    if (sh.kind === 'select' && sh.got) {
      var cmp = sh.cmp, rows = sh.got.rows, cls = [], cols = sh.got.columns;
      if (cmp && cmp.mapped) {
        var counts = {};
        cmp.extra.forEach(function (r) { var k = JSON.stringify(r.map(E.norm)); counts[k] = (counts[k] || 0) + 1; });
        cls = cmp.proj.map(function (r) { var k = JSON.stringify(r.map(E.norm)); if (counts[k]) { counts[k]--; return 'extra'; } return 'ok'; });
        rows = cmp.proj.concat(cmp.missing); cmp.missing.forEach(function () { cls.push('missing'); }); cols = sh.exp.columns;
      }
      html += '<div class="out-h">Ta requête renvoie <b>' + plural(sh.got.rows.length, 'ligne', 'lignes') + '</b>' + (cmp && cmp.mapped && !cmp.rowsOk ? ' <b class="legend">barré : en trop · hachuré : manquant</b>' : '') + '</div>' + grid(cols, rows, cls);
    } else if (sh.kind === 'modify' && sh.after) {
      html += Object.keys(sh.after).map(function (t) { var a = sh.after[t], d = sh['diff_' + t]; if (!a) return ''; var cls = []; var extra = {}; (d ? d.extra : []).forEach(function (r) { extra[JSON.stringify(r.map(E.norm))] = 1; }); a.rows.forEach(function (r) { cls.push(extra[JSON.stringify(r.map(E.norm))] ? 'extra' : ''); }); return '<div class="out-h">Table ' + esc(t) + ' après ta requête</div>' + grid(a.columns, a.rows, cls); }).join('');
    } else if (sh.kind === 'create' && sh.tables) {
      html += sh.tables.map(function (t) { return '<div class="out-h">Table ' + esc(t.name) + '</div>' + grid(['colonne', 'type', 'clé', 'obligatoire'], t.columns.map(function (c) { return [c.name, c.type.replace(/ COLLATE NOCASE/i, ''), c.pk ? 'primaire' : (c.fk ? '→ ' + c.fk.table : (c.unique ? 'unique' : '')), c.notNull ? 'oui' : 'non']; })); }).join('');
    }
    return html;
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
  function showEnd(lv, r) {
    G.view = 'end';
    var q = r.quality || 0, nl = nextOpen(), chrono = lv.kind !== 'level';
    top.innerHTML = '<div class="row">' + icoBtn('back', ICON.back, 'Retour à la carte des niveaux') + '<div class="ttl"><small>' + (chrono ? (lv.kind === 'final' ? 'Épreuve finale' : 'Défi chrono') : 'Niveau ' + lv.num) + '</small><b>' + esc(lv.title) + '</b></div>' + sideButtons() + '</div>';
    bindTop();
    var title = lv.kind === 'final' ? 'Épreuve finale réussie' : (chrono ? 'Défi chrono réussi' : 'Niveau ' + lv.num + ' réussi');
    main.innerHTML = '<div class="lvdone"><span class="lvdone-n' + (chrono ? ' chr' : '') + '">' + (chrono ? ICON.clock : lv.num) + '</span><h3>' + esc(title) + '</h3><p>' + esc(lv.title) + '</p><span class="s">' + starLine(q) + '</span><p>' + (q === 3 ? 'Sans erreur et sans indice.' : (q === 2 ? 'Avec une ou deux erreurs ou indices.' : 'Avec plusieurs erreurs ou indices : le défi chrono de la notion te dira si c\'est acquis.')) + '</p></div>' +
      (chrono && lv.kind === 'chrono' ? '<div class="banner good">Notions maîtrisées : ' + uniqueNotions(lv).map(function (n) { return esc(C.notion(n).label); }).join(', ') + '.</div>' : '') +
      (lv.kind === 'final' ? '<div class="banner good">Tu sais lire, filtrer, regrouper, relier, modifier et créer une base. Le chapitre bonus, sur la sécurité, est ouvert.</div>' : '') +
      rewardsHtml(r.rewards) +
      (nl ? '<p class="hint-p">Ensuite : <b>' + (nl.kind === 'final' ? 'l\'épreuve finale' : 'niveau ' + nl.num + ', ' + esc(nl.title)) + '</b>.' + (chronoOpen().length ? ' Un défi chrono t\'attend aussi sur la carte : il ne bloque rien.' : '') + '</p>' : '');
    setFoot([{ label: 'Carte des niveaux', fn: showMap }].concat(nl ? [{ label: nl.kind === 'final' ? 'Épreuve finale →' : 'Niveau ' + nl.num + ' →', cls: 'primary', fn: function () { openLevel(nl); } }] : []), null);
    sideAndNews();
    scroller.scrollTop = 0;
  }
  function chronoOpen() { return levels().filter(function (lv) { return lv.kind === 'chrono' && stateOf(lv) === 'open'; }); }
  function uniqueNotions(chrono) { var o = []; (chrono.covers || []).forEach(function (id) { (C.get(id).notions || []).forEach(function (n) { if (o.indexOf(n) === -1) o.push(n); }); }); return o; }
  function showDone(lv) {
    G.lv = lv; G.view = 'done';
    top.innerHTML = '<div class="row">' + icoBtn('back', ICON.back, 'Retour à la carte des niveaux') + '<div class="ttl"><small>Niveau ' + lv.num + ' · réussi</small><b>' + esc(lv.title) + '</b></div>' + sideButtons() + '</div>';
    bindTop();
    main.innerHTML = '<div class="lvdone"><span class="lvdone-n">' + lv.num + '</span><h3>Niveau réussi</h3><p>' + esc(lv.title) + '</p><span class="s">' + starLine(qualityOf(lv.id)) + '</span></div><div class="out-h">Les cours du niveau</div>' + lessonsListHtml();
    $$('[data-lesson]', main).forEach(function (b) { b.addEventListener('click', function () { reviewLesson(lv, b.dataset.lesson); }); });
    setFoot([{ label: 'Carte des niveaux', cls: 'primary', fn: showMap }], null);
    sideAndNews();
  }
  function showChronoDone(lv) {
    G.lv = lv; G.view = 'done';
    top.innerHTML = '<div class="row">' + icoBtn('back', ICON.back, 'Retour à la carte des niveaux') + '<div class="ttl"><small>' + (lv.kind === 'final' ? 'Épreuve finale · réussie' : 'Défi chrono · maîtrisé') + '</small><b>' + esc(lv.title) + '</b></div>' + sideButtons() + '</div>';
    bindTop();
    main.innerHTML = '<div class="lvdone"><span class="lvdone-n chr">' + ICON.clock + '</span><h3>' + (lv.kind === 'final' ? 'Épreuve réussie' : 'Défi maîtrisé') + '</h3><p>' + esc(lv.title) + '</p><span class="s">' + starLine(qualityOf(lv.id)) + '</span>' + (G.data.best[lv.id] !== undefined ? '<p>Meilleur score : ' + G.data.best[lv.id] + '/' + lv.draw + '</p>' : '') + '</div>' +
      (lv.kind === 'chrono' ? '<div class="banner good">Notions maîtrisées : ' + uniqueNotions(lv).map(function (n) { return esc(C.notion(n).label); }).join(', ') + '.</div>' : '');
    setFoot([{ label: 'Carte des niveaux', cls: 'primary', fn: showMap }], null);
    sideAndNews();
  }
  function reviewLesson(lv, key) {
    var l = lv.lessons[key];
    main.innerHTML = '<div class="kind k-cap"><span class="pill">Cours</span><h3>' + esc(l.title) + '</h3></div><div class="lesson">' + l.html + '</div>' + (l.example ? editorHtml(l.example, 'exemple à modifier') + '<div id="res" class="out"></div>' : '');
    var run = function () { var box = $('#res', main); box.innerHTML = runHtml($('#sql', main).value); reveal(box); };
    if (l.example) { run(); bindRunKey(run); }
    setFoot([].concat(l.example ? [{ label: '▶ Tester', fn: run }] : [], [{ label: 'Retour', cls: 'primary', fn: function () { showDone(lv); } }]), l.example ? chipList({ ref: l.example }, lv) : null);
    scroller.scrollTop = 0;
  }

  // ——— Timed challenges and the final test ———
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
    if (left <= 0) finishRun();
  }
  function chronoTop() {
    var lv = G.lv, r = G.run, isFinal = lv.kind === 'final';
    top.innerHTML = '<div class="row">' + icoBtn('back', ICON.back, r && !r.over ? 'Abandonner et revenir à la carte' : 'Retour à la carte des niveaux') + '<div class="ttl"><small>' + (isFinal ? 'Épreuve finale' : 'Défi chrono · ' + esc(lv.title.replace(/^Défi chrono · /, ''))) + '</small><b>' + (r && !r.over ? 'Question ' + (r.i + 1) + ' sur ' + r.qs.length : esc(lv.title)) + '</b></div><span class="timer" id="timer">' + fmt(r ? remaining() : limitOf(lv)) + '</span></div>' +
      '<div class="tbar" id="tbar"><span style="width:' + (r ? Math.max(0, remaining() / r.limit * 100) : 100) + '%"></span></div>' +
      '<div class="where">' + (r ? '<b>' + r.ok + '</b> réussie' + (r.ok > 1 ? 's' : '') + ' · ' + r.skipped + ' passée' + (r.skipped > 1 ? 's' : '') : 'Meilleur score : <b>' + (G.data.best[lv.id] !== undefined ? G.data.best[lv.id] + '/' + lv.draw : '—') + '</b>') + '</div>';
    bindTop();
  }
  function showChronoIntro() {
    var lv = G.lv, isFinal = lv.kind === 'final', limit = limitOf(lv), pct = G.user && G.user.extraTimePercent || 0;
    G.run = null; chronoTop();
    main.innerHTML = '<div class="kind k-chr"><span class="pill">' + (isFinal ? 'Épreuve finale' : 'Défi chrono') + '</span><h3>' + esc(isFinal ? 'Tout le SQL du jeu' : lv.title.replace(/^Défi chrono · /, '')) + '</h3></div><ul class="rules">' +
      '<li><b>' + lv.draw + ' requêtes</b> à écrire seul' + (isFinal ? ', de tous les chapitres' : '') + ', tirées au hasard parmi ' + lv.pool.length + ' : chaque essai pose d\'autres questions.</li>' +
      '<li><b>' + fmt(limit) + ' au total</b>' + (pct ? ', ton temps majoré de ' + pct + ' % compris' : '') + '. Le temps court dès le lancement.</li>' +
      '<li><b>Pas d\'indice.</b> Une erreur MySQL reste expliquée, et « Tester » reste permis. Tu peux passer une question.</li>' +
      '<li><b>' + lv.draw + ' sur ' + lv.draw + '</b> : ' + (isFinal ? 'le jeu est terminé, et le globe céleste rejoint ta vitrine.' : 'les notions des niveaux couverts passent à « maîtrisée ».') + ' Sinon, rien ne baisse : recommence quand tu veux.</li></ul>' +
      (G.data.best[lv.id] !== undefined ? '<p class="hint-p">Meilleur score : <b>' + G.data.best[lv.id] + '/' + lv.draw + '</b>.</p>' : '');
    setFoot([{ label: 'Carte', fn: function () { showMap(); } }, { label: '⏱ Lancer', cls: 'primary', fn: startRun }], null);
    sideAndNews(); scroller.scrollTop = 0;
  }
  function shuffle(a) { a = a.slice(); for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), t = a[i]; a[i] = a[j]; a[j] = t; } return a; }
  function startRun() {
    var lv = G.lv, qs;
    if (lv.perChapter) { qs = []; Object.keys(lv.perChapter).forEach(function (ch) { qs = qs.concat(shuffle(lv.pool.filter(function (q) { return String(q.chapter) === ch; })).slice(0, lv.perChapter[ch])); }); }
    else qs = shuffle(lv.pool).slice(0, lv.draw);
    G.run = { qs: qs, i: 0, ok: 0, skipped: 0, start: Date.now(), limit: limitOf(lv), over: false, results: [] };
    stopTimer(); timer = setInterval(tick, 250);
    showQuestion();
  }
  function showQuestion() {
    var r = G.run, q = r.qs[r.i];
    lastField = null; chronoTop();
    var info = '';
    if (!q.check || q.check === 'select') { var exp = E.result(q.ref); if (exp.select) info = '<div class="cols-exp"><span class="out-h">Colonnes attendues</span>' + exp.select.columns.map(function (c) { return '<code>' + esc(c) + '</code>'; }).join('') + '<span class="out-h"><b>' + plural(exp.select.rows.length, 'ligne', 'lignes') + '</b></span></div>'; }
    else if (q.check === 'modify') info = '<p class="hint-p">On vérifie l\'état de la table ' + q.tables.map(esc).join(', ') + ' après ta requête.</p>';
    main.innerHTML = ask('k-chr', 'Question ' + (r.i + 1), esc(q.ask)) + info + editorHtml('', 'console MySQL') + '<div id="res" class="out"></div>';
    var run = function () { var box = $('#res', main); box.innerHTML = runHtml($('#sql', main).value, q.check === 'modify' ? q.tables : null); reveal(box); };
    var valid = function () {
      if (!G.run || G.run.over) return;
      var text = $('#sql', main).value, box = $('#res', main);
      if (!text.trim()) return;
      var res = E.check(q, text);
      if (res.passed) { r.ok++; r.results.push(true); SND.ok(); box.innerHTML = '<div class="flash ok">Juste.</div>'; setTimeout(advance, 450); return; }
      SND.ko();
      if (res.error) { box.innerHTML = errBox(res.error); reveal(box); return; }
      var c = res.criteria.filter(function (x) { return !x.ok && !x.wait; })[0];
      box.innerHTML = '<div class="flash ko">' + (c ? c.detail : 'Pas encore.') + (c && c.ir ? '<span class="ir">' + c.ir + '</span>' : '') + '</div>';
      reveal(box);
    };
    setFoot([{ label: 'Passer', fn: function () { r.skipped++; r.results.push(false); advance(); } }, { label: '▶ Tester', fn: run }, { label: 'Valider', cls: 'primary', fn: valid }], chipList(q, null));
    bindRunKey(valid);
    scroller.scrollTop = 0;
    var ta = $('#sql', main); if (ta && window.matchMedia('(min-width: 900px)').matches) ta.focus();
  }
  function advance() { var r = G.run; if (!r || r.over) return; r.i++; if (r.i >= r.qs.length) finishRun(); else showQuestion(); }
  function finishRun() {
    var r = G.run, lv = G.lv;
    if (!r || r.over) return;
    stopTimer(); r.over = true; r.used = Math.min(r.limit, (Date.now() - r.start) / 1000);
    G.data.best[lv.id] = Math.max(G.data.best[lv.id] || 0, r.ok); save();
    var win = r.ok === r.qs.length, stepId = G.ticket.steps[0].id;
    chronoTop();
    main.innerHTML = '<div class="score' + (win ? ' win' : '') + '"><span class="out-h">' + esc(lv.title) + '</span><b>' + r.ok + '/' + r.qs.length + '</b><span>en ' + fmt(r.used) + (r.used >= r.limit ? ' · temps écoulé' : '') + '</span></div>' +
      '<ul class="recap">' + r.qs.map(function (q, i) { return '<li><span>' + esc(q.ask) + '</span><b>' + (r.results[i] ? '✓' : (i < r.results.length ? 'passée' : '—')) + '</b></li>'; }).join('') + '</ul><div id="res" class="out"></div>';
    setFoot([], null);
    deliver(stepId, { passed: win, failed: win ? [] : ['Défi chrono : ' + r.ok + '/' + r.qs.length] }, function () { endLevel(); }).then(function (res) {
      if (!res || res.ok) return;
      $('#res', main).innerHTML = '<p class="hint-p">Rien ne baisse : ton meilleur score est <b>' + G.data.best[lv.id] + '/' + lv.draw + '</b>. Recommence quand tu veux, les questions changent.</p>';
      setFoot([{ label: 'Carte des niveaux', fn: function () { G.run = null; showMap(); } }, { label: 'Recommencer', cls: 'primary', fn: startRun }], null);
    });
  }

  // ——— Start ———
  window.SGSdk.onReward(function (rewards) { G.rewards = G.rewards.concat(rewards); });
  Promise.all([api('getUser'), api('getProgress'), refresh()]).then(function (res) {
    G.user = res[0]; G.prog = res[1];
    var d = G.prog && G.prog.data;
    G.data = d && typeof d === 'object' && !Array.isArray(d) ? d : {};
    ['seen', 'nseen', 'drafts', 'hints', 'best'].forEach(function (k) { if (!G.data[k] || typeof G.data[k] !== 'object' || Array.isArray(G.data[k])) G.data[k] = {}; });
    SND.setMuted(!!G.data.mute);
    return E.ready();
  }).then(function () {
    snapshotNotions();
    var go = /open=([A-Z0-9]+)/.exec(location.hash); // a shortcut for the tests and the screenshots
    showMap();
    if (go && C.get(go[1])) openLevel(C.get(go[1]));
  }).catch(function (e) { app.textContent = 'Erreur de chargement : ' + e.message; });
})();
