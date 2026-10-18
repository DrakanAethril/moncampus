/* Station Abysse — the drawings (design/conception_serious_games/jeux.html, « Station Abysse »):
   the porthole of the station at 3 000 m, the biologist standing in a diving suit with the mask
   pushed up on the forehead, the sketch of a species at each level of the notebook, the sonar and
   its anomalies, the bathyscaphe of the ascent.

   A classic script that sets globalThis.ABYScene. Every function returns SVG markup as a string;
   nothing here reads or writes the game's state. Nothing depends on time: a sketch never fades. */
(function (root) {
  'use strict';

  var S = {};
  var C = { bg: '#031319', deep: '#021116', sea: '#062A35', sea2: '#0F4C5C', panel: '#04222A', ink: '#CFF7F1', dim: '#69B7AE', accent: '#7FF5E3', ok: '#7FF5A0', ko: '#FF8A7A', brass: '#C9973F', brassD: '#8C6A2E', brassDD: '#5E4520', lure: '#F7F3A6', suit: '#17252B', suit2: '#22343B', trim: '#3FD9C8', amber: '#E0A93B', paper: '#E9F4F1', pencil: '#5B6F73' };
  S.C = C;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  S.esc = esc;

  // the conception's seeded randomness, so the drawn plankton never moves between two renders
  function rng(seed) {
    var a = (seed >>> 0) || 1;
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      var t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  /* ---------- the sketch of a species, at each level ---------- */

  /* The creature drawn for a zone: the reef's fish, the trench's double helix, the hot spring's
     shrimp, the plateau's jellyfish, the ridge's crab. In a 40 × 30 box. */
  var CREATURE = {
    recif: 'M6 15 Q14 5 26 12 L34 6 L33 24 L26 18 Q14 25 6 15 Z',
    fosse: 'M12 3 Q28 9 12 15 Q28 21 12 27 M28 3 Q12 9 28 15 Q12 21 28 27',
    sources: 'M6 18 Q8 9 18 9 Q28 9 32 14 Q30 20 22 21 L14 21 Q9 21 6 18 Z M30 13 L37 7 M31 15 L38 12',
    plateau: 'M9 15 Q9 4 20 4 Q31 4 31 15 Z M13 15 q2 6 -1 12 M20 15 q2 6 -1 12 M27 15 q2 6 -1 12',
    dorsale: 'M9 17 Q9 10 20 10 Q31 10 31 17 Q31 22 20 22 Q9 22 9 17 Z M9 14 L4 9 L8 7 M31 14 L36 9 L32 7 M12 22 L9 27 M28 22 L31 27'
  };
  var OPEN = { fosse: true };

  /* A species as the notebook draws it: blank (never observed), tried, then the four levels of its
     best dive - esquissée (a pencil outline), annotée (outline and notes), coloriée (filled),
     encadrée (filled, in its frame). */
  S.sketch = function (zone, state, size) {
    var w = size || 34, h = Math.round(w * 0.75), d = CREATURE[zone] || CREATURE.recif, open = !!OPEN[zone];
    var body = '';
    var shape = function (stroke, width, fill, dash) {
      return '<path d="' + d + '" fill="' + (open ? 'none' : (fill || 'none')) + '" stroke="' + stroke + '" stroke-width="' + width + '" stroke-linejoin="round" stroke-linecap="round"' + (dash ? ' stroke-dasharray="' + dash + '"' : '') + '/>';
    };
    switch (state) {
      case 'discrete':
        body = '<rect x="1" y="1" width="38" height="28" rx="3" fill="' + C.paper + '"/>' + shape(C.pencil, 1.3);
        break;
      case 'clear':
        body = '<rect x="1" y="1" width="38" height="28" rx="3" fill="' + C.paper + '"/>' + shape('#24363B', 1.6) +
          '<path d="M24 26 L36 26 M28 23 L36 23" stroke="' + C.pencil + '" stroke-width="1"/>';
        break;
      case 'bright':
        body = '<rect x="1" y="1" width="38" height="28" rx="3" fill="' + C.paper + '"/>' + shape('#0B5F68', 1.6, '#57D6C6') +
          '<path d="M24 26 L36 26" stroke="' + C.pencil + '" stroke-width="1"/>';
        break;
      case 'blazing':
        body = '<rect x="1" y="1" width="38" height="28" rx="3" fill="' + C.paper + '" stroke="' + C.brass + '" stroke-width="2"/>' +
          '<rect x="3.5" y="3.5" width="33" height="23" rx="1.5" fill="none" stroke="' + C.brass + '" stroke-width=".7"/>' + shape('#0B5F68', 1.6, '#57D6C6');
        break;
      case 'tried':
        body = '<rect x="1" y="1" width="38" height="28" rx="3" fill="none" stroke="' + C.dim + '" stroke-width="1.2" stroke-dasharray="3 2"/>' + shape(C.dim, 1.1, null, '2 2');
        break;
      default:
        body = '<rect x="1" y="1" width="38" height="28" rx="3" fill="none" stroke="' + C.dim + '" stroke-width="1.2" stroke-dasharray="3 2" opacity=".7"/>';
    }
    return '<svg class="sk-ico" viewBox="0 0 40 30" width="' + w + '" height="' + h + '" aria-hidden="true" focusable="false">' + body + '</svg>';
  };

  /* ---------- the sonar and its anomalies ---------- */

  S.blip = function (size) {
    var s = size || 30;
    return '<svg viewBox="0 0 40 40" width="' + s + '" height="' + s + '" aria-hidden="true" focusable="false">' +
      '<circle cx="20" cy="20" r="17" fill="none" stroke="' + C.ko + '" stroke-width="1.6" stroke-dasharray="3 2.4"/>' +
      '<circle cx="20" cy="20" r="9" fill="none" stroke="' + C.ko + '" stroke-width="1.2" opacity=".6"/>' +
      '<circle cx="20" cy="20" r="4.5" fill="' + C.ko + '"/></svg>';
  };

  /* The sonar screen of the station: rings, the sweep, and one blip per anomaly in view. */
  S.sonar = function (n, solved) {
    var k = ['<svg viewBox="0 0 320 320" class="sonar-art" role="img" aria-label="' + (solved ? 'Le sonar : l’anomalie est identifiée' : 'Le sonar de la station : une anomalie') + '">'];
    k.push('<circle cx="160" cy="160" r="150" fill="' + C.panel + '" stroke="#1F6F7A" stroke-width="6"/>');
    [110, 70, 32].forEach(function (r) { k.push('<circle cx="160" cy="160" r="' + r + '" fill="none" stroke="#1F6F7A" stroke-width="2"/>'); });
    k.push('<path d="M160 10 V310 M10 160 H310" stroke="#1F6F7A" stroke-width="1.4" opacity=".7"/>');
    k.push('<path d="M160 160 L270 70 A142 142 0 0 1 300 160 Z" fill="' + C.accent + '" opacity=".12"/><line x1="160" y1="160" x2="270" y2="70" stroke="' + C.accent + '" stroke-width="3"/>');
    var r = rng(7);
    for (var i = 0; i < Math.max(1, n || 1); i++) {
      var a = r() * Math.PI * 2, d = 40 + r() * 90, x = 160 + d * Math.cos(a), y = 160 + d * Math.sin(a);
      if (solved) k.push('<circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="9" fill="none" stroke="' + C.ok + '" stroke-width="3"/><path d="M' + (x - 5).toFixed(1) + ' ' + y.toFixed(1) + ' l4 4 l7 -8" stroke="' + C.ok + '" stroke-width="2.6" fill="none"/>');
      else k.push('<circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="18" fill="' + C.ko + '" opacity=".18"/><circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="7" fill="' + C.ko + '"/>');
    }
    k.push('</svg>');
    return k.join('');
  };

  /* The bathyscaphe moored at the station, waiting for a full notebook - the ascent. */
  S.bathyscaphe = function (opts) {
    opts = opts || {};
    var k = ['<svg viewBox="0 0 400 420" class="bathy-art" role="img" aria-label="Le bathyscaphe de relève, amarré à la station">'];
    k.push('<defs><linearGradient id="aby-up" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1F6F7A"/><stop offset=".55" stop-color="' + C.sea + '"/><stop offset="1" stop-color="' + C.deep + '"/></linearGradient></defs>');
    k.push('<rect width="400" height="420" fill="url(#aby-up)"/>');
    var r = rng(17);
    for (var i = 0; i < 40; i++) k.push('<circle cx="' + (r() * 400).toFixed(0) + '" cy="' + (r() * 420).toFixed(0) + '" r="' + (r() * 1.8 + .5).toFixed(1) + '" fill="' + C.ink + '" opacity="' + (.2 + r() * .4).toFixed(2) + '"/>');
    k.push('<line x1="200" y1="0" x2="200" y2="120" stroke="' + C.dim + '" stroke-width="3" stroke-dasharray="6 6"/>');
    k.push('<rect x="110" y="120" width="180" height="40" rx="8" fill="' + C.amber + '"/><rect x="186" y="96" width="28" height="26" rx="4" fill="' + C.amber + '"/>');
    k.push('<ellipse cx="200" cy="230" rx="96" ry="78" fill="#D9D3C0" stroke="#8D8774" stroke-width="5"/>');
    k.push('<circle cx="200" cy="232" r="38" fill="' + C.deep + '" stroke="' + C.brass + '" stroke-width="8"/><circle cx="188" cy="220" r="8" fill="' + C.accent + '" opacity=".5"/>');
    k.push('<path d="M120 300 L100 340 M280 300 L300 340" stroke="#8D8774" stroke-width="6"/><rect x="80" y="338" width="240" height="10" rx="4" fill="#8D8774"/>');
    k.push('<circle cx="146" cy="196" r="5" fill="' + (opts.light ? C.lure : '#5A5A4E') + '"/><circle cx="254" cy="196" r="5" fill="' + (opts.light ? C.lure : '#5A5A4E') + '"/>');
    if (opts.light) k.push('<path d="M146 196 L40 120 L40 270 Z M254 196 L360 120 L360 270 Z" fill="' + C.lure + '" opacity=".08"/>');
    k.push('<rect y="380" width="400" height="40" fill="' + C.deep + '"/><path d="M0 380 Q60 360 120 378 Q200 400 280 372 Q340 360 400 380" fill="' + C.deep + '"/>');
    k.push('</svg>');
    return k.join('');
  };

  /* ---------- the biologist ---------- */

  /* The body, in the head's 0 0 200 200 box extended downwards (feet at y 452): a dark diving suit
     with teal seams, a chest instrument, fins. The collar opens under the neck of the head scaled by
     .7 and lifted by 16 (the neck shows from y 89 to 121). */
  function body(c, A) {
    var k = [];
    k.push('<ellipse cx="100" cy="452" rx="74" ry="8" fill="#000" opacity=".4"/>');
    // legs and fins
    k.push('<rect x="72" y="316" width="24" height="124" rx="6" fill="' + C.suit + '"/><rect x="104" y="316" width="24" height="124" rx="6" fill="' + C.suit + '"/>');
    k.push('<path d="M80 320 L80 434 M120 320 L120 434" stroke="' + C.trim + '" stroke-width="3"/>');
    k.push('<path d="M60 452 Q62 436 84 436 L96 436 L98 452 Z" fill="' + C.amber + '"/><path d="M102 452 L104 436 L116 436 Q138 436 140 452 Z" fill="' + C.amber + '"/>');
    // the suit
    k.push('<path d="M84 114 Q100 126 116 114 L150 128 Q160 140 160 170 L156 210 L146 210 L146 330 Q100 338 54 330 L54 210 L44 210 L40 170 Q40 140 50 128 Z" fill="' + C.suit + '" stroke="#0B1418" stroke-width="1.4" stroke-linejoin="round"/>');
    k.push('<path d="M84 116 Q100 128 116 116" stroke="' + C.trim + '" stroke-width="3" fill="none"/>');
    k.push('<path d="M62 150 L62 328 M138 150 L138 328" stroke="' + C.trim + '" stroke-width="4"/>');
    // the chest instrument: a small depth gauge
    k.push('<rect x="76" y="196" width="48" height="58" rx="8" fill="' + C.suit2 + '" stroke="' + C.trim + '" stroke-width="2"/>');
    k.push('<circle cx="100" cy="218" r="12" fill="' + C.deep + '" stroke="' + C.accent + '" stroke-width="1.6"/><line x1="100" y1="218" x2="107" y2="211" stroke="' + C.accent + '" stroke-width="2"/>');
    k.push('<rect x="86" y="238" width="28" height="8" rx="2" fill="' + C.accent + '" opacity=".6"/>');
    k.push('<rect x="52" y="262" width="96" height="9" rx="3" fill="#0B1418"/><rect x="92" y="260" width="16" height="13" rx="2" fill="' + C.brass + '"/>');
    // the arms
    k.push('<path d="M44 208 L40 290 Q38 302 48 304 L58 304 L58 210 Z" fill="' + C.suit + '"/><path d="M156 208 L160 290 Q162 302 152 304 L142 304 L142 210 Z" fill="' + C.suit + '"/>');
    k.push('<path d="M42 220 L40 296 M158 220 L160 296" stroke="' + C.trim + '" stroke-width="3"/>');
    k.push('<circle cx="49" cy="308" r="10.5" fill="' + C.suit + '"/><circle cx="151" cy="308" r="10.5" fill="' + C.suit + '"/>');
    // the waterproof notebook in the hand
    k.push('<g transform="rotate(-10 168 298)"><rect x="150" y="280" width="36" height="44" rx="3" fill="' + C.amber + '" stroke="#8C6A2E" stroke-width="1.2"/><rect x="155" y="286" width="26" height="32" fill="' + C.paper + '"/>' +
      '<path d="M158 294 H178 M158 300 H174 M158 306 H178" stroke="' + C.pencil + '" stroke-width="1.3"/></g>');
    k.push('<circle cx="151" cy="308" r="7" fill="' + C.suit + '"/>');
    return k.join('');
  }

  /* The biologist, standing: the shared avatar's bare head (shared/avatar.js) on the diving suit,
     the mask of Station Abysse pushed up on the forehead, the pin worn if any. */
  S.student = function (config) {
    var A = root.SGAvatar;
    if (!A) return '';
    var c = A.normalize(config || {});
    var head = A.parts(c, true) + (A.COSTUMES.abysse ? A.COSTUMES.abysse.svg() : '');
    var pin = c.pin && A.pinSvg ? A.pinSvg(c.pin, 128, 146, 8) : '';
    return body(c, A) + pin + '<g transform="translate(30 -16) scale(.7)">' + head + '</g>';
  };

  /* The station's observation room: the riveted porthole on the abyss - a jellyfish, the
     anglerfish and its lure, a black smoker - the sonar, the three depth lights, and the biologist.
     `depth` lights 0 to 3 of the lights. */
  S.scene = function (config, opts) {
    opts = opts || {};
    var r = rng(13), k = [];
    k.push('<defs><radialGradient id="ab-sea" cx=".45" cy=".4" r=".6"><stop offset="0" stop-color="' + C.sea2 + '"/><stop offset=".6" stop-color="' + C.sea + '"/><stop offset="1" stop-color="' + C.deep + '"/></radialGradient>' +
      '<radialGradient id="ab-glow" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="' + C.accent + '" stop-opacity=".8"/><stop offset="1" stop-color="' + C.accent + '" stop-opacity="0"/></radialGradient>' +
      '<clipPath id="ab-hub"><circle cx="300" cy="250" r="190"/></clipPath></defs>');
    k.push('<rect width="760" height="640" fill="#0A1A20"/>');
    for (var y = 0; y < 640; y += 110) k.push('<line x1="0" y1="' + y + '" x2="760" y2="' + y + '" stroke="#16323B" stroke-width="3"/>');
    for (var x = 0; x < 760; x += 140) k.push('<line x1="' + x + '" y1="0" x2="' + x + '" y2="640" stroke="#16323B" stroke-width="3"/>');
    // the porthole and what swims beyond it
    k.push('<g clip-path="url(#ab-hub)"><rect x="90" y="40" width="420" height="420" fill="url(#ab-sea)"/>');
    for (var i = 0; i < 60; i++) k.push('<circle cx="' + (110 + r() * 380).toFixed(0) + '" cy="' + (60 + r() * 380).toFixed(0) + '" r="' + (r() * 2 + .6).toFixed(1) + '" fill="' + C.ink + '" opacity="' + (.15 + r() * .4).toFixed(2) + '"/>');
    k.push('<circle cx="230" cy="170" r="54" fill="url(#ab-glow)" opacity=".5"/><path d="M196 172 Q230 120 264 172 Z" fill="' + C.accent + '" opacity=".55"/>');
    for (var t = 0; t < 6; t++) k.push('<path d="M' + (202 + t * 11) + ' 172 q6 34 -4 66 q-6 24 4 48" fill="none" stroke="' + C.accent + '" stroke-width="2" opacity=".45"/>');
    k.push('<path d="M310 330 q50 -42 110 -8 q-26 26 -68 28 q-26 2 -42 -20 Z" fill="#0B0F12"/><circle cx="430" cy="288" r="7" fill="' + C.lure + '"/><circle cx="430" cy="288" r="26" fill="url(#ab-glow)"/><path d="M394 306 q18 -26 36 -18" fill="none" stroke="#0B0F12" stroke-width="3"/><circle cx="374" cy="320" r="2.6" fill="' + C.lure + '"/>');
    k.push('<path d="M90 450 Q190 380 270 420 Q350 460 440 400 L510 450 L510 470 L90 470 Z" fill="#05181E"/><path d="M390 418 L404 344 L426 344 L432 410 Z" fill="#141414"/><path d="M404 344 q-8 -46 16 -92" fill="none" stroke="#3A3A3A" stroke-width="18" opacity=".7"/></g>');
    k.push('<circle cx="300" cy="250" r="190" fill="none" stroke="' + C.brassD + '" stroke-width="26"/><circle cx="300" cy="250" r="204" fill="none" stroke="' + C.brassDD + '" stroke-width="5"/>');
    for (var a = 0; a < 20; a++) { var an = a * Math.PI / 10; k.push('<circle cx="' + (300 + 190 * Math.cos(an)).toFixed(1) + '" cy="' + (250 + 190 * Math.sin(an)).toFixed(1) + '" r="5" fill="' + C.brass + '"/>'); }
    // the sonar and the three depth lights
    k.push('<circle cx="160" cy="530" r="44" fill="#04161C" stroke="#1F6F7A" stroke-width="4"/><circle cx="160" cy="530" r="29" fill="none" stroke="#1F6F7A" stroke-width="2"/><circle cx="160" cy="530" r="14" fill="none" stroke="#1F6F7A" stroke-width="2"/><line x1="160" y1="530" x2="187" y2="511" stroke="' + C.accent + '" stroke-width="3"/>');
    if (opts.anomalies) k.push('<circle cx="142" cy="518" r="5" fill="' + C.ko + '"/><circle cx="142" cy="518" r="12" fill="' + C.ko + '" opacity=".25"/>');
    var lit = Math.max(0, Math.min(3, opts.depth || 0));
    for (var b = 0; b < 3; b++) k.push('<circle cx="' + (250 + b * 34) + '" cy="526" r="10" fill="' + (b < lit ? C.accent : 'none') + '" stroke="' + C.accent + '" stroke-width="3" opacity="' + (b < lit ? .9 : .4) + '"/>');
    k.push('<text x="284" y="551" font-family="IBM Plex Mono, monospace" font-size="11" fill="' + C.dim + '" text-anchor="middle" letter-spacing="1">200 · 1000 · 3000 m</text>');
    // the floor
    k.push('<rect y="560" width="760" height="80" fill="#0B1F25"/><rect y="556" width="760" height="6" fill="#1F6F7A"/>');
    // the biologist
    k.push('<g transform="translate(476 120) scale(.92)">' + S.student(config) + '</g>');
    return '<svg class="scene-svg" viewBox="0 0 760 640" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Le poste d’observation de la station : votre avatar en combinaison, le masque relevé sur le front, devant le hublot ouvert sur les abysses">' + k.join('') + '</svg>';
  };

  root.ABYScene = S;
})(typeof globalThis !== 'undefined' ? globalThis : window);
