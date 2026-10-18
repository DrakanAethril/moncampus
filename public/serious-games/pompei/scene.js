/* Les Tablettes de Pompéi — the drawings (Claude Design page « Serious game - Tablettes de Pompéi
   v2 »): the architect's workshop in a Pompeian house, the apprentice standing in an ochre tunic
   with the stylus behind the ear, the wax tablet drawn at each level, the crack in a wall, the
   Lararium's shrine.

   A classic script that sets globalThis.POMScene. Every function returns SVG markup as a string;
   nothing here reads or writes the game's state. Nothing depends on time: the wax never softens. */
(function (root) {
  'use strict';

  var S = {};
  var C = { red: '#9E2B25', redDark: '#7A1F1B', ink: '#2E1D14', gold: '#C9923E', goldHi: '#E8B85E', cream: '#EFE3CB', stone: '#8F877D', ochre: '#E9CFA3', ochre2: '#DDB884', wood: '#5A3A28', wood2: '#3F2819', green: '#6E8B4E', water: '#5B8FA3' };
  S.C = C;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  S.esc = esc;

  /* ---------- the wax tablet, at each level ---------- */

  /* A tablet as the plan and the lists draw it: blank (never played), tried, then the four levels
     of its best series - ébauchée, nette, gravée, dorée. */
  S.tablet = function (state, size) {
    var w = size || 30, h = Math.round(w * 0.72);
    var lines = function (n, color) {
      var out = '';
      for (var i = 0; i < n; i++) out += '<line x1="9" y1="' + (9 + i * 5) + '" x2="' + (i === n - 1 && n > 1 ? 22 : 31) + '" y2="' + (9 + i * 5) + '" stroke="' + color + '" stroke-width="1.6" stroke-linecap="round"/>';
      return out;
    };
    var body;
    switch (state) {
      case 'discrete':
        body = '<rect x="1.5" y="1.5" width="37" height="26" rx="2" fill="#6B4A33" stroke="#4A3022" stroke-width="1.4"/>' + lines(1, C.cream);
        break;
      case 'clear':
        body = '<rect x="1.5" y="1.5" width="37" height="26" rx="2" fill="' + C.ink + '" stroke="' + C.gold + '" stroke-width="1.6"/>' + lines(2, C.cream);
        break;
      case 'bright':
        body = '<rect x="1.5" y="1.5" width="37" height="26" rx="2" fill="' + C.ink + '" stroke="' + C.gold + '" stroke-width="1.8"/><rect x="4.5" y="4.5" width="31" height="20" rx="1" fill="none" stroke="' + C.gold + '" stroke-width=".8"/>' + lines(3, C.cream);
        break;
      case 'blazing':
        body = '<rect x="1.5" y="1.5" width="37" height="26" rx="2" fill="' + C.gold + '" stroke="#8C6A2E" stroke-width="1.8"/><rect x="4.5" y="4.5" width="31" height="20" rx="1" fill="none" stroke="#8C6A2E" stroke-width=".8"/>' + lines(3, C.ink);
        break;
      case 'tried':
        body = '<rect x="1.5" y="1.5" width="37" height="26" rx="2" fill="#D9CDB4" stroke="' + C.stone + '" stroke-width="1.2" stroke-dasharray="3 2"/><line x1="9" y1="14.5" x2="20" y2="14.5" stroke="' + C.stone + '" stroke-width="1.6" stroke-linecap="round"/>';
        break;
      default:
        body = '<rect x="1.5" y="1.5" width="37" height="26" rx="2" fill="#D9CDB4" stroke="' + C.stone + '" stroke-width="1.2" stroke-dasharray="3 2"/>';
    }
    return '<svg class="tab-ico" viewBox="0 0 40 29" width="' + w + '" height="' + h + '" aria-hidden="true" focusable="false">' + body + '</svg>';
  };

  /* ---------- the crack, the shrine ---------- */

  S.crack = function (size) {
    var s = size || 34;
    return '<svg viewBox="0 0 40 40" width="' + s + '" height="' + s + '" aria-hidden="true" focusable="false">' +
      '<circle cx="20" cy="20" r="17" fill="none" stroke="' + C.red + '" stroke-width="1.6" stroke-dasharray="3 2.4"/>' +
      '<path d="M22 6 L15 14 L22 19 L14 27 L19 34" fill="none" stroke="' + C.ink + '" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"/>' +
      '<path d="M22 19 L27 22" fill="none" stroke="' + C.ink + '" stroke-width="1.6" stroke-linecap="round"/></svg>';
  };

  /* A wall of the villa split by a crack - the study of a « lézarde ». */
  S.crackWall = function () {
    return '<svg viewBox="0 0 520 300" class="crack-wall" role="img" aria-label="Un mur de la villa, fendu par une lézarde">' +
      '<rect width="520" height="300" fill="' + C.red + '"/><rect x="28" y="28" width="464" height="216" fill="none" stroke="' + C.gold + '" stroke-width="2"/>' +
      '<rect x="40" y="40" width="440" height="192" fill="none" stroke="' + C.gold + '" stroke-width=".8"/>' +
      '<path d="M180 70 L180 210 M168 82 Q180 70 192 82" fill="none" stroke="' + C.goldHi + '" stroke-width="2"/>' +
      '<rect y="244" width="520" height="56" fill="' + C.ink + '"/>' +
      '<path class="crack-line" d="M330 0 L312 40 L338 92 L300 150 L322 196 L296 244 L310 300" fill="none" stroke="#1B110B" stroke-width="7" stroke-linejoin="round"/>' +
      '<path class="crack-line crack-line--branch" d="M300 150 L262 166 L246 160 M338 92 L366 104" fill="none" stroke="#1B110B" stroke-width="3" stroke-linejoin="round"/>' +
      '<path d="M330 0 L312 40 L338 92 L300 150 L322 196 L296 244" fill="none" stroke="#C9B79A" stroke-width="1" opacity=".5"/></svg>';
  };

  /* The Lararium: the household shrine at the heart of the villa, revealed once the villa is built. */
  S.lararium = function (opts) {
    opts = opts || {};
    return '<svg viewBox="0 0 400 420" class="lararium-art" role="img" aria-label="Le lararium, petit autel des dieux de la maison">' +
      '<rect width="400" height="420" fill="' + C.ink + '"/><rect x="20" y="20" width="360" height="320" fill="' + C.red + '"/>' +
      '<rect x="34" y="34" width="332" height="292" fill="none" stroke="' + C.gold + '" stroke-width="2"/>' +
      '<path d="M90 140 L200 70 L310 140 Z" fill="' + C.gold + '"/>' +
      '<rect x="110" y="140" width="22" height="130" fill="' + C.cream + '"/><rect x="268" y="140" width="22" height="130" fill="' + C.cream + '"/>' +
      '<rect x="96" y="268" width="208" height="20" fill="' + C.cream + '"/><rect x="160" y="196" width="80" height="16" fill="' + C.gold + '"/><rect x="168" y="212" width="64" height="56" fill="' + C.cream + '"/>' +
      '<path d="M70 312 q22 -20 44 0 t44 0 M200 312 q22 -20 44 0 t44 0" fill="none" stroke="' + C.gold + '" stroke-width="6"/>' +
      (opts.flame ? '<path d="M200 166 Q190 182 200 192 Q210 182 200 166 Z" fill="' + C.goldHi + '"/>' : '') +
      '<rect y="340" width="400" height="80" fill="#1F140E"/></svg>';
  };

  /* ---------- the workshop and the apprentice ---------- */

  /* The body, in the head's 0 0 200 200 box extended downwards (feet at y 452): an ochre tunic
     with its two red clavi, a belt, sandals. The collar opens under the neck of the head scaled by
     .7 and lifted by 16 (the neck shows from y 89 to 121). */
  function body(c, A) {
    var sk = A.SKIN[c.skin][0], skd = A.SKIN[c.skin][1], tun = '#C27A34', tun2 = '#A8642A', ln = '#7E4A1E';
    var k = [];
    k.push('<ellipse cx="100" cy="452" rx="70" ry="8" fill="#000" opacity=".3"/>');
    // legs and sandals
    k.push('<rect x="76" y="320" width="18" height="118" fill="' + sk + '"/><rect x="106" y="320" width="18" height="118" fill="' + sk + '"/>');
    k.push('<path d="M76 330 L94 330" stroke="' + skd + '" stroke-width="2"/>');
    [[70, 98], [102, 130]].forEach(function (f) {
      k.push('<path d="M' + f[0] + ' 452 Q' + f[0] + ' 440 ' + (f[0] + 8) + ' 438 L' + (f[1] - 4) + ' 438 Q' + f[1] + ' 444 ' + f[1] + ' 452 Z" fill="' + C.wood + '"/>');
      k.push('<path d="M' + (f[0] + 6) + ' 412 L' + (f[1] - 6) + ' 428 M' + (f[1] - 6) + ' 412 L' + (f[0] + 6) + ' 428" stroke="' + C.wood + '" stroke-width="3"/>');
    });
    // the tunic
    k.push('<path d="M84 114 Q100 126 116 114 L150 128 Q160 140 160 170 L156 210 L146 210 L146 330 Q100 338 54 330 L54 210 L44 210 L40 170 Q40 140 50 128 Z" fill="' + tun + '" stroke="' + ln + '" stroke-width="1.4" stroke-linejoin="round"/>');
    k.push('<path d="M140 150 Q146 250 146 330 L132 332 Q136 240 132 150 Z" fill="' + tun2 + '" opacity=".55"/>');
    k.push('<path d="M86 116 Q100 128 114 116" stroke="' + ln + '" stroke-width="2" fill="none"/>');
    // the clavi
    k.push('<path d="M76 120 L78 332 L88 333 L86 122 Z" fill="' + C.red + '"/><path d="M124 120 L122 332 L112 333 L114 122 Z" fill="' + C.red + '"/>');
    // the belt and its knot
    k.push('<rect x="52" y="246" width="96" height="10" rx="3" fill="#5E3A24"/><path d="M98 256 L94 290 M102 256 L106 286" stroke="#5E3A24" stroke-width="3" stroke-linecap="round"/>');
    k.push('<path d="M100 260 L100 330" stroke="' + ln + '" stroke-width="1" opacity=".5"/>');
    // the arms
    k.push('<path d="M44 208 L40 290 Q38 302 48 304 L58 304 L58 210 Z" fill="' + sk + '"/><path d="M156 208 L160 290 Q162 302 152 304 L142 304 L142 210 Z" fill="' + sk + '"/>');
    k.push('<circle cx="49" cy="308" r="10.5" fill="' + sk + '"/><circle cx="151" cy="308" r="10.5" fill="' + sk + '"/>');
    // the small tablet in the hand
    k.push('<g transform="rotate(-12 166 300)"><rect x="150" y="284" width="38" height="28" rx="2" fill="' + C.wood + '" stroke="' + C.wood2 + '" stroke-width="1.2"/><rect x="154" y="288" width="30" height="20" fill="' + C.ink + '"/>' +
      '<path d="M158 294 H178 M158 300 H172" stroke="' + C.goldHi + '" stroke-width="1.4"/></g>');
    k.push('<circle cx="151" cy="308" r="7" fill="' + sk + '"/>');
    return k.join('');
  }

  /* The apprentice, standing: the shared avatar's bare head (shared/avatar.js) on the tunic, the
     stylus of Les Tablettes de Pompéi behind the ear, the pin worn if any. */
  S.student = function (config) {
    var A = root.SGAvatar;
    if (!A) return '';
    var c = A.normalize(config || {});
    var head = A.parts(c, true) + (A.COSTUMES.pompei ? A.COSTUMES.pompei.svg() : '');
    var pin = c.pin && A.pinSvg ? A.pinSvg(c.pin, 128, 146, 8) : '';
    return body(c, A) + pin + '<g transform="translate(30 -16) scale(.7)">' + head + '</g>';
  };

  function column(x, top, bottom) {
    var w = 34, out = '<rect x="' + (x - w / 2 - 6) + '" y="' + (top - 14) + '" width="' + (w + 12) + '" height="14" fill="' + C.cream + '" stroke="#C9B79A"/>';
    out += '<rect x="' + (x - w / 2) + '" y="' + top + '" width="' + w + '" height="' + (bottom - top) + '" fill="#F3EBD9"/>';
    for (var i = 1; i < 5; i++) out += '<line x1="' + (x - w / 2 + i * w / 5) + '" y1="' + top + '" x2="' + (x - w / 2 + i * w / 5) + '" y2="' + (bottom - 120) + '" stroke="#D9CBAE" stroke-width="2"/>';
    out += '<rect x="' + (x - w / 2) + '" y="' + (bottom - 120) + '" width="' + w + '" height="120" fill="#B8453C"/>';
    out += '<rect x="' + (x - w / 2 - 6) + '" y="' + bottom + '" width="' + (w + 12) + '" height="12" fill="' + C.cream + '" stroke="#C9B79A"/>';
    return out;
  }

  /* The workshop: an ochre frieze, red panels with gold frames, two columns, the floor's mosaic
     band, the table with the tablet of rosa - and the apprentice. */
  S.scene = function (config) {
    var k = [];
    k.push('<rect width="760" height="640" fill="' + C.ochre + '"/>');
    k.push('<rect y="92" width="760" height="8" fill="' + C.ochre2 + '"/>');
    for (var x = 8; x < 760; x += 26) k.push('<rect x="' + x + '" y="94" width="16" height="4" fill="' + C.gold + '" opacity=".7"/>');
    k.push('<rect y="108" width="760" height="6" fill="' + C.gold + '"/>');
    k.push('<rect y="114" width="760" height="416" fill="' + C.red + '"/>');
    [[100, 140, 250, 330], [380, 140, 250, 330]].forEach(function (p) {
      k.push('<rect x="' + p[0] + '" y="' + p[1] + '" width="' + p[2] + '" height="' + p[3] + '" fill="none" stroke="' + C.gold + '" stroke-width="2"/>');
      k.push('<rect x="' + (p[0] + 10) + '" y="' + (p[1] + 10) + '" width="' + (p[2] - 20) + '" height="' + (p[3] - 20) + '" fill="none" stroke="' + C.gold + '" stroke-width=".8" opacity=".7"/>');
    });
    k.push('<path d="M226 190 L226 400 M212 206 Q226 190 240 206" stroke="' + C.goldHi + '" stroke-width="2" fill="none" opacity=".8"/>');
    k.push('<path d="M506 190 L506 400 M492 206 Q506 190 520 206" stroke="' + C.goldHi + '" stroke-width="2" fill="none" opacity=".8"/>');
    k.push('<rect y="530" width="760" height="40" fill="' + C.ink + '"/>');
    k.push('<rect y="570" width="760" height="70" fill="#E5D3B0"/>');
    for (var m = 6; m < 760; m += 22) { k.push('<rect x="' + m + '" y="580" width="10" height="10" fill="' + C.ink + '"/>'); k.push('<rect x="' + (m + 8) + '" y="618" width="10" height="10" fill="' + C.ink + '"/>'); }
    k.push(column(48, 100, 530));
    k.push(column(712, 100, 530));
    // the table and the tablet of rosa
    k.push('<ellipse cx="200" cy="560" rx="120" ry="7" fill="#000" opacity=".25"/>');
    k.push('<rect x="100" y="470" width="200" height="12" rx="2" fill="' + C.wood + '"/><rect x="114" y="482" width="10" height="76" fill="' + C.wood2 + '"/><rect x="276" y="482" width="10" height="76" fill="' + C.wood2 + '"/>');
    k.push('<g transform="rotate(-10 150 430)"><rect x="104" y="384" width="94" height="84" rx="3" fill="' + C.wood + '" stroke="' + C.wood2 + '" stroke-width="2"/><rect x="112" y="392" width="78" height="68" fill="' + C.ink + '"/>' +
      '<text x="122" y="414" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-size="17" fill="' + C.cream + '">rosa</text>' +
      '<text x="122" y="433" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-size="17" fill="' + C.cream + '">rosae</text>' +
      '<text x="122" y="452" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-size="17" fill="' + C.cream + '">rosam</text></g>');
    k.push('<rect x="208" y="458" width="64" height="12" fill="#6E4A32"/><rect x="212" y="450" width="56" height="8" fill="#8A5E3E"/>');
    k.push('<path d="M258 468 L296 440" stroke="' + C.gold + '" stroke-width="4" stroke-linecap="round"/>');
    k.push('<ellipse cx="300" cy="466" rx="16" ry="5" fill="#B07A48"/><path d="M300 466 L300 452" stroke="' + C.goldHi + '" stroke-width="3"/>');
    // the apprentice
    k.push('<g transform="translate(400 118) scale(.92)">' + S.student(config) + '</g>');
    return '<svg class="scene-svg" viewBox="0 0 760 640" preserveAspectRatio="xMidYMid slice" role="img" aria-label="L’atelier de l’architecte : votre avatar, en tunique, un stylet derrière l’oreille">' + k.join('') + '</svg>';
  };

  /* The fresco of a built room on the plan: a red panel and its gold frame. */
  S.fresco = function () {
    return '<svg class="fresco" viewBox="0 0 100 60" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect width="100" height="60" fill="' + C.red + '"/>' +
      '<rect x="4" y="4" width="92" height="52" fill="none" stroke="' + C.gold + '" stroke-width="1" vector-effect="non-scaling-stroke"/></svg>';
  };

  root.POMScene = S;
})(typeof globalThis !== 'undefined' ? globalThis : window);
