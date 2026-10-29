/* Black-out — the cast, drawn in SVG (direction D, « plateau e-sport »).

   BlackoutChars.svg(who, expr, crop) returns an <svg> string: crop 'buste' (default) or 'tete'.
   A character is a body that never changes plus a face layer (brows, eyes, mouth, extras): the
   expressions change the face, never the body. Construction: 4 px outline, flat colours, one flat
   shadow, a magenta rim light on the left and a cyan one on the right (the screens of the room).
   Cast: nadia (tutor), karim (club president), lou (streamer), roussel (deputy head),
   enzo (a player), ghost (never shown whole). */
(function (root) {
  'use strict';
  var INK = '#0A0618';
  function P(d, fill, extra) { return '<path d="' + d + '" fill="' + (fill || 'none') + '"' + (extra || ' stroke="' + INK + '" stroke-width="4"') + '/>'; }

  var FACE = {
    almond: 'M124 176 C130 168 144 168 150 176 C144 181 130 181 124 176 Z M170 176 C176 168 190 168 196 176 C190 181 176 181 170 176 Z',
    pupils: 'M132.5 175 a5.5 5.5 0 1 0 11 0 a5.5 5.5 0 1 0 -11 0 Z M178.5 175 a5.5 5.5 0 1 0 11 0 a5.5 5.5 0 1 0 -11 0 Z',
    small: 'M134 174 a4 4 0 1 0 8 0 a4 4 0 1 0 -8 0 Z M178 174 a4 4 0 1 0 8 0 a4 4 0 1 0 -8 0 Z',
    tiny: 'M134.5 175 a2.5 2.5 0 1 0 5 0 a2.5 2.5 0 1 0 -5 0 Z M180.5 175 a2.5 2.5 0 1 0 5 0 a2.5 2.5 0 1 0 -5 0 Z',
    happy: 'M124 178 C130 168 144 168 150 178 M170 178 C176 168 190 168 196 178',
    wide: 'M124 176 C128 164 146 164 150 176 C146 186 128 186 124 176 Z M170 176 C174 164 192 164 196 176 C192 186 174 186 170 176 Z'
  };
  var EXPR = {
    neutre: { brows: 'M122 158 C130 155 142 155 150 157 M170 157 C178 155 190 155 198 158', eyes: FACE.almond, pupils: FACE.pupils, mouth: 'M148 230 L174 230' },
    sourcil: { brows: 'M122 160 C130 154 142 154 150 158 M170 152 C178 144 192 146 198 154', eyes: FACE.almond, pupils: FACE.pupils, mouth: 'M146 230 C156 235 168 234 178 226' },
    sourire: { brows: 'M122 154 C130 148 142 148 150 152 M170 152 C178 148 190 148 198 154', eyes: FACE.happy, mouth: 'M140 222 C150 238 170 238 180 222 Z', mouthFill: '#3B1620' },
    inquiet: { brows: 'M122 160 L150 150 M170 150 L198 160', eyes: FACE.almond, pupils: FACE.small, mouth: 'M144 228 C152 224 168 224 176 228', sweat: true },
    panique: { brows: 'M122 154 L150 144 M170 144 L198 154', eyes: FACE.wide, pupils: FACE.tiny, mouth: 'M146 220 C146 244 174 244 174 220 C166 214 154 214 146 220 Z', mouthFill: '#3B1620', sweat: true, sweat2: true },
    severe: { brows: 'M122 154 L150 162 M170 162 L198 154', eyes: FACE.almond, pupils: FACE.small, mouth: 'M148 231 L174 231' }
  };
  EXPR.joie = EXPR.sourire; EXPR.soulage = EXPR.sourire; EXPR.satisfaite = EXPR.sourire; EXPR.inquiete = EXPR.inquiet;

  function face(expr, browColor) {
    var e = EXPR[expr] || EXPR.neutre, s = '';
    s += '<path d="' + e.brows + '" fill="none" stroke="' + browColor + '" stroke-width="5.5" stroke-linecap="round"/>';
    s += '<path d="' + e.eyes + '" fill="' + (e.pupils ? '#F4EEE6' : 'none') + '" stroke="' + INK + '" stroke-width="' + (e.pupils ? 2.5 : 4) + '" stroke-linecap="round"/>';
    if (e.pupils) s += '<path d="' + e.pupils + '" fill="#1B120C"/>';
    s += '<path d="' + e.mouth + '" fill="' + (e.mouthFill || 'none') + '" stroke="#3B2216" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>';
    if (e.sweat) s += '<path d="M222 118 C214 132 212 142 220 146 C228 148 232 138 222 118 Z" fill="#BFE6FF" stroke="' + INK + '" stroke-width="2"/>';
    if (e.sweat2) s += '<path d="M100 128 C94 140 94 148 100 150 C106 150 108 142 100 128 Z" fill="#BFE6FF" stroke="' + INK + '" stroke-width="2"/>';
    return s;
  }
  function headBase(skin, shade) {
    return '<ellipse cx="104" cy="180" rx="10" ry="16" fill="' + skin + '" stroke="' + INK + '" stroke-width="4"/>' +
      '<ellipse cx="216" cy="180" rx="10" ry="16" fill="' + shade + '" stroke="' + INK + '" stroke-width="4"/>' +
      P('M106 142 C106 99 214 99 214 142 L212 190 C208 230 186 254 160 256 C134 254 112 230 108 190 Z', skin) +
      '<path d="M180 111 C202 117 214 129 214 142 L212 190 C208 230 186 254 160 256 C176 240 190 214 192 186 C194 151 190 127 180 111 Z" fill="' + shade + '"/>';
  }
  var RIM = '<path d="M108 162 L109 192 C112 222 126 244 140 252" fill="none" stroke="#FF2E88" stroke-width="4"/><path d="M212 162 L211 192 C208 222 194 244 180 252" fill="none" stroke="#00E5FF" stroke-width="4"/>';

  var CAST = {
    nadia: {
      name: 'Nadia', role: 'Tutrice · admin réseau', brow: '#160F2A',
      back: '<circle cx="160" cy="60" r="34" fill="#160F2A" stroke="' + INK + '" stroke-width="4"/>' + P('M96 150 C92 92 128 72 160 72 C192 72 228 92 224 150 C222 178 214 196 208 206 L112 206 C106 196 98 178 96 150 Z', '#160F2A'),
      body: P('M138 236 L138 292 L182 292 L182 236 Z', '#6E4429') + P('M14 400 C18 330 50 296 118 282 L160 300 L202 282 C270 296 302 330 306 400 Z', '#2A2342') +
        P('M126 282 L160 320 L194 282 L182 280 L160 302 L138 280 Z', '#C6FF3D') + '<path d="M24 372 L118 350 M202 350 L296 372" stroke="#00E5FF" stroke-width="9"/>' +
        '<ellipse cx="236" cy="322" rx="46" ry="26" fill="none" stroke="#FF9F43" stroke-width="8" transform="rotate(-24 236 322)"/>' +
        '<rect x="84" y="318" width="22" height="40" rx="4" fill="' + INK + '"/><circle cx="95" cy="331" r="3.5" fill="#C6FF3D"/>',
      head: function () { return headBase('#8A5A3C', '#6E4429'); },
      front: P('M104 152 C104 106 132 88 160 88 C190 88 216 106 216 152 C200 130 178 118 160 120 C140 118 120 128 104 152 Z', '#160F2A') +
        '<path d="M102 134 C130 120 190 120 218 134" fill="none" stroke="#2A2342" stroke-width="12"/>' +
        '<rect x="142" y="110" width="36" height="24" rx="6" fill="#3A3358" stroke="' + INK + '" stroke-width="4"/><circle cx="160" cy="122" r="8" fill="#FFF4D6"/>',
      over: ''
    },
    karim: {
      name: 'Karim', role: 'Président du club e-sport', brow: '#120C1E',
      back: P('M70 330 C80 280 120 262 160 262 C200 262 240 280 250 330 Z', '#3A3358'),
      body: P('M138 236 L138 292 L182 292 L182 236 Z', '#A06A42') + P('M14 400 C18 330 50 296 116 284 C130 300 190 300 204 284 C270 296 302 330 306 400 Z', '#2A2342') +
        '<path d="M150 300 L146 360 M170 300 L174 360" stroke="#C2BDD9" stroke-width="4" stroke-linecap="round"/>' +
        '<text x="210" y="370" font-family="Chakra Petch, sans-serif" font-weight="700" font-size="24" fill="#C6FF3D" transform="rotate(-6 230 362)">ORGA</text>' +
        '<path d="M96 262 C96 300 224 300 224 262" fill="none" stroke="#00E5FF" stroke-width="12" stroke-linecap="round"/>' +
        '<rect x="78" y="246" width="30" height="40" rx="8" fill="#1B1236" stroke="#00E5FF" stroke-width="4"/><rect x="212" y="246" width="30" height="40" rx="8" fill="#1B1236" stroke="#00E5FF" stroke-width="4"/>',
      head: function () { return headBase('#C68B5E', '#A06A42'); },
      front: P('M104 146 C100 100 128 76 162 76 C196 76 222 98 216 146 L210 132 C206 120 196 110 182 108 C160 104 136 108 120 118 C112 124 108 134 104 146 Z', '#120C1E'),
      over: ''
    },
    lou: {
      name: 'Lou', role: 'Streameuse du tournoi', brow: '#233A8F',
      back: P('M98 206 C88 124 118 84 160 84 C202 84 232 124 222 206 Z', '#233A8F'),
      body: P('M138 236 L138 292 L182 292 L182 236 Z', '#D9A584') + P('M14 400 C18 330 50 296 118 284 L160 298 L202 284 C270 296 302 330 306 400 Z', '#FFD23F') +
        P('M14 400 C18 330 50 296 118 284 L128 400 Z M306 400 C302 330 270 296 202 284 L192 400 Z', '#233A8F') +
        '<circle cx="142" cy="352" r="6" fill="#FF3B3B"/><text x="154" y="360" font-family="Chakra Petch, sans-serif" font-weight="700" font-size="22" fill="' + INK + '">LIVE</text>',
      head: function () { return headBase('#F1C9A8', '#D9A584'); },
      front: P('M100 160 C98 108 128 90 160 90 C194 90 222 108 220 160 L200 144 L198 128 L176 140 L170 122 L150 140 L140 124 L124 146 Z', '#233A8F') +
        '<path d="M196 96 C206 104 214 118 218 136 L206 130 C204 118 200 108 196 96 Z" fill="#FFD23F"/>' +
        '<path d="M92 160 C88 64 232 64 228 160" fill="none" stroke="#F4F2FF" stroke-width="12" stroke-linecap="round"/>' +
        '<rect x="78" y="150" width="30" height="50" rx="10" fill="#F4F2FF" stroke="' + INK + '" stroke-width="4"/><rect x="212" y="150" width="30" height="50" rx="10" fill="#F4F2FF" stroke="' + INK + '" stroke-width="4"/>' +
        '<rect x="84" y="160" width="12" height="30" rx="5" fill="#00E5FF"/><rect x="224" y="160" width="12" height="30" rx="5" fill="#00E5FF"/>',
      over: '<circle cx="137" cy="176" r="17" fill="none" stroke="' + INK + '" stroke-width="3.5"/><circle cx="183" cy="176" r="17" fill="none" stroke="' + INK + '" stroke-width="3.5"/><path d="M154 176 L166 176" stroke="' + INK + '" stroke-width="3.5"/>'
    },
    roussel: {
      name: 'Mme Roussel', role: 'Proviseure adjointe', brow: '#8E8AA3',
      back: '',
      body: P('M138 236 L138 292 L182 292 L182 236 Z', '#C99470') + P('M14 400 C18 330 50 296 118 284 L160 330 L202 284 C270 296 302 330 306 400 Z', '#1E2A4A') +
        P('M128 286 L160 330 L192 286 L180 280 L160 304 L140 280 Z', '#F4F2FF') +
        '<path d="M118 284 L140 360 L118 400 M202 284 L180 360 L202 400" fill="none" stroke="' + INK + '" stroke-width="4"/>' +
        '<path d="M146 292 C150 320 170 340 196 352 M174 292 C180 316 190 336 200 352" fill="none" stroke="#00E5FF" stroke-width="4"/>' +
        '<rect x="186" y="350" width="34" height="44" rx="3" fill="#F4F2FF" stroke="' + INK + '" stroke-width="3"/><rect x="186" y="350" width="34" height="10" fill="#FF2E88"/>',
      head: function () { return headBase('#E8B996', '#C99470'); },
      front: P('M100 168 C92 104 126 78 162 78 C200 78 228 104 220 168 C216 142 208 128 194 122 C172 114 150 118 132 128 C118 136 108 150 100 168 Z', '#B8B4C8') +
        '<path d="M132 128 C150 110 190 106 206 124" fill="none" stroke="#8E8AA3" stroke-width="4"/>',
      over: '<rect x="118" y="164" width="38" height="24" rx="3" fill="#E6F6FF" fill-opacity=".2" stroke="' + INK + '" stroke-width="3.5"/><rect x="164" y="164" width="38" height="24" rx="3" fill="#E6F6FF" fill-opacity=".2" stroke="' + INK + '" stroke-width="3.5"/><path d="M156 175 L164 175" stroke="' + INK + '" stroke-width="3.5"/>' +
        '<path d="M118 178 C106 210 114 240 138 262 M202 178 C214 210 206 240 182 262" fill="none" stroke="#C6A85A" stroke-width="2" stroke-dasharray="3 3"/>'
    },
    enzo: {
      name: 'Enzo', role: 'Joueur, rang B', brow: '#3A2414',
      back: '',
      body: P('M138 236 L138 292 L182 292 L182 236 Z', '#B07A50') + P('M14 400 C18 330 50 296 118 284 L160 300 L202 284 C270 296 302 330 306 400 Z', '#B26BFF') +
        '<text x="128" y="372" font-family="Chakra Petch, sans-serif" font-weight="700" font-size="22" fill="' + INK + '">TEAM KITSUNE</text>',
      head: function () { return headBase('#D49A6A', '#B07A50'); },
      front: P('M104 148 C102 104 130 84 160 84 C192 84 218 104 216 148 C206 128 186 116 160 116 C134 116 114 128 104 148 Z', '#3A2414') +
        P('M96 122 C110 92 210 92 224 122 L236 128 L230 136 C200 120 120 120 92 136 L84 128 Z', '#FF2E88'),
      over: ''
    },
    ghost: {
      name: 'Le Ghost', role: '???', brow: INK,
      back: '',
      body: P('M14 400 C18 330 50 290 120 276 L160 290 L200 276 C270 290 302 330 306 400 Z', '#120C1E') +
        '<path d="M146 280 L142 340 M174 280 L178 340" stroke="#5A4F80" stroke-width="4" stroke-linecap="round"/>',
      head: function () {
        return P('M84 236 C64 120 104 48 160 48 C216 48 256 120 236 236 C226 262 200 276 160 280 C120 276 94 262 84 236 Z', '#1A1230') +
          '<path d="M116 156 C116 112 204 112 204 156 L200 212 C192 246 128 246 120 212 Z" fill="#05030C"/>' +
          '<rect x="130" y="170" width="18" height="5" fill="#00E5FF"/><rect x="172" y="170" width="18" height="5" fill="#00E5FF"/>' +
          '<path d="M86 230 C70 150 96 76 150 54" fill="none" stroke="#FF2E88" stroke-width="4"/>';
      },
      front: '', over: '', noFace: true
    }
  };

  function svg(who, expr, crop) {
    var c = CAST[who] || CAST.enzo;
    var vb = crop === 'tete' ? '84 40 152 180' : '0 0 320 400';
    var inner = c.back + c.body + c.head() + (c.noFace ? '' : c.front + face(expr || 'neutre', c.brow) + c.over + RIM);
    return '<svg viewBox="' + vb + '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' + c.name + '">' + inner + '</svg>';
  }

  root.BlackoutChars = {
    svg: svg,
    cast: CAST,
    name: function (who) { return (CAST[who] || { name: who }).name; },
    role: function (who) { return (CAST[who] || { role: '' }).role; }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
