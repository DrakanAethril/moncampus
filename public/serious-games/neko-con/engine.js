/* Panique à la Neko-Con — the engine, browser side.

   It builds a ticket's files (correct site + bug), shows them in a preview frame, checks the
   rendering (computed styles, positions, at several widths) and provides the workstation's tools:
   simulated screen reader, search-engine preview, request monitor. It is the only place in the
   game that « marks »: it judges the rendering, never the text of the code (save explicit
   constraints: no !important, locked rule…).

   The game itself runs in a sandboxed frame (opaque origin, like every serious game of Formatool):
   it cannot read the preview's document. Every page shown therefore carries a small agent
   (pageAgent, below) that measures, reads the page and intercepts links and forms; the game talks
   to it by postMessage. The tickets' tests are sent to it as text and run over there: they must be
   self-contained (no outside variable).

   Nothing here makes a network request: pages, style sheet and images come from NekoSite
   (site.js) and from the student's edits. */
(function (root) {
  'use strict';

  var SITE = root.NekoSite;
  var FONT_BASE = new URL('fonts/', root.location.href).href;
  var FONT_FACES = [
    ['Rampart One', 400, 'rampart-one-400'],
    ['Zen Maru Gothic', 500, 'zen-maru-gothic-500'],
    ['Zen Maru Gothic', 700, 'zen-maru-gothic-700'],
    ['Zen Maru Gothic', 900, 'zen-maru-gothic-900']
  ].map(function (f) {
    return '@font-face{font-family:"' + f[0] + '";font-weight:' + f[1] + ';font-display:block;src:url("' + FONT_BASE + f[2] + '-latin.woff2") format("woff2");}' +
      '@font-face{font-family:"' + f[0] + '";font-weight:' + f[1] + ';font-display:block;unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF;src:url("' + FONT_BASE + f[2] + '-latin-ext.woff2") format("woff2");}';
  }).join('');

  // ——— A ticket's files ———

  function applyMutations(files, mutations, reverse) {
    var list = (mutations || []).slice();
    if (reverse) list.reverse();
    list.forEach(function (m) {
      var find = reverse ? m.replace : m.find, repl = reverse ? m.find : m.replace;
      if (!find) throw new Error('Mutation vide dans ' + m.file + ' : garder du contexte dans « replace » pour qu\'elle s\'inverse');
      var targets = m.file === '*.html' ? Object.keys(files).filter(function (k) { return /\.html$/.test(k); }) : [m.file];
      targets.forEach(function (f) {
        if (files[f] === undefined) throw new Error('Fichier inconnu : ' + f);
        if (files[f].indexOf(find) === -1) {
          if (m.file === '*.html') return;
          throw new Error('Mutation introuvable dans ' + f + ' : ' + find.slice(0, 80));
        }
        files[f] = files[f].split(find).join(repl);
      });
    });
    return files;
  }

  /** A ticket's files: the correct site, the phase (no CSS before the bascule), the bug. */
  function ticketFiles(ticket, opts) {
    var files = Object.assign({}, SITE.files);
    if (ticket.phase === 'html') {
      applyMutations(files, [{ file: '*.html', find: 'href="css/style.css"', replace: 'href="css/styles.css"' }]);
    }
    applyMutations(files, ticket.bug || []);
    if (opts && opts.solved) applyMutations(files, ticket.fix || ticket.bug || [], !ticket.fix);
    return files;
  }

  // ——— The agent, run inside the preview frame ———
  /* eslint-disable no-eval */
  function pageAgent(TOKEN) {
    var parentWin = window.parent;
    function post(m) { m.nc = 1; m.token = TOKEN; parentWin.postMessage(m, '*'); }

    function textOf(el) { return (el.textContent || '').replace(/\s+/g, ' ').trim(); }
    function labelOf(el) {
      if (el.getAttribute('aria-label')) return el.getAttribute('aria-label');
      if (el.id) { var l = document.querySelector('label[for="' + el.id + '"]'); if (l) return textOf(l); }
      var wrap = el.closest('label');
      return wrap ? textOf(wrap) : '';
    }
    function tableGrid(table) {
      var grid = [];
      if (!table) return grid;
      Array.prototype.forEach.call(table.rows, function (row, r) {
        grid[r] = grid[r] || [];
        var c = 0;
        Array.prototype.forEach.call(row.cells, function (cell) {
          while (grid[r][c]) c++;
          var rs = Math.max(1, parseInt(cell.getAttribute('rowspan') || '1', 10)), cs = Math.max(1, parseInt(cell.getAttribute('colspan') || '1', 10));
          for (var i = 0; i < rs; i++) { grid[r + i] = grid[r + i] || []; for (var j = 0; j < cs; j++) grid[r + i][c + j] = cell; }
          c += cs;
        });
      });
      return grid;
    }
    var LANDMARK = { HEADER: 'Bannière', NAV: 'Navigation', MAIN: 'Contenu principal', FOOTER: 'Informations sur le site', ASIDE: 'Contenu complémentaire' };
    function screenReader() {
      var out = [{ kind: 'page', text: (document.title || 'Sans titre') + ', page web' }];
      function cellHeaders(cell) {
        var grid = tableGrid(cell.closest('table')), pos = null;
        grid.forEach(function (row, r) { row.forEach(function (c, k) { if (c === cell && !pos) pos = [r, k]; }); });
        if (!pos) return [];
        var heads = [];
        for (var r = 0; r < grid.length; r++) {
          var c = grid[r][pos[1]];
          if (c && c.tagName === 'TH' && c !== cell && (c.getAttribute('scope') === 'col' || c.closest('thead'))) { heads.push(textOf(c)); break; }
        }
        var rowHead = grid[pos[0]].filter(function (c) { return c && c.tagName === 'TH' && c.getAttribute('scope') === 'row'; })[0];
        if (rowHead && rowHead !== cell) heads.push(textOf(rowHead));
        return heads;
      }
      function walk(el) {
        if (el.nodeType !== 1) return;
        var tag = el.tagName;
        if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'HEAD') return;
        var cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden' || el.getAttribute('aria-hidden') === 'true') return;
        if (LANDMARK[tag] && !((tag === 'HEADER' || tag === 'FOOTER') && el.parentElement && el.parentElement.closest('main,article,section'))) {
          var name = el.getAttribute('aria-label');
          out.push({ kind: 'landmark', text: LANDMARK[tag] + (name ? ', ' + name : '') });
        }
        if (/^H[1-6]$/.test(tag)) { out.push({ kind: 'heading', level: +tag.charAt(1), text: 'Titre de niveau ' + tag.charAt(1) + ', ' + textOf(el) }); return; }
        if (tag === 'IMG') {
          if (!el.hasAttribute('alt')) out.push({ kind: 'image', missing: true, text: 'Image' });
          else if (el.getAttribute('alt').trim()) out.push({ kind: 'image', text: 'Image, ' + el.getAttribute('alt') });
          return;
        }
        if (tag === 'A' && el.hasAttribute('href')) {
          var img = el.querySelector('img');
          out.push({ kind: 'link', text: 'Lien, ' + (textOf(el) || (img && img.getAttribute('alt')) || 'sans nom') });
          return;
        }
        if (tag === 'BUTTON') { out.push({ kind: 'control', text: 'Bouton, ' + textOf(el) }); return; }
        if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') {
          var type = (el.getAttribute('type') || 'text').toLowerCase();
          if (type === 'hidden') return;
          var lab = labelOf(el);
          var kinds = { checkbox: 'Case à cocher', radio: 'Bouton radio', email: 'Zone de saisie e-mail', tel: 'Zone de saisie téléphone', number: 'Zone de saisie numérique', password: 'Zone de saisie protégée', submit: 'Bouton' };
          var txt = (kinds[type] || 'Zone de saisie') + ', ' + (lab || 'sans étiquette');
          if (type === 'checkbox' || type === 'radio') txt += el.checked ? ', cochée' : ', non cochée';
          if (el.required) txt += ', obligatoire';
          out.push({ kind: 'control', missingLabel: !lab, text: txt });
          return;
        }
        if (tag === 'TABLE') {
          var g = tableGrid(el);
          out.push({ kind: 'table', text: 'Tableau, ' + g.length + ' lignes, ' + (g[0] ? g[0].length : 0) + ' colonnes' + (el.caption ? ', ' + textOf(el.caption) : '') });
          Array.prototype.forEach.call(el.querySelectorAll('td'), function (td) {
            var heads = cellHeaders(td);
            out.push({ kind: 'cell', text: (heads.length ? heads.join(', ') + ' : ' : '') + textOf(td) });
          });
          return;
        }
        if (/^(P|LI|FIGCAPTION|LEGEND|CAPTION)$/.test(tag) || (tag === 'LABEL' && !el.htmlFor)) {
          if (!el.querySelector('a[href], img, input, button, select, textarea')) { var tx = textOf(el); if (tx) out.push({ kind: 'text', text: tx }); return; }
        }
        Array.prototype.forEach.call(el.childNodes, function (c) {
          if (c.nodeType === 3) { var s = c.textContent.replace(/\s+/g, ' ').trim(); if (s && tag !== 'LABEL') out.push({ kind: 'text', text: s }); }
          else walk(c);
        });
        var after = getComputedStyle(el, '::after').content;
        if (after && /^["']/.test(after) && after.length > 2) out.push({ kind: 'text', text: after.slice(1, -1) });
      }
      walk(document.body || document.documentElement);
      return out;
    }
    function searchPreview() {
      var meta = document.querySelector('meta[name="description"]');
      return { title: document.title || '', description: meta ? meta.getAttribute('content') || '' : '', hasDescription: !!meta };
    }
    function normColor(c) {
      var m = String(c).match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
      return m ? 'rgb(' + m[1] + ',' + m[2] + ',' + m[3] + ')' : String(c).replace(/\s+/g, '');
    }
    function helpers(ctx) {
      function el(x) { return typeof x === 'string' ? document.querySelector(x) : x; }
      var h = {
        doc: document, win: window, device: ctx.device, layout: ctx.layout, files: ctx.files, info: ctx.info,
        $: function (s) { return document.querySelector(s); },
        $$: function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); },
        rect: function (x) { var e = el(x); return e ? e.getBoundingClientRect() : null; },
        css: function (x, prop, pseudo) { var e = el(x); return e ? getComputedStyle(e, pseudo || null).getPropertyValue(prop) : ''; },
        px: function (x, prop) { return parseFloat(h.css(x, prop)) || 0; },
        text: function (x) { var e = el(x); return e ? textOf(e) : ''; },
        color: function (x, prop) { return normColor(h.css(x, prop || 'color')); },
        rgb: normColor,
        near: function (a, b, tol) { return Math.abs(a - b) <= (tol === undefined ? 2 : tol); },
        inside: function (a, b, tol) {
          var A = h.rect(a), B = h.rect(b), t = tol || 0;
          return !!A && !!B && A.left >= B.left - t && A.right <= B.right + t && A.top >= B.top - t && A.bottom <= B.bottom + t;
        },
        overflowX: function () { var s = document.scrollingElement || document.documentElement; return s.scrollWidth > s.clientWidth + 1; },
        grid: function (x) { return tableGrid(el(x)); },
        reader: screenReader,
        search: searchPreview,
        src: function (f) { return (ctx.files && ctx.files[f]) || ''; },
        rules: function () {
          var out = [];
          Array.prototype.forEach.call(document.styleSheets, function (sheet) {
            var walkRules = function (list, media) {
              Array.prototype.forEach.call(list, function (rule) {
                if (rule.selectorText) out.push({ selector: rule.selectorText, style: rule.style, media: media });
                else if (rule.cssRules) walkRules(rule.cssRules, rule.conditionText || (rule.media && rule.media.mediaText) || media);
              });
            };
            try { walkRules(sheet.cssRules, null); } catch (e) { /* unreadable sheet */ }
          });
          return out;
        },
        hasSelector: function (sel) {
          return h.rules().some(function (r) { return r.selector.split(',').map(function (s) { return s.trim().replace(/\s+/g, ' '); }).indexOf(sel) !== -1; });
        },
        sheetLoaded: function () { return !!(ctx.info && ctx.info.sheets && ctx.info.sheets.length); }
      };
      return h;
    }

    function sendForm(form) {
      if (!form.checkValidity()) { form.reportValidity(); post({ type: 'invalid' }); return; }
      var fields = [];
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.disabled) return;
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
        if (el.tagName === 'BUTTON' || el.type === 'submit') return;
        fields.push([el.name, el.value]);
      });
      var method = (form.getAttribute('method') || 'get').toLowerCase() === 'post' ? 'POST' : 'GET';
      var action = form.getAttribute('action') || '';
      var query = fields.map(function (f) { return encodeURIComponent(f[0]) + '=' + encodeURIComponent(f[1]); }).join('&');
      post({ type: 'request', req: {
        method: method, action: action, fields: fields,
        url: 'https://neko-con.lycee.example/' + action + (method === 'GET' && query ? '?' + query : ''),
        body: method === 'POST' ? query : '',
        unnamed: Array.prototype.filter.call(form.elements, function (el) { return !el.name && el.tagName === 'INPUT' && el.type !== 'submit'; }).map(function (el) { return el.id || el.type; })
      } });
    }
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a');
      var submit = e.target.closest && e.target.closest('button, input[type="submit"]');
      if (a) {
        e.preventDefault();
        if (!a.hasAttribute('href')) return;
        var href = a.getAttribute('href');
        if (href.charAt(0) === '#') { var t = document.getElementById(href.slice(1)); if (t) t.scrollIntoView(); return; }
        if (/^[a-z]+:/i.test(href)) return;
        post({ type: 'navigate', href: href.split('#')[0] });
        return;
      }
      if (submit && submit.form && (submit.type === 'submit' || (submit.tagName === 'BUTTON' && !submit.getAttribute('type')))) { e.preventDefault(); sendForm(submit.form); }
    }, true);
    document.addEventListener('submit', function (e) { e.preventDefault(); sendForm(e.target); }, true);

    window.addEventListener('message', function (e) {
      if (e.source !== parentWin) return;
      var m = e.data;
      if (!m || m.nc !== 1 || m.token !== TOKEN) return;
      var result;
      try {
        if (m.type === 'run') {
          var h = helpers(m.ctx || {});
          result = m.tests.map(function (src) {
            try {
              var fn = (0, eval)('(' + src + ')');
              var v = fn(h);
              return (v && typeof v === 'object') ? { ok: !!v.ok, detail: v.detail || '' } : { ok: !!v, detail: '' };
            } catch (err) { return { ok: false, detail: 'Erreur du test : ' + err.message }; }
          });
        } else if (m.type === 'reader') result = screenReader();
        else if (m.type === 'search') result = searchPreview();
        else if (m.type === 'size') result = { w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight };
        post({ type: 'reply', id: m.id, result: result });
      } catch (err) { post({ type: 'reply', id: m.id, error: err.message }); }
    });

    var sent = false;
    function ready() {
      if (sent) return; sent = true;
      setTimeout(function () { post({ type: 'ready', w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight }); }, 30);
    }
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(ready);
    setTimeout(ready, 1500);
  }
  /* eslint-enable no-eval */

  // ——— Rendering ———

  function mojibake(s) {
    // UTF-8 text read as Latin-1: « lycée » becomes « lycÃ©e ».
    try { return unescape(encodeURIComponent(s)); } catch (e) { return s; }
  }
  function hasUtf8(html) { return /<meta\s[^>]*charset\s*=\s*["']?utf-8/i.test(html); }
  function hasViewport(html) {
    return /<meta\s[^>]*name\s*=\s*["']viewport["'][^>]*width\s*=\s*device-width/i.test(html) ||
      /<meta\s[^>]*content\s*=\s*["'][^"']*width\s*=\s*device-width[^>]*name\s*=\s*["']viewport/i.test(html);
  }
  function dataUri(svg) { return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg); }
  function notFound(page) {
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>404</title></head><body style="font-family:sans-serif;padding:40px"><h1>404 — Page introuvable</h1><p>Le serveur ne trouve pas <code>' +
      String(page).replace(/[<>&]/g, '') + '</code>.</p></body></html>';
  }

  var tokenSeq = 0;
  /** The document to show for a page: style sheet inlined when its link is right, images, agent. */
  function buildDocument(files, page, token) {
    var html = files[page];
    var missing = html === undefined;
    if (missing) html = notFound(page);
    var utf8 = hasUtf8(html), viewport = missing || hasViewport(html), sheets = [];
    html = html.replace(/<script\b[\s\S]*?<\/script>/gi, '');
    html = html.replace(/(<[a-z][^>]*?)\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '$1');
    html = html.replace(/<link\b[^>]*>/gi, function (tag) {
      if (!/rel\s*=\s*["']?stylesheet/i.test(tag)) return tag;
      var m = tag.match(/href\s*=\s*["']([^"']*)["']/i);
      var href = m ? m[1] : null;
      if (href && files[href] !== undefined && /\.css$/.test(href)) {
        sheets.push(href);
        return '<style data-file="' + href + '">\n' + files[href].replace(/<\/style/gi, '<\\/style') + '\n</style>';
      }
      return '<!-- feuille introuvable : ' + (href || '?') + ' -->';
    });
    html = html.replace(/(<img\b[^>]*?\ssrc\s*=\s*)(["'])([^"']*)\2/gi, function (all, pre, q, src) {
      var img = SITE.images[src];
      return pre + q + (img ? dataUri(img) : 'data:image/png;base64,AAAA') + q;
    });
    if (!utf8) html = mojibake(html);
    var fonts = '<style data-engine>' + FONT_FACES + '</style>';
    html = /<head[^>]*>/i.test(html) ? html.replace(/<head[^>]*>/i, function (x) { return x + fonts; }) : fonts + html;
    var agent = '<script>(' + pageAgent.toString() + ')(' + JSON.stringify(token) + ');<\/script>';
    // Replace with a function: in a replacement string, the agent code's « $$ » would become « $ ».
    html = /<\/body>/i.test(html) ? html.replace(/<\/body>(?![\s\S]*<\/body>)/i, function () { return agent + '</body>'; }) : html + agent;
    return { html: html, utf8: utf8, viewport: viewport, sheets: sheets };
  }

  // The known frames: their current document (token), the replies awaited, their handlers.
  var frames = [];
  function entryFor(frame) {
    var e = frames.filter(function (x) { return x.frame === frame; })[0];
    if (!e) { e = { frame: frame, token: null, pending: {}, seq: 1, handlers: {}, onReady: null }; frames.push(e); }
    return e;
  }
  root.addEventListener('message', function (ev) {
    var m = ev.data;
    if (!m || m.nc !== 1) return;
    var e = frames.filter(function (x) { return x.frame.contentWindow === ev.source; })[0];
    if (!e || m.token !== e.token) return;
    if (m.type === 'ready') { e.size = { w: m.w, h: m.h }; if (e.onReady) { var r = e.onReady; e.onReady = null; r(); } }
    else if (m.type === 'reply' && e.pending[m.id]) { var p = e.pending[m.id]; delete e.pending[m.id]; if (m.error) p.reject(new Error(m.error)); else p.resolve(m.result); }
    else if (m.type === 'navigate' && e.handlers.navigate) e.handlers.navigate(m.href);
    else if (m.type === 'request' && e.handlers.request) e.handlers.request(m.req);
    else if (m.type === 'invalid' && e.handlers.invalid) e.handlers.invalid();
  });

  /**
   * Shows a page in a frame (sandbox="allow-scripts"). `width` is the width of the simulated device;
   * without a viewport tag, a phone lays the page out over 980 px and scales it down, like a real
   * mobile browser. Resolves to { info, layout, device, size, call(type, data) }.
   */
  function render(frame, files, page, width) {
    var token = 'nc' + (++tokenSeq) + '-' + Math.random().toString(36).slice(2, 8);
    var doc = buildDocument(files, page, token);
    var layout = (!doc.viewport && width < 700) ? 980 : width;
    frame.style.width = layout + 'px';
    frame.dataset.layout = layout;
    frame.dataset.device = width;
    var e = entryFor(frame);
    e.token = token;
    Object.keys(e.pending).forEach(function (k) { e.pending[k].reject(new Error('page remplacée')); });
    e.pending = {};
    return new Promise(function (resolve) {
      var done = false;
      function finish() {
        if (done) return; done = true;
        resolve({
          frame: frame, info: { utf8: doc.utf8, viewport: doc.viewport, sheets: doc.sheets }, layout: layout, device: width, page: page,
          size: e.size || null,
          call: function (type, data) {
            if (e.token !== token) return Promise.reject(new Error('page remplacée'));
            var id = e.seq++;
            return new Promise(function (res, rej) {
              e.pending[id] = { resolve: res, reject: rej };
              var msg = Object.assign({ nc: 1, token: token, id: id, type: type }, data || {});
              frame.contentWindow.postMessage(msg, '*');
              setTimeout(function () { if (e.pending[id]) { delete e.pending[id]; rej(new Error('pas de réponse de l\'aperçu')); } }, 8000);
            });
          }
        });
      }
      e.onReady = finish;
      setTimeout(finish, 6000);
      frame.srcdoc = doc.html;
    });
  }

  /** Wires the preview's links and forms: navigate(href), request(req), invalid(). */
  function wire(frame, handlers) { entryFor(frame).handlers = handlers || {}; }

  function cssBlock(css, selector) {
    var i = css.indexOf(selector + ' {');
    if (i === -1) return null;
    var j = css.indexOf('}', i);
    return css.slice(i, j + 1).replace(/\s+/g, ' ').trim();
  }

  /**
   * Checks a ticket against the given files. Renders each page at each width asked for in the test
   * frame, runs the ticket's tests there, then its code constraints. Resolves to the list of
   * criteria, each { label, ok, detail }.
   */
  function check(ticket, files, frame) {
    var groups = {};
    (ticket.checks || []).forEach(function (c, i) {
      var key = (c.page || ticket.page || 'index.html') + '@' + (c.width || 1280);
      (groups[key] = groups[key] || []).push({ c: c, i: i });
    });
    var results = [];
    var chain = Promise.resolve();
    Object.keys(groups).forEach(function (key) {
      chain = chain.then(function () {
        var page = key.split('@')[0], width = +key.split('@')[1];
        return render(frame, files, page, width).then(function (r) {
          var list = groups[key];
          return r.call('run', { tests: list.map(function (g) { return g.c.test.toString(); }), ctx: { files: files, info: r.info, device: r.device, layout: r.layout } })
            .then(function (res) {
              list.forEach(function (g, k) { results[g.i] = { label: g.c.label, ok: !!(res[k] && res[k].ok), detail: (res[k] && res[k].detail) || '' }; });
            }, function (err) {
              list.forEach(function (g) { results[g.i] = { label: g.c.label, ok: false, detail: 'Vérification impossible : ' + err.message }; });
            });
        });
      });
    });
    return chain.then(function () {
      (ticket.forbid || []).forEach(function (f) {
        var ok = !f.pattern.test(files[f.file] || '');
        results.push({ label: f.label, ok: ok, detail: ok ? '' : 'Interdit dans ' + f.file });
      });
      (ticket.locked || []).forEach(function (l) {
        var ref = cssBlock(ticketFiles(ticket)[l.file], l.rule), now = cssBlock(files[l.file] || '', l.rule);
        var ok = ref !== null && ref === now;
        results.push({ label: l.label || 'La règle ' + l.rule + ' n\'a pas été modifiée', ok: ok, detail: ok ? '' : 'La règle ' + l.rule + ' a changé.' });
      });
      return results.filter(Boolean);
    });
  }

  root.NekoEngine = {
    ticketFiles: ticketFiles,
    applyMutations: applyMutations,
    buildDocument: buildDocument,
    render: render,
    wire: wire,
    check: check
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
