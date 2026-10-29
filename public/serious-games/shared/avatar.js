/* Formatool — serious games — the avatar, drawn once for the platform and for every game
   (design/validated/serious-games.md §7). Ported from the conception's sg-core.js (SG.avatar).

   A classic script, strict, that sets globalThis.SGAvatar: the games load it with a plain
   <script src> from their sandboxed frame (an opaque origin, where a module script would need CORS),
   and Formatool's Stimulus controllers import() it, which runs it just the same.

     SGAvatar.svg(config, {size, bare, viewBox, costume, round}) → SVG markup   (renderAvatar)
     SGAvatar.html(config, size, options)                       → the same in a round span
     SGAvatar.normalize(config)                                 → a version 2 config, defaults filled
     SGAvatar.random(rng?) · SGAvatar.CATS (the editor's catalogue) · SGAvatar.DEF · SGAvatar.PRESET

   No gender is stored ('start' only says which drawing it began from), no headwear of one's own,
   and the bare head - the one a game sets on its own body - carries a neck in the skin colour from
   y 150 to 196, with a shadow under the chin. Every outfit starts at y 186 at the highest. */
(function (root) {
  'use strict';
  var A = {};
  var uid = 0;
  // The pins a student can wear (one per finished game).
  var PINS = [
    { id: 'pin-etoile', name: 'Épinglette Étoile', color: '#1B2550', glyph: '✦' },
    { id: 'pin-cave-canem', name: 'Épinglette Cave canem', color: '#9E2B25', glyph: 'C' },
    { id: 'pin-vague', name: 'Épinglette Abysse', color: '#167784', glyph: '≈' },
    { id: 'pin-loupe', name: 'Épinglette Loupe', color: '#1F3A2E', glyph: '⌕' },
    { id: 'pin-neko', name: 'Épinglette Neko', color: '#E8457A', glyph: '^' }
  ];
  A.PINS = PINS;

  A.SKIN = [['#F6D7C3', '#DDB49A'], ['#EBC09C', '#CC9A72'], ['#D69E73', '#B47D56'], ['#B57A4E', '#935F38'], ['#8A5534', '#6B4027'], ['#5E3A24', '#46291A']];
  A.HAIR = ['#2B211C', '#5A3825', '#8A5A33', '#D9B26A', '#B5532C', '#A8A8A8', '#4E6BD1', '#D9779B'];
  A.IRIS = ['#4A2E1E', '#7A5A2E', '#4F7A4A', '#4A78A8', '#6D7580'];
  A.TOP = ['#C8463D', '#2F6FB0', '#3E8E5E', '#E0A93B', '#6B4FA0', '#2B2B2B'];
  A.BG = ['#DDE7F2', '#F3E3C8', '#E4EEDD', '#F2DCDC', '#E6DFF0', '#D9EEEC', '#ECEBE6', '#1B2550'];
  // Starting points. The handoff called them Masculin / Féminin; the conception keeps two drawn presets
  // and stores no gender (see avatar.html, « Point de départ »): 'start' only says which drawing it began from.
  A.PRESET = { a: { hair: 'court', brows: 'droits' }, b: { hair: 'milong', brows: 'arques' } };
  A.DEF = { start: 'a', face: 'rond', skin: 2, hair: 'court', hairC: 1, eyes: 'ronds', iris: 0, brows: 'arques', nose: 'arrondi', mouth: 'sourire', glasses: 'aucune', top: 1, earrings: false, freckles: false, beard: false, blush: true, braces: false, hearing: false, bg: 0, pin: null };
  A.ZOOM = '48 72 104 104';
  // Options marked "added" are proposals of this conception, absent from the handoff.
  A.CATS = [
    { id: 'visage', label: 'Visage', groups: [
      { t: 'Point de départ', key: 'start', preset: true, opts: [['a', 'Silhouette A'], ['b', 'Silhouette B']] },
      { t: 'Forme', key: 'face', opts: [['rond', 'Rond'], ['ovale', 'Ovale'], ['carre', 'Carré']] },
      { t: 'Teint', key: 'skin', colors: A.SKIN.map(function (s) { return s[0]; }) }] },
    { id: 'cheveux', label: 'Cheveux', groups: [
      { t: 'Coupe', key: 'hair', opts: [['rase', 'Rasé'], ['court', 'Court'], ['milong', 'Mi-long'], ['long', 'Long'], ['boucle', 'Bouclé'], ['afro', 'Afro', 'added'], ['tresses', 'Tresses', 'added'], ['chignon', 'Chignon'], ['crete', 'Crête']] },
      { t: 'Couleur', key: 'hairC', colors: A.HAIR }] },
    { id: 'yeux', label: 'Yeux', vb: A.ZOOM, groups: [
      { t: 'Forme', key: 'eyes', opts: [['ronds', 'Ronds'], ['amande', 'Amande'], ['grands', 'Grands'], ['souriants', 'Rieurs']] },
      { t: 'Couleur', key: 'iris', colors: A.IRIS }] },
    { id: 'sourcils', label: 'Sourcils', vb: A.ZOOM, groups: [{ t: 'Forme', key: 'brows', opts: [['aucun', 'Aucun'], ['droits', 'Droits'], ['arques', 'Arqués'], ['epais', 'Épais']] }] },
    { id: 'nez', label: 'Nez', vb: A.ZOOM, groups: [{ t: 'Forme', key: 'nose', opts: [['petit', 'Petit'], ['arrondi', 'Arrondi'], ['long', 'Long'], ['triangle', 'Marqué']] }] },
    { id: 'bouche', label: 'Bouche', vb: A.ZOOM, groups: [{ t: 'Forme', key: 'mouth', opts: [['sourire', 'Sourire'], ['grand', 'Rire'], ['neutre', 'Neutre'], ['surprise', 'Surprise']] }] },
    { id: 'acc', label: 'Accessoires', vb: A.ZOOM, groups: [
      { t: 'Lunettes', key: 'glasses', opts: [['aucune', 'Aucune'], ['rondes', 'Rondes'], ['carrees', 'Carrées'], ['solaires', 'Solaires']] },
      { t: 'Détails', toggle: true, opts: [['blush', 'Joues roses'], ['freckles', 'Taches de rousseur'], ['beard', 'Barbe'], ['earrings', "Boucles d'oreille"], ['braces', 'Appareil dentaire', 'added'], ['hearing', 'Appareil auditif', 'added']] }] },
    { id: 'fond', label: 'Haut & fond', groups: [{ t: 'Haut', key: 'top', colors: A.TOP }, { t: 'Fond', key: 'bg', colors: A.BG }] }
  ];

  function attrs(o) {
    var s = '';
    for (var k in o) { if (o[k] === null || o[k] === undefined) continue; s += ' ' + k.replace(/[A-Z]/g, function (m) { return '-' + m.toLowerCase(); }) + '="' + o[k] + '"'; }
    return s;
  }
  function el(t, o) { return '<' + t + attrs(o) + '/>'; }
  var ST = { fill: 'none', strokeLinecap: 'round', strokeLinejoin: 'round' };
  function sx(o) { var r = {}; for (var k in ST) r[k] = ST[k]; for (var j in o) r[j] = o[j]; return r; }

  /* The head, in the 0 0 200 200 box. bare=true: no background, no top (to sit on a game's body). */
  A.parts = function (c, bare) {
    c = Object.assign({}, A.DEF, c || {});
    var k = [], p = function (t, a) { k.push(el(t, a)); };
    var sk = A.SKIN[c.skin][0], skd = A.SKIN[c.skin][1], hc = A.HAIR[c.hairC], ir = A.IRIS[c.iris], ink = '#2A1E1A', lip = c.start === 'b' ? '#B5444A' : '#8A3A34';
    var F = { rond: { rx: 58, ry: 64 }, ovale: { rx: 52, ry: 68 }, carre: { rx: 56, ry: 62 } }[c.face], cy = 108, ex = F.rx;
    if (!bare) p('rect', { x: 0, y: 0, width: 200, height: 200, fill: A.BG[c.bg] });
    if (c.hair === 'long') p('path', { d: 'M38 104 Q36 36 100 34 Q164 36 162 104 L170 200 L30 200 Z', fill: hc });
    if (c.hair === 'milong') p('path', { d: 'M40 104 Q38 36 100 34 Q162 36 160 104 L162 156 Q100 166 38 156 Z', fill: hc });
    if (c.hair === 'afro') { p('circle', { cx: 100, cy: 88, r: 72, fill: hc }); for (var aa = 0; aa < 360; aa += 30) { var ar = aa * Math.PI / 180; p('circle', { cx: 100 + 66 * Math.cos(ar), cy: 88 + 66 * Math.sin(ar) * .95, r: 12, fill: hc }); } }
    if (c.hair === 'tresses') { for (var tx = 0; tx < 2; tx++) { var bx = tx ? 150 : 42; for (var ty = 0; ty < 6; ty++) p('ellipse', { cx: bx + (tx ? 4 : -4), cy: 98 + ty * 15, rx: 9, ry: 9, fill: hc }); } }
    // The neck. On a bare head (the one games put on their own bodies) it is longer and in the skin
    // colour, so it shows between the chin (y = 172) and any collar: games start their outfit at y ≥ 186.
    if (bare) { p('rect', { x: 85, y: 150, width: 30, height: 46, rx: 6, fill: sk }); p('path', { d: 'M85 168 Q100 184 115 168 L115 178 Q100 190 85 178 Z', fill: skd, opacity: .55 }); }
    else p('rect', { x: 84, y: 150, width: 32, height: 26, fill: skd });
    if (!bare) {
      p('path', { d: 'M24 200 Q30 168 100 166 Q170 168 176 200 Z', fill: A.TOP[c.top] });
      if (c.pin) k.push(A.pinSvg(c.pin, 136, 186, 9));
    }
    if (c.hair === 'long' && !bare) p('path', { d: 'M30 200 L36 150 Q50 176 58 200 Z M170 200 L164 150 Q150 176 142 200 Z', fill: hc });
    p('circle', { cx: 100 - ex + 2, cy: 114, r: 11, fill: sk }); p('circle', { cx: 100 + ex - 2, cy: 114, r: 11, fill: sk });
    if (c.hearing) { p('path', { d: 'M' + (100 + ex + 4) + ' 104 q8 6 2 18', stroke: '#C9CED6', strokeWidth: 6, fill: 'none', strokeLinecap: 'round' }); }
    if (c.face === 'carre') p('rect', { x: 100 - F.rx, y: cy - F.ry, width: F.rx * 2, height: F.ry * 2, rx: 30, fill: sk });
    else p('ellipse', { cx: 100, cy: cy, rx: F.rx, ry: F.ry, fill: sk });
    if (c.blush) { p('ellipse', { cx: 72, cy: 134, rx: 9, ry: 5, fill: '#E8857A', opacity: .4 }); p('ellipse', { cx: 128, cy: 134, rx: 9, ry: 5, fill: '#E8857A', opacity: .4 }); }
    if (c.freckles) [[80, 126], [86, 131], [74, 131], [120, 126], [114, 131], [126, 131]].forEach(function (a) { p('circle', { cx: a[0], cy: a[1], r: 1.7, fill: '#7A4A2C', opacity: .6 }); });
    if (c.beard) p('path', { d: 'M44 116 Q44 184 100 186 Q156 184 156 116 Q152 154 124 160 Q100 146 76 160 Q48 154 44 116 Z', fill: hc });
    if (c.mouth === 'sourire') p('path', sx({ d: 'M86 147 Q100 159 114 147', stroke: lip, strokeWidth: 3.5 }));
    if (c.mouth === 'grand') { p('path', { d: 'M84 145 Q100 170 116 145 Z', fill: lip }); p('path', { d: 'M88 146 L112 146 L110 151 L90 151 Z', fill: '#fff' }); if (c.braces) p('path', sx({ d: 'M89 148.5 L111 148.5', stroke: '#9AA3AE', strokeWidth: 1.6 })); }
    if (c.mouth === 'neutre') p('path', sx({ d: 'M90 151 L110 151', stroke: lip, strokeWidth: 3.5 }));
    if (c.mouth === 'surprise') p('ellipse', { cx: 100, cy: 152, rx: 6, ry: 8, fill: lip });
    if (c.braces && c.mouth === 'sourire') { p('path', { d: 'M90 150 Q100 156 110 150 L109 152.5 Q100 158 91 152.5 Z', fill: '#fff' }); p('path', sx({ d: 'M91 151.6 Q100 157 109 151.6', stroke: '#9AA3AE', strokeWidth: 1.2 })); }
    if (c.nose === 'petit') p('circle', { cx: 100, cy: 128, r: 3.2, fill: skd });
    if (c.nose === 'arrondi') p('path', sx({ d: 'M94 128 Q100 135 106 128', stroke: skd, strokeWidth: 3 }));
    if (c.nose === 'long') p('path', sx({ d: 'M101 112 L95 130 L103 131', stroke: skd, strokeWidth: 3 }));
    if (c.nose === 'triangle') p('path', { d: 'M100 116 L92 132 L108 132 Z', fill: skd });
    [78, 122].forEach(function (x) {
      if (c.eyes === 'ronds') p('circle', { cx: x, cy: 106, r: 6, fill: ink });
      if (c.eyes === 'amande') { p('ellipse', { cx: x, cy: 106, rx: 10, ry: 6, fill: '#fff' }); p('circle', { cx: x, cy: 106, r: 5, fill: ir }); p('circle', { cx: x, cy: 106, r: 2.4, fill: ink }); }
      if (c.eyes === 'grands') { p('circle', { cx: x, cy: 106, r: 10, fill: '#fff' }); p('circle', { cx: x, cy: 107, r: 6.5, fill: ir }); p('circle', { cx: x, cy: 107, r: 3, fill: ink }); p('circle', { cx: x + 2.5, cy: 104, r: 2, fill: '#fff' }); }
      if (c.eyes === 'souriants') p('path', sx({ d: 'M' + (x - 8) + ' 108 Q' + x + ' 99 ' + (x + 8) + ' 108', stroke: ink, strokeWidth: 3.5 }));
      if (c.start === 'b' && c.eyes !== 'souriants') {
        var o = x < 100 ? -1 : 1, ry = c.eyes === 'grands' ? 10 : 6, xx = x + o * (c.eyes === 'amande' ? 9 : c.eyes === 'grands' ? 8 : 5);
        p('path', sx({ d: 'M' + xx + ' ' + (106 - ry + 2) + ' l' + (o * 5) + ' -4', stroke: ink, strokeWidth: 2.5 }));
        p('path', sx({ d: 'M' + (xx - o * 3) + ' ' + (106 - ry) + ' l' + (o * 3) + ' -5', stroke: ink, strokeWidth: 2.5 }));
      }
      var L = x < 100;
      if (c.brows === 'droits') p('path', sx({ d: 'M' + (x - 10) + ' 90 L' + (x + 10) + ' 90', stroke: hc, strokeWidth: 4.5 }));
      if (c.brows === 'arques' || c.brows === 'epais') p('path', sx({ d: L ? 'M' + (x - 11) + ' 93 Q' + x + ' 83 ' + (x + 11) + ' 90' : 'M' + (x - 11) + ' 90 Q' + x + ' 83 ' + (x + 11) + ' 93', stroke: hc, strokeWidth: c.brows === 'epais' ? 7 : 4 }));
    });
    var court = 'M42 104 Q38 38 100 36 Q162 38 158 104 Q154 74 128 66 Q104 78 76 66 Q48 76 42 104 Z';
    if (c.hair === 'court' || c.hair === 'chignon' || c.hair === 'tresses' || c.hair === 'afro') p('path', { d: court, fill: hc });
    if (c.hair === 'chignon') p('circle', { cx: 100, cy: 30, r: 18, fill: hc });
    if (c.hair === 'milong' || c.hair === 'long') p('path', { d: 'M40 128 Q34 36 100 34 Q166 36 160 128 Q154 84 132 68 Q108 82 70 70 Q48 86 40 128 Z', fill: hc });
    if (c.hair === 'boucle') { p('path', { d: court, fill: hc }); for (var a = 180; a <= 360; a += 22.5) { var r = a * Math.PI / 180; p('circle', { cx: 100 + 56 * Math.cos(r), cy: 98 + 56 * Math.sin(r), r: 15, fill: hc }); } }
    if (c.hair === 'rase' || c.hair === 'crete') p('path', { d: 'M44 96 Q44 40 100 38 Q156 40 156 96 Q130 70 100 70 Q70 70 44 96 Z', fill: hc, opacity: .45 });
    if (c.hair === 'crete') p('path', { d: 'M90 72 Q86 30 100 16 Q114 30 110 72 Z', fill: hc });
    if (c.earrings) { p('circle', { cx: 100 - ex + 2, cy: 128, r: 3.5, fill: '#E0A93B' }); p('circle', { cx: 100 + ex - 2, cy: 128, r: 3.5, fill: '#E0A93B' }); }
    if (c.glasses !== 'aucune') {
      var g = { stroke: '#2B2B2B', strokeWidth: 3, fill: 'none' };
      [78, 122].forEach(function (x) {
        if (c.glasses === 'rondes') p('circle', Object.assign({ cx: x, cy: 106, r: 15 }, g));
        else p('rect', Object.assign({ x: x - 17, y: 95, width: 34, height: 23, rx: c.glasses === 'solaires' ? 9 : 4 }, g, { fill: c.glasses === 'solaires' ? '#1E1E24' : 'none' }));
      });
      p('path', Object.assign({ d: 'M93 104 Q100 99 107 104' }, g));
      p('path', Object.assign({ d: 'M' + (100 - ex + 4) + ' 101 L61 101' }, g)); p('path', Object.assign({ d: 'M139 101 L' + (100 + ex - 4) + ' 101' }, g));
    }
    return k.join('');
  };

  /* What each game puts on the head. The shared avatar carries no headwear: games add theirs. */
  /* What a game puts on the head. The shared avatar carries no headwear of its own (the user's
     decision of 2026-10-18): a game adds its costume, and only while it is played. */
  A.COSTUMES = {
    observatoire: { label: 'Lampe frontale rouge', svg: function () {
      return el('path', sx({ d: 'M44 82 Q100 58 156 82', stroke: '#2E3448', strokeWidth: 6 })) + el('rect', { x: 89, y: 56, width: 22, height: 15, rx: 4, fill: '#2E3448' }) +
        el('circle', { cx: 100, cy: 63.5, r: 10, fill: '#E2564A', opacity: .25 }) + el('circle', { cx: 100, cy: 63.5, r: 4.5, fill: '#F07A6E' });
    } },
    // Les Tablettes de Pompéi: the apprentice's stylus, slipped behind the ear (no wreath - a
    // costume puts nothing on the head that would read as one's own headwear).
    pompei: { label: 'Stylet derrière l’oreille', svg: function () {
      return el('path', sx({ d: 'M70 44 L50 108', stroke: '#8C6A2E', strokeWidth: 6.5 })) + el('path', sx({ d: 'M70 44 L50 108', stroke: '#D9A441', strokeWidth: 4 })) +
        el('path', { d: 'M64.5 36 L76.5 39.5 L72.8 47.2 L66 45.2 Z', fill: '#D9A441', stroke: '#8C6A2E', strokeWidth: 1.2, strokeLinejoin: 'round' }) +
        el('circle', { cx: 49.6, cy: 109.4, r: 1.6, fill: '#5E4520' });
    } },
    // Station Abysse: the diving mask pushed up on the forehead, its strap, the snorkel at the side.
    abysse: { label: 'Masque relevé sur le front', svg: function () {
      return el('path', sx({ d: 'M42 72 Q100 52 158 72', stroke: '#1F2A33', strokeWidth: 6 })) +
        el('rect', { x: 60, y: 52, width: 80, height: 28, rx: 12, fill: '#8FE1EA', opacity: .42, stroke: '#167784', strokeWidth: 4 }) +
        el('path', sx({ d: 'M100 54 L100 78', stroke: '#167784', strokeWidth: 3 })) +
        el('path', sx({ d: 'M70 58 Q76 54 84 56', stroke: '#FFFFFF', strokeWidth: 2.5, opacity: .7 })) +
        el('path', sx({ d: 'M154 70 Q166 92 160 128', stroke: '#E0A93B', strokeWidth: 6 }));
    } },
    // Panique à la Neko-Con: the Badge Staff's lanyard round the neck, the badge hanging below
    // (design/validated/serious-games-neko-con.md §4) - on the body, nothing on the head.
    'neko-con': { label: 'Cordon du badge staff', svg: function () {
      return el('path', sx({ d: 'M76 166 Q84 188 95 204', stroke: '#E8457A', strokeWidth: 5 })) + el('path', sx({ d: 'M124 166 Q116 188 105 204', stroke: '#E8457A', strokeWidth: 5 })) +
        el('path', sx({ d: 'M79 172 L82 178 M121 172 L118 178', stroke: '#FFFFFF', strokeWidth: 1.6, opacity: .8 })) +
        el('rect', { x: 88, y: 194, width: 24, height: 14, rx: 2.5, fill: '#FBF9FF', stroke: '#2A2440', strokeWidth: 1.6 }) +
        el('rect', { x: 88, y: 194, width: 24, height: 4, fill: '#E8457A' });
    } },
    // The Irregulars: the street urchin's flat cap of 1895, its peak and its button.
    irregulars: { label: 'Casquette gavroche', svg: function () {
      return el('path', { d: 'M38 80 Q38 30 100 28 Q162 30 162 80 Q100 62 38 80 Z', fill: '#5B5348' }) + el('path', sx({ d: 'M100 30 L100 66', stroke: '#4A433A', strokeWidth: 2 })) +
        el('path', { d: 'M38 80 Q100 62 162 80 Q172 92 150 92 Q100 76 50 92 Q30 92 38 80 Z', fill: '#433C34' }) + el('circle', { cx: 100, cy: 29, r: 4, fill: '#433C34' });
    } }
  };

  /* A pin earned with a collection piece, worn on the top. Drawn by shared/rewards.js when the page
     loaded it, as a plain enamelled disc otherwise. */
  A.pinSvg = function (pinId, cx, cy, r) {
    // the enamelled drawing of sg-rewards.js when it is loaded, a plain disc otherwise
    var R = root.SGRewards;
    if (R && R.art) {
      try {
        var art = R.art('pin', pinId, { size: r * 2.4 });
        if (art) return art.replace('<svg ', '<svg x="' + (cx - r * 1.2) + '" y="' + (cy - r * 1.2) + '" ').replace(' style="display:block;overflow:visible"', '');
      } catch (e) { /* fall back to the disc */ }
    }
    var pin = PINS.filter(function (x) { return x.id === pinId; })[0];
    var col = pin ? pin.color : '#C9973F', glyph = pin ? pin.glyph : '★';
    return el('circle', { cx: cx, cy: cy, r: r + 2, fill: '#fff', opacity: .9 }) + el('circle', { cx: cx, cy: cy, r: r, fill: col }) +
      '<text x="' + cx + '" y="' + (cy + r * .42) + '" text-anchor="middle" font-size="' + (r * 1.2) + '" fill="#fff" font-family="Georgia, serif">' + glyph + '</text>';
  };

  /* renderAvatar(config, {size, bare, viewBox, costume, round}) → SVG markup */
  A.svg = function (c, o) {
    o = o || {};
    var size = o.size || 64, vb = o.viewBox || '0 0 200 200';
    var inner = A.parts(c, !!o.bare) + (o.costume && A.COSTUMES[o.costume] ? A.COSTUMES[o.costume].svg() : '');
    var round = o.round !== false && !o.bare;
    var id = 'av' + (++uid).toString(36) + Math.random().toString(36).slice(2, 6);
    var clip = round ? '<defs><clipPath id="' + id + '"><circle cx="100" cy="100" r="100"/></clipPath></defs><g clip-path="url(#' + id + ')">' + inner + '</g>' : inner;
    if (round && o.viewBox) clip = inner; // zoomed thumbnails are clipped by their container
    return '<svg viewBox="' + vb + '" width="' + size + '" height="' + size + '" role="img" aria-label="Avatar" style="display:block;overflow:visible">' + clip + '</svg>';
  };
  A.html = function (c, size, o) {
    o = Object.assign({ size: size }, o || {});
    return '<span class="sg-av" style="display:inline-block;width:' + size + 'px;height:' + size + 'px;border-radius:50%;overflow:hidden;flex:none;vertical-align:middle">' + A.svg(c, o) + '</span>';
  };
  A.random = function (r) {
    r = r || Math.random; var c = {};
    A.CATS.forEach(function (cat) { cat.groups.forEach(function (g) {
      if (g.colors) c[g.key] = Math.floor(r() * g.colors.length);
      else if (g.toggle) g.opts.forEach(function (o) { c[o[0]] = r() < (o[0] === 'braces' || o[0] === 'hearing' ? .08 : .25); });
      else c[g.key] = g.opts[Math.floor(r() * g.opts.length)][0];
    }); });
    // most people wear no glasses, few wear sunglasses indoors
    var gr = r(); c.glasses = gr < .62 ? 'aucune' : gr < .8 ? 'rondes' : gr < .95 ? 'carrees' : 'solaires';
    c.bg = Math.floor(r() * 7); c.pin = null;
    return Object.assign({}, A.DEF, c);
  };
  A.VERSION = 2;
  /* A stored config read back: version 2 keys, unknown values replaced by the default, and the
     old « sex » of the handoff's first drawings turned into a starting point. */
  A.normalize = function (c) {
    c = Object.assign({}, c || {});
    if (c.sex && !c.start) { c.start = c.sex === 'f' ? 'b' : 'a'; }
    delete c.sex;
    var out = Object.assign({}, A.DEF);
    A.CATS.forEach(function (cat) { cat.groups.forEach(function (g) {
      if (g.colors) { var n = parseInt(c[g.key], 10); if (n >= 0 && n < g.colors.length) out[g.key] = n; }
      else if (g.toggle) g.opts.forEach(function (o) { if (typeof c[o[0]] === 'boolean') out[o[0]] = c[o[0]]; });
      else if (g.opts.some(function (o) { return o[0] === c[g.key]; })) out[g.key] = c[g.key];
    }); });
    out.pin = PINS.some(function (p) { return p.id === c.pin; }) ? c.pin : null;
    return out;
  };

  root.SGAvatar = A;
})(typeof globalThis !== 'undefined' ? globalThis : window);
