/* Formatool — serious games — the drawings of the collection pieces (design/validated/serious-games.md
   §8), ported from the conception's sg-rewards.js: L'Observatoire's, Station Abysse's and The
   Irregulars', and those of Les Tablettes de Pompéi drawn after the Claude Design page « Serious
   game - Tablettes de Pompéi v2 ».

   A classic script that sets globalThis.SGRewards - loaded by the game in its frame and by
   Formatool's screens (the player page, the showcase), like shared/avatar.js:

     SGRewards.art(kind, code, {size, finish, locked}) → SVG markup
       kind: 'trophy' ('astrolabe', 'groma', 'nautile', 'loupe') | 'card' ('card-<game>-<chapter>')
             | 'pin' ('pin-etoile', 'pin-cave-canem', 'pin-vague', 'pin-loupe')
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
  // The stored finishes (SeriousGameFinish) and the drawing's names for them.
  var FINISH = { brass: 'simple', silver: 'brillant', gold: 'parfait' };

  // Card palette (frame, paper, ink, accent, display font)
  var CARD = {
    observatoire: { bg: '#101833', paper: '#1B2550', ink: '#EDE6D6', accent: '#D9A441', font: "'Cormorant Garamond', Georgia, serif", italic: true },
    pompei: { bg: '#EFE3CB', paper: '#9E2B25', ink: '#2E1D14', accent: '#C9923E', font: "'Cormorant Garamond', Georgia, serif", italic: false },
    abysse: { bg: '#031319', paper: '#072B36', ink: '#CFF7F1', accent: '#7FF5E3', font: "'Chakra Petch', 'Trebuchet MS', sans-serif", italic: false },
    irregulars: { bg: '#142820', paper: '#1F3A2E', ink: '#E9D8A6', accent: '#C9A04E', font: "'Libre Caslon Display', Georgia, serif", italic: false }
  };
  var GAMES = {
    observatoire: { title: "L'Observatoire", chapters: [
      { id: 'compas', name: 'Le Compas', topic: 'Calcul numérique & algébrique' },
      { id: 'sablier', name: 'Le Sablier', topic: 'Proportions & pourcentages' },
      { id: 'balance', name: 'La Balance', topic: 'Équations & inéquations' },
      { id: 'serpent', name: 'Le Serpent', topic: 'Fonctions & représentations' },
      { id: 'des', name: 'Les Dés', topic: 'Statistiques & probabilités' }],
      finale: { id: 'couronne', name: 'La Couronne', topic: "Nuit de l'épreuve" } },
    pompei: { title: 'Les Tablettes de Pompéi', chapters: [
      { id: 'atrium', name: "L'Atrium", topic: '1re et 2e déclinaisons' },
      { id: 'tablinum', name: 'Le Tablinum', topic: '3e déclinaison' },
      { id: 'triclinium', name: 'Le Triclinium', topic: '4e et 5e déclinaisons' },
      { id: 'peristylium', name: 'Le Péristyle', topic: 'Adjectifs et accord' },
      { id: 'thermae', name: 'Les Thermes', topic: 'Pronoms' },
      { id: 'hortus', name: "L'Hortus", topic: 'Cas et fonctions' }],
      finale: { id: 'lararium', name: 'Le Lararium', topic: "Journée de l'épreuve" } },
    abysse: { title: 'Station Abysse', chapters: [
      { id: 'recif', name: 'Le Récif', topic: 'Biodiversité et classification' },
      { id: 'fosse', name: 'La Fosse', topic: 'ADN et expression génétique' },
      { id: 'sources', name: 'Les Sources chaudes', topic: 'Métabolisme cellulaire' },
      { id: 'plateau', name: 'Le Plateau', topic: 'Écosystèmes et réseaux trophiques' },
      { id: 'dorsale', name: 'La Dorsale', topic: 'Évolution et sélection' }],
      finale: { id: 'remontee', name: 'La Remontée', topic: 'Tout le carnet' } },
    irregulars: { title: 'The Irregulars', chapters: [
      { id: 'docks', name: 'The Docks', topic: 'Le prétérit' },
      { id: 'market', name: 'Covent Garden', topic: 'Le present perfect' },
      { id: 'theatre', name: 'The Lyceum', topic: 'Questions et auxiliaires' },
      { id: 'museum', name: 'The Museum', topic: 'Comparatifs et superlatifs' },
      { id: 'station', name: 'Paddington', topic: 'Modaux et futur' }],
      finale: { id: 'final-case', name: 'The Final Case', topic: 'Toutes les affaires' } }
  };
  var PINS = [
    { id: 'pin-etoile', name: 'Épinglette Étoile', color: '#1B2550' },
    { id: 'pin-cave-canem', name: 'Épinglette Cave canem', color: '#EFE3CB', ink: '#1E1410', ring: '#9E2B25' },
    { id: 'pin-vague', name: 'Épinglette Abysse', color: '#167784' },
    { id: 'pin-loupe', name: 'Épinglette Loupe', color: '#1F3A2E', ink: '#E9D8A6' }
  ];

  function cardOf(code) {
    // a game slug and a chapter code may both carry hyphens (card-irregulars-final-case): the
    // game is the longest known prefix
    var rest = /^card-(.+)$/.exec(code || ''), m = null;
    Object.keys(GAMES).forEach(function (g) { if (rest && rest[1].indexOf(g + '-') === 0 && (!m || g.length > m[1].length)) m = [null, g, rest[1].slice(g.length + 1)]; });
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
    },
    /* La Groma, the Roman surveyors' instrument: a staff, a cross of two arms on a pivot, a plumb
       line with its bob at each of the four ends. */
    groma: function (M, g) {
      var ends = [[24, 33, 72], [96, 19, 64], [43, 12, 52], [77, 40, 80]];
      var bobs = ends.map(function (e) {
        return '<line x1="' + e[0] + '" y1="' + e[1] + '" x2="' + e[0] + '" y2="' + (e[2] - 6) + '" stroke="' + M.deep + '" stroke-width=".9"/>' +
          '<path d="M' + e[0] + ' ' + (e[2] - 7) + ' l4 6 l-4 7 l-4 -7 Z" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width=".8"/>';
      }).join('');
      return '<path d="M44 112 L76 112 L71 104 L49 104 Z" fill="' + M.dark + '" stroke="' + M.deep + '" stroke-width="1"/>' +
        '<rect x="47" y="100" width="26" height="5" rx="1.5" fill="' + M.base + '" stroke="' + M.deep + '" stroke-width=".8"/>' +
        '<rect x="57.5" y="24" width="5" height="78" rx="2" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1"/>' +
        '<line x1="24" y1="33" x2="96" y2="19" stroke="' + M.deep + '" stroke-width="5.4" stroke-linecap="round"/>' +
        '<line x1="24" y1="33" x2="96" y2="19" stroke="url(#' + g + ')" stroke-width="3.6" stroke-linecap="round"/>' +
        '<line x1="43" y1="12" x2="77" y2="40" stroke="' + M.deep + '" stroke-width="5.4" stroke-linecap="round"/>' +
        '<line x1="43" y1="12" x2="77" y2="40" stroke="url(#' + g + ')" stroke-width="3.6" stroke-linecap="round"/>' +
        '<circle cx="60" cy="26" r="5" fill="' + M.light + '" stroke="' + M.deep + '" stroke-width="1.2"/>' + bobs;
    },
    /* Le Nautile (Station Abysse): a logarithmic whorl r = a·e^(bθ) with its septa, on a stand. */
    nautile: function (M, g) {
      var a = 2.7, b = .22, cx = 62, cy = 54, pts = [], inner = [], septa = '', N = 64;
      for (var i = 0; i <= N; i++) {
        var t = i / N * 2 * Math.PI + 2 * Math.PI;
        var ro = a * Math.exp(b * t), ri = a * Math.exp(b * (t - 2 * Math.PI));
        pts.push([cx + ro * Math.cos(t), cy + ro * Math.sin(t)]);
        inner.push([cx + ri * Math.cos(t), cy + ri * Math.sin(t)]);
        if (i % 6 === 3 && i < N - 4) septa += '<path d="M' + inner[i][0].toFixed(1) + ' ' + inner[i][1].toFixed(1) + ' Q' + ((pts[i][0] + inner[i][0]) / 2 + 3).toFixed(1) + ' ' + ((pts[i][1] + inner[i][1]) / 2 - 2).toFixed(1) + ' ' + pts[i][0].toFixed(1) + ' ' + pts[i][1].toFixed(1) + '" stroke="' + M.dark + '" stroke-width="1.6" fill="none"/>';
      }
      var d = 'M' + pts.map(function (p) { return p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' L') + ' L' + inner.slice().reverse().map(function (p) { return p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' L') + ' Z';
      var full = 'M' + pts.map(function (p) { return p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' L') + ' Z';
      var core = '';
      for (var j = 0; j <= 40; j++) { var tt = j / 40 * 2 * Math.PI, rr = a * Math.exp(b * tt); core += (j ? ' L' : 'M') + (cx + rr * Math.cos(tt)).toFixed(1) + ' ' + (cy + rr * Math.sin(tt)).toFixed(1); }
      return '<ellipse cx="60" cy="104" rx="30" ry="5" fill="' + M.deep + '" opacity=".35"/>' +
        '<path d="M48 104 L52 92 L68 92 L72 104 Z" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1"/>' +
        '<path d="' + full + '" fill="' + M.dark + '" stroke="' + M.deep + '" stroke-width="1.4"/>' +
        '<path d="' + d + '" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1.4" stroke-linejoin="round"/>' + septa +
        '<path d="' + core + '" fill="none" stroke="' + M.deep + '" stroke-width="1.4"/>' +
        '<circle cx="' + cx + '" cy="' + cy + '" r="3" fill="' + M.deep + '"/>';
    },
    /* La Loupe (The Irregulars): the detective's magnifying glass, a wooden handle and its ferrule. */
    loupe: function (M, g, locked) {
      var lens = locked ? 'currentColor' : '#BFD9E6', wood = locked ? 'currentColor' : '#5A3A28', woodD = locked ? 'currentColor' : '#3E271A';
      return '<g transform="rotate(-40 60 60)">' +
        '<rect x="54" y="72" width="12" height="40" rx="5" fill="' + wood + '" stroke="' + woodD + '" stroke-width="1"/>' +
        '<line x1="57" y1="80" x2="57" y2="106" stroke="' + woodD + '" stroke-width="1" opacity=".6"/>' +
        '<rect x="52" y="64" width="16" height="10" rx="2" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1"/>' +
        '<line x1="52" y1="69" x2="68" y2="69" stroke="' + M.dark + '" stroke-width="1"/>' +
        '</g>' +
        '<circle cx="50" cy="50" r="30" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1.4"/>' +
        '<circle cx="50" cy="50" r="23" fill="' + lens + '" opacity="' + (locked ? 1 : .55) + '" stroke="' + M.dark + '" stroke-width="1.2"/>' +
        (locked ? '' : '<path d="M36 42 Q40 32 52 30" stroke="#fff" stroke-width="3" fill="none" stroke-linecap="round" opacity=".85"/><circle cx="62" cy="62" r="2" fill="#fff" opacity=".6"/>') +
        '<circle cx="50" cy="50" r="27" fill="none" stroke="' + M.light + '" stroke-width=".8" opacity=".8"/>';
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
    if (gameId === 'pompei') s = villaEmblem(idx, P);
    if (gameId === 'abysse') s = abyssEmblem(idx, P);
    if (gameId === 'irregulars') s = londonEmblem(idx, P);
    return s;
  }
  /* The fresco of a room of the villa, inside the card's red panel (x 9-111, y 22-118): one
     drawing per room, the Lararium's shrine for the finale. */
  function villaEmblem(idx, P) {
    var c = '#EFE3CB', g = P.accent, d = '#2E1D14';
    var frame = '<rect x="15" y="28" width="90" height="84" fill="none" stroke="' + g + '" stroke-width="1"/>';
    var column = function (x, y, h) { return '<rect x="' + (x - 3) + '" y="' + y + '" width="6" height="' + h + '" fill="' + c + '"/><rect x="' + (x - 5) + '" y="' + (y - 3) + '" width="10" height="3" fill="' + c + '"/><rect x="' + (x - 5) + '" y="' + (y + h) + '" width="10" height="3" fill="' + c + '"/>'; };
    var waves = '<path d="M28 102 q8 -7 16 0 t16 0 t16 0 t16 0" fill="none" stroke="' + g + '" stroke-width="2.4"/>';
    switch (idx) {
      case 0: // atrium: the impluvium under the open roof, two columns
        return frame + column(34, 46, 44) + column(86, 46, 44) + '<path d="M26 44 L60 34 L94 44 Z" fill="' + g + '"/><rect x="44" y="78" width="32" height="12" fill="#5B8FA3" stroke="' + c + '" stroke-width="1.6"/>';
      case 1: // tablinum: the archive table and its tablets
        return frame + '<rect x="32" y="74" width="56" height="5" fill="' + c + '"/><rect x="36" y="79" width="4" height="22" fill="' + c + '"/><rect x="80" y="79" width="4" height="22" fill="' + c + '"/>' +
          '<rect x="38" y="50" width="20" height="16" rx="1.5" fill="' + d + '" stroke="' + g + '" stroke-width="1.2"/><rect x="62" y="50" width="20" height="16" rx="1.5" fill="' + d + '" stroke="' + g + '" stroke-width="1.2"/>' +
          '<path d="M42 56 H54 M42 60 H52 M66 56 H78 M66 60 H76" stroke="' + c + '" stroke-width="1"/>';
      case 2: // triclinium: the couches round the table, an amphora
        return frame + '<path d="M26 92 L26 66 L36 66 L36 84 L84 84 L84 66 L94 66 L94 92 Z" fill="' + c + '"/><circle cx="60" cy="74" r="7" fill="' + g + '"/>' +
          '<path d="M56 40 Q50 48 54 60 Q60 66 66 60 Q70 48 64 40 Z" fill="' + g + '"/><path d="M57 40 h6" stroke="' + c + '" stroke-width="2"/>';
      case 3: // peristylium: the colonnade round the garden
        return frame + column(28, 44, 50) + column(46, 44, 50) + column(74, 44, 50) + column(92, 44, 50) + '<rect x="20" y="38" width="80" height="4" fill="' + g + '"/><circle cx="60" cy="86" r="8" fill="#6E8B4E"/>';
      case 4: // thermae: the vault and the pool
        return frame + '<path d="M28 96 V62 Q60 30 92 62 V96" fill="none" stroke="' + c + '" stroke-width="4"/>' + waves + '<path d="M28 88 q8 -7 16 0 t16 0 t16 0 t16 0" fill="none" stroke="#5B8FA3" stroke-width="2.4"/>';
      case 5: // hortus: a tree and the fountain
        return frame + '<rect x="57" y="66" width="6" height="28" fill="' + c + '"/><circle cx="60" cy="56" r="18" fill="#6E8B4E"/><circle cx="50" cy="62" r="10" fill="#6E8B4E"/><circle cx="70" cy="62" r="10" fill="#6E8B4E"/>' +
          '<path d="M34 100 H86" stroke="' + c + '" stroke-width="3"/>';
      default: // the Lararium's shrine
        return frame + '<path d="M30 54 L60 36 L90 54 Z" fill="' + g + '"/>' + column(40, 58, 30) + column(80, 58, 30) +
          '<rect x="50" y="70" width="20" height="18" fill="' + c + '"/><rect x="46" y="66" width="28" height="4" fill="' + g + '"/>' + waves;
    }
  }
  /* A zone of Station Abysse seen through the porthole: the reef's fish, the trench's double
     helix, the hot spring's smoker, the plateau's jellyfish, the ridge's two populations; the
     nautilus for the ascent. */
  function abyssEmblem(idx, P) {
    var a = P.accent, bg = P.bg, s = '<circle cx="60" cy="66" r="40" fill="none" stroke="' + a + '" stroke-width="1" opacity=".5"/>';
    switch (idx) {
      case 0:
        s += '<path d="M30 64 Q50 44 78 62 L92 52 L90 76 L78 66 Q50 86 30 64 Z" fill="' + a + '" opacity=".85"/><circle cx="40" cy="62" r="2.4" fill="' + bg + '"/>';
        break;
      case 1:
        for (var k = 0; k < 7; k++) {
          var y = 38 + k * 8;
          s += '<line x1="' + (60 - 16 * Math.cos(k * .9)).toFixed(1) + '" y1="' + y + '" x2="' + (60 + 16 * Math.cos(k * .9)).toFixed(1) + '" y2="' + y + '" stroke="' + a + '" stroke-width="1.6" opacity=".8"/>';
        }
        s += '<path d="M44 36 Q76 52 44 68 Q76 84 44 100" fill="none" stroke="' + a + '" stroke-width="2.4"/><path d="M76 36 Q44 52 76 68 Q44 84 76 100" fill="none" stroke="' + a + '" stroke-width="2.4"/>';
        break;
      case 2:
        s += '<path d="M48 100 L54 64 L66 64 L72 100 Z" fill="' + a + '" opacity=".7"/><path d="M60 62 q-12 -10 -4 -20 q8 -8 -2 -16" fill="none" stroke="' + a + '" stroke-width="5" opacity=".45" stroke-linecap="round"/>';
        break;
      case 3:
        s += '<path d="M36 64 Q36 38 60 38 Q84 38 84 64 Z" fill="' + a + '" opacity=".75"/>';
        for (var t = 0; t < 6; t++) s += '<path d="M' + (40 + t * 8) + ' 64 q4 12 -2 26" stroke="' + a + '" stroke-width="1.6" fill="none" opacity=".8"/>';
        break;
      case 4:
        s += '<path d="M60 30 L60 102" stroke="' + a + '" stroke-width="1.4" stroke-dasharray="3 3"/>' +
          '<path d="M30 58 q8 -8 18 0 q-8 8 -18 0 Z M30 76 q8 -8 18 0 q-8 8 -18 0 Z" fill="' + a + '" opacity=".85"/>' +
          '<path d="M72 58 q8 -8 18 0 q-8 8 -18 0 Z M72 76 q8 -8 18 0 q-8 8 -18 0 Z" fill="' + a + '" opacity=".5"/>';
        break;
      default:
        s += '<path d="M40 92 Q30 70 46 54 Q62 40 76 50 Q90 62 80 80" fill="none" stroke="' + a + '" stroke-width="5" stroke-linecap="round"/><circle cx="60" cy="68" r="6" fill="' + a + '" opacity=".6"/>';
    }
    return s + '<circle cx="34" cy="96" r="1.6" fill="' + a + '" opacity=".6"/><circle cx="88" cy="40" r="1.2" fill="' + a + '" opacity=".6"/>';
  }
  /* A place of The Irregulars' London: the docks' crane, the market's arcade, the Lyceum's
     portico, the museum's vase, Paddington's clock; the magnifying glass on the roofs for the
     final case. */
  function londonEmblem(idx, P) {
    var a = P.accent, ink = P.ink;
    var roofs = '<path d="M20 100 L20 84 L30 84 L30 76 L38 76 L38 84 L48 84 L48 72 L56 66 L64 72 L64 84 L74 84 L74 62 L80 56 L86 62 L86 84 L100 84 L100 100 Z" fill="' + a + '" opacity=".3"/>';
    switch (idx) {
      case 0:
        return roofs + '<path d="M44 96 L44 40 L84 40 M44 48 L76 40 M78 40 L78 58" stroke="' + ink + '" stroke-width="2.4" fill="none"/><rect x="72" y="58" width="12" height="10" fill="' + ink + '"/><path d="M28 104 q8 -5 16 0 t16 0 t16 0 t16 0" stroke="' + a + '" stroke-width="2" fill="none"/>';
      case 1:
        return roofs + '<path d="M28 96 V60 Q38 46 48 60 V96 M48 60 Q58 46 68 60 V96 M68 60 Q78 46 88 60 V96" stroke="' + ink + '" stroke-width="2.4" fill="none"/><path d="M26 58 H90" stroke="' + ink + '" stroke-width="3"/>';
      case 2:
        return roofs + '<path d="M30 52 L60 36 L90 52 Z" fill="' + ink + '"/><rect x="34" y="54" width="6" height="40" fill="' + ink + '"/><rect x="50" y="54" width="6" height="40" fill="' + ink + '"/><rect x="64" y="54" width="6" height="40" fill="' + ink + '"/><rect x="80" y="54" width="6" height="40" fill="' + ink + '"/>';
      case 3:
        return roofs + '<path d="M50 42 h20 v6 q12 8 10 24 q-2 16 -20 20 q-18 -4 -20 -20 q-2 -16 10 -24 Z" fill="' + ink + '"/><path d="M44 66 h32" stroke="' + a + '" stroke-width="2"/>';
      case 4:
        return roofs + '<circle cx="60" cy="62" r="22" fill="none" stroke="' + ink + '" stroke-width="3"/><path d="M60 62 V48 M60 62 L70 68" stroke="' + ink + '" stroke-width="2.4" stroke-linecap="round"/><circle cx="60" cy="62" r="2" fill="' + ink + '"/>';
      default:
        return roofs + '<circle cx="60" cy="62" r="13" fill="none" stroke="' + ink + '" stroke-width="3"/><line x1="69" y1="71" x2="80" y2="82" stroke="' + ink + '" stroke-width="4" stroke-linecap="round"/>';
    }
  }
  var ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'];
  /* A card of Les Tablettes de Pompéi: a cream tablet, its fresco on a red panel, the room's name in
     capitals and its Roman numeral. */
  function villaCard(c, idx, P, frame) {
    var name = c.name.replace(/^(L'|L’|Le |La |Les )/, ''), fs = Math.min(12, 150 / Math.max(6, name.length));
    var finale = c.finale;
    return '<rect x="2" y="2" width="116" height="164" rx="7" fill="' + (finale ? '#2E1D14' : P.bg) + '" stroke="' + frame + '" stroke-width="' + (finale ? 3 : 1.6) + '"/>' +
      '<rect x="9" y="12" width="102" height="104" fill="' + P.paper + '"/><rect x="9" y="108" width="102" height="8" fill="#2E1D14"/>' +
      '<g transform="translate(0 -14)">' + villaEmblem(idx, P) + '</g>' +
      '<text x="60" y="137" text-anchor="middle" font-size="' + fs.toFixed(1) + '" font-weight="600" letter-spacing="1.2" font-family="' + P.font + '" fill="' + (finale ? P.accent : P.ink) + '">' + esc(name.toUpperCase()) + '</text>' +
      '<text x="60" y="152" text-anchor="middle" font-size="7" letter-spacing="2.4" font-family="\'IBM Plex Mono\', monospace" fill="' + (finale ? '#EFE3CB' : P.ink) + '" opacity=".75">' + ROMAN[idx] + '</text>';
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
    var inner = c.game === 'pompei' ? villaCard(c, idx, P, frame) : '<rect x="2" y="2" width="116" height="164" rx="9" fill="' + P.bg + '" stroke="' + frame + '" stroke-width="' + (c.finale ? 3 : 1.5) + '"/>' +
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
    'pin-etoile': function (c) { return '<path d="M30 14 Q31.5 28.5 46 30 Q31.5 31.5 30 46 Q28.5 31.5 14 30 Q28.5 28.5 30 14 Z" fill="' + c + '"/><circle cx="41" cy="19" r="1.6" fill="' + c + '"/>'; },
    // The dog of the mosaic at the door of the House of the Tragic Poet, running, its red collar.
    'pin-vague': function (c) { return '<path d="M14 26 Q20 20 26 26 T38 26 T48 26 M14 35 Q20 29 26 35 T38 35 T48 35" stroke="' + c + '" stroke-width="2.6" fill="none" stroke-linecap="round"/>'; },
    'pin-loupe': function (c) { return '<circle cx="27" cy="27" r="9" fill="none" stroke="' + c + '" stroke-width="3"/><line x1="34" y1="34" x2="43" y2="43" stroke="' + c + '" stroke-width="4" stroke-linecap="round"/>'; },
    'pin-cave-canem': function (c, accent) {
      return '<path d="M13 31.5 L17.5 28.6 L27 28 L34.5 27.4 L39.6 22.6 L43.4 21.2 L46.8 23.4 L45.8 26 L42.6 26.8 L41.2 30.8 L44.2 36.4 L42.2 37.4 L38.4 32.6 L30.6 33.6 L26.4 38.2 L24.2 37.6 L26.2 33.2 L20.4 34 L16.4 38.6 L14.4 37.6 L17.6 33.2 Z" fill="' + c + '"/>' +
        '<path d="M13.5 31.4 L9 27" stroke="' + c + '" stroke-width="2" stroke-linecap="round"/>' +
        (accent ? '<path d="M38.8 24.4 L41.6 29.4" stroke="' + accent + '" stroke-width="2.2" stroke-linecap="round"/>' : '');
    }
  };
  function pin(id, o) {
    var p = PINS.filter(function (x) { return x.id === id; })[0];
    var locked = !!o.locked, M = METALS.simple, g = nid('pg');
    var enamel = p ? p.color : '#1B2550';
    var body = locked
      ? '<circle cx="30" cy="30" r="25" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="3 3" opacity=".45"/><g opacity=".3">' + (PIN_GLYPH[id] ? PIN_GLYPH[id]('currentColor') : '') + '</g>'
      : '<defs>' + metalGrad(g, M) + '</defs><circle cx="30" cy="32" r="25" fill="#000" opacity=".18"/><circle cx="30" cy="30" r="25" fill="url(#' + g + ')"/>' +
        '<circle cx="30" cy="30" r="20.5" fill="' + enamel + '"/>' +
        (p && p.ring ? '<circle cx="30" cy="30" r="17" fill="none" stroke="' + p.ring + '" stroke-width="1.4" stroke-dasharray="2.2 1.8"/>' : '') +
        '<path d="M14 24 Q20 12 34 11" stroke="#fff" stroke-width="2" fill="none" opacity=".35" stroke-linecap="round"/>' +
        (PIN_GLYPH[id] ? PIN_GLYPH[id](p && p.ink ? p.ink : '#F5E6C0', p && p.ring) : '');
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
