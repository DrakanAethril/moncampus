/* The Irregulars — the drawings (after the conception's scene « baker », design/conception_serious_games/jeux.html):
   Baker Street at night in the autumn of 1895 - the brick front, the lit window, the green door of
   221B, the gas lamp in the fog -, the urchin standing in a long coat with the flat cap of the
   Irregulars, the case file drawn at each mention, the red thread of a false lead, the corkboard
   of an investigation, the detective's empty armchair of the final case.

   A classic script that sets globalThis.IRRScene. Every function returns SVG markup as a string;
   nothing here reads or writes the game's state. Nothing depends on time. */
(function (root) {
  'use strict';

  var S = {};
  var C = { fog: '#0E1714', fog2: '#1A2A24', brick: '#3B2A22', brick2: '#4A3328', ink: '#26261F', paper: '#F4EEDC', paper2: '#E6DCC6', cork: '#B08A5A', corkDark: '#5A4228', green: '#1F3A2E', green2: '#14281F', brass: '#C9A04E', lamp: '#F3D27A', red: '#A3281C', redHi: '#C0392B', cream: '#E9D8A6', stone: '#22231F', cobble: '#2E302A', mist: '#A9B59B' };
  S.C = C;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  S.esc = esc;

  // the conception's seeded randomness, for the bricks and the cobbles: the street is drawn the same every time
  function rng(seed) {
    var a = (seed >>> 0) || 1;
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      var t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  /* ---------- the case file, at each mention ---------- */

  /* A case file as the board and the lists draw it: never opened, tried, then the four mentions of
     the best investigation - de justesse (a plain folder), résolue (tied with string), brillamment
     (a wax seal), élémentaire (the brass seal of the magnifying glass). */
  S.file = function (state, size) {
    var w = size || 30, h = Math.round(w * 0.8);
    var body;
    var folder = function (fill, stroke, dash) {
      return '<path d="M2 6 L14 6 L17 2 L38 2 L38 30 L2 30 Z" fill="' + fill + '" stroke="' + stroke + '" stroke-width="1.3"' + (dash ? ' stroke-dasharray="3 2"' : '') + ' stroke-linejoin="round"/>';
    };
    var lines = function (n, color) {
      var out = '';
      for (var i = 0; i < n; i++) out += '<line x1="8" y1="' + (13 + i * 5) + '" x2="' + (i === n - 1 ? 24 : 32) + '" y2="' + (13 + i * 5) + '" stroke="' + color + '" stroke-width="1.5" stroke-linecap="round"/>';
      return out;
    };
    switch (state) {
      case 'discrete':
        body = folder('#C8B48E', '#6B5A45') + lines(1, '#5C4A36');
        break;
      case 'clear':
        body = folder(C.paper, '#6B5A45') + lines(2, '#5C4A36') + '<path d="M2 18 H38" stroke="' + C.red + '" stroke-width="1.6"/>';
        break;
      case 'bright':
        body = folder(C.paper, '#6B5A45') + lines(3, '#5C4A36') + '<circle cx="30" cy="24" r="5" fill="' + C.red + '"/><circle cx="30" cy="24" r="2.2" fill="#7A1B12"/>';
        break;
      case 'blazing':
        body = folder(C.paper, C.brass) + lines(3, '#5C4A36') + '<circle cx="29" cy="23" r="6" fill="' + C.brass + '" stroke="#7A5F22" stroke-width="1"/><circle cx="28" cy="22" r="2.6" fill="none" stroke="#5A4418" stroke-width="1.2"/><line x1="30" y1="24" x2="33" y2="27" stroke="#5A4418" stroke-width="1.4" stroke-linecap="round"/>';
        break;
      case 'tried':
        body = folder('#3A4A40', '#7E8F78', true) + '<line x1="8" y1="17" x2="20" y2="17" stroke="#7E8F78" stroke-width="1.5" stroke-linecap="round"/>';
        break;
      default:
        body = folder('#24332C', '#5C6450', true);
    }
    return '<svg class="file-ico" viewBox="0 0 40 32" width="' + w + '" height="' + h + '" aria-hidden="true" focusable="false">' + body + '</svg>';
  };

  /* ---------- the false lead: a red thread between two pins ---------- */

  S.thread = function (size) {
    var s = size || 30;
    return '<svg viewBox="0 0 40 40" width="' + s + '" height="' + s + '" aria-hidden="true" focusable="false">' +
      '<circle cx="20" cy="20" r="18" fill="' + C.paper + '"/>' +
      '<path d="M8 27 Q20 6 32 14" fill="none" stroke="' + C.red + '" stroke-width="2.6" stroke-linecap="round"/>' +
      '<circle cx="8" cy="27" r="3.4" fill="' + C.redHi + '"/><circle cx="32" cy="14" r="3.4" fill="' + C.redHi + '"/>' +
      '<circle cx="7" cy="26" r="1.1" fill="#fff" opacity=".7"/><circle cx="31" cy="13" r="1.1" fill="#fff" opacity=".7"/></svg>';
  };

  /* The corkboard of an investigation: one pinned note per clue (a right statement), one red thread
     per false lead still stretched (an error a later right answer has not lifted yet). */
  S.board = function (clues, threads, opts) {
    opts = opts || {};
    var k = [], W = 300, H = 220;
    k.push('<rect width="' + W + '" height="' + H + '" rx="4" fill="' + C.cork + '"/>');
    k.push('<rect x="5" y="5" width="' + (W - 10) + '" height="' + (H - 10) + '" rx="2" fill="none" stroke="' + C.corkDark + '" stroke-width="6"/>');
    var r = rng(29);
    for (var d = 0; d < 90; d++) k.push('<circle cx="' + (14 + r() * (W - 28)).toFixed(0) + '" cy="' + (14 + r() * (H - 28)).toFixed(0) + '" r="' + (r() * 1.4 + .4).toFixed(1) + '" fill="#8C6A40" opacity=".5"/>');
    var slots = [];
    for (var i = 0; i < 12; i++) slots.push([28 + (i % 6) * 44, 30 + Math.floor(i / 6) * 86]);
    for (var c = 0; c < Math.min(12, clues); c++) {
      var p = slots[c], tilt = ((c * 37) % 9) - 4;
      k.push('<g transform="rotate(' + tilt + ' ' + (p[0] + 15) + ' ' + (p[1] + 20) + ')"><rect x="' + p[0] + '" y="' + p[1] + '" width="32" height="40" fill="' + (c % 3 ? C.paper : C.paper2) + '"/>' +
        '<line x1="' + (p[0] + 5) + '" y1="' + (p[1] + 14) + '" x2="' + (p[0] + 27) + '" y2="' + (p[1] + 14) + '" stroke="#8A7A62" stroke-width="1.4"/>' +
        '<line x1="' + (p[0] + 5) + '" y1="' + (p[1] + 21) + '" x2="' + (p[0] + 22) + '" y2="' + (p[1] + 21) + '" stroke="#8A7A62" stroke-width="1.4"/>' +
        '<line x1="' + (p[0] + 5) + '" y1="' + (p[1] + 28) + '" x2="' + (p[0] + 25) + '" y2="' + (p[1] + 28) + '" stroke="#8A7A62" stroke-width="1.4"/></g>');
      k.push('<circle cx="' + (p[0] + 16) + '" cy="' + (p[1] + 3) + '" r="3.2" fill="' + C.brass + '"/>');
    }
    for (var t = 0; t < Math.min(6, threads); t++) {
      var y0 = 46 + t * 26, x0 = 18 + (t % 2) * 10;
      k.push('<path d="M' + x0 + ' ' + y0 + ' Q' + (W / 2) + ' ' + (y0 + 34 - t * 4) + ' ' + (W - 20 - (t % 3) * 8) + ' ' + (y0 + 12) + '" fill="none" stroke="' + C.red + '" stroke-width="2.4"/>');
      k.push('<circle cx="' + x0 + '" cy="' + y0 + '" r="3.4" fill="' + C.redHi + '"/><circle cx="' + (W - 20 - (t % 3) * 8) + '" cy="' + (y0 + 12) + '" r="3.4" fill="' + C.redHi + '"/>');
    }
    return '<svg class="board-svg" viewBox="0 0 ' + W + ' ' + H + '" width="100%" role="img" aria-label="' + esc(opts.label || ('Le tableau : ' + clues + ' indice' + (clues > 1 ? 's' : '') + ', ' + threads + ' fil' + (threads > 1 ? 's' : '') + ' rouge' + (threads > 1 ? 's' : ''))) + '">' + k.join('') + '</svg>';
  };

  /* The study of a false lead: a large red thread across the board, lifted once the lead is cleared. */
  S.leadBoard = function () {
    var k = [];
    k.push('<rect width="520" height="300" rx="4" fill="' + C.cork + '"/><rect x="8" y="8" width="504" height="284" fill="none" stroke="' + C.corkDark + '" stroke-width="10"/>');
    [[60, 60], [190, 90], [330, 54], [410, 170], [120, 190]].forEach(function (p, i) {
      k.push('<g transform="rotate(' + ((i * 7) % 9 - 4) + ' ' + p[0] + ' ' + p[1] + ')"><rect x="' + p[0] + '" y="' + p[1] + '" width="74" height="54" fill="' + (i % 2 ? C.paper : C.paper2) + '"/>' +
        '<line x1="' + (p[0] + 8) + '" y1="' + (p[1] + 18) + '" x2="' + (p[0] + 62) + '" y2="' + (p[1] + 18) + '" stroke="#8A7A62" stroke-width="2"/><line x1="' + (p[0] + 8) + '" y1="' + (p[1] + 30) + '" x2="' + (p[0] + 50) + '" y2="' + (p[1] + 30) + '" stroke="#8A7A62" stroke-width="2"/></g>');
      k.push('<circle cx="' + (p[0] + 37) + '" cy="' + (p[1] + 4) + '" r="5" fill="' + C.brass + '"/>');
    });
    k.push('<path class="lead-line" d="M96 66 Q240 250 446 176" fill="none" stroke="' + C.red + '" stroke-width="5"/>');
    k.push('<path class="lead-line lead-line--2" d="M226 96 Q300 40 366 60" fill="none" stroke="' + C.red + '" stroke-width="3"/>');
    k.push('<circle cx="96" cy="66" r="7" fill="' + C.redHi + '"/><circle cx="446" cy="176" r="7" fill="' + C.redHi + '"/><circle cx="226" cy="96" r="5" fill="' + C.redHi + '"/><circle cx="366" cy="60" r="5" fill="' + C.redHi + '"/>');
    return '<svg viewBox="0 0 520 300" class="lead-board" role="img" aria-label="Le tableau des indices, traversé par un fil rouge : une fausse piste">' + k.join('') + '</svg>';
  };

  /* The final case: the detective's armchair, empty by the fire, the violin on the cushion, the
     pipe on the mantelpiece - the Irregulars conclude alone. */
  S.armchair = function (opts) {
    opts = opts || {};
    var k = [];
    k.push('<rect width="400" height="420" fill="#1E140F"/>');
    k.push('<rect width="400" height="300" fill="#3A2A20"/>');
    for (var x = 0; x < 400; x += 40) k.push('<rect x="' + x + '" y="0" width="20" height="300" fill="#43302A" opacity=".6"/>');
    // the fireplace and its glow
    k.push('<rect x="250" y="120" width="130" height="180" fill="#2A1C14" stroke="#5A4228" stroke-width="6"/><rect x="240" y="110" width="150" height="14" fill="#5A4228"/>');
    k.push('<path d="M290 300 Q300 240 314 270 Q322 220 336 268 Q346 246 352 300 Z" fill="' + (opts.flame ? '#E8913A' : '#5A3020') + '"/>');
    if (opts.flame) k.push('<circle cx="318" cy="280" r="90" fill="#F3D27A" opacity=".12"/>');
    k.push('<path d="M262 104 L280 104 L282 96 L292 96" stroke="#8A7458" stroke-width="3" fill="none"/>');
    // the window and the fog of Baker Street
    k.push('<rect x="40" y="40" width="120" height="150" fill="#1A2A24" stroke="#5A4228" stroke-width="8"/><line x1="100" y1="40" x2="100" y2="190" stroke="#5A4228" stroke-width="5"/><line x1="40" y1="115" x2="160" y2="115" stroke="#5A4228" stroke-width="5"/>');
    k.push('<rect x="44" y="130" width="112" height="56" fill="' + C.mist + '" opacity=".18"/><circle cx="130" cy="80" r="10" fill="' + C.lamp + '" opacity=".5"/>');
    // the armchair
    k.push('<path d="M70 380 L70 250 Q70 200 120 196 L190 196 Q236 200 236 250 L236 380 Z" fill="#6B2A22"/><path d="M58 300 Q58 270 84 270 L92 270 L92 380 L58 380 Z M248 300 Q248 270 222 270 L214 270 L214 380 L248 380 Z" fill="#5A221B"/>');
    k.push('<rect x="88" y="300" width="130" height="30" rx="6" fill="#7E3329"/><rect x="74" y="380" width="10" height="20" fill="#2A1C14"/><rect x="222" y="380" width="10" height="20" fill="#2A1C14"/>');
    // the violin and its bow on the cushion
    k.push('<g transform="rotate(-18 150 300)"><ellipse cx="140" cy="300" rx="22" ry="12" fill="#A0582A"/><ellipse cx="162" cy="300" rx="18" ry="10" fill="#A0582A"/><rect x="176" y="297" width="42" height="6" fill="#3A2416"/><line x1="120" y1="292" x2="232" y2="292" stroke="#D8C9A4" stroke-width="1.6"/></g>');
    k.push('<rect y="400" width="400" height="20" fill="#120C09"/>');
    return '<svg viewBox="0 0 400 420" class="armchair-art" role="img" aria-label="Le fauteuil vide du détective, près du feu : il est absent, les Irregulars concluent seuls">' + k.join('') + '</svg>';
  };

  /* ---------- the urchin ---------- */

  /* The body, in the head's 0 0 200 200 box extended downwards (feet at y 452): a long brown coat
     over a waistcoat, a red scarf, patched trousers, worn boots, the notebook in the hand. The
     collar opens under the neck of the head scaled by .7 and lifted by 16. */
  function body(c, A) {
    var sk = A.SKIN[c.skin][0], coat = '#6B5A45', coat2 = '#57493A', ln = '#3E3226';
    var k = [];
    k.push('<ellipse cx="100" cy="452" rx="72" ry="9" fill="#000" opacity=".35"/>');
    // trousers and boots
    k.push('<rect x="72" y="340" width="24" height="100" fill="#3E3A33"/><rect x="104" y="340" width="24" height="100" fill="#3E3A33"/>');
    k.push('<rect x="76" y="380" width="14" height="12" fill="#57524A"/>');
    k.push('<path d="M64 452 Q64 438 76 436 L96 436 L98 452 Z" fill="#2B241D"/><path d="M102 452 L104 436 L124 436 Q136 438 136 452 Z" fill="#2B241D"/>');
    // the waistcoat and the shirt under the coat
    k.push('<path d="M84 118 Q100 130 116 118 L120 260 L80 260 Z" fill="#D9CDB4"/>');
    k.push('<path d="M86 132 L100 150 L114 132 L116 250 L84 250 Z" fill="#4A4A52"/>');
    k.push('<circle cx="100" cy="170" r="2.4" fill="' + C.brass + '"/><circle cx="100" cy="196" r="2.4" fill="' + C.brass + '"/><circle cx="100" cy="222" r="2.4" fill="' + C.brass + '"/>');
    // the long coat, open
    k.push('<path d="M84 116 L48 132 Q36 142 38 180 L44 396 L84 400 L88 150 Z" fill="' + coat + '" stroke="' + ln + '" stroke-width="1.4" stroke-linejoin="round"/>');
    k.push('<path d="M116 116 L152 132 Q164 142 162 180 L156 396 L116 400 L112 150 Z" fill="' + coat + '" stroke="' + ln + '" stroke-width="1.4" stroke-linejoin="round"/>');
    k.push('<path d="M150 150 Q156 270 152 392 L140 394 Q146 270 140 150 Z" fill="' + coat2 + '" opacity=".6"/>');
    k.push('<rect x="56" y="300" width="22" height="18" fill="#8A7458"/>');
    // the red scarf
    k.push('<path d="M80 116 Q100 132 120 116 L122 130 Q100 146 78 130 Z" fill="' + C.red + '"/><path d="M110 132 L116 176 L106 178 L102 136 Z" fill="#8E2218"/>');
    // the arms
    k.push('<path d="M48 136 Q32 144 30 180 L26 296 L48 298 L56 186 Z" fill="' + coat + '" stroke="' + ln + '" stroke-width="1.2"/>');
    k.push('<path d="M152 136 Q168 144 170 180 L174 296 L152 298 L144 186 Z" fill="' + coat + '" stroke="' + ln + '" stroke-width="1.2"/>');
    k.push('<rect x="28" y="246" width="20" height="16" fill="#8A7458"/>');
    k.push('<circle cx="37" cy="306" r="10" fill="' + sk + '"/>');
    // the notebook in the right hand
    k.push('<g transform="rotate(10 166 300)"><rect x="152" y="282" width="30" height="40" rx="2" fill="' + C.green + '" stroke="' + C.green2 + '" stroke-width="1.2"/><rect x="156" y="286" width="22" height="32" fill="' + C.paper + '"/>' +
      '<path d="M159 294 H175 M159 300 H171 M159 306 H174" stroke="#8A7A62" stroke-width="1.2"/></g>');
    k.push('<circle cx="163" cy="306" r="9" fill="' + sk + '"/>');
    return k.join('');
  }

  /* The Irregular, standing: the shared avatar's bare head (shared/avatar.js) on the coat, the flat
     cap of The Irregulars, the pin worn if any. */
  S.student = function (config) {
    var A = root.SGAvatar;
    if (!A) return '';
    var c = A.normalize(config || {});
    var head = A.parts(c, true) + (A.COSTUMES.irregulars ? A.COSTUMES.irregulars.svg() : '');
    var pin = c.pin && A.pinSvg ? A.pinSvg(c.pin, 136, 150, 8) : '';
    return body(c, A) + pin + '<g transform="translate(30 -16) scale(.7)">' + head + '</g>';
  };

  /* Baker Street at night: the brick front with its lit window and the detective's silhouette, the
     green door of 221B, the gas lamp and its halo, the cobbles in the fog - and the Irregular. */
  S.scene = function (config) {
    var r = rng(31), k = [];
    k.push('<defs><linearGradient id="irr-fog" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + C.fog2 + '"/><stop offset="1" stop-color="' + C.fog + '"/></linearGradient>' +
      '<radialGradient id="irr-lamp" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="' + C.lamp + '" stop-opacity=".55"/><stop offset="1" stop-color="' + C.lamp + '" stop-opacity="0"/></radialGradient>' +
      '<linearGradient id="irr-mist" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + C.mist + '" stop-opacity="0"/><stop offset="1" stop-color="' + C.mist + '" stop-opacity=".22"/></linearGradient></defs>');
    k.push('<rect width="760" height="640" fill="url(#irr-fog)"/>');
    // the brick front
    k.push('<rect x="0" y="30" width="560" height="520" fill="' + C.brick + '"/>');
    for (var y = 36; y < 550; y += 20) for (var x = (Math.round(y / 20) % 2) ? -24 : 0; x < 560; x += 48) k.push('<rect x="' + x + '" y="' + y + '" width="46" height="18" fill="' + C.brick2 + '" opacity="' + (.3 + r() * .4).toFixed(2) + '"/>');
    // the lit window of the first floor, the detective's silhouette
    k.push('<rect x="120" y="90" width="140" height="170" fill="#E9C46A"/><line x1="190" y1="90" x2="190" y2="260" stroke="' + C.brick + '" stroke-width="7"/><line x1="120" y1="175" x2="260" y2="175" stroke="' + C.brick + '" stroke-width="7"/>');
    k.push('<path d="M156 260 Q162 198 176 190 Q180 176 192 180 Q202 172 208 190 Q220 204 224 260 Z" fill="#2A1E16" opacity=".75"/>');
    k.push('<rect x="110" y="260" width="160" height="12" fill="#2A1E16"/>');
    k.push('<rect x="330" y="90" width="140" height="170" fill="#2A3A34"/><line x1="400" y1="90" x2="400" y2="260" stroke="' + C.brick + '" stroke-width="7"/><line x1="330" y1="175" x2="470" y2="175" stroke="' + C.brick + '" stroke-width="7"/><rect x="320" y="260" width="160" height="12" fill="#2A1E16"/>');
    // the door of 221B
    k.push('<rect x="350" y="330" width="130" height="220" fill="' + C.green + '"/><rect x="350" y="330" width="130" height="220" fill="none" stroke="' + C.green2 + '" stroke-width="9"/>');
    k.push('<rect x="368" y="350" width="40" height="80" fill="none" stroke="' + C.green2 + '" stroke-width="3"/><rect x="422" y="350" width="40" height="80" fill="none" stroke="' + C.green2 + '" stroke-width="3"/>');
    k.push('<circle cx="462" cy="452" r="6" fill="' + C.brass + '"/><rect x="380" y="294" width="70" height="28" fill="' + C.brass + '"/>');
    k.push('<text x="415" y="315" font-family="Libre Caslon Display, Georgia, serif" font-size="20" fill="#2A1E16" text-anchor="middle">221B</text>');
    k.push('<rect x="340" y="550" width="150" height="10" fill="#4A4A40"/>');
    // the gas lamp
    k.push('<line x1="620" y1="200" x2="620" y2="560" stroke="#1A1A1A" stroke-width="10"/><path d="M594 156 L646 156 L638 200 L602 200 Z" fill="#2B2B2B"/>');
    k.push('<rect x="604" y="166" width="32" height="30" fill="' + C.lamp + '"/><path d="M590 156 L650 156 L620 140 Z" fill="#1A1A1A"/><circle cx="620" cy="182" r="170" fill="url(#irr-lamp)"/>');
    // the street, the cobbles, the fog
    k.push('<rect x="0" y="560" width="760" height="80" fill="' + C.stone + '"/>');
    for (var cy = 0; cy < 3; cy++) for (var cx = 0; cx < 16; cx++) k.push('<ellipse cx="' + (cx * 50 + (cy % 2) * 25) + '" cy="' + (578 + cy * 22) + '" rx="22" ry="8" fill="' + C.cobble + '" opacity=".9"/>');
    k.push('<rect x="0" y="300" width="760" height="340" fill="url(#irr-mist)"/>');
    // the Irregular
    k.push('<g transform="translate(150 180) scale(.86)">' + S.student(config) + '</g>');
    return '<svg class="scene-svg" viewBox="0 0 760 640" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Baker Street la nuit : votre avatar, casquette gavroche, devant la porte du 221B, sous un bec de gaz">' + k.join('') + '</svg>';
  };

  root.IRRScene = S;
})(typeof globalThis !== 'undefined' ? globalThis : window);
