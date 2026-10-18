/* Formatool — serious games — the drawings of the collection pieces (design/validated/serious-games.md
   §8), ported from the conception's sg-rewards.js for the one game built: L'Observatoire.

   A classic script that sets globalThis.SGRewards - loaded by the game in its frame and by
   Formatool's screens (the player page, the showcase), like shared/avatar.js:

     SGRewards.art(kind, code, {size, finish, locked}) → SVG markup
       kind: 'trophy' (code 'astrolabe') | 'card' (code 'card-observatoire-<chapter>') | 'pin' ('pin-etoile')
       finish (trophy only): 'brass' | 'silver' | 'gold' - gold carries its glints
       locked: a silhouette in currentColor, readable on both themes.

   A piece gives no point and is never ranked; only students collect. */
(function (root) {
  'use strict';

  var METALS = {
    simple: { base: '#C9973F', light: '#EBCB82', dark: '#8C6A2E', deep: '#5E4520' },
    brillant: { base: '#B4BEC9', light: '#F1F5F9', dark: '#76818D', deep: '#4D5661' },
    parfait: { base: '#E2B23A', light: '#FFE69A', dark: '#A8761B', deep: '#6F4C0E' }
  };
  var LOCK = { base: 'currentColor', light: 'currentColor', dark: 'currentColor', deep: 'currentColor' };

  var LOCK = { base: 'currentColor', light: 'currentColor', dark: 'currentColor', deep: 'currentColor' };
  // The stored finishes (SeriousGameFinish) and the drawing's names for them.
  var FINISH = { brass: 'simple', silver: 'brillant', gold: 'parfait' };

  // Card palette (frame, paper, ink, accent, display font)
  var CARD = {
    observatoire: { bg: '#101833', paper: '#1B2550', ink: '#EDE6D6', accent: '#D9A441', font: "'Cormorant Garamond', Georgia, serif", italic: true }
  };
  var GAMES = {
    observatoire: { title: "L'Observatoire", chapters: [
      { id: 'compas', name: 'Le Compas', topic: 'Calcul numérique & algébrique' },
      { id: 'sablier', name: 'Le Sablier', topic: 'Proportions & pourcentages' },
      { id: 'balance', name: 'La Balance', topic: 'Équations & inéquations' },
      { id: 'serpent', name: 'Le Serpent', topic: 'Fonctions & représentations' },
      { id: 'des', name: 'Les Dés', topic: 'Statistiques & probabilités' }],
      finale: { id: 'couronne', name: 'La Couronne', topic: "Nuit de l'épreuve" } }
  };
  var PINS = [{ id: 'pin-etoile', name: 'Épinglette Étoile', color: '#1B2550' }];

  function cardOf(code) {
    var m = /^card-([a-z0-9-]+?)-([a-z0-9]+)$/.exec(code || '');
    var game = m && GAMES[m[1]];
    if (!game) return null;
    var chapter = game.chapters.filter(function (c) { return c.id === m[2]; })[0];
    var finale = !chapter && game.finale.id === m[2];
    if (!chapter && !finale) return null;
    var c = chapter || game.finale;
    return { game: m[1], chapter: c.id, name: c.name, topic: c.topic, finale: finale };
  }
  // the conception's seeded randomness (SG.rng), for the constellation drawn on a card
  function rng(seed) {
    var a = (seed >>> 0) || 1;
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      var t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  var uid = 0;
  function nid(p) { uid += 1; return p + uid + Math.random().toString(36).slice(2, 6); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* ---------- shared pieces ---------- */
  function metalGrad(id, M) {
    return '<linearGradient id="' + id + '" x1="0" y1="0" x2="1" y2="1">' +
      '<stop offset="0" stop-color="' + M.light + '"/><stop offset=".45" stop-color="' + M.base + '"/><stop offset="1" stop-color="' + M.dark + '"/></linearGradient>';
  }
  function glints(color) {
    var star = function (x, y, r) { return '<path d="M' + x + ' ' + (y - r) + ' Q' + x + ' ' + y + ' ' + (x + r) + ' ' + y + ' Q' + x + ' ' + y + ' ' + x + ' ' + (y + r) + ' Q' + x + ' ' + y + ' ' + (x - r) + ' ' + y + ' Q' + x + ' ' + y + ' ' + x + ' ' + (y - r) + ' Z" fill="' + color + '"/>'; };
    return '<g class="sg-glints">' + star(22, 24, 7) + star(98, 30, 5) + star(94, 96, 6.5) + star(28, 92, 4) + '</g>';
  }
  function halo(id, color) {
    return '<radialGradient id="' + id + '"><stop offset="0" stop-color="' + color + '" stop-opacity=".55"/><stop offset="1" stop-color="' + color + '" stop-opacity="0"/></radialGradient>';
  }
  function lockBadge(x, y) {
    return '<g transform="translate(' + x + ' ' + y + ')" opacity=".9"><rect x="-9" y="-3" width="18" height="14" rx="3" fill="currentColor"/>' +
      '<path d="M-5.5 -3 V-7 a5.5 5.5 0 0 1 11 0 V-3" fill="none" stroke="currentColor" stroke-width="2.6"/></g>';
  }

  /* ---------- trophies (viewBox 0 0 120 120) ---------- */
  var TROPHY = {
    astrolabe: function (M, g) {
      var ticks = '';
      for (var a = 0; a < 360; a += 10) {
        var r = a * Math.PI / 180, l = a % 30 === 0 ? 6 : 3;
        ticks += '<line x1="' + (60 + 40 * Math.cos(r)).toFixed(1) + '" y1="' + (66 + 40 * Math.sin(r)).toFixed(1) + '" x2="' + (60 + (40 - l) * Math.cos(r)).toFixed(1) + '" y2="' + (66 + (40 - l) * Math.sin(r)).toFixed(1) + '" stroke="' + M.deep + '" stroke-width="' + (a % 30 === 0 ? 1.4 : .8) + '"/>';
      }
      return '<path d="M52 22 Q60 10 68 22 L66 26 L54 26 Z" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1"/>' +
        '<circle cx="60" cy="13" r="6" fill="none" stroke="url(#' + g + ')" stroke-width="3"/>' +
        '<circle cx="60" cy="66" r="44" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1.6"/>' +
        '<circle cx="60" cy="66" r="34" fill="' + M.dark + '"/>' + ticks +
        '<circle cx="60" cy="66" r="30" fill="none" stroke="' + M.light + '" stroke-width=".8" opacity=".7"/>' +
        '<circle cx="60" cy="58" r="16" fill="none" stroke="' + M.base + '" stroke-width="2.2"/>' +
        '<path d="M60 36 Q74 50 82 72 M60 36 Q46 50 38 72 M44 80 Q60 88 76 80" fill="none" stroke="' + M.base + '" stroke-width="2"/>' +
        '<path d="M60 36 L63 41 L60 40 L57 41 Z M82 72 l4 3 l-5 0 Z M38 72 l-4 3 l5 0 Z" fill="' + M.light + '"/>' +
        '<rect x="20" y="63.5" width="80" height="5" rx="2.5" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width=".8" transform="rotate(-28 60 66)"/>' +
        '<circle cx="60" cy="66" r="4" fill="' + M.light + '" stroke="' + M.deep + '" stroke-width="1"/>';
    }
  };

  function trophy(id, o) {
    var locked = !!o.locked, finish = FINISH[o.finish] || o.finish || 'simple';
    var M = locked ? LOCK : (METALS[finish] || METALS.simple);
    var g = nid('mg'), h = nid('ha');
    var defs = '<defs>' + (locked ? '<linearGradient id="' + g + '"><stop offset="0" stop-color="currentColor"/></linearGradient>' : metalGrad(g, M)) + halo(h, M.light) + '</defs>';
    var draw = TROPHY[id] ? TROPHY[id](M, g, locked) : '<circle cx="60" cy="60" r="40" fill="url(#' + g + ')"/>';
    var body = locked ? '<g opacity=".22">' + draw.replace(/fill="#[0-9A-Fa-f]{3,6}"/g, 'fill="currentColor"').replace(/stroke="#[0-9A-Fa-f]{3,6}"/g, 'stroke="currentColor"') + '</g>' + lockBadge(60, 108) : draw;
    var extra = '';
    if (!locked && finish === 'parfait') extra = glints('#FFF4C8');
    var back = !locked && finish !== 'simple' ? '<circle cx="60" cy="62" r="56" fill="url(#' + h + ')"/>' : '';
    return svg(120, 120, o.size || 96, defs + back + body + extra, locked ? 'Pièce verrouillée' : 'Pièce de collection');
  }

  /* ---------- chapter cards (viewBox 0 0 120 168) ---------- */
  function emblem(gameId, idx, P, locked) {
    var a = P.accent, ink = P.ink, r = rng(idx * 97 + 11);
    var s = '';
    if (gameId === 'observatoire') {
      var pts = [];
      for (var i = 0; i < 5 + (idx % 3); i++) pts.push([30 + r() * 60, 38 + r() * 50]);
      for (var j = 1; j < pts.length; j++) s += '<line x1="' + pts[j - 1][0].toFixed(1) + '" y1="' + pts[j - 1][1].toFixed(1) + '" x2="' + pts[j][0].toFixed(1) + '" y2="' + pts[j][1].toFixed(1) + '" stroke="' + a + '" stroke-width="1" opacity=".7"/>';
      pts.forEach(function (p, k) { s += '<circle cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="' + (k % 2 ? 6 : 8) + '" fill="' + a + '" opacity=".15"/><circle cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="' + (k % 2 ? 2 : 2.8) + '" fill="#F2CE7A"/>'; });
      s += '<circle cx="60" cy="64" r="34" fill="none" stroke="' + a + '" stroke-width=".8" stroke-dasharray="2 4" opacity=".6"/>';
    }
    return s;
  }
  function card(id, o) {
    var c = cardOf(id);
    if (!c) return svg(120, 168, o.size || 96, '', 'Carte');
    var game = GAMES[c.game] || null, P = CARD[c.game] || CARD.observatoire;
    var idx = game ? game.chapters.map(function (ch) { return ch.id; }).indexOf(c.chapter) : 0;
    if (c.finale) idx = game ? game.chapters.length : 6;
    var locked = !!o.locked;
    var name = c.name, fs = Math.min(13, 172 / Math.max(8, name.length));
    var tfs = Math.min(7.2, 150 / Math.max(10, c.topic.length));
    var frame = c.finale ? '#D9A441' : P.accent;
    var inner = '<rect x="2" y="2" width="116" height="164" rx="9" fill="' + P.bg + '" stroke="' + frame + '" stroke-width="' + (c.finale ? 3 : 1.5) + '"/>' +
      '<rect x="9" y="22" width="102" height="96" rx="5" fill="' + P.paper + '"/>' +
      '<text x="60" y="15" text-anchor="middle" font-size="6.4" letter-spacing="1.4" font-family="\'IBM Plex Mono\', monospace" fill="' + P.ink + '" opacity=".75">' + esc((game ? game.title : '').toUpperCase()).slice(0, 26) + '</text>' +
      '<g>' + emblem(c.game, idx, P, locked) + '</g>' +
      '<text x="60" y="138" text-anchor="middle" font-size="' + fs.toFixed(1) + '" font-weight="600" ' + (P.italic ? 'font-style="italic" ' : '') + 'font-family="' + P.font + '" fill="' + P.ink + '">' + esc(name) + '</text>' +
      '<text x="60" y="152" text-anchor="middle" font-size="' + tfs.toFixed(1) + '" font-family="\'Source Sans 3\', sans-serif" fill="' + P.ink + '" opacity=".72">' + esc(c.topic) + '</text>' +
      (c.finale ? '<path d="M50 160 L60 154 L70 160" fill="none" stroke="#D9A441" stroke-width="1.6"/>' : '<circle cx="60" cy="159" r="1.6" fill="' + P.accent + '"/>');
    var body = locked
      ? '<rect x="2" y="2" width="116" height="164" rx="9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-dasharray="4 4" opacity=".45"/>' +
        '<text x="60" y="78" text-anchor="middle" font-size="30" font-family="Georgia, serif" fill="currentColor" opacity=".25">?</text>' +
        '<text x="60" y="138" text-anchor="middle" font-size="' + fs.toFixed(1) + '" font-family="\'Spectral\', Georgia, serif" fill="currentColor" opacity=".5">' + esc(name) + '</text>' + lockBadge(60, 100)
      : inner;
    return svg(120, 168, o.size || 96, body, (locked ? 'Carte à découvrir : ' : 'Carte : ') + name);
  }

  /* ---------- pins (viewBox 0 0 60 60) ---------- */
  var PIN_GLYPH = {
    'pin-etoile': function (c) { return '<path d="M30 14 Q31.5 28.5 46 30 Q31.5 31.5 30 46 Q28.5 31.5 14 30 Q28.5 28.5 30 14 Z" fill="' + c + '"/><circle cx="41" cy="19" r="1.6" fill="' + c + '"/>'; }
  };
  function pin(id, o) {
    var p = PINS.filter(function (x) { return x.id === id; })[0];
    var locked = !!o.locked, M = METALS.simple, g = nid('pg');
    var enamel = p ? p.color : '#1B2550';
    var body = locked
      ? '<circle cx="30" cy="30" r="25" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="3 3" opacity=".45"/><g opacity=".3">' + (PIN_GLYPH[id] ? PIN_GLYPH[id]('currentColor') : '') + '</g>'
      : '<defs>' + metalGrad(g, M) + '</defs><circle cx="30" cy="32" r="25" fill="#000" opacity=".18"/><circle cx="30" cy="30" r="25" fill="url(#' + g + ')"/>' +
        '<circle cx="30" cy="30" r="20.5" fill="' + enamel + '"/><path d="M14 24 Q20 12 34 11" stroke="#fff" stroke-width="2" fill="none" opacity=".35" stroke-linecap="round"/>' +
        (PIN_GLYPH[id] ? PIN_GLYPH[id]('#F5E6C0') : '');
    return svg(60, 60, o.size || 48, body, p ? p.name : 'Épinglette');
  }

  function svg(w, h, size, inner, label) {
    var height = Math.round(size * h / w);
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" width="' + size + '" height="' + height + '" role="img" aria-label="' + esc(label) + '" style="display:block;overflow:visible">' + inner + '</svg>';
  }

  root.SGRewards = {
    FINISHES: FINISH,
    art: function (kind, code, o) {
      o = o || {};
      if (kind === 'trophy') return trophy(code, o);
      if (kind === 'card') return card(code, o);
      if (kind === 'pin') return pin(code, o);
      return '';
    }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
