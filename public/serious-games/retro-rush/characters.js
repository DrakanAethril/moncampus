/* Rétro Rush — the cast, drawn in SVG (direction E, « plastique translucide »).

   RetroChars.svg(who, expr, crop) returns an <svg> string: crop 'buste' (default) or 'tete'.
   A character is a body that never changes plus a face layer (brows, eyes, mouth, extras): the
   expressions change the face, never the body. Construction: 4 px ink outline, flat colours, one
   flat shadow on the face, a glacier rim light on the left and a raspberry one on the right (the
   translucent shell the portrait sits in). Cast: jade (tutor), gilles (founder of « Continue ? »),
   oceane (shop assistant), ferrand (a depositor). */
(function (root) {
  'use strict';
  var INK = '#1E1433';
  function P(d, fill, extra) { return '<path d="' + d + '" fill="' + (fill || 'none') + '"' + (extra || ' stroke="' + INK + '" stroke-width="4"') + '/>'; }

  var FACE = {
    almond: 'M124 176 C130 168 144 168 150 176 C144 181 130 181 124 176 Z M170 176 C176 168 190 168 196 176 C190 181 176 181 170 176 Z',
    pupils: 'M132.5 175 a5.5 5.5 0 1 0 11 0 a5.5 5.5 0 1 0 -11 0 Z M178.5 175 a5.5 5.5 0 1 0 11 0 a5.5 5.5 0 1 0 -11 0 Z',
    small: 'M134 174 a4 4 0 1 0 8 0 a4 4 0 1 0 -8 0 Z M178 174 a4 4 0 1 0 8 0 a4 4 0 1 0 -8 0 Z',
    tiny: 'M134.5 175 a2.5 2.5 0 1 0 5 0 a2.5 2.5 0 1 0 -5 0 Z M180.5 175 a2.5 2.5 0 1 0 5 0 a2.5 2.5 0 1 0 -5 0 Z',
    side: 'M140 175 a5 5 0 1 0 10 0 a5 5 0 1 0 -10 0 Z M186 175 a5 5 0 1 0 10 0 a5 5 0 1 0 -10 0 Z',
    happy: 'M124 178 C130 168 144 168 150 178 M170 178 C176 168 190 168 196 178',
    wide: 'M124 176 C128 164 146 164 150 176 C146 186 128 186 124 176 Z M170 176 C174 164 192 164 196 176 C192 186 174 186 170 176 Z'
  };
  var EXPR = {
    neutre: { brows: 'M122 158 C130 155 142 155 150 157 M170 157 C178 155 190 155 198 158', eyes: FACE.almond, pupils: FACE.pupils, mouth: 'M148 230 L174 230' },
    sourcil: { brows: 'M122 160 C130 154 142 154 150 158 M170 152 C178 144 192 146 198 154', eyes: FACE.almond, pupils: FACE.side, mouth: 'M146 230 C156 235 168 234 178 226' },
    sourire: { brows: 'M122 154 C130 148 142 148 150 152 M170 152 C178 148 190 148 198 154', eyes: FACE.happy, mouth: 'M140 222 C150 238 170 238 180 222 Z', mouthFill: '#5B1530' },
    inquiet: { brows: 'M122 160 L150 150 M170 150 L198 160', eyes: FACE.almond, pupils: FACE.small, mouth: 'M144 228 C152 224 168 224 176 228', sweat: true },
    furieux: { brows: 'M120 150 L150 162 M170 162 L200 150', eyes: FACE.wide, pupils: FACE.tiny, mouth: 'M140 224 C148 240 172 240 180 224 C170 230 150 230 140 224 Z', mouthFill: '#5B1530', steam: true },
    perplexe: { brows: 'M122 156 C130 152 142 152 150 156 M170 150 C178 144 192 146 198 152', eyes: FACE.almond, pupils: FACE.side, mouth: 'M148 232 C156 228 164 236 174 230' },
    severe: { brows: 'M122 154 L150 162 M170 162 L198 154', eyes: FACE.almond, pupils: FACE.small, mouth: 'M148 231 L174 231' }
  };
  EXPR.ravi = EXPR.sourire; EXPR.soulagee = EXPR.sourire; EXPR.satisfait = EXPR.sourire; EXPR.inquiete = EXPR.inquiet; EXPR.tatillon = EXPR.sourcil;

  function face(expr, browColor) {
    var e = EXPR[expr] || EXPR.neutre, s = '';
    s += '<path d="' + e.brows + '" fill="none" stroke="' + browColor + '" stroke-width="5.5" stroke-linecap="round"/>';
    s += '<path d="' + e.eyes + '" fill="' + (e.pupils ? '#FBF8FF' : 'none') + '" stroke="' + INK + '" stroke-width="' + (e.pupils ? 2.5 : 4) + '" stroke-linecap="round"/>';
    if (e.pupils) s += '<path d="' + e.pupils + '" fill="' + INK + '"/>';
    s += '<path d="' + e.mouth + '" fill="' + (e.mouthFill || 'none') + '" stroke="#3B1A26" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>';
    if (e.sweat) s += '<path d="M222 118 C214 132 212 142 220 146 C228 148 232 138 222 118 Z" fill="#BFE6FF" stroke="' + INK + '" stroke-width="2"/>';
    if (e.steam) s += '<path d="M226 96 C236 88 232 78 242 70 M236 104 C248 98 246 88 256 82" fill="none" stroke="#E0457B" stroke-width="4" stroke-linecap="round"/>';
    return s;
  }
  function headBase(skin, shade) {
    return '<ellipse cx="104" cy="180" rx="10" ry="16" fill="' + skin + '" stroke="' + INK + '" stroke-width="4"/>' +
      '<ellipse cx="216" cy="180" rx="10" ry="16" fill="' + shade + '" stroke="' + INK + '" stroke-width="4"/>' +
      P('M106 142 C106 99 214 99 214 142 L212 190 C208 230 186 254 160 256 C134 254 112 230 108 190 Z', skin) +
      '<path d="M180 111 C202 117 214 129 214 142 L212 190 C208 230 186 254 160 256 C176 240 190 214 192 186 C194 151 190 127 180 111 Z" fill="' + shade + '"/>';
  }
  var RIM = '<path d="M108 162 L109 192 C112 222 126 244 140 252" fill="none" stroke="#BFE6FF" stroke-width="4"/><path d="M212 162 L211 192 C208 222 194 244 180 252" fill="none" stroke="#E0457B" stroke-width="4"/>';

  var CAST = {
    jade: {
      name: 'Jade', role: 'Tutrice · dev freelance', brow: INK,
      back: '<circle cx="160" cy="58" r="46" fill="' + INK + '"/>',
      body: P('M138 236 L138 292 L182 292 L182 236 Z', '#7A4A2E') + P('M14 400 C18 330 50 296 118 282 L160 300 L202 282 C270 296 302 330 306 400 Z', '#FF8A3D') +
        P('M120 284 C130 312 190 312 200 284', 'none') + '<path d="M146 306 L142 352 M174 306 L178 352" stroke="#FBF8FF" stroke-width="4" stroke-linecap="round"/>' +
        '<circle cx="236" cy="344" r="15" fill="#6A3EA0" stroke="' + INK + '" stroke-width="3"/><rect x="228" y="338" width="16" height="11" rx="2" fill="#BFE6FF"/>',
      head: function () { return headBase('#9A6240', '#7A4A2E'); },
      front: P('M102 150 C98 104 128 88 160 88 C194 88 224 104 220 150 C206 128 184 118 160 120 C136 118 114 128 102 150 Z', INK) +
        '<path d="M86 164 C86 92 234 92 234 164" fill="none" stroke="' + INK + '" stroke-width="11"/><path d="M90 150 C96 104 224 104 230 150" fill="none" stroke="#6A3EA0" stroke-width="4"/>' +
        '<rect x="72" y="150" width="30" height="50" rx="12" fill="#6A3EA0" stroke="' + INK + '" stroke-width="4"/><rect x="218" y="150" width="30" height="50" rx="12" fill="#6A3EA0" stroke="' + INK + '" stroke-width="4"/>' +
        '<path d="M92 196 C100 220 120 228 138 226" fill="none" stroke="' + INK + '" stroke-width="4" stroke-linecap="round"/><circle cx="140" cy="226" r="5" fill="' + INK + '"/>',
      over: ''
    },
    gilles: {
      name: 'Gilles', role: 'Fondateur de « Continue ? »', brow: '#8E8AA3',
      back: '',
      body: P('M138 236 L138 292 L182 292 L182 236 Z', '#E2AE86') + P('M14 400 C18 330 50 296 116 284 L160 314 L204 284 C270 296 302 330 306 400 Z', '#E0457B') +
        P('M124 286 L160 314 L196 286 L184 280 L160 300 L136 280 Z', '#FBF8FF') +
        '<rect x="196" y="330" width="62" height="26" rx="13" fill="#FBF8FF" stroke="' + INK + '" stroke-width="3"/><text x="227" y="348" text-anchor="middle" font-family="Syncopate, sans-serif" font-weight="700" font-size="9" fill="#6A3EA0">CONTINUE ?</text>',
      head: function () { return headBase('#F1C7A1', '#E2AE86'); },
      front: '<path d="M104 160 C98 140 100 122 108 112 L114 156 Z M216 160 C222 140 220 122 212 112 L206 156 Z" fill="#B8B4C8" stroke="' + INK + '" stroke-width="3"/>' +
        P('M110 196 C118 240 140 254 160 254 C180 254 202 240 210 196 C196 214 176 220 160 220 C144 220 124 214 110 196 Z', '#B8B4C8'),
      over: '<rect x="116" y="162" width="40" height="26" rx="4" fill="#E6F6FF" fill-opacity=".25" stroke="' + INK + '" stroke-width="3.5"/><rect x="164" y="162" width="40" height="26" rx="4" fill="#E6F6FF" fill-opacity=".25" stroke="' + INK + '" stroke-width="3.5"/><path d="M156 174 L164 174" stroke="' + INK + '" stroke-width="3.5"/>'
    },
    oceane: {
      name: 'Océane', role: 'Vendeuse au comptoir', brow: '#8A3418',
      back: P('M210 112 C250 120 262 180 236 240 C230 220 226 180 214 150 Z', '#C2552B'),
      body: P('M138 236 L138 292 L182 292 L182 236 Z', '#D9A584') + P('M14 400 C18 330 50 296 118 284 L160 298 L202 284 C270 296 302 330 306 400 Z', '#8FD14F') +
        P('M96 400 L104 300 L216 300 L224 400 Z', '#BFE6FF') + '<path d="M104 300 L126 284 M216 300 L194 284" stroke="#BFE6FF" stroke-width="6"/>' +
        '<text x="160" y="346" text-anchor="middle" font-family="Syncopate, sans-serif" font-weight="700" font-size="12" fill="#6A3EA0">CONTINUE ?</text>' +
        '<rect x="128" y="360" width="64" height="20" rx="4" fill="#FBF8FF" stroke="' + INK + '" stroke-width="2.5"/><text x="160" y="375" text-anchor="middle" font-family="Manrope, sans-serif" font-weight="700" font-size="12" fill="' + INK + '">Océane</text>',
      head: function () { return headBase('#F1C9A8', '#D9A584'); },
      front: P('M100 156 C96 104 128 86 160 86 C194 86 226 104 220 156 C212 134 196 120 176 116 L170 128 L156 114 C130 116 108 130 100 156 Z', '#C2552B') +
        '<circle cx="212" cy="112" r="8" fill="#8FD14F" stroke="' + INK + '" stroke-width="3"/>' +
        '<g fill="#E8A98A"><circle cx="128" cy="206" r="2"/><circle cx="136" cy="210" r="2"/><circle cx="186" cy="210" r="2"/><circle cx="194" cy="206" r="2"/></g>',
      over: ''
    },
    ferrand: {
      name: 'M. Ferrand', role: 'Déposant', brow: '#EDEAF5',
      back: '',
      body: P('M138 236 L138 292 L182 292 L182 236 Z', '#D49E7A') + P('M14 400 C18 330 50 296 118 284 L160 316 L202 284 C270 296 302 330 306 400 Z', '#C9B38A') +
        P('M132 288 L160 316 L188 288 L178 282 L160 300 L142 282 Z', '#FBF8FF') + '<path d="M160 316 L160 400" stroke="' + INK + '" stroke-width="3"/>' +
        '<rect x="200" y="320" width="44" height="62" rx="5" fill="#1E1433" stroke="' + INK + '" stroke-width="3"/><rect x="206" y="326" width="32" height="14" fill="#8FD14F"/>' +
        '<g fill="#BFE6FF"><rect x="207" y="346" width="8" height="7"/><rect x="218" y="346" width="8" height="7"/><rect x="229" y="346" width="8" height="7"/><rect x="207" y="357" width="8" height="7"/><rect x="218" y="357" width="8" height="7"/><rect x="229" y="357" width="8" height="7"/></g>',
      head: function () { return headBase('#E8B996', '#D49E7A'); },
      front: P('M94 136 C96 96 224 96 226 136 Z', '#8C7A5B') + P('M90 134 L238 140 L234 152 L88 146 Z', '#6E5F45') +
        P('M132 210 C140 202 152 204 160 210 C168 204 180 202 188 210 C178 218 170 216 160 214 C150 216 142 218 132 210 Z', '#EDEAF5'),
      over: '<circle cx="137" cy="176" r="14" fill="none" stroke="' + INK + '" stroke-width="3"/><circle cx="183" cy="176" r="14" fill="none" stroke="' + INK + '" stroke-width="3"/><path d="M151 176 L169 176" stroke="' + INK + '" stroke-width="3"/>'
    }
  };

  function svg(who, expr, crop) {
    var c = CAST[who] || CAST.gilles;
    var vb = crop === 'tete' ? '84 40 152 180' : '0 0 320 400';
    var inner = c.back + c.body + c.head() + c.front + face(expr || 'neutre', c.brow) + c.over + RIM;
    return '<svg viewBox="' + vb + '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' + c.name + '">' + inner + '</svg>';
  }

  root.RetroChars = {
    svg: svg,
    cast: CAST,
    name: function (who) { return (CAST[who] || { name: who }).name; },
    role: function (who) { return (CAST[who] || { role: '' }).role; }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
