/* Panique à la Neko-Con — the characters, drawn in SVG.

   NekoChars.svg(who, expression, { crop: 'bust' | 'head' | 'full' }) returns an <svg> ready to insert.
   A character is a shared body plus an expression layer (eyebrows, eyes, mouth, extras).
   Characters: maelle (club president), ines (tutor), theo (treasurer), mika (guest mangaka). */
(function (root) {
  'use strict';

  var OL = 'stroke="#3a2f52" stroke-width="2" stroke-linejoin="round"';
  var LN = 'fill="none" stroke="#3a2f52" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"';

  function star(cx, cy, R, r) {
    var p = [];
    for (var i = 0; i < 10; i++) {
      var a = -Math.PI / 2 + i * Math.PI / 5, rad = i % 2 ? r : R;
      p.push((cx + rad * Math.cos(a)).toFixed(1) + ',' + (cy + rad * Math.sin(a)).toFixed(1));
    }
    return p.join(' ');
  }
  function spark(x, y, s, fill, cls) {
    return '<path class="' + (cls || 'nc-spark') + '" d="M' + x + ' ' + (y - s) + ' L' + (x + s * .3) + ' ' + (y - s * .3) + ' L' + (x + s) + ' ' + y + ' L' + (x + s * .3) + ' ' + (y + s * .3) + ' L' + x + ' ' + (y + s) + ' L' + (x - s * .3) + ' ' + (y + s * .3) + ' L' + (x - s) + ' ' + y + ' L' + (x - s * .3) + ' ' + (y - s * .3) + ' Z" fill="' + fill + '"/>';
  }
  function drop(x, y) {
    return '<path d="M' + x + ' ' + y + ' C' + (x - 10) + ' ' + (y + 18) + ' ' + (x - 12) + ' ' + (y + 30) + ' ' + (x - 2) + ' ' + (y + 36) + ' C' + (x + 8) + ' ' + (y + 40) + ' ' + (x + 16) + ' ' + (y + 30) + ' ' + (x + 12) + ' ' + (y + 18) + ' Z" fill="#bfe6ff" ' + OL + '/>';
  }

  // Open « anime » eyes: iris, highlights, upper lash.
  function animeEye(cx, cy, iris, irisLight, dark, side, small) {
    var rx = small ? 8 : 15, ry = small ? 10 : 19;
    var s = side < 0;
    var sclera = s
      ? 'M' + (cx - 24) + ' ' + (cy - 2) + ' C' + (cx - 18) + ' ' + (cy - 22) + ' ' + (cx + 16) + ' ' + (cy - 24) + ' ' + (cx + 24) + ' ' + (cy - 4) + ' C' + (cx + 20) + ' ' + (cy + 20) + ' ' + (cx - 16) + ' ' + (cy + 22) + ' ' + (cx - 24) + ' ' + (cy - 2) + ' Z'
      : 'M' + (cx - 24) + ' ' + (cy - 4) + ' C' + (cx - 16) + ' ' + (cy - 24) + ' ' + (cx + 18) + ' ' + (cy - 22) + ' ' + (cx + 24) + ' ' + (cy - 2) + ' C' + (cx + 16) + ' ' + (cy + 22) + ' ' + (cx - 20) + ' ' + (cy + 20) + ' ' + (cx - 24) + ' ' + (cy - 4) + ' Z';
    return '<path d="' + sclera + '" fill="#fff"/>' +
      '<ellipse cx="' + cx + '" cy="' + (cy + 1) + '" rx="' + rx + '" ry="' + ry + '" fill="' + iris + '"/>' +
      (small ? '' : '<ellipse cx="' + cx + '" cy="' + (cy + 6) + '" rx="11" ry="12" fill="' + irisLight + '"/>') +
      '<circle cx="' + cx + '" cy="' + (cy + 2) + '" r="' + (small ? 4 : 6) + '" fill="' + dark + '"/>' +
      '<circle cx="' + (cx - 5) + '" cy="' + (cy - 7) + '" r="' + (small ? 2.5 : 5.5) + '" fill="#fff"/>' +
      (small ? '' : '<circle cx="' + (cx + 5) + '" cy="' + (cy + 10) + '" r="2.4" fill="#fff"/>') +
      '<path d="M' + (cx - 28) + ' ' + (cy - 4) + ' C' + (cx - 20) + ' ' + (cy - 28) + ' ' + (cx + 20) + ' ' + (cy - 28) + ' ' + (cx + 28) + ' ' + (cy - 4) + '" fill="none" stroke="' + dark + '" stroke-width="5.5" stroke-linecap="round"/>' +
      '<path d="M' + (cx + side * 28) + ' ' + (cy - 4) + ' L' + (cx + side * 36) + ' ' + (cy - 10) + '" stroke="' + dark + '" stroke-width="4" stroke-linecap="round"/>';
  }
  function happyEyes(lx, rx, y, dark) {
    return '<path d="M' + (lx - 20) + ' ' + (y + 4) + ' C' + (lx - 12) + ' ' + (y - 12) + ' ' + (lx + 12) + ' ' + (y - 12) + ' ' + (lx + 20) + ' ' + (y + 4) + '" fill="none" stroke="' + dark + '" stroke-width="5.5" stroke-linecap="round"/>' +
      '<path d="M' + (rx - 20) + ' ' + (y + 4) + ' C' + (rx - 12) + ' ' + (y - 12) + ' ' + (rx + 12) + ' ' + (y - 12) + ' ' + (rx + 20) + ' ' + (y + 4) + '" fill="none" stroke="' + dark + '" stroke-width="5.5" stroke-linecap="round"/>';
  }
  function blush(lx, rx, y, strong) {
    return '<ellipse cx="' + lx + '" cy="' + y + '" rx="17" ry="8" fill="#ff8a96" opacity="' + (strong ? .8 : .55) + '"/>' +
      '<ellipse cx="' + rx + '" cy="' + y + '" rx="17" ry="8" fill="#ff8a96" opacity="' + (strong ? .8 : .55) + '"/>';
  }

  // ——— Maëlle ———
  var MAELLE_HAIR = '#3b2a4a';
  function maelleBody() {
    return '<path d="M110 170 C60 200 40 280 60 360 C70 330 86 300 100 280 C96 250 100 210 120 186 Z" fill="' + MAELLE_HAIR + '" ' + OL + '/>' +
      '<path d="M250 170 C300 200 320 280 300 360 C290 330 274 300 260 280 C264 250 260 210 240 186 Z" fill="' + MAELLE_HAIR + '" ' + OL + '/>' +
      '<circle cx="116" cy="182" r="12" fill="#ff6f91" ' + OL + '/><circle cx="244" cy="182" r="12" fill="#ff6f91" ' + OL + '/>' +
      '<path d="M64 560 C70 440 112 370 160 344 L200 344 C248 370 290 440 296 560 Z" fill="#e8457a" ' + OL + '/>' +
      '<path d="M166 470 L172 500 L180 482 L188 500 L194 470" fill="none" stroke="#ffd166" stroke-width="3"/>' +
      '<path d="M164 350 L174 432 M196 350 L186 432" fill="none" stroke="#ffd166" stroke-width="5" stroke-linecap="round"/>' +
      '<rect x="150" y="428" width="60" height="40" rx="6" fill="#fbf9ff" ' + OL + '/>' +
      '<text x="180" y="454" text-anchor="middle" font-family="Dela Gothic One, sans-serif" font-size="14" fill="#c2185b">ORGA</text>' +
      '<path d="M164 290 L162 344 L198 344 L196 290 Z" fill="#f5c9b4"/>' +
      '<path d="M160 344 C170 360 190 360 200 344" ' + LN + ' stroke-width="2"/>' +
      '<path d="M96 222 C88 130 128 76 180 76 C234 76 274 130 264 222 C256 196 248 178 240 168 L120 168 C110 182 102 200 96 222 Z" fill="' + MAELLE_HAIR + '"/>' +
      '<path d="M116 206 C116 140 144 112 180 112 C218 112 244 140 244 206 C244 258 216 294 180 298 C144 294 116 258 116 206 Z" fill="#ffe4d4" ' + OL + '/>' +
      '<path d="M112 196 C110 140 142 104 180 104 C220 104 252 140 248 196 C242 178 236 166 228 158 L222 180 L206 152 L194 178 L180 150 L166 178 L154 152 L140 180 L132 158 C122 168 116 182 112 196 Z" fill="' + MAELLE_HAIR + '"/>' +
      '<path d="M182 104 C176 86 190 72 202 78 C192 82 188 92 188 104 Z" fill="' + MAELLE_HAIR + '"/>' +
      '<path d="M140 122 C156 112 194 110 216 118" fill="none" stroke="#7a5a96" stroke-width="4" stroke-linecap="round"/>' +
      '<path d="M118 150 C140 112 220 112 242 150" fill="none" stroke="#2b2350" stroke-width="7" stroke-linecap="round"/>' +
      '<path d="M128 132 L120 84 L158 112 Z" fill="#2b2350"/><path d="M132 124 L127 96 L150 113 Z" fill="#ff6f91"/>' +
      '<path d="M232 132 L240 84 L202 112 Z" fill="#2b2350"/><path d="M228 124 L233 96 L210 113 Z" fill="#ff6f91"/>' +
      '<path d="M180 236 L178 241" ' + LN + ' stroke-width="2"/>';
  }
  function maelleFace(expr) {
    var D = '#2a1840';
    switch (expr) {
      case 'joie':
        return '<path d="M134 172 C142 164 154 164 164 168 M196 168 C206 164 218 164 228 172" ' + LN + '/>' +
          happyEyes(152, 208, 212, D) + blush(136, 224, 242, true) +
          '<path d="M158 254 C164 282 196 282 202 254 Z" fill="#b8405a" ' + OL + '/><ellipse cx="180" cy="270" rx="11" ry="5" fill="#ff8a8a"/>';
      case 'inquiete':
        return '<path d="M134 180 C144 176 156 170 164 164 M196 164 C204 170 216 176 226 180" ' + LN + '/>' +
          animeEye(152, 214, '#6a3fb0', '#a77be0', D, -1) + animeEye(208, 214, '#6a3fb0', '#a77be0', D, 1) + blush(136, 244, 244) +
          '<path d="M168 266 C174 260 186 260 192 266 C186 272 174 272 168 266 Z" fill="#b8405a" ' + OL + '/>' + drop(262, 150);
      case 'paniquee':
        return '<path d="M132 182 C142 176 154 168 164 160 M196 160 C206 168 218 176 228 182" ' + LN + '/>' +
          '<path d="M140 128 L140 150 M156 124 L156 148 M172 122 L172 146" stroke="#6f86c9" stroke-width="3" stroke-linecap="round" opacity=".7"/>' +
          animeEye(152, 214, '#6a3fb0', '#a77be0', D, -1, true) + animeEye(208, 214, '#6a3fb0', '#a77be0', D, 1, true) +
          '<path d="M162 262 C168 254 174 270 180 262 C186 254 192 270 198 262 L198 276 C186 282 174 282 162 276 Z" fill="#b8405a" ' + OL + '/>' +
          drop(262, 146) + drop(96, 170);
      case 'etoiles':
        return '<path d="M132 172 C142 164 154 164 164 168 M196 168 C206 164 218 164 228 172" ' + LN + '/>' +
          '<polygon class="nc-star" points="' + star(152, 212, 18, 7.5) + '" fill="#ffd166" stroke="' + D + '" stroke-width="3" stroke-linejoin="round"/>' +
          '<polygon class="nc-star" points="' + star(208, 212, 18, 7.5) + '" fill="#ffd166" stroke="' + D + '" stroke-width="3" stroke-linejoin="round"/>' +
          blush(134, 226, 244, true) +
          '<path d="M156 252 C162 290 198 290 204 252 Z" fill="#b8405a" ' + OL + '/><ellipse cx="180" cy="274" rx="13" ry="6" fill="#ff8a8a"/>' +
          spark(310, 90, 18, '#ffd166') + spark(46, 126, 14, '#ffd166', 'nc-spark nc-spark2') + spark(320, 262, 10, '#fff', 'nc-spark nc-spark2');
      default: // 'neutre'
        return '<path d="M134 176 C142 170 154 170 164 172 M196 172 C206 170 218 170 226 176" ' + LN + '/>' +
          animeEye(152, 214, '#6a3fb0', '#a77be0', D, -1) + animeEye(208, 214, '#6a3fb0', '#a77be0', D, 1) + blush(136, 224, 244) +
          '<path d="M168 262 C174 270 186 270 192 262" ' + LN + '/>';
    }
  }

  // ——— Inès ———
  function inesBody() {
    return '<path d="M56 560 C62 440 106 368 158 336 L208 336 C260 368 302 440 308 560 Z" fill="#3fa7b5" ' + OL + '/>' +
      '<path d="M120 344 C140 330 226 330 246 344 C232 372 134 372 120 344 Z" fill="#2f8f9c" ' + OL + '/>' +
      '<path d="M170 372 L168 420 M196 372 L198 420" stroke="#fbf9ff" stroke-width="3" stroke-linecap="round"/>' +
      '<path d="M166 296 L164 336 L202 336 L200 296 Z" fill="#e6bba0"/>' +
      '<path d="M118 312 C130 352 234 352 246 312" fill="none" stroke="#1d1830" stroke-width="10" stroke-linecap="round"/>' +
      '<circle cx="118" cy="312" r="15" fill="#1d1830"/><circle cx="246" cy="312" r="15" fill="#1d1830"/>' +
      '<path d="M92 240 C80 140 124 70 182 70 C244 70 286 140 272 244 C262 214 254 190 244 176 L120 176 C110 192 100 214 92 240 Z" fill="#2b2b3a"/>' +
      '<path d="M96 200 C92 240 96 270 110 292 L130 292 C118 262 116 230 120 200 Z M268 200 C272 240 268 270 254 292 L234 292 C246 262 248 230 244 200 Z" fill="#2b2b3a"/>' +
      '<path d="M114 204 C114 136 144 108 182 108 C222 108 250 136 250 204 C250 258 222 296 184 302 C144 296 114 258 114 204 Z" fill="#f3d3bb" ' + OL + '/>' +
      '<path d="M110 196 C108 140 140 102 182 102 C226 102 256 140 254 196 C250 176 244 162 236 152 L230 178 L214 150 L200 176 L186 148 L170 174 L156 148 L142 174 L130 152 C122 164 114 178 110 196 Z" fill="#2b2b3a"/>' +
      '<path d="M136 124 C156 112 198 110 224 122" fill="none" stroke="#6f86c9" stroke-width="5" stroke-linecap="round"/>' +
      '<path d="M182 240 L180 248" ' + LN + ' stroke-width="2"/>';
  }
  function inesEyes(lid, open) {
    var skin = '#f3d3bb', s = '';
    s += '<ellipse cx="152" cy="219" rx="12" ry="' + (open ? 13 : 10) + '" fill="#2f4a7a"/><circle cx="148" cy="' + (open ? 212 : 222) + '" r="' + (open ? 3 : 2.5) + '" fill="#fff"/>';
    s += '<ellipse cx="212" cy="219" rx="12" ry="' + (open ? 13 : 10) + '" fill="#2f4a7a"/><circle cx="208" cy="' + (open ? 212 : 222) + '" r="' + (open ? 3 : 2.5) + '" fill="#fff"/>';
    if (!open) {
      s += '<path d="M124 196 L180 196 L180 ' + lid + ' C166 ' + (lid - 5) + ' 140 ' + (lid - 5) + ' 124 ' + lid + ' Z M184 196 L240 196 L240 ' + lid + ' C224 ' + (lid - 5) + ' 198 ' + (lid - 5) + ' 184 ' + lid + ' Z" fill="' + skin + '"/>';
      s += '<path d="M126 ' + lid + ' C140 ' + (lid - 5) + ' 166 ' + (lid - 5) + ' 178 ' + lid + ' M186 ' + lid + ' C198 ' + (lid - 5) + ' 224 ' + (lid - 5) + ' 238 ' + lid + '" fill="none" stroke="#1d1830" stroke-width="4.5" stroke-linecap="round"/>';
    } else {
      s += '<path d="M128 202 C140 196 164 196 176 202 M188 202 C200 196 224 196 236 202" fill="none" stroke="#1d1830" stroke-width="4" stroke-linecap="round"/>';
    }
    return s;
  }
  var GLASSES = '<rect x="122" y="198" width="60" height="38" rx="13" fill="none" stroke="#1d1830" stroke-width="3"/><rect x="184" y="198" width="60" height="38" rx="13" fill="none" stroke="#1d1830" stroke-width="3"/><path d="M182 214 L184 214" stroke="#1d1830" stroke-width="3"/>';
  function inesFace(expr) {
    switch (expr) {
      case 'sourcil':
        return inesEyes(210, false) + GLASSES +
          '<path d="M130 186 L170 188" ' + LN + '/><path d="M194 182 C206 170 222 170 236 178" ' + LN + '/>' +
          '<path d="M172 270 L194 266" ' + LN + '/>';
      case 'soupir':
        return '<path d="M128 218 C140 224 166 224 176 218 M188 218 C200 224 226 224 236 218" fill="none" stroke="#1d1830" stroke-width="4.5" stroke-linecap="round"/>' + GLASSES +
          '<path d="M130 190 L170 186 M194 186 L234 190" ' + LN + '/>' +
          '<ellipse cx="183" cy="270" rx="6" ry="5" fill="#9a4a4a" ' + OL + '/>' +
          '<path d="M222 262 C240 258 252 266 262 260 C270 256 278 262 288 258" fill="none" stroke="#cfc8e6" stroke-width="4" stroke-linecap="round"/>';
      case 'sourire':
        return inesEyes(210, false) + GLASSES + '<path d="M130 188 L170 186 M194 186 L234 188" ' + LN + '/>' +
          blush(140, 226, 250) + '<path d="M170 264 C178 272 188 272 196 264" ' + LN + '/>';
      case 'surprise':
        return inesEyes(0, true) + GLASSES + '<path d="M130 180 C140 174 160 174 170 178 M194 178 C204 174 224 174 234 180" ' + LN + '/>' +
          '<ellipse cx="183" cy="270" rx="7" ry="9" fill="#9a4a4a" ' + OL + '/>';
      default: // 'neutre'
        return inesEyes(210, false) + GLASSES + '<path d="M130 188 L170 188 M194 188 L234 188" ' + LN + '/>' +
          '<path d="M172 268 L194 268" ' + LN + '/>';
    }
  }

  // ——— Théo, treasurer ———
  function theoBody() {
    return '<path d="M58 560 C64 440 108 372 160 340 L204 340 C256 372 300 440 306 560 Z" fill="#f29a3a" ' + OL + '/>' +
      '<path d="M150 340 C160 380 204 380 214 340" fill="#e2852a" ' + OL + '/>' +
      '<path d="M164 292 L162 340 L202 340 L200 292 Z" fill="#d9a07c"/>' +
      '<path d="M112 208 C112 140 142 110 182 110 C222 110 250 140 250 208 C250 258 222 294 182 298 C142 294 112 258 112 208 Z" fill="#f0c3a0" ' + OL + '/>' +
      '<path d="M104 196 C100 120 150 84 190 88 L178 70 L204 88 L206 66 L222 94 L240 80 L238 104 C256 120 262 156 258 196 C248 170 232 150 210 140 L214 158 L194 138 L188 158 L172 136 L160 156 L152 134 C128 146 112 168 104 196 Z" fill="#5a3a22"/>' +
      '<path d="M182 236 L180 244" ' + LN + ' stroke-width="2"/>';
  }
  function theoFace(expr) {
    var eyes = '<ellipse cx="150" cy="214" rx="9" ry="12" fill="#3a2a1a"/><circle cx="147" cy="209" r="3" fill="#fff"/>' +
      '<ellipse cx="212" cy="214" rx="9" ry="12" fill="#3a2a1a"/><circle cx="209" cy="209" r="3" fill="#fff"/>';
    if (expr === 'inquiet') {
      return eyes + '<path d="M130 190 C140 186 152 180 162 174 M200 174 C210 180 222 186 232 190" ' + LN + '/>' +
        '<path d="M168 268 C174 262 188 262 194 268" ' + LN + '/>' + drop(258, 160);
    }
    if (expr === 'joie') {
      return happyEyes(150, 212, 214, '#3a2a1a') + '<path d="M132 184 C142 178 154 178 162 180 M200 180 C208 178 220 178 230 184" ' + LN + '/>' +
        '<path d="M160 258 C166 280 196 280 202 258 Z" fill="#a8483a" ' + OL + '/>';
    }
    return eyes + '<path d="M132 186 C142 182 154 182 162 184 M200 184 C208 182 220 182 230 186" ' + LN + '/>' +
      '<path d="M168 264 C176 270 188 270 194 264" ' + LN + '/>';
  }

  // ——— Mika S., mangaka ———
  function mikaBody() {
    return '<path d="M60 560 C66 440 110 372 160 340 L204 340 C254 372 298 440 304 560 Z" fill="#2b2350" ' + OL + '/>' +
      '<path d="M150 344 L182 400 L214 344" fill="#fbf9ff" ' + OL + '/>' +
      '<path d="M164 292 L162 344 L200 344 L198 292 Z" fill="#f5d0bd"/>' +
      '<path d="M98 260 C84 180 116 104 182 104 C248 104 280 180 266 260 C254 236 250 214 244 196 L120 196 C114 214 110 236 98 260 Z" fill="#c9c4d8"/>' +
      '<path d="M114 206 C114 140 144 112 182 112 C220 112 248 140 248 206 C248 258 220 294 182 298 C144 294 114 258 114 206 Z" fill="#ffe8da" ' + OL + '/>' +
      '<path d="M110 200 C112 146 140 118 182 118 C226 118 252 146 252 200 C230 180 204 166 172 164 C150 166 128 180 110 200 Z" fill="#c9c4d8"/>' +
      '<path d="M104 150 C110 96 250 92 262 146 C272 150 270 162 258 160 C220 136 144 136 108 162 C96 164 94 152 104 150 Z" fill="#e8457a" ' + OL + '/>' +
      '<circle cx="186" cy="100" r="7" fill="#e8457a" ' + OL + '/>' +
      '<path d="M266 330 L300 210" stroke="#2b2350" stroke-width="6" stroke-linecap="round"/><path d="M300 210 L304 196" stroke="#ffd166" stroke-width="6" stroke-linecap="round"/>' +
      '<path d="M182 238 L180 244" ' + LN + ' stroke-width="2"/>';
  }
  function mikaFace(expr) {
    var D = '#3a2a4a';
    if (expr === 'joie') return happyEyes(152, 212, 214, D) + blush(136, 228, 244, true) + '<path d="M162 258 C168 282 196 282 202 258 Z" fill="#b8405a" ' + OL + '/>';
    return animeEye(152, 214, '#3f8fa8', '#7fc6d8', D, -1) + animeEye(212, 214, '#3f8fa8', '#7fc6d8', D, 1) + blush(136, 228, 246) +
      '<path d="M170 264 C178 270 188 270 194 264" ' + LN + '/>';
  }

  var CAST = {
    maelle: { name: 'Maëlle', role: 'présidente du club manga', color: '#ff6f91', body: maelleBody, face: maelleFace, bg: '#ffb8cf' },
    ines: { name: 'Inès', role: 'ta tutrice', color: '#2bb3b1', body: inesBody, face: inesFace, bg: '#7fd8d6' },
    theo: { name: 'Théo', role: 'trésorier du club', color: '#f29a3a', body: theoBody, face: theoFace, bg: '#ffd9a8' },
    mika: { name: 'Mika S.', role: 'mangaka invitée', color: '#7a5ae0', body: mikaBody, face: mikaFace, bg: '#cfc2ff' }
  };
  var CROPS = { full: '0 0 360 560', bust: '72 40 216 300', head: '96 64 168 236' };

  function svg(who, expr, opts) {
    var c = CAST[who];
    if (!c) return '';
    var crop = CROPS[(opts && opts.crop) || 'full'];
    var label = c.name + (expr && expr !== 'neutre' ? ' (' + expr + ')' : '');
    return '<svg class="nc-char nc-char-' + who + '" viewBox="' + crop + '" role="img" aria-label="' + label + '" preserveAspectRatio="xMidYMax meet">' +
      c.body() + '<g class="nc-face">' + c.face(expr || 'neutre') + '</g></svg>';
  }

  root.NekoChars = { svg: svg, cast: CAST };
})(typeof globalThis !== 'undefined' ? globalThis : window);
