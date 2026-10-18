/* L'Observatoire — the drawings (design/validated/serious-games.md §8, Claude Design page
   « Serious Games - Observatoire illustrations »): the night sky, the mountains, the desk, the
   student standing in a lab coat, the brass telescope, the sky map with its five constellations,
   La Couronne, the comets and the star drawn at each brightness.

   A classic script that sets globalThis.OBSScene. Every function returns SVG markup as a string;
   nothing here reads or writes the game's state. No weather, nothing that depends on time. */
(function (root) {
  'use strict';

  var S = {};
  var uid = 0;
  function nid(p) { uid += 1; return p + uid; }

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  S.esc = esc;

  // the conception's seeded randomness: the same sky at every visit
  function rng(seed) { return function () { seed = (seed * 16807) % 2147483647; return seed / 2147483647; }; }

  /* ---------- the background: sky, stars, mountains (CSS places them) ---------- */

  /* The starfield, covering the whole frame (preserveAspectRatio slice). A few stars twinkle,
     unless the reader asked for reduced motion (game.css stops the animation). */
  S.starfield = function () {
    var r = rng(7), out = [];
    for (var i = 0; i < 240; i++) {
      var y = r() * 900, op = .18 + r() * .6 * (1 - y / 1100), x = r() * 1440, big = r() < .08, blue = r() < .15, tw = i % 7 === 0;
      out.push('<circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="' + (big ? 1.6 : .85) + '" fill="' + (blue ? '#A9C1E8' : '#EDE6D6') + '" opacity="' + op.toFixed(2) + '"' +
        (tw ? ' class="tw" style="animation-duration:' + (6 + (i % 5) * 1.5) + 's;animation-delay:' + ((i % 11) * .7).toFixed(1) + 's"' : '') + '/>');
      if (big && i % 3 === 0) out.push('<path d="M' + (x - 4).toFixed(1) + ' ' + y.toFixed(1) + 'H' + (x + 4).toFixed(1) + 'M' + x.toFixed(1) + ' ' + (y - 4).toFixed(1) + 'V' + (y + 4).toFixed(1) + '" stroke="#EDE6D6" stroke-opacity="' + (op * .6).toFixed(2) + '" stroke-width=".6"/>');
    }
    // the faint meridians of the design, centred under the horizon
    var arcs = '';
    [700, 900, 1100, 1300].forEach(function (rr) { arcs += '<circle cx="720" cy="1500" r="' + rr + '" fill="none" stroke="#A9C1E8" stroke-opacity=".05"/>'; });
    return '<svg class="sky-stars" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">' + arcs + out.join('') + '</svg>';
  };

  /* Two ranges of mountains, stretched to the width of the frame. */
  S.mountains = function () {
    var id = nid('hatch');
    var far = 'M0 110 L120 50 L210 80 L330 0 L430 60 L520 30 L640 90 L760 40 L900 90 L1040 30 L1180 80 L1300 40 L1440 80 L1440 200 L0 200 Z';
    var near = 'M0 160 L160 120 L300 150 L480 110 L660 160 L860 125 L1060 160 L1260 130 L1440 155 L1440 200 L0 200 Z';
    return '<svg class="mountains" viewBox="0 0 1440 200" preserveAspectRatio="none" aria-hidden="true" focusable="false">' +
      '<defs><pattern id="' + id + '" width="5" height="5" patternUnits="userSpaceOnUse"><path d="M0 2.5H5" stroke="#A9C1E8" stroke-opacity=".07"/></pattern></defs>' +
      '<path d="' + far + '" fill="#0C1230"/><path d="' + far + '" fill="url(#' + id + ')"/>' +
      '<path d="' + far.replace(/ L1440 200 L0 200 Z$/, '') + '" fill="none" stroke="#2E3A6B" stroke-width="1.2" vector-effect="non-scaling-stroke"/>' +
      '<path d="' + near + '" fill="#080C20"/>' +
      '<path d="' + near.replace(/ L1440 200 L0 200 Z$/, '') + '" fill="none" stroke="#1B2550" stroke-width="1" vector-effect="non-scaling-stroke"/></svg>';
  };

  /* ---------- the student: the bare head of the shared avatar on a lab coat ---------- */

  /* The body in the head's 0 0 200 200 box extended downwards (the feet at y 452). The collar
     opens at y 114-116 in this box, i.e. under the neck of the head scaled by .7 and lifted by 16
     (the neck shows from y 89 to 121), and the shirt of the avatar's config shows in the V. */
  function body(c, A) {
    var sk = A.SKIN[c.skin][0], skd = A.SKIN[c.skin][1], coat = '#EEF0F2', sh = '#D3D8E0', ln = '#AEB6C3', top = A.TOP[c.top];
    var o = ' stroke="' + ln + '" stroke-width="1.4" stroke-linejoin="round"';
    var k = [];
    k.push('<ellipse cx="100" cy="452" rx="74" ry="8" fill="#000" opacity=".35"/>');
    k.push('<path d="M68 376 L94 376 L95 442 L69 442 Z" fill="#2E3448"/><path d="M106 376 L132 376 L131 442 L105 442 Z" fill="#2E3448"/>');
    k.push('<path d="M82 392 L82 440 M119 392 L119 440" stroke="#232838" stroke-width="1.4"/>');
    k.push('<path d="M60 452 Q60 437 76 436 L95 436 Q99 445 97 452 Z" fill="#17171B"/><path d="M140 452 Q140 437 124 436 L105 436 Q101 445 103 452 Z" fill="#17171B"/>');
    k.push('<path d="M68 441 Q78 438 90 439 M132 441 Q122 438 110 439" stroke="#3A3A44" stroke-width="1.4" fill="none"/>');
    k.push('<path d="M84 114 L100 176 L116 114 Q100 122 84 114 Z" fill="' + top + '"/>');
    k.push('<path d="M88 118 Q100 124 112 118" stroke="rgba(0,0,0,.22)" stroke-width="2" fill="none"/>');
    k.push('<path d="M50 132 Q58 120 84 116 L100 176 L116 116 Q142 120 150 132 L160 392 Q100 398 40 392 Z" fill="' + coat + '"' + o + '/>');
    k.push('<path d="M140 150 Q148 270 154 392 L160 391 L150 132 Z" fill="url(#obs-hatchC)"/>');
    k.push('<path d="M58 150 Q52 270 46 392 L40 391 L50 132 Z" fill="' + sh + '" opacity=".6"/>');
    k.push('<path d="M84 116 L70 150 L80 157 L100 194 L100 176 Z" fill="#E4E8ED"' + o + '/>');
    k.push('<path d="M116 116 L130 150 L120 157 L100 194 L100 176 Z" fill="#E4E8ED"' + o + '/>');
    k.push('<path d="M100 194 L100 392 M100 358 L100 395" stroke="' + ln + '" stroke-width="1.4"/>');
    [228, 268, 308, 348].forEach(function (y) { k.push('<circle cx="106" cy="' + y + '" r="3" fill="' + sh + '" stroke="' + ln + '" stroke-width=".8"/>'); });
    k.push('<path d="M54 290 L88 290 L88 318 Q71 322 54 318 Z" fill="none"' + o + '/><path d="M56 296 L86 296" stroke="' + sh + '" stroke-width="1.2"/>');
    k.push('<path d="M112 290 L146 290 L146 318 Q129 322 112 318 Z" fill="none"' + o + '/><path d="M114 296 L144 296" stroke="' + sh + '" stroke-width="1.2"/>');
    k.push('<path d="M76 330 Q80 360 77 390 M126 214 Q133 252 130 282" stroke="' + sh + '" stroke-width="1.3" fill="none"/>');
    k.push('<line x1="128" y1="150" x2="128" y2="182" stroke="#2F6FB0" stroke-width="3.8" stroke-linecap="round"/>');
    k.push('<line x1="130.5" y1="152" x2="130.5" y2="164" stroke="#1E4A7A" stroke-width="1.3" stroke-linecap="round"/><circle cx="128" cy="150" r="2.2" fill="#1E4A7A"/>');
    k.push('<path d="M117 166 L143 166 L143 188 L117 188 Z" fill="' + coat + '"' + o + '/><path d="M119 171 L141 171" stroke="' + sh + '" stroke-width="1.1"/>');
    k.push('<rect x="58" y="166" width="25" height="15" rx="2" fill="#D9A441" stroke="#8C6A2E" stroke-width=".9"/>');
    k.push('<path d="M70.5 169 L71.6 172.2 L75 172.2 L72.3 174.2 L73.3 177.4 L70.5 175.4 L67.7 177.4 L68.7 174.2 L66 172.2 L69.4 172.2 Z" fill="#8C6A2E"/>');
    k.push('<path d="M50 132 Q34 140 32 178 L28 312 L52 314 L58 186 Z" fill="#E3E6EB"' + o + '/>');
    k.push('<path d="M150 132 Q166 140 168 178 L172 312 L148 314 L142 186 Z" fill="#E3E6EB"' + o + '/>');
    k.push('<path d="M40 200 Q45 244 40 290 M160 200 Q155 244 160 290" stroke="' + sh + '" stroke-width="1.3" fill="none"/>');
    k.push('<path d="M29 302 L52 304 M171 302 L148 304" stroke="' + ln + '" stroke-width="1.4"/>');
    k.push('<circle cx="39" cy="322" r="10.5" fill="' + sk + '"/><circle cx="161" cy="322" r="10.5" fill="' + sk + '"/>');
    k.push('<path d="M33 326 Q39 331 45 326 M155 326 Q161 331 167 326" stroke="' + skd + '" stroke-width="1.6" fill="none" stroke-linecap="round"/>');
    return k.join('');
  }

  /* The student, standing: the shared avatar's bare head (shared/avatar.js) on the coat, with the
     red head-lamp of L'Observatoire when it is switched on. */
  S.student = function (config, lamp) {
    var A = root.SGAvatar;
    if (!A) return '';
    var c = A.normalize(config || {});
    var head = A.parts(c, true) + (lamp && A.COSTUMES.observatoire ? A.COSTUMES.observatoire.svg() : '');
    // the body first, the head over it: the neck covers the top of the collar's V
    return body(c, A) + '<g transform="translate(30 -16) scale(.7)">' + head + '</g>';
  };

  /* ---------- the desk, the lamp and the telescope ---------- */

  function desk() {
    var d = [], wo = ' stroke="#2A1810" stroke-width="1.2"';
    d.push('<ellipse cx="165" cy="826" rx="160" ry="8" fill="#000" opacity=".35"/>');
    d.push('<path d="M40 706 H54 V818 Q47 824 40 818 Z" fill="#4A3022"' + wo + '/><path d="M276 706 H290 V818 Q283 824 276 818 Z" fill="#4A3022"' + wo + '/>');
    d.push('<rect x="186" y="706" width="96" height="58" fill="#5A3A28"' + wo + '/>');
    d.push('<rect x="196" y="716" width="76" height="16" rx="2" fill="#4A3022"' + wo + '/><circle cx="234" cy="724" r="3" fill="#C9973F"/>');
    d.push('<rect x="196" y="738" width="76" height="16" rx="2" fill="#4A3022"' + wo + '/><circle cx="234" cy="746" r="3" fill="#C9973F"/>');
    d.push('<rect x="24" y="692" width="282" height="16" rx="2" fill="#6E4A32"' + wo + '/><rect x="24" y="704" width="282" height="4" fill="#4A3022"/>');
    d.push('<path d="M34 697 Q120 694 200 698 T300 697 M44 701 Q140 699 240 702" stroke="#5A3A28" stroke-width=".9" fill="none"/>');
    [[44, 0, -3], [46, -5, 2], [42, -10, -1], [48, -15, 4], [44, -20, -2]].forEach(function (s, j) {
      d.push('<rect x="' + s[0] + '" y="' + (686 + s[1]) + '" width="96" height="7" fill="' + (j % 2 ? '#E8DFC9' : '#F2EBDA') + '" stroke="#C9BC9E" stroke-width=".6" transform="rotate(' + s[2] + ' 92 ' + (690 + s[1]) + ')"/>');
    });
    [[150, 0, 2], [152, -5, -3], [149, -10, 1]].forEach(function (s, j) {
      d.push('<rect x="' + s[0] + '" y="' + (686 + s[1]) + '" width="84" height="7" fill="' + (j % 2 ? '#EDE3CD' : '#F5EFE0') + '" stroke="#C9BC9E" stroke-width=".6" transform="rotate(' + s[2] + ' 192 ' + (690 + s[1]) + ')"/>');
    });
    // the sheet of calculations, corrected in red: Hélène's own errors
    d.push('<g transform="rotate(-8 120 660)"><rect x="62" y="600" width="110" height="84" fill="#F7F1E3" stroke="#D8CDB4"/>' +
      '<path d="M66 604 H168 V680 H66 Z" fill="none" stroke="#E6DCC4" stroke-width=".6"/>' +
      '<text x="70" y="620" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-size="13" fill="#2B3A6B">α = 3,2 × 10⁻³</text>' +
      '<text x="70" y="638" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-size="13" fill="#2B3A6B">δ = (x − 3)²</text>' +
      '<text x="70" y="656" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-size="13" fill="#2B3A6B">× 1,25 → −20 %</text>' +
      '<line x1="70" y1="664" x2="150" y2="664" stroke="#B5444A" stroke-width="1.2"/><circle cx="156" cy="672" r="6" fill="none" stroke="#B5444A" stroke-width="1.2"/></g>');
    d.push('<path d="M222 690 L300 688 L312 724 L236 728 Z" fill="#F2EBDA" stroke="#C9BC9E" stroke-width=".6"/>');
    d.push('<line x1="240" y1="700" x2="292" y2="699" stroke="#9AA7BA"/><line x1="244" y1="710" x2="298" y2="709" stroke="#9AA7BA"/>');
    d.push('<circle cx="330" cy="810" r="10" fill="#E8DFC9" stroke="#C9BC9E" stroke-width=".6"/><path d="M322 806 L332 812 L338 804 M326 815 L333 811" stroke="#C9BC9E" stroke-width="1.2" fill="none"/>');
    d.push('<circle cx="262" cy="684" r="8" fill="#EDE3CD" stroke="#C9BC9E" stroke-width=".6"/><path d="M256 682 L264 688 L268 680" stroke="#C9BC9E" fill="none"/>');
    d.push('<line x1="196" y1="672" x2="186" y2="618" stroke="#E0A93B" stroke-width="5" stroke-linecap="round"/><path d="M184 620 L186 608 L190 619 Z" fill="#2A1E1A"/>');
    d.push('<line x1="204" y1="672" x2="208" y2="612" stroke="#C8463D" stroke-width="5" stroke-linecap="round"/><path d="M206 614 L208 602 L211 614 Z" fill="#2A1E1A"/>');
    d.push('<line x1="212" y1="672" x2="226" y2="622" stroke="#2F6FB0" stroke-width="5" stroke-linecap="round"/><path d="M224 624 L228 612 L229 625 Z" fill="#2A1E1A"/>');
    d.push('<path d="M190 650 L222 650 L218 690 L194 690 Z" fill="#8C6A2E" stroke="#5A4420" stroke-width=".8"/><path d="M196 656 L195 686 M204 656 L203.5 686 M212 656 L212 686" stroke="#6E5222" stroke-width=".8"/><rect x="189" y="648" width="34" height="5" rx="1" fill="#B8893A"/>');
    d.push('<g transform="rotate(12 120 690)"><rect x="90" y="686" width="58" height="5" rx="1" fill="#E0A93B"/><path d="M148 686 L156 688.5 L148 691 Z" fill="#2A1E1A"/></g>');
    d.push('<path d="M150 640 L138 684 M150 640 L162 684" stroke="#A8A8A8" stroke-width="2.5" fill="none" stroke-linecap="round"/><circle cx="150" cy="638" r="3.5" fill="#C9973F"/>');
    d.push('<rect x="262" y="682" width="30" height="6" rx="2" fill="#2E3448"/><line x1="277" y1="682" x2="268" y2="620" stroke="#2E3448" stroke-width="3"/>');
    d.push('<line x1="268" y1="620" x2="240" y2="604" stroke="#2E3448" stroke-width="3"/><circle cx="268" cy="620" r="3" fill="#2E3448"/>');
    d.push('<path d="M222 598 L250 594 L246 620 Z" fill="#3E8E5E" stroke="#2A5E3E"/>');
    // the lamp's halo, breathing slowly (game.css stops it under reduced motion)
    d.push('<g class="breathe"><ellipse cx="130" cy="688" rx="150" ry="34" fill="url(#obs-glow)"/>' +
      '<path d="M228 612 L246 620 L120 690 L60 690 Z" fill="#F2CE7A" opacity=".09"/><circle cx="238" cy="614" r="5" fill="#FFF0C2" opacity=".9"/></g>');
    return d.join('');
  }

  function telescope() {
    var t = [];
    [[560, 624, 512, 820, 7], [560, 624, 612, 820, 7], [560, 624, 568, 826, 5]].forEach(function (l, j) {
      t.push('<line x1="' + l[0] + '" y1="' + l[1] + '" x2="' + l[2] + '" y2="' + l[3] + '" stroke="' + (j === 2 ? '#6E5222' : '#8C6A2E') + '" stroke-width="' + l[4] + '" stroke-linecap="round"/>');
      if (j < 2) t.push('<line x1="' + (l[0] + (j ? 2 : -2)) + '" y1="' + (l[1] + 6) + '" x2="' + (l[2] + (j ? 2 : -2)) + '" y2="' + (l[3] - 6) + '" stroke="#C9973F" stroke-width="1.4" stroke-opacity=".7"/>');
    });
    t.push('<path d="M536 722 L586 722 L564 726 Z" fill="none" stroke="#6E5222" stroke-width="2.5"/>');
    t.push('<rect x="552" y="600" width="16" height="26" rx="2" fill="#8C6A2E" stroke="#5A4420"/>');
    t.push('<g transform="translate(560 604) rotate(-38)">' +
      '<rect x="-120" y="-6" width="30" height="12" rx="2" fill="#8C6A2E" stroke="#5A4420"/><rect x="-104" y="6" width="8" height="10" rx="1" fill="#6E5222"/>' +
      '<path d="M-92 -15 L118 -12 L118 12 L-92 15 Z" fill="url(#obs-brass)" stroke="#6E5222"/>' +
      '<path d="M-84 9 L112 7 M-84 11.5 L112 9.5" stroke="#6E5222" stroke-width=".7" stroke-opacity=".7"/>' +
      '<path d="M-84 -10 L112 -8" stroke="#F5DA9A" stroke-width="1.2" stroke-opacity=".7"/>' +
      '<rect x="-62" y="-16" width="7" height="32" fill="#8C6A2E"/><rect x="38" y="-14" width="7" height="28" fill="#8C6A2E"/><rect x="106" y="-13" width="6" height="26" fill="#8C6A2E"/>' +
      '<path d="M118 -19 L164 -21 L164 21 L118 19 Z" fill="url(#obs-brass)" stroke="#6E5222"/>' +
      '<ellipse cx="164" cy="0" rx="4.5" ry="21" fill="#8C6A2E"/><ellipse cx="165" cy="0" rx="2.6" ry="17" fill="#1B2550"/><path d="M165 -12 Q166 -6 165 -2" stroke="#A9C1E8" fill="none"/>' +
      '<rect x="-40" y="-33" width="72" height="9" rx="2" fill="url(#obs-brass)" stroke="#6E5222" stroke-width=".8"/>' +
      '<rect x="-30" y="-25" width="4" height="10" fill="#8C6A2E"/><rect x="20" y="-25" width="4" height="10" fill="#8C6A2E"/>' +
      '<circle cx="0" cy="0" r="10" fill="#6E5222" stroke="#C9973F" stroke-width="1.5"/><circle cx="0" cy="0" r="3" fill="#C9973F"/></g>');
    return t.join('');
  }

  function sceneDefs() {
    return '<defs>' +
      '<linearGradient id="obs-brass" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#E7BE6A"/><stop offset=".35" stop-color="#C9973F"/><stop offset=".7" stop-color="#B8893A"/><stop offset="1" stop-color="#8C6A2E"/></linearGradient>' +
      '<radialGradient id="obs-glow"><stop offset="0" stop-color="#F2CE7A" stop-opacity=".32"/><stop offset="1" stop-color="#F2CE7A" stop-opacity="0"/></radialGradient>' +
      '<pattern id="obs-hatchC" width="4" height="4" patternUnits="userSpaceOnUse" patternTransform="rotate(60)"><path d="M0 2H4" stroke="#AEB6C3" stroke-opacity=".6" stroke-width=".8"/></pattern>' +
      '</defs>';
  }

  /* The left of the main screen: the desk, the student and the telescope, in the design's
     1440 × 900 coordinates cropped to what they occupy (the floor is drawn by CSS). */
  S.scene = function (config, lamp) {
    return '<svg class="scene-svg" viewBox="0 236 720 604" preserveAspectRatio="xMidYMax meet" role="img" aria-label="Le bureau de l’observatoire : vous, en blouse blanche, à côté du télescope" focusable="false">' +
      sceneDefs() + desk() + telescope() +
      '<g transform="translate(300 268) scale(1.25)">' + S.student(config, lamp) + '</g></svg>';
  };

  /* ---------- stars and brightness ---------- */

  // brightness → drawing: core radius, halo radius, rays (the design's ECR, 1.4 times larger
  // because the map is drawn smaller in the frame than in the design's 1440 × 900 stage)
  var K = 1.4;
  var LEVELS = {
    blazing: { r: 6.5 * K, h: 22 * K, ray: 20 * K },
    bright: { r: 5.5 * K, h: 16 * K, ray: 12 * K },
    clear: { r: 4.5 * K, h: 12 * K, ray: 0 },
    discrete: { r: 3.4 * K, h: 9 * K, ray: 0 }
  };
  S.LEVELS = LEVELS;

  function litStar(x, y, level, isNew) {
    var R = LEVELS[level] || LEVELS.discrete, s = '';
    if (isNew) s += '<circle class="ring-new" cx="' + x + '" cy="' + y + '" r="' + (R.h * 2.6).toFixed(1) + '" fill="none" stroke="#F2CE7A" stroke-width="1.5"/>';
    s += '<circle class="' + (isNew ? 'halo-new' : '') + '" cx="' + x + '" cy="' + y + '" r="' + R.h.toFixed(1) + '" fill="#D9A441" opacity=".18"/>';
    s += '<g class="' + (isNew ? 'core-new' : (level === 'blazing' ? 'blaze' : '')) + '">';
    if (R.ray) {
      var a = R.ray, w = 1.3 * K;
      s += '<path d="M' + x + ' ' + (y - a) + ' L' + (x + w) + ' ' + y + ' L' + x + ' ' + (y + a) + ' L' + (x - w) + ' ' + y + ' Z M' + (x - a) + ' ' + y + ' L' + x + ' ' + (y - w) + ' L' + (x + a) + ' ' + y + ' L' + x + ' ' + (y + w) + ' Z" fill="#F2CE7A" opacity=".85"/>';
    }
    s += '<circle cx="' + x + '" cy="' + y + '" r="' + R.r.toFixed(1) + '" fill="#F2CE7A"/><circle cx="' + x + '" cy="' + y + '" r="' + (R.r * .42).toFixed(1) + '" fill="#FFF6DC"/></g>';
    return s;
  }

  /* A star alone, for the cards and the end of a series: null draws the unlit point. */
  S.starIcon = function (level, size) {
    size = size || 22;
    var inner = level ? litStar(40, 40, level, false).replace(/ class="[^"]*"/g, '') : '<circle cx="40" cy="40" r="7" fill="#0D1430" stroke="#5D6894" stroke-width="2.5"/>';
    var bg = level ? '<circle cx="40" cy="40" r="38" fill="#D9A441" opacity=".08"/>' : '';
    return '<svg class="star-icon" viewBox="0 0 80 80" width="' + size + '" height="' + size + '" aria-hidden="true" focusable="false">' + bg + inner + '</svg>';
  };

  /* ---------- the sky map ---------- */

  // The five constellations, in the order of the chapters, and La Couronne at the centre
  // (the design's CONST and CROWN, in the map's 1000 × 1000 box).
  var CONST = {
    compas: { at: [150, 140], s: [[150, 18], [122, 70], [178, 70], [96, 122], [150, 92], [204, 122], [246, 52]], e: [[0, 1], [0, 2], [1, 3], [1, 4], [4, 2], [2, 5], [2, 6]] },
    sablier: { at: [510, 130], s: [[90, 18], [210, 18], [150, 72], [90, 124], [254, 44], [210, 124]], e: [[0, 1], [1, 2], [0, 2], [2, 3], [1, 4], [3, 5], [2, 5]] },
    balance: { at: [90, 560], s: [[150, 32], [50, 52], [250, 52], [150, 124], [50, 104]], e: [[0, 1], [0, 2], [0, 3], [1, 4]] },
    serpent: { at: [330, 740], s: [[28, 100], [70, 58], [110, 90], [150, 46], [190, 80], [232, 36], [272, 66]], e: [[0, 1], [1, 2], [2, 3], [3, 4], [4, 5], [5, 6]], labelAbove: true },
    des: { at: [620, 560], s: [[100, 30], [180, 30], [206, 62], [126, 62], [126, 124], [206, 124]], e: [[0, 1], [1, 2], [0, 3], [3, 2], [3, 4], [2, 5]] }
  };
  var CROWN = { at: [350, 390], s: [[40, 110], [80, 60], [130, 40], [170, 40], [220, 60], [260, 110], [100, 105], [150, 125], [200, 105], [150, 18], [58, 88], [242, 88]], e: [[0, 1], [1, 2], [2, 3], [3, 4], [4, 5], [1, 6], [6, 7], [7, 8], [8, 4], [2, 9], [9, 3], [0, 10], [5, 11]] };
  var SC = .9;

  /* Where each star of a chapter sits. A chapter the design did not draw, or with more units than
     drawn points, gets its points on a small arc, so a change of the manifest never breaks the map. */
  function positions(code, n, index) {
    var C = CONST[code];
    var pts = [];
    if (C && C.s.length >= n) {
      for (var i = 0; i < n; i++) pts.push([C.at[0] + C.s[i][0] * SC, C.at[1] + C.s[i][1] * SC]);
      return { pts: pts, edges: C.e.filter(function (e) { return e[0] < n && e[1] < n; }), label: [C.at[0] + 150 * SC, C.at[1] + (C.labelAbove ? -6 : 155 * SC)] };
    }
    var a0 = index * 72 - 90, cx = 500 + 300 * Math.cos(a0 * Math.PI / 180), cy = 500 + 300 * Math.sin(a0 * Math.PI / 180);
    for (var j = 0; j < n; j++) { var a = (j / Math.max(1, n - 1) - .5) * 1.4; pts.push([cx + 90 * Math.sin(a), cy - 40 + 60 * Math.cos(a) * (j % 2 ? .6 : 1)]); }
    var edges = []; for (var k = 1; k < n; k++) edges.push([k - 1, k]);
    return { pts: pts, edges: edges, label: [cx, cy + 70] };
  }

  function mapFrame() {
    var k = [];
    k.push('<circle cx="500" cy="500" r="492" fill="url(#obs-disc)" stroke="#B8893A" stroke-width="6"/>');
    k.push('<circle cx="500" cy="500" r="462" fill="none" stroke="#B8893A" stroke-opacity=".5" stroke-width="1.5"/>');
    k.push('<circle cx="500" cy="500" r="428" fill="none" stroke="#8C6A2E" stroke-opacity=".35" stroke-width=".8"/>');
    k.push('<circle cx="500" cy="500" r="330" fill="none" stroke="#2E3A6B" stroke-dasharray="2 6"/>');
    k.push('<circle cx="500" cy="500" r="170" fill="none" stroke="#2E3A6B" stroke-dasharray="2 6"/>');
    for (var a = 0; a < 360; a += 30) {
      var r = a * Math.PI / 180;
      k.push('<line x1="' + (500 + 170 * Math.cos(r)).toFixed(1) + '" y1="' + (500 + 170 * Math.sin(r)).toFixed(1) + '" x2="' + (500 + 428 * Math.cos(r)).toFixed(1) + '" y2="' + (500 + 428 * Math.sin(r)).toFixed(1) + '" stroke="#1E2852" stroke-width=".8"/>');
    }
    for (var b = 0; b < 360; b += 5) {
      var rb = b * Math.PI / 180, l = b % 30 === 0 ? 20 : 9;
      k.push('<line x1="' + (500 + 492 * Math.cos(rb)).toFixed(1) + '" y1="' + (500 + 492 * Math.sin(rb)).toFixed(1) + '" x2="' + (500 + (492 - l) * Math.cos(rb)).toFixed(1) + '" y2="' + (500 + (492 - l) * Math.sin(rb)).toFixed(1) + '" stroke="#B8893A" stroke-width="' + (b % 30 === 0 ? 2 : 1) + '"/>');
    }
    ['XII', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI'].forEach(function (t, h) {
      var rh = (h * 30 - 90) * Math.PI / 180;
      k.push('<text x="' + (500 + 446 * Math.cos(rh)).toFixed(1) + '" y="' + (500 + 446 * Math.sin(rh) + 7).toFixed(1) + '" text-anchor="middle" font-family="Cormorant Garamond, Georgia, serif" font-weight="600" font-size="22" fill="#A07A35">' + t + '</text>');
    });
    var rr = rng(3);
    for (var j = 0; j < 90; j++) {
      var an = rr() * 6.28, d = Math.sqrt(rr()) * 420;
      k.push('<circle cx="' + (500 + d * Math.cos(an)).toFixed(1) + '" cy="' + (500 + d * Math.sin(an)).toFixed(1) + '" r=".9" fill="#EDE6D6" opacity=".25"/>');
    }
    return k.join('');
  }

  function edgeLine(P, a, b, on, revealed) {
    return '<line x1="' + P[a][0].toFixed(1) + '" y1="' + P[a][1].toFixed(1) + '" x2="' + P[b][0].toFixed(1) + '" y2="' + P[b][1].toFixed(1) + '" stroke="' + (on ? '#D9A441' : revealed ? '#4A5688' : '#3A4677') + '" stroke-opacity="' + (on ? .75 : 1) + '" stroke-width="' + (on ? 1.8 : 1.2) + '"' + (on || revealed ? '' : ' stroke-dasharray="3 5"') + '/>';
  }

  function unlitStar(x, y, hidden) {
    if (hidden) return '<circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="3.5" fill="none" stroke="#2E3A6B" stroke-width="1.2" stroke-dasharray="2 2"/>';
    return '<circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="' + (3.4 * K).toFixed(1) + '" fill="#0D1430" stroke="#5D6894" stroke-width="1.6"/>';
  }

  /* A comet crossing the map near the star of its unit: the head towards the centre, the tail
     pointing back out. Drifts slowly (stopped under reduced motion). */
  function comet(x, y, label, n) {
    var dx = 500 - x, dy = 500 - y, len = Math.sqrt(dx * dx + dy * dy) || 1;
    var hx = x + dx / len * (70 + n * 26) + dy / len * 30, hy = y + dy / len * (70 + n * 26) - dx / len * 30;
    var ang = Math.atan2(-dy, -dx) * 180 / Math.PI; // the tail points away from the centre
    var lx = hx + (dx > 0 ? 18 : -18), anchor = dx > 0 ? 'start' : 'end';
    return {
      head: [hx, hy],
      svg: '<g class="comet"><g class="comet-drift">' +
        '<g transform="translate(' + hx.toFixed(1) + ' ' + hy.toFixed(1) + ') rotate(' + ang.toFixed(1) + ')">' +
        '<path d="M0 -7 L170 -1.5 L170 1.5 L0 7 Z" fill="url(#obs-tail)"/><path d="M0 -3 L120 -.6 L120 .6 L0 3 Z" fill="#FFF6DC" opacity=".55"/>' +
        '<circle r="16" fill="#A9C1E8" opacity=".2"/><circle r="6.5" fill="#EDF3FF"/><circle r="2.6" fill="#fff"/></g>' +
        '<text x="' + lx.toFixed(1) + '" y="' + (hy - 14).toFixed(1) + '" text-anchor="' + anchor + '" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-weight="600" font-size="28" fill="#BCD0F0">☄ ' + esc(label) + '</text>' +
        '</g></g>'
    };
  }

  /* The whole map. Returns the SVG and the points the page turns into buttons:
       {svg, spots: [{kind: 'star'|'comet'|'crown', x, y, unit?, comet?}]} (x, y in 0..1000).
     opts: {selected: unitCode, fresh: unitCode (a star just lit, played once)} */
  S.skyMap = function (progress, opts) {
    opts = opts || {};
    var k = [], spots = [], starAt = {};
    var defs = '<defs><radialGradient id="obs-disc"><stop offset=".6" stop-color="#0D1430"/><stop offset="1" stop-color="#0A1028"/></radialGradient>' +
      '<linearGradient id="obs-tail" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#A9C1E8" stop-opacity=".75"/><stop offset="1" stop-color="#A9C1E8" stop-opacity="0"/></linearGradient>' +
      '<clipPath id="obs-mapclip"><circle cx="500" cy="500" r="458"/></clipPath></defs>';
    k.push(mapFrame());

    var units = progress.units || [], chapters = progress.chapters || [];
    chapters.forEach(function (ch, ci) {
      var list = units.filter(function (u) { return u.chapter === ch.code; });
      var g = positions(ch.code, list.length, ci), P = g.pts;
      g.edges.forEach(function (e) {
        var a = list[e[0]], b = list[e[1]];
        k.push(edgeLine(P, e[0], e[1], a.lit && b.lit && !a.hidden && !b.hidden, false));
      });
      list.forEach(function (u, j) {
        var x = P[j][0], y = P[j][1];
        starAt[u.code] = [x, y];
        if (u.hidden) { k.push(unlitStar(x, y, true)); return; }
        k.push(u.lit ? litStar(+x.toFixed(1), +y.toFixed(1), u.brightness || 'discrete', opts.fresh === u.code) : unlitStar(x, y, false));
        if (opts.selected === u.code) {
          k.push('<circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="30" fill="none" stroke="#F2CE7A" stroke-width="2"/><circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="38" fill="none" stroke="#F2CE7A" stroke-opacity=".5" stroke-dasharray="3 4"/>');
        }
        spots.push({ kind: 'star', x: x, y: y, unit: u.code });
      });
      var full = ch.total > 0 && ch.lit >= ch.total;
      k.push('<text x="' + g.label[0].toFixed(1) + '" y="' + g.label[1].toFixed(1) + '" text-anchor="middle" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-weight="600" font-size="33" fill="' + (full ? '#D9A441' : '#A3ACD0') + '">' + esc(ch.label) + '  ' + ch.lit + '/' + ch.total + '</text>');
    });

    // La Couronne: dotted with « ? » until every star is lit, then its stars light with the best score
    var fin = progress.final || {}, CP = CROWN.s.map(function (s) { return [CROWN.at[0] + s[0] * SC, CROWN.at[1] + s[1] * SC]; });
    if (!fin.unlocked) {
      CROWN.e.forEach(function (e) { k.push('<line x1="' + CP[e[0]][0] + '" y1="' + CP[e[0]][1] + '" x2="' + CP[e[1]][0] + '" y2="' + CP[e[1]][1] + '" stroke="#26305C" stroke-dasharray="2 5"/>'); });
      k.push('<text x="' + (CROWN.at[0] + 150 * SC) + '" y="' + (CROWN.at[1] + 92 * SC) + '" text-anchor="middle" font-family="Cormorant Garamond, Georgia, serif" font-size="80" fill="#36417A">?</text>');
    } else {
      var best = fin.best || 0;
      CROWN.e.forEach(function (e) { k.push(edgeLine(CP, e[0], e[1], e[0] < best && e[1] < best, true)); });
      CP.forEach(function (p, j) { k.push(j < best ? litStar(+p[0].toFixed(1), +p[1].toFixed(1), best >= 12 ? 'blazing' : 'bright', false) : unlitStar(p[0], p[1], false)); });
      k.push('<text x="' + (CROWN.at[0] + 150 * SC) + '" y="' + (CROWN.at[1] + 160 * SC) + '" text-anchor="middle" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-weight="600" font-size="31" fill="#D9A441">' + esc(fin.label || 'La Couronne') + (fin.best !== null && fin.best !== undefined ? '  ' + fin.best + '/' + (fin.size || 12) : '') + '</text>');
      spots.push({ kind: 'crown', x: CROWN.at[0] + 150 * SC, y: CROWN.at[1] + 75 * SC });
    }

    // comets in view, each near the star of its unit
    var perUnit = {}, cm = [];
    (progress.comets || []).forEach(function (c) {
      var at = starAt[c.unit] || [500, 500], n = perUnit[c.unit] = (perUnit[c.unit] || 0) + 1;
      var drawn = comet(at[0], at[1], c.label, n - 1);
      cm.push(drawn.svg);
      spots.push({ kind: 'comet', x: drawn.head[0], y: drawn.head[1], comet: c.misconception });
    });
    k.push('<g clip-path="url(#obs-mapclip)">' + cm.join('') + '</g>');

    return {
      svg: '<svg class="map-svg" viewBox="0 0 1000 1000" aria-hidden="true" focusable="false">' + defs + k.join('') + '</svg>',
      spots: spots
    };
  };

  /* La Couronne drawn alone for the review of the final test: `score` stars lit. */
  S.crown = function (score, size) {
    var P = CROWN.s.map(function (s) { return [s[0] * 1.3 + 20, s[1] * 1.3 + 20]; }), k = [];
    CROWN.e.forEach(function (e) { k.push(edgeLine(P, e[0], e[1], e[0] < score && e[1] < score, false)); });
    P.forEach(function (p, j) { k.push(j < score ? litStar(+p[0].toFixed(1), +p[1].toFixed(1), 'bright', false) : unlitStar(p[0], p[1], false)); });
    return '<svg class="crown-svg" viewBox="0 0 430 210" width="' + (size || 380) + '" role="img" aria-label="La Couronne : ' + score + ' étoiles allumées sur 12" focusable="false">' + k.join('') + '</svg>';
  };

  /* The comet alone, for the study screen. */
  S.cometArt = function () {
    return '<svg class="comet-art" viewBox="0 0 440 120" aria-hidden="true" focusable="false"><defs><linearGradient id="obs-tail2" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#A9C1E8" stop-opacity=".8"/><stop offset="1" stop-color="#A9C1E8" stop-opacity="0"/></linearGradient></defs>' +
      '<g transform="translate(400 30) rotate(165)"><path d="M0 -8 L400 -1.5 L400 1.5 L0 8 Z" fill="url(#obs-tail2)"/><path d="M0 -3 L260 -.6 L260 .6 L0 3 Z" fill="#FFF6DC" opacity=".55"/></g>' +
      '<circle cx="400" cy="30" r="22" fill="#A9C1E8" opacity=".18"/><circle cx="400" cy="30" r="9" fill="#EDF3FF"/><circle cx="400" cy="30" r="3.5" fill="#fff"/></svg>';
  };

  root.OBSScene = S;
})(typeof globalThis !== 'undefined' ? globalThis : window);
