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
    irregulars: { bg: '#142820', paper: '#1F3A2E', ink: '#E9D8A6', accent: '#C9A04E', font: "'Libre Caslon Display', Georgia, serif", italic: false },
    'neko-con': { bg: '#241F3A', paper: '#3B3266', ink: '#FBF9FF', accent: '#FF6F91', font: "'Trebuchet MS', 'Source Sans 3', sans-serif", italic: false },
    blackout: { bg: '#0A0618', paper: '#151029', ink: '#F4F2FF', accent: '#FF2E88', font: "'Chakra Petch', 'Trebuchet MS', sans-serif", italic: false }
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
      finale: { id: 'final-case', name: 'The Final Case', topic: 'Toutes les affaires' } },
    // Panique à la Neko-Con: a card per act (design/validated/serious-games-neko-con.md §4); the
    // last ticket belongs to the last act, so no finale is ever granted.
    'neko-con': { title: 'Panique à la Neko-Con', chapters: [
      { id: 'acte1', name: 'Le site brut', topic: 'HTML 01' },
      { id: 'acte2', name: "La relecture d'Inès", topic: 'HTML 02' },
      { id: 'acte3', name: 'Le planning', topic: 'HTML 03' },
      { id: 'acte4', name: 'Les premières inscriptions', topic: 'HTML 04' },
      { id: 'acte5', name: 'La bascule', topic: 'CSS 01' },
      { id: 'acte6', name: "L'avalanche", topic: 'CSS 02 à 05' },
      { id: 'acte7', name: 'Sur mon téléphone…', topic: 'CSS 06' },
      { id: 'acte8', name: "J'avais oublié…", topic: 'CSS 07' },
      { id: 'acte9', name: 'Les coulisses', topic: 'Mise en ligne' }],
      finale: { id: 'none', name: '', topic: '' } },
    // Black-out: a card per act (design/validated/serious-games-blackout.md §5), like Neko-Con.
    blackout: { title: 'Black-out', chapters: [
      { id: 'acte1', name: 'Le local technique', topic: 'Interfaces et adressage' },
      { id: 'acte2', name: 'Un VLAN par usage', topic: 'VLAN, trunk et STP' },
      { id: 'acte3', name: 'Les étages se parlent', topic: 'Routage' },
      { id: 'acte4', name: 'Les adresses tombent du ciel', topic: 'DHCP, relais et DNS' },
      { id: 'acte5', name: 'Ouverture sur Internet', topic: 'NAT, DMZ et certificats' },
      { id: 'acte6', name: 'Le pare-feu', topic: 'nftables' },
      { id: 'acte7', name: 'Les invités et les absents', topic: 'Wi-Fi, RADIUS et WireGuard' },
      { id: 'acte8', name: 'Le Ghost frappe', topic: 'DHCP pirate et SSH' },
      { id: 'acte9', name: "L'incident", topic: "Gestion d'incident" }],
      finale: { id: 'none', name: '', topic: '' } }
  };
  var PINS = [
    { id: 'pin-etoile', name: 'Épinglette Étoile', color: '#1B2550' },
    { id: 'pin-cave-canem', name: 'Épinglette Cave canem', color: '#EFE3CB', ink: '#1E1410', ring: '#9E2B25' },
    { id: 'pin-vague', name: 'Épinglette Abysse', color: '#167784' },
    { id: 'pin-loupe', name: 'Épinglette Loupe', color: '#1F3A2E', ink: '#E9D8A6' },
    { id: 'pin-neko', name: 'Épinglette Neko', color: '#FBF9FF', ink: '#E8457A' },
    { id: 'pin-ghost', name: 'Épinglette Ghost', color: '#0A0618', ink: '#F4F2FF', ring: '#FF2E88' }
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
    // Panique à la Neko-Con: the laminated staff badge on its lanyard, « STAFF · Neko-Con », the
    // metal on its clip and frame.
    'badge-staff': function (M, g, locked) {
      return '<path d="M38 6 Q48 30 54 40 M82 6 Q72 30 66 40" fill="none" stroke="#E8457A" stroke-width="5" stroke-linecap="round"/>' +
        '<rect x="50" y="36" width="20" height="12" rx="3" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1.2"/>' +
        '<rect x="24" y="46" width="72" height="66" rx="7" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1.4"/>' +
        '<rect x="29" y="51" width="62" height="56" rx="4" fill="' + (locked ? 'currentColor' : '#FBF9FF') + '"/>' +
        '<rect x="29" y="51" width="62" height="15" rx="4" fill="' + (locked ? 'currentColor' : '#E8457A') + '"/>' +
        '<text x="60" y="62" text-anchor="middle" font-size="10" font-weight="700" letter-spacing="2" font-family="\'Trebuchet MS\', sans-serif" fill="#FBF9FF">STAFF</text>' +
        '<path d="M50 84 L47 72 L55 79 Z M70 84 L73 72 L65 79 Z" fill="#2A2440"/><ellipse cx="60" cy="86" rx="12" ry="9" fill="#2A2440"/>' +
        '<path d="M54 85 Q56 82 58 85 M62 85 Q64 82 66 85" fill="none" stroke="#FBF9FF" stroke-width="1.4" stroke-linecap="round"/>' +
        '<text x="60" y="103" text-anchor="middle" font-size="7.5" font-family="\'Trebuchet MS\', sans-serif" fill="#3B3266">Neko-Con</text>';
    },
    // Black-out: Nadia's RJ45 tester, its two boxes and their eight LEDs, the cable between them.
    'testeur-cable': function (M, g, locked) {
      var led = function (x, y, on) { var o = ''; for (var i = 0; i < 8; i++) o += '<circle cx="' + (x + (i % 4) * 7) + '" cy="' + (y + Math.floor(i / 4) * 7) + '" r="2.2" fill="' + (locked ? 'currentColor' : (on ? '#C6FF3D' : '#3A3358')) + '"/>'; return o; };
      var screen = locked ? 'currentColor' : '#0A0618';
      return '<path d="M38 92 Q38 110 60 110 Q82 110 82 92" fill="none" stroke="' + (locked ? 'currentColor' : '#00E5FF') + '" stroke-width="4" stroke-linecap="round"/>' +
        '<rect x="12" y="22" width="44" height="72" rx="8" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1.4"/>' +
        '<rect x="18" y="30" width="32" height="30" rx="3" fill="' + screen + '"/>' + led(23.5, 38, true) +
        '<rect x="28" y="66" width="12" height="10" rx="2" fill="' + M.dark + '"/><text x="34" y="88" text-anchor="middle" font-size="6.5" font-weight="700" font-family="\'Chakra Petch\', sans-serif" fill="' + M.deep + '">MAIN</text>' +
        '<rect x="64" y="34" width="44" height="60" rx="8" fill="url(#' + g + ')" stroke="' + M.deep + '" stroke-width="1.4"/>' +
        '<rect x="70" y="42" width="32" height="22" rx="3" fill="' + screen + '"/>' + led(75.5, 46, true) +
        '<rect x="80" y="70" width="12" height="10" rx="2" fill="' + M.dark + '"/><text x="86" y="89" text-anchor="middle" font-size="6.5" font-weight="700" font-family="\'Chakra Petch\', sans-serif" fill="' + M.deep + '">REMOTE</text>';
    },
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
    if (gameId === 'neko-con') s = nekoEmblem(idx, P);
    if (gameId === 'blackout') s = blackoutEmblem(idx, P);
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
  /* A card of Panique à la Neko-Con: the act's tool or symptom, drawn like the game's own screens -
     a browser window on the card's purple, the club's pink and turquoise. */
  function nekoEmblem(idx, P) {
    var pink = '#FF6F91', teal = '#7FD8D6', gold = '#FFD166', ink = P.ink;
    var win = '<rect x="22" y="34" width="76" height="64" rx="5" fill="#241F3A" stroke="' + ink + '" stroke-width="1.4"/><rect x="22" y="34" width="76" height="10" rx="5" fill="' + ink + '" opacity=".2"/>' +
      '<circle cx="29" cy="39" r="1.8" fill="' + pink + '"/><circle cx="35" cy="39" r="1.8" fill="' + gold + '"/><circle cx="41" cy="39" r="1.8" fill="' + teal + '"/>';
    var code = function (y, w, c) { return '<rect x="30" y="' + y + '" width="' + w + '" height="3" rx="1.5" fill="' + (c || ink) + '" opacity=".75"/>'; };
    switch (idx) {
      case 0: // le site brut: the tags, plain
        return win + '<text x="60" y="78" text-anchor="middle" font-size="18" font-family="\'Fira Code\', monospace" fill="' + teal + '">&lt;/&gt;</text>';
      case 1: // la relecture d'Inès: the screen reader's speech
        return win + '<path d="M40 60 h10 l8 -7 v24 l-8 -7 h-10 Z" fill="' + teal + '"/><path d="M64 58 q6 7 0 14 M70 54 q10 11 0 22" fill="none" stroke="' + pink + '" stroke-width="2.4" stroke-linecap="round"/>';
      case 2: // le planning: the table
        return win + '<g stroke="' + ink + '" stroke-width="1" opacity=".8"><rect x="32" y="52" width="56" height="38" fill="none"/><line x1="32" y1="62" x2="88" y2="62"/><line x1="32" y1="72" x2="88" y2="72"/><line x1="32" y1="81" x2="88" y2="81"/><line x1="51" y1="52" x2="51" y2="90"/><line x1="70" y1="52" x2="70" y2="90"/></g><rect x="52" y="63" width="17" height="17" fill="' + pink + '" opacity=".8"/>';
      case 3: // les inscriptions: the form
        return win + code(54, 22) + '<rect x="30" y="60" width="60" height="8" rx="2" fill="none" stroke="' + teal + '" stroke-width="1.4"/>' + code(73, 18) + '<rect x="30" y="79" width="8" height="8" rx="1.5" fill="none" stroke="' + teal + '" stroke-width="1.4"/><path d="M31.5 83 l2 2 l3 -4" fill="none" stroke="' + gold + '" stroke-width="1.6"/><rect x="66" y="80" width="24" height="9" rx="4" fill="' + pink + '"/>';
      case 4: // la bascule: the stylesheet switched on, colours at last
        return win + '<rect x="23" y="45" width="37" height="52" fill="#FBF9FF"/><rect x="60" y="45" width="37" height="52" fill="' + pink + '" opacity=".85"/><path d="M60 50 L54 66 h8 L56 90" fill="none" stroke="' + gold + '" stroke-width="3" stroke-linejoin="round"/>';
      case 5: // l'avalanche: the boxes of the box model, nested
        return win + '<rect x="32" y="50" width="56" height="42" fill="none" stroke="' + gold + '" stroke-width="1.6" stroke-dasharray="3 2"/><rect x="38" y="56" width="44" height="30" fill="none" stroke="' + pink + '" stroke-width="2"/><rect x="45" y="63" width="30" height="16" fill="' + teal + '" opacity=".8"/>';
      case 6: // sur mon téléphone: the phone
        return '<rect x="42" y="30" width="36" height="70" rx="6" fill="#241F3A" stroke="' + ink + '" stroke-width="1.6"/><rect x="46" y="38" width="28" height="52" rx="2" fill="' + P.paper + '"/>' +
          '<rect x="49" y="42" width="22" height="6" rx="1" fill="' + pink + '"/><rect x="49" y="51" width="22" height="14" rx="1" fill="' + teal + '" opacity=".8"/><rect x="49" y="68" width="16" height="3" rx="1.5" fill="' + ink + '" opacity=".7"/><rect x="49" y="74" width="20" height="3" rx="1.5" fill="' + ink + '" opacity=".7"/><circle cx="60" cy="95" r="2" fill="' + ink + '"/>';
      case 7: // j'avais oublié: the grid of the stands
        return win + '<g fill="' + teal + '" opacity=".85"><rect x="30" y="50" width="26" height="18" rx="2"/><rect x="60" y="50" width="28" height="18" rx="2" fill="' + pink + '"/><rect x="30" y="71" width="16" height="20" rx="2" fill="' + gold + '"/><rect x="49" y="71" width="39" height="20" rx="2"/></g>';
      default: // les coulisses: the lock opened by the five pieces
        return '<rect x="40" y="58" width="40" height="34" rx="5" fill="' + gold + '"/><path d="M48 58 V48 Q48 36 60 36 Q72 36 72 48" fill="none" stroke="' + ink + '" stroke-width="4" stroke-linecap="round" transform="rotate(-18 72 50)"/>' +
          '<circle cx="60" cy="72" r="4" fill="#241F3A"/><rect x="58.5" y="74" width="3" height="9" fill="#241F3A"/>' +
          '<g fill="' + pink + '"><circle cx="32" cy="102" r="2.4"/><circle cx="46" cy="102" r="2.4"/><circle cx="60" cy="102" r="2.4"/><circle cx="74" cy="102" r="2.4"/><circle cx="88" cy="102" r="2.4"/></g>';
    }
  }
  /* A card of Black-out: the act's piece of the network, drawn like the game's map of the room - a
     dark control room, the stream's magenta and cyan, the VLANs' colours. */
  function blackoutEmblem(idx, P) {
    var mag = '#FF2E88', cyan = '#00E5FF', lime = '#C6FF3D', ink = P.ink, dark = '#0A0618';
    var V = ['#4D8BFF', '#B26BFF', '#FF9F43', '#FFD23F', '#3DDBB0'];
    var box = function (x, y, w, hh, c) { return '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + hh + '" rx="3" fill="' + dark + '" stroke="' + (c || ink) + '" stroke-width="1.6"/>'; };
    var leds = function (x, y, n, cols) { var o = ''; for (var i = 0; i < n; i++) o += '<rect x="' + (x + i * 8) + '" y="' + y + '" width="5" height="4" rx="1" fill="' + (cols ? cols[i % cols.length] : lime) + '"/>'; return o; };
    var wire = function (d, c) { return '<path d="' + d + '" fill="none" stroke="' + (c || cyan) + '" stroke-width="2" stroke-linecap="round"/>'; };
    switch (idx) {
      case 0: // le local technique: the router in its rack, one port still dark
        return box(26, 40, 68, 16) + leds(32, 46, 6) + '<rect x="80" y="46" width="5" height="4" rx="1" fill="' + mag + '"/>' +
          box(26, 62, 68, 16) + leds(32, 68, 7) + box(26, 84, 68, 16) + '<text x="60" y="95" text-anchor="middle" font-size="8" font-family="\'IBM Plex Mono\', monospace" fill="' + cyan + '">r1 · ens19</text>';
      case 1: // un VLAN par usage: the switch, its ports in the VLANs' colours, the trunk
        return box(20, 56, 80, 22) + leds(27, 64, 9, V) + wire('M60 56 V36', mag) + '<circle cx="60" cy="34" r="4" fill="' + mag + '"/>' +
          wire('M30 78 V98', V[0]) + wire('M50 78 V98', V[1]) + wire('M70 78 V98', V[2]) + wire('M90 78 V98', V[4]);
      case 2: // les étages se parlent: two networks and the router between them
        return '<circle cx="34" cy="52" r="14" fill="none" stroke="' + V[0] + '" stroke-width="2"/><circle cx="86" cy="52" r="14" fill="none" stroke="' + V[2] + '" stroke-width="2"/>' +
          box(48, 80, 24, 18, cyan) + wire('M40 64 L54 80') + wire('M80 64 L66 80') + '<path d="M56 89 h8 m-3 -3 l3 3 l-3 3" fill="none" stroke="' + lime + '" stroke-width="1.6"/>';
      case 3: // les adresses tombent du ciel: a lease coming down to a PC
        return '<path d="M38 50 a14 14 0 0 1 26 -8 a10 10 0 0 1 18 8 z" fill="none" stroke="' + ink + '" stroke-width="1.6"/>' +
          '<rect x="38" y="58" width="44" height="12" rx="2" fill="' + lime + '"/><text x="60" y="67" text-anchor="middle" font-size="6.5" font-family="\'IBM Plex Mono\', monospace" fill="' + dark + '">10.20.30.x</text>' +
          wire('M60 72 V82', lime) + box(44, 84, 32, 18, cyan) + '<rect x="56" y="102" width="8" height="5" fill="' + ink + '" opacity=".7"/>';
      case 4: // ouverture sur Internet: the globe, the box's one open door
        return '<circle cx="60" cy="50" r="18" fill="none" stroke="' + cyan + '" stroke-width="1.8"/><ellipse cx="60" cy="50" rx="8" ry="18" fill="none" stroke="' + cyan + '" stroke-width="1.2"/><line x1="42" y1="50" x2="78" y2="50" stroke="' + cyan + '" stroke-width="1.2"/>' +
          box(34, 82, 52, 18) + '<rect x="56" y="86" width="8" height="10" fill="' + lime + '"/>' + wire('M60 68 V82', mag) + '<text x="60" y="112" text-anchor="middle" font-size="7" font-family="\'IBM Plex Mono\', monospace" fill="' + ink + '" opacity=".8">:443</text>';
      case 5: // le pare-feu: the wall, one packet let through, one dropped
        var bricks = '';
        for (var r = 0; r < 5; r++) for (var c = 0; c < 3; c++) bricks += '<rect x="' + (46 + c * 10 - (r % 2) * 5) + '" y="' + (38 + r * 12) + '" width="9" height="10" fill="' + (r % 2 ? '#3A3358' : '#2A2342') + '" stroke="' + mag + '" stroke-width=".8"/>';
        return bricks + wire('M22 56 H40', lime) + '<path d="M80 56 H98 m-4 -4 l4 4 l-4 4" fill="none" stroke="' + lime + '" stroke-width="2"/>' +
          wire('M22 84 H38', mag) + '<path d="M36 80 l6 8 m0 -8 l-6 8" stroke="' + mag + '" stroke-width="2"/>';
      case 6: // les invités et les absents: the Wi-Fi and the tunnel's padlock
        return '<g fill="none" stroke="' + cyan + '" stroke-width="2.4" stroke-linecap="round"><path d="M36 58 a34 34 0 0 1 48 0"/><path d="M43 66 a24 24 0 0 1 34 0"/><path d="M50 74 a14 14 0 0 1 20 0"/></g><circle cx="60" cy="80" r="3" fill="' + cyan + '"/>' +
          '<rect x="48" y="92" width="24" height="16" rx="3" fill="' + mag + '"/><path d="M53 92 v-5 a7 7 0 0 1 14 0 v5" fill="none" stroke="' + mag + '" stroke-width="2.4"/>';
      case 7: // le Ghost frappe: the ghost, split by the glitch of the stream
        return '<path d="M42 96 V58 a18 18 0 0 1 36 0 V96 l-6 -6 l-6 6 l-6 -6 l-6 6 l-6 -6 Z" fill="' + ink + '" opacity=".92"/>' +
          '<path d="M42 96 V58 a18 18 0 0 1 36 0 V96" fill="none" stroke="' + cyan + '" stroke-width="1.4" transform="translate(-3 0)" opacity=".8"/>' +
          '<path d="M42 96 V58 a18 18 0 0 1 36 0 V96" fill="none" stroke="' + mag + '" stroke-width="1.4" transform="translate(3 0)" opacity=".8"/>' +
          '<circle cx="53" cy="62" r="3.2" fill="' + dark + '"/><circle cx="67" cy="62" r="3.2" fill="' + dark + '"/><rect x="30" y="74" width="60" height="3" fill="' + mag + '" opacity=".6"/>';
      default: // l'incident: the sealed envelope and the night's timeline
        return '<rect x="34" y="40" width="52" height="34" rx="2" fill="' + dark + '" stroke="' + ink + '" stroke-width="1.6"/><path d="M34 40 L60 60 L86 40" fill="none" stroke="' + ink + '" stroke-width="1.6"/><circle cx="60" cy="62" r="5" fill="' + mag + '"/>' +
          '<line x1="26" y1="94" x2="94" y2="94" stroke="' + cyan + '" stroke-width="1.6"/>' +
          '<g fill="' + lime + '"><circle cx="30" cy="94" r="3"/><circle cx="46" cy="94" r="3"/><circle cx="60" cy="94" r="3"/><circle cx="74" cy="94" r="3"/><circle cx="90" cy="94" r="3"/></g>';
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
    // Panique à la Neko-Con: the head of Neko, the convention's cat mascot.
    'pin-neko': function (c) {
      return '<path d="M17 24 L19 12 L27 19 Z M43 24 L41 12 L33 19 Z" fill="' + c + '"/><ellipse cx="30" cy="31" rx="15" ry="12.5" fill="' + c + '"/>' +
        '<path d="M22.5 30 Q25 27 27.5 30 M32.5 30 Q35 27 37.5 30" fill="none" stroke="#2A2440" stroke-width="1.8" stroke-linecap="round"/><path d="M28.5 35 L30 36.5 L31.5 35" fill="none" stroke="#2A2440" stroke-width="1.4" stroke-linecap="round"/>';
    },
    // Black-out: the Ghost, the intruder of the night, on the stream's black.
    'pin-ghost': function (c) {
      return '<path d="M20 44 V27 a10 10 0 0 1 20 0 V44 l-3.4 -3.4 l-3.3 3.4 l-3.3 -3.4 l-3.3 3.4 l-3.3 -3.4 Z" fill="' + c + '"/>' +
        '<circle cx="26.5" cy="28" r="2" fill="#0A0618"/><circle cx="33.5" cy="28" r="2" fill="#0A0618"/>' +
        '<rect x="15" y="34" width="30" height="1.8" fill="#00E5FF" opacity=".85"/>';
    },
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
