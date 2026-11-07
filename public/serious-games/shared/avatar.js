/* Formatool — serious games — the avatar, drawn once for the platform and for every game
   (design/validated/serious-games.md §7, with the manga wardrobe of design/validated/avatar-manga.md).
   Ported from the conception's sg-core.js (SG.avatar).

   A classic script, strict, that sets globalThis.SGAvatar: the games load it with a plain
   <script src> from their sandboxed frame (an opaque origin, where a module script would need CORS),
   and Formatool's Stimulus controllers import() it, which runs it just the same.

     SGAvatar.svg(config, {size, bare, viewBox, costume, round}) → SVG markup   (renderAvatar)
     SGAvatar.html(config, size, options)                       → the same in a round span
     SGAvatar.normalize(config)                                 → a version 3 config, defaults filled
     SGAvatar.random(rng?) · SGAvatar.CATS (the editor's catalogue) · SGAvatar.DEF · SGAvatar.PRESET

   No gender is stored ('start' only says which drawing it began from), no headwear of one's own
   (a hair accessory is not one; a halo or horns are effects), and the bare head - the one a game sets
   on its own body - carries a neck in the skin colour from y 150 to 196, with a shadow under the chin.
   Every outfit starts at y 186 at the highest. The avatar is drawn the same everywhere, effects
   included: the bare head only leaves out what is not the head (background, pattern, aura, top).

   Colours are stored as indexes: the palettes only ever grow at the end. */
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
    { id: 'pin-neko', name: 'Épinglette Neko', color: '#E8457A', glyph: '^' },
    { id: 'pin-ghost', name: 'Épinglette Ghost', color: '#0A0618', glyph: '◠' },
    { id: 'pin-joystick', name: 'Épinglette Joystick', color: '#6A3EA0', glyph: '✚' },
    { id: 'pin-comete', name: 'Épinglette Comète', color: '#14181C', glyph: '☄' },
    { id: 'pin-passerelle', name: 'Épinglette Passerelle', color: '#D8C29D', glyph: '⊓' },
    { id: 'pin-neon', name: 'Épinglette Néon', color: '#14111F', glyph: '☾' },
    { id: 'pin-polaire', name: 'Épinglette Polaire', color: '#0B1030', glyph: '✦' }
  ];
  A.PINS = PINS;

  A.SKIN = [['#F6D7C3', '#DDB49A'], ['#EBC09C', '#CC9A72'], ['#D69E73', '#B47D56'], ['#B57A4E', '#935F38'], ['#8A5534', '#6B4027'], ['#5E3A24', '#46291A'],
    ['#FBE7DA', '#E5C4AE'], ['#3F2618', '#2B190F'], ['#CFE2F3', '#A6C1DB'], ['#C7E5CB', '#9DC4A3'], ['#E3DAF3', '#C0B3DC']];
  A.HAIR = ['#2B211C', '#5A3825', '#8A5A33', '#D9B26A', '#B5532C', '#A8A8A8', '#4E6BD1', '#D9779B',
    '#F1EEE6', '#F7C520', '#C3CEDD', '#3E9E52', '#7A4CC6', '#D52B3C', '#25BFC4', '#EE7D2B', '#1C2846'];
  A.IRIS = ['#4A2E1E', '#7A5A2E', '#4F7A4A', '#4A78A8', '#6D7580', '#C0392B', '#7D4CB8', '#D4A017', '#25A55A', '#1FB0D3'];
  A.TOP = ['#C8463D', '#2F6FB0', '#3E8E5E', '#E0A93B', '#6B4FA0', '#2B2B2B', '#F2F2EE', '#1F2B4D', '#E58AA8', '#2BB3A8', '#EE7D2B'];
  A.BG = ['#DDE7F2', '#F3E3C8', '#E4EEDD', '#F2DCDC', '#E6DFF0', '#D9EEEC', '#ECEBE6', '#1B2550', '#FFE6A6', '#FFD3DE', '#16182A',
    '#FFB9A6', '#D3EC94', '#3A2E7A', '#6B1E2E', '#0F3D3E'];
  // The backgrounds a pattern is drawn on in a light ink.
  var DARK_BG = [7, 10, 13, 14, 15];
  // An aura: its flames, their heart, the heart's opacity.
  var AURA = { doree: ['#FFD23F', '#FFFFFF', .35], bleue: ['#4FC3F7', '#FFFFFF', .35], rouge: ['#FF5A4E', '#FFFFFF', .35], violette: ['#B388FF', '#FFFFFF', .35],
    verte: ['#5BE37D', '#FFFFFF', .35], noire: ['#2A1748', '#B388FF', .6], feu: ['#FF6A1F', '#FFD23F', .75], glace: ['#7FE3FF', '#F2FBFF', .6] };
  // Starting points. The handoff called them Masculin / Féminin; the conception keeps two drawn presets
  // and stores no gender (see avatar.html, « Point de départ »): 'start' only says which drawing it began from.
  A.PRESET = { a: { hair: 'court', brows: 'droits' }, b: { hair: 'milong', brows: 'arques' } };
  A.DEF = { start: 'a', face: 'rond', skin: 2, hair: 'court', hairC: 1, hairC2: -1, hairAcc: 'aucun', eyes: 'ronds', iris: 0, brows: 'arques', nose: 'arrondi', mouth: 'sourire',
    glasses: 'aucune', mask: 'aucun', ears: 'aucune', top: 1, topCut: 'rond', freckles: false, beard: false, blush: true, braces: false, hearing: false,
    scar: false, bandage: false, mole: false, ahoge: false, reflet: false, hetero: false, patch: false, bg: 0, bgPat: 'uni', aura: 'aucune', above: 'aucun',
    hachures: false, goutte: false, veine: false, larmes: false, ombre: false, deprime: false, glare: false, bulle: false, etincelles: false, electricite: false, confettis: false,
    pin: null };
  A.ZOOM = '48 72 104 104';
  // Options marked "added" are proposals of a conception, absent from the handoff (the manga wardrobe's
  // among them). A group's own `vb` replaces its tab's view; `note` is a line under the group's title.
  A.CATS = [
    { id: 'visage', label: 'Visage', groups: [
      { t: 'Point de départ', key: 'start', preset: true, opts: [['a', 'Silhouette A'], ['b', 'Silhouette B']] },
      { t: 'Forme', key: 'face', opts: [['rond', 'Rond'], ['ovale', 'Ovale'], ['carre', 'Carré'], ['pointu', 'Menton en V', 'added'], ['joues', 'Joues rondes', 'added']] },
      { t: 'Teint', key: 'skin', colors: A.SKIN.map(function (s) { return s[0]; }) },
      { t: 'Marques', toggle: true, vb: A.ZOOM, opts: [['scar', 'Cicatrice', 'added'], ['bandage', 'Pansement', 'added'], ['mole', 'Grain de beauté', 'added']] }] },
    { id: 'cheveux', label: 'Cheveux', groups: [
      { t: 'Coupe', key: 'hair', opts: [['rase', 'Rasé'], ['court', 'Court'], ['milong', 'Mi-long'], ['long', 'Long'], ['boucle', 'Bouclé'], ['afro', 'Afro', 'added'], ['tresses', 'Tresses', 'added'], ['chignon', 'Chignon'], ['crete', 'Crête'],
        ['pics', 'Pics de combattant', 'added'], ['flamme', 'Flamme dorée', 'added'], ['herisson', 'Hérisson', 'added'], ['longpics', 'Crinière', 'added'], ['frange', 'Frange droite', 'added'],
        ['couettes', 'Couettes', 'added'], ['queue', 'Queue de cheval haute', 'added'], ['odango', 'Deux chignons', 'added'], ['meche', 'Mèche sur l’œil', 'added'], ['rideau', 'Raie au milieu', 'added'],
        ['undercut', 'Côtés rasés', 'added'], ['banane', 'Banane', 'added'], ['locks', 'Locks', 'added']] },
      { t: 'Couleur', key: 'hairC', colors: A.HAIR },
      { t: 'Pointes d’une autre couleur', key: 'hairC2', colors: A.HAIR, none: true, note: 'Vers le haut sur les coupes dressées, vers le bas sur les autres.' },
      { t: 'Détails', toggle: true, opts: [['ahoge', 'Mèche rebelle', 'added'], ['reflet', 'Reflet', 'added']] },
      { t: 'Accessoire', key: 'hairAcc', opts: [['aucun', 'Aucun'], ['barrette', 'Barrettes', 'added'], ['ruban', 'Nœud', 'added']] }] },
    { id: 'yeux', label: 'Yeux', vb: A.ZOOM, groups: [
      { t: 'Forme', key: 'eyes', opts: [['ronds', 'Ronds'], ['amande', 'Amande'], ['grands', 'Grands'], ['souriants', 'Rieurs'],
        ['manga', 'Manga', 'added'], ['percants', 'Perçants', 'added'], ['endormis', 'Endormis', 'added'], ['etoiles', 'Étoilés', 'added'], ['clin', 'Clin d’œil', 'added']] },
      { t: 'Couleur', key: 'iris', colors: A.IRIS },
      { t: 'Détail', toggle: true, opts: [['hetero', 'Yeux vairons', 'added']] }] },
    { id: 'sourcils', label: 'Sourcils', vb: A.ZOOM, groups: [{ t: 'Forme', key: 'brows', opts: [['aucun', 'Aucun'], ['droits', 'Droits'], ['arques', 'Arqués'], ['epais', 'Épais'],
      ['fronces', 'Froncés', 'added'], ['inquiets', 'Inquiets', 'added'], ['points', 'Points', 'added'], ['fins', 'Fins', 'added']] }] },
    { id: 'nez', label: 'Nez', vb: A.ZOOM, groups: [{ t: 'Forme', key: 'nose', opts: [['petit', 'Petit'], ['arrondi', 'Arrondi'], ['long', 'Long'], ['triangle', 'Marqué'],
      ['trait', 'Trait manga', 'added'], ['retrousse', 'Retroussé', 'added'], ['aucun', 'Aucun', 'added']] }] },
    { id: 'bouche', label: 'Bouche', vb: A.ZOOM, groups: [{ t: 'Forme', key: 'mouth', opts: [['sourire', 'Sourire'], ['grand', 'Rire'], ['neutre', 'Neutre'], ['surprise', 'Surprise'],
      ['croc', 'Petit croc', 'added'], ['chat', 'Bouche de chat', 'added'], ['coin', 'Sourire en coin', 'added'], ['moue', 'Moue', 'added'], ['langue', 'Langue tirée', 'added'], ['dents', 'Dents serrées', 'added']] }] },
    { id: 'acc', label: 'Accessoires', vb: A.ZOOM, groups: [
      { t: 'Lunettes', key: 'glasses', opts: [['aucune', 'Aucune'], ['rondes', 'Rondes'], ['carrees', 'Carrées'], ['solaires', 'Solaires'], ['fines', 'Rondes fines', 'added'], ['demi', 'Demi-cerclées', 'added']] },
      { t: 'Bas du visage', key: 'mask', opts: [['aucun', 'Aucun'], ['blanc', 'Masque blanc', 'added'], ['noir', 'Masque noir', 'added']] },
      { t: 'Oreilles', key: 'ears', vb: null, opts: [['aucune', 'Aucune'], ['clous', 'Clous'], ['anneaux', 'Anneaux', 'added'], ['pendants', 'Pendants', 'added']] },
      { t: 'Détails', toggle: true, opts: [['blush', 'Joues roses'], ['freckles', 'Taches de rousseur'], ['beard', 'Barbe'], ['braces', 'Appareil dentaire', 'added'], ['hearing', 'Appareil auditif', 'added'], ['patch', 'Cache-œil', 'added']] }] },
    { id: 'fond', label: 'Haut & fond', groups: [
      { t: 'Coupe du haut', key: 'topCut', opts: [['rond', 'Col rond'], ['marin', 'Col marin', 'added'], ['gakuran', 'Col montant', 'added'], ['capuche', 'Sweat à capuche', 'added'],
        ['kimono', 'Col croisé', 'added'], ['veste', 'Veste ouverte', 'added'], ['echarpe', 'Écharpe', 'added']] },
      { t: 'Couleur du haut', key: 'top', colors: A.TOP },
      { t: 'Fond', key: 'bg', colors: A.BG },
      { t: 'Motif du fond', key: 'bgPat', opts: [['uni', 'Uni'], ['vitesse', 'Lignes de vitesse', 'added'], ['trame', 'Trame', 'added'], ['eclat', 'Éclat', 'added'], ['cases', 'Cases de BD', 'added'],
        ['onomatopee', 'Onomatopée', 'added'], ['sakura', 'Pétales', 'added'], ['vagues', 'Vagues japonaises', 'added'], ['eclairs', 'Éclairs', 'added'], ['flammes', 'Flammes', 'added'],
        ['etoiles', 'Étoiles', 'added'], ['cosmos', 'Cosmos', 'added'], ['lune', 'Pleine lune', 'added'], ['nuages', 'Nuages', 'added'], ['bulles', 'Bulles', 'added'], ['coeurs', 'Cœurs', 'added'],
        ['pluie', 'Pluie', 'added'], ['neige', 'Neige', 'added'], ['rayures', 'Rayures', 'added'], ['damier', 'Damier', 'added'], ['code', 'Code binaire', 'added'], ['circuit', 'Circuit imprimé', 'added'],
        ['degrade', 'Coucher de soleil', 'added']] }] },
    { id: 'effets', label: 'Effets', groups: [
      { t: 'Aura', key: 'aura', opts: [['aucune', 'Aucune'], ['doree', 'Dorée', 'added'], ['bleue', 'Bleue', 'added'], ['rouge', 'Rouge', 'added'], ['violette', 'Violette', 'added'],
        ['verte', 'Verte', 'added'], ['noire', 'Ténèbres', 'added'], ['feu', 'Feu', 'added'], ['glace', 'Glace', 'added']] },
      { t: 'Au-dessus de la tête', key: 'above', note: 'Un seul à la fois.', opts: [['aucun', 'Aucun'], ['exclam', 'Exclamation', 'added'], ['interro', 'Question', 'added'], ['ampoule', 'Idée', 'added'],
        ['zzz', 'Sommeil', 'added'], ['musique', 'Musique', 'added'], ['coeurs', 'Amoureux', 'added'], ['etourdi', 'Étourdi', 'added'], ['fumee', 'Fumée', 'added'], ['halo', 'Auréole', 'added'], ['cornes', 'Cornes', 'added']] },
      { t: 'Sur le visage', toggle: true, opts: [['hachures', 'Rougissement', 'added'], ['goutte', 'Goutte de sueur', 'added'], ['veine', 'Veine de colère', 'added'], ['larmes', 'Larmes', 'added'],
        ['ombre', 'Regard dans l’ombre', 'added'], ['deprime', 'Déprime', 'added'], ['glare', 'Lunettes qui brillent', 'added'], ['bulle', 'Bulle de sommeil', 'added']] },
      { t: 'Autour', toggle: true, opts: [['etincelles', 'Étincelles', 'added'], ['electricite', 'Électricité', 'added'], ['confettis', 'Confettis', 'added']] }] }
  ];

  function attrs(o) {
    var s = '';
    for (var k in o) { if (o[k] === null || o[k] === undefined) continue; s += ' ' + k.replace(/[A-Z]/g, function (m) { return '-' + m.toLowerCase(); }) + '="' + o[k] + '"'; }
    return s;
  }
  function el(t, o) { return '<' + t + attrs(o) + '/>'; }
  var ST = { fill: 'none', strokeLinecap: 'round', strokeLinejoin: 'round' };
  function sx(o) { var r = {}; for (var k in ST) r[k] = ST[k]; for (var j in o) r[j] = o[j]; return r; }
  function poly(pts) { return 'M' + pts.map(function (p) { return p[0] + ' ' + p[1]; }).join(' L') + ' Z'; }
  // Points round a centre, alternately at rOut and rIn: spikes, flames, a burst.
  function burst(cx, cy, a0, a1, step, rOut, rIn) {
    var pts = [], i = 0;
    for (var a = a0; a <= a1 + 0.001; a += step, i++) { var r = i % 2 ? rIn : rOut, t = a * Math.PI / 180; pts.push([+(cx + r * Math.cos(t)).toFixed(1), +(cy + r * Math.sin(t)).toFixed(1)]); }
    return pts;
  }
  function star4(cx, cy, r) { return 'M' + cx + ' ' + (cy - r) + ' Q' + cx + ' ' + cy + ' ' + (cx + r) + ' ' + cy + ' Q' + cx + ' ' + cy + ' ' + cx + ' ' + (cy + r) + ' Q' + cx + ' ' + cy + ' ' + (cx - r) + ' ' + cy + ' Q' + cx + ' ' + cy + ' ' + cx + ' ' + (cy - r) + ' Z'; }
  function star5(cx, cy, R, r) { var pts = []; for (var i = 0; i < 10; i++) { var a = (-90 + i * 36) * Math.PI / 180, rr = i % 2 ? r : R; pts.push([+(cx + rr * Math.cos(a)).toFixed(2), +(cy + rr * Math.sin(a)).toFixed(2)]); } return poly(pts); }
  function heart(cx, cy, s) { return 'M' + cx + ' ' + (cy + s * .9) + ' C' + (cx - s * 1.6) + ' ' + (cy - s * .2) + ' ' + (cx - s * .6) + ' ' + (cy - s * 1.3) + ' ' + cx + ' ' + (cy - s * .45) + ' C' + (cx + s * .6) + ' ' + (cy - s * 1.3) + ' ' + (cx + s * 1.6) + ' ' + (cy - s * .2) + ' ' + cx + ' ' + (cy + s * .9) + ' Z'; }
  function cloud(cx, cy, s, fill, stroke) { return '<g fill="' + fill + '"' + (stroke ? ' stroke="' + stroke + '" stroke-width="1.2"' : '') + '><circle cx="' + (cx - s) + '" cy="' + cy + '" r="' + (s * .7) + '"/><circle cx="' + cx + '" cy="' + (cy - s * .4) + '" r="' + s + '"/><circle cx="' + (cx + s) + '" cy="' + cy + '" r="' + (s * .75) + '"/></g>'; }
  // A fixed sequence, so that rain, snow, stars and code fall the same way at every drawing.
  function seeded(n) { var x = n; return function () { x = (x * 9301 + 49297) % 233280; return x / 233280; }; }
  // <defs> ids must stay unique: several avatars share a page.
  function nextId(prefix) { return prefix + (++uid).toString(36) + Math.random().toString(36).slice(2, 6); }

  var FACES = { rond: { rx: 58, ry: 64 }, ovale: { rx: 52, ry: 68 }, carre: { rx: 56, ry: 62 }, pointu: { rx: 58, ry: 64 }, joues: { rx: 62, ry: 60, cy: 110 } };
  // Hair whose tips point up: their second colour goes up, everybody else's down.
  var UP = ['pics', 'flamme', 'herisson', 'crete', 'banane'];
  var COURT = 'M42 104 Q38 38 100 36 Q162 38 158 104 Q154 74 128 66 Q104 78 76 66 Q48 76 42 104 Z';
  var RASE = 'M44 96 Q44 40 100 38 Q156 40 156 96 Q130 70 100 70 Q70 70 44 96 Z';
  var FRINGE_MESSY = [[42, 104], [40, 66], [60, 44], [100, 36], [140, 44], [160, 66], [158, 104], [150, 80], [146, 86], [136, 70], [126, 84], [116, 66], [106, 84], [96, 66], [86, 84], [76, 68], [66, 84], [58, 72], [50, 86]];
  // The hair behind the face and in front of it. The spikes stay inside the r = 100 disc the avatar is cut in.
  var BACK = {
    herisson: poly([[100, 140]].concat(burst(100, 92, 150, 390, 12, 84, 64))),
    longpics: poly([[100, 30], [166, 40], [162, 100], [178, 140], [164, 140], [174, 178], [156, 166], [158, 200], [140, 184], [128, 200], [72, 200], [60, 184], [42, 200], [44, 166], [26, 178], [36, 140], [22, 140], [38, 100], [34, 40]]),
    frange: 'M38 100 Q36 32 100 30 Q164 32 162 100 L166 200 L34 200 Z',
    couettes: 'M54 58 Q8 70 14 140 Q18 176 36 196 Q28 150 44 104 Q48 84 60 70 Z M146 58 Q192 70 186 140 Q182 176 164 196 Q172 150 156 104 Q152 84 140 70 Z',
    queue: 'M118 44 Q172 26 178 76 Q182 124 160 166 Q164 116 150 86 Q140 64 116 58 Z'
  };
  var FRONT = {
    pics: poly([[42, 108], [36, 80], [22, 72], [40, 62], [30, 42], [52, 48], [50, 24], [70, 40], [76, 14], [92, 36], [104, 10], [114, 34], [130, 16], [132, 42], [154, 28], [150, 52], [172, 50], [158, 68], [176, 80], [158, 86], [158, 108], [150, 82], [140, 84], [134, 70], [124, 84], [112, 68], [104, 84], [94, 68], [84, 84], [74, 70], [64, 84], [58, 76], [50, 88]]),
    flamme: poly([[44, 106], [38, 74], [24, 60], [42, 54], [34, 28], [56, 38], [58, 12], [76, 32], [90, 4], [100, 30], [112, 4], [122, 32], [142, 12], [142, 38], [166, 28], [156, 54], [176, 60], [160, 74], [156, 106], [150, 82], [136, 74], [124, 84], [112, 70], [106, 78], [104, 98], [98, 114], [98, 94], [92, 76], [82, 72], [70, 82], [58, 74], [50, 90]]),
    herisson: poly(FRINGE_MESSY),
    longpics: poly(FRINGE_MESSY),
    frange: 'M40 120 Q34 34 100 32 Q166 34 160 120 L160 82 L40 82 Z M40 82 L56 82 L56 152 L40 148 Z M144 82 L160 82 L160 148 L144 152 Z',
    meche: 'M40 112 Q34 34 100 32 Q166 34 160 112 Q158 92 150 80 Q154 110 142 136 Q132 100 110 80 Q98 74 92 72 Q70 82 54 76 Q44 90 40 112 Z',
    rideau: 'M40 116 Q34 34 100 32 Q166 34 160 116 Q154 76 104 64 L100 78 L96 64 Q46 76 40 116 Z',
    undercut: 'M44 86 Q44 32 100 28 Q156 30 160 76 Q150 60 128 58 Q100 56 80 64 Q60 72 44 86 Z M84 62 Q56 66 48 100 Q62 78 90 70 Z',
    banane: 'M46 92 Q40 46 84 34 Q132 16 168 44 Q160 50 148 52 Q164 64 160 96 Q146 70 112 66 Q76 66 46 92 Z'
  };
  // The cuts drawn over the short cap, and the locks' columns (x, top, bottom).
  var CAP = ['court', 'chignon', 'tresses', 'afro', 'couettes', 'queue', 'odango', 'locks'];
  var LOCKS = [[30, 92, 172], [42, 80, 190], [54, 96, 160], [146, 96, 160], [158, 80, 190], [170, 92, 172]];

  /* The background's pattern, drawn over its colour (in a light ink on a dark one). */
  function pattern(kind, bg, dark) {
    var ink = dark ? '#FFFFFF' : '#1B1A22', s = '', R;
    var full = function (inner, w, h, extra) { var pid = nextId('ap'); return '<defs><pattern id="' + pid + '" width="' + w + '" height="' + h + '" patternUnits="userSpaceOnUse"' + (extra || '') + '>' + inner + '</pattern></defs>' + el('rect', { x: 0, y: 0, width: 200, height: 200, fill: 'url(#' + pid + ')' }); };
    var jp = 'Hiragino Sans, Yu Gothic, Noto Sans JP, sans-serif';
    if (kind === 'vitesse') {
      for (var a = 0; a < 360; a += 7.5) { var t = a * Math.PI / 180; s += el('path', { d: 'M' + (100 + 66 * Math.cos(t)).toFixed(1) + ' ' + (100 + 66 * Math.sin(t)).toFixed(1) + ' L' + (100 + 150 * Math.cos(t)).toFixed(1) + ' ' + (100 + 150 * Math.sin(t)).toFixed(1), stroke: ink, strokeWidth: (a / 7.5) % 3 === 0 ? 2.6 : 1.1, opacity: .22 }); }
      return s;
    }
    if (kind === 'trame') return full('<circle cx="3.5" cy="3.5" r="1.5" fill="' + ink + '" opacity=".2"/>', 7, 7);
    if (kind === 'eclat') return el('path', { d: poly(burst(100, 100, 0, 360, 7.5, 150, 72)), fill: '#FFFFFF', opacity: dark ? .12 : .65 });
    if (kind === 'cases') { var cd = 'M-4 66 L204 50 M122 -4 L112 57 M64 63 L48 204 M150 54 L170 204'; return el('path', sx({ d: cd, stroke: ink, strokeWidth: 10, opacity: .55 })) + el('path', sx({ d: cd, stroke: dark ? '#0E0E16' : '#FFFFFF', strokeWidth: 6 })); }
    if (kind === 'onomatopee') return '<text x="6" y="72" font-size="40" font-weight="900" font-family="' + jp + '" fill="' + ink + '" opacity=".2" transform="rotate(-14 6 72)">ドン</text><text x="138" y="188" font-size="26" font-weight="900" font-family="' + jp + '" fill="' + ink + '" opacity=".2" transform="rotate(10 138 188)">ドン</text>';
    if (kind === 'sakura') {
      [[28, 40, 30], [170, 34, -20], [162, 150, 50], [34, 158, 10], [62, 16, 70], [180, 98, -40], [18, 96, 60]].forEach(function (q) {
        s += el('ellipse', { cx: q[0], cy: q[1], rx: 7, ry: 4, fill: '#F59AB8', opacity: .85, transform: 'rotate(' + q[2] + ' ' + q[0] + ' ' + q[1] + ')' });
      });
      return s;
    }
    if (kind === 'vagues') {
      var scale = function (x, y) { return [14, 9.5, 5].map(function (r, i) { return '<circle cx="' + x + '" cy="' + y + '" r="' + r + '" fill="' + (i ? 'none' : bg) + '" stroke="' + ink + '" stroke-opacity=".24" stroke-width="1.3"/>'; }).join(''); };
      return full(scale(0, 0) + scale(28, 0) + scale(14, 7) + scale(0, 14) + scale(28, 14), 28, 14);
    }
    if (kind === 'eclairs') {
      [[36, 26, 1.3], [170, 36, 1.1], [176, 136, .9], [26, 124, .9]].forEach(function (q) { var x = q[0], y = q[1], k = q[2];
        s += el('path', { d: poly([[x, y], [x - 6 * k, y + 14 * k], [x - k, y + 14 * k], [x - 5 * k, y + 28 * k], [x + 7 * k, y + 10 * k], [x + 2 * k, y + 10 * k], [x + 6 * k, y]]), fill: '#FFE14D', stroke: '#C99A00', strokeWidth: 1, strokeLinejoin: 'round' }); });
      return s;
    }
    if (kind === 'flammes') {
      var fo = [[0, 200]], fi = [[0, 200]];
      for (var fx = 0, n = 0; fx <= 200; fx += 12.5, n++) { fo.push([fx, n % 2 ? 160 : 118]); fi.push([fx, n % 2 ? 182 : 150]); }
      fo.push([200, 200]); fi.push([200, 200]);
      return el('path', { d: poly(fo), fill: '#FF6A1F', opacity: .75 }) + el('path', { d: poly(fi), fill: '#FFD23F', opacity: .85 });
    }
    if (kind === 'etoiles') {
      [[30, 36, 7], [168, 30, 5], [178, 120, 6], [22, 130, 5], [150, 172, 4], [52, 178, 4], [120, 14, 4]].forEach(function (q) { s += el('path', { d: star4(q[0], q[1], q[2]), fill: dark ? '#FFE6A6' : '#FFFFFF', opacity: .9 }); });
      return s;
    }
    if (kind === 'cosmos') {
      s = el('rect', { x: 0, y: 0, width: 200, height: 200, fill: '#120F2E', opacity: .9 }) + el('ellipse', { cx: 60, cy: 60, rx: 74, ry: 38, fill: '#6B3FC7', opacity: .38, transform: 'rotate(-24 60 60)' }) + el('ellipse', { cx: 150, cy: 150, rx: 64, ry: 30, fill: '#1FB0D3', opacity: .28, transform: 'rotate(-24 150 150)' });
      R = seeded(3); for (var ci = 0; ci < 36; ci++) s += el('circle', { cx: Math.round(R() * 200), cy: Math.round(R() * 200), r: (.5 + R() * 1.3).toFixed(1), fill: '#FFFFFF', opacity: (.5 + R() * .5).toFixed(2) });
      return s;
    }
    if (kind === 'lune') return el('circle', { cx: 154, cy: 48, r: 30, fill: '#FFF1B8', opacity: .95 }) + el('circle', { cx: 145, cy: 41, r: 5, fill: '#EAD68E' }) + el('circle', { cx: 163, cy: 58, r: 3.5, fill: '#EAD68E' }) + el('circle', { cx: 160, cy: 33, r: 2.4, fill: '#EAD68E' });
    if (kind === 'nuages') { [[40, 40, 14], [160, 30, 11], [170, 170, 16], [30, 172, 12], [184, 100, 8]].forEach(function (q) { s += cloud(q[0], q[1], q[2], dark ? 'rgba(255,255,255,.25)' : '#FFFFFF', null); }); return s; }
    if (kind === 'bulles') { [[30, 40, 14], [170, 62, 10], [160, 160, 18], [40, 160, 9], [100, 14, 7], [186, 120, 6], [14, 100, 7]].forEach(function (q) { s += el('circle', { cx: q[0], cy: q[1], r: q[2], fill: '#FFFFFF', fillOpacity: .3, stroke: '#FFFFFF', strokeOpacity: .8, strokeWidth: 1.5 }) + el('circle', { cx: q[0] - q[2] * .4, cy: q[1] - q[2] * .4, r: q[2] * .2, fill: '#FFFFFF', opacity: .9 }); }); return s; }
    if (kind === 'coeurs') { [[28, 44, 8], [172, 40, 7], [168, 160, 9], [34, 156, 6], [100, 14, 5], [186, 104, 5], [14, 104, 5]].forEach(function (q) { s += el('path', { d: heart(q[0], q[1], q[2]), fill: '#F06292', opacity: .7 }); }); return s; }
    if (kind === 'pluie') { R = seeded(7); for (var pi = 0; pi < 46; pi++) { var px = Math.round(R() * 210), py = Math.round(R() * 200); s += el('path', sx({ d: 'M' + px + ' ' + py + ' l-5 13', stroke: dark ? '#9FC3E8' : '#4E6BD1', strokeWidth: 1.6, opacity: .45 })); } return s; }
    if (kind === 'neige') { R = seeded(11); for (var ni = 0; ni < 40; ni++) s += el('circle', { cx: Math.round(R() * 200), cy: Math.round(R() * 200), r: (1.4 + R() * 2.4).toFixed(1), fill: '#FFFFFF', stroke: dark ? 'none' : '#AFC0D6', strokeWidth: .8 }); return s; }
    if (kind === 'rayures') return full('<rect width="7" height="14" fill="' + ink + '" opacity=".1"/>', 14, 14, ' patternTransform="rotate(45)"');
    if (kind === 'damier') return full('<rect width="20" height="20" fill="' + ink + '" opacity=".09"/><rect x="20" y="20" width="20" height="20" fill="' + ink + '" opacity=".09"/>', 40, 40);
    if (kind === 'code') {
      R = seeded(5);
      for (var cx = 6; cx < 200; cx += 14) { s += '<text font-family="Menlo, Consolas, monospace" font-size="10" fill="#2FD27A" opacity="' + (.25 + R() * .25).toFixed(2) + '">'; for (var cy = 10; cy < 200; cy += 12) s += '<tspan x="' + cx + '" y="' + cy + '">' + (R() < .5 ? '0' : '1') + '</tspan>'; s += '</text>'; }
      return s;
    }
    if (kind === 'circuit') {
      var cc = dark ? '#45C9C0' : '#1F8F86';
      s = el('path', sx({ d: 'M0 40 H40 V70 H66 M200 30 H160 V60 H140 M0 150 H30 V120 H50 M200 160 H170 V130 H152 M88 200 V180 H120 V168 M30 0 V22 H58', stroke: cc, strokeWidth: 2, opacity: .5 }));
      [[66, 70], [140, 60], [50, 120], [152, 130], [120, 168], [58, 22]].forEach(function (q) { s += el('circle', { cx: q[0], cy: q[1], r: 3.2, fill: cc, opacity: .6 }); });
      return s;
    }
    if (kind === 'degrade') { var gd = nextId('ag'); return '<defs><linearGradient id="' + gd + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + bg + '"/><stop offset="1" stop-color="#F7A35C"/></linearGradient></defs>' + el('rect', { x: 0, y: 0, width: 200, height: 200, fill: 'url(#' + gd + ')' }); }
    return '';
  }

  /* The top: one cut, in the top's colour. */
  function drawTop(p, c) {
    var col = A.TOP[c.top], base = 'M24 200 Q30 168 100 166 Q170 168 176 200 Z', white = '#F4F4F0';
    if (c.topCut === 'marin') {
      p('path', { d: base, fill: white });
      p('path', { d: 'M30 190 Q50 170 84 167 L100 194 L116 167 Q150 170 170 190 L176 200 L24 200 Z', fill: col });
      p('path', sx({ d: 'M40 188 Q60 174 87 172 M160 188 Q140 174 113 172', stroke: white, strokeWidth: 2 }));
      p('path', { d: 'M92 184 L108 184 L100 199 Z', fill: '#D8263A' });
      return;
    }
    p('path', { d: base, fill: col });
    if (c.topCut === 'gakuran') {
      p('path', { d: 'M82 158 L118 158 L120 174 Q100 179 80 174 Z', fill: col }); p('path', { d: 'M82 158 L118 158 L120 174 Q100 179 80 174 Z', fill: '#000', opacity: .22 });
      p('circle', { cx: 100, cy: 186, r: 2.8, fill: '#E0A93B' }); p('circle', { cx: 100, cy: 198, r: 2.8, fill: '#E0A93B' });
    }
    if (c.topCut === 'capuche') { p('path', sx({ d: 'M92 174 L90 196', stroke: white, strokeWidth: 2.4 })); p('path', sx({ d: 'M108 174 L110 196', stroke: white, strokeWidth: 2.4 })); }
    if (c.topCut === 'kimono') {
      p('path', { d: 'M86 166 L100 186 L114 166 Z', fill: white });
      p('path', sx({ d: 'M84 166 L112 200', stroke: '#000', strokeWidth: 7, opacity: .22 })); p('path', sx({ d: 'M116 166 L101 185', stroke: '#000', strokeWidth: 7, opacity: .22 }));
    }
    if (c.topCut === 'veste') {
      p('path', { d: 'M82 167 Q100 172 118 167 L112 200 L88 200 Z', fill: white });
      p('path', sx({ d: 'M82 167 L90 200 M118 167 L110 200', stroke: '#000', strokeWidth: 3, opacity: .25 }));
    }
    if (c.topCut === 'echarpe') {
      var sc = c.top === 0 || c.top === 8 ? '#1F2B4D' : '#D8263A';
      p('path', { d: 'M62 160 Q100 180 138 160 L140 174 Q100 194 60 174 Z', fill: sc });
      p('path', { d: 'M116 176 L130 200 L114 200 L106 180 Z', fill: sc }); p('path', { d: 'M116 176 L130 200 L114 200 L106 180 Z', fill: '#000', opacity: .2 });
    }
  }

  /* The head, in the 0 0 200 200 box. bare=true: no background, pattern, aura nor top (to sit on a game's body). */
  A.parts = function (c, bare) {
    c = Object.assign({}, A.DEF, c || {});
    var k = [], p = function (t, a) { k.push(el(t, a)); }, raw = function (s) { k.push(s); };
    var sk = A.SKIN[c.skin][0], skd = A.SKIN[c.skin][1], hs = A.HAIR[c.hairC], ir = A.IRIS[c.iris], ink = '#2A1E1A', lip = c.start === 'b' ? '#B5444A' : '#8A3A34';
    var ir2 = c.hetero ? A.IRIS[(c.iris + 5) % A.IRIS.length] : ir;
    // hs is the hair's own colour (brows, beard); hc what the hair is filled with, a gradient when the tips differ.
    var hc = hs;
    if (c.hairC2 >= 0 && c.hairC2 < A.HAIR.length && c.hairC2 !== c.hairC) {
      var gh = nextId('ah'), up = UP.indexOf(c.hair) >= 0;
      raw('<defs><linearGradient id="' + gh + '" gradientUnits="userSpaceOnUse" x1="0" x2="0" y1="' + (up ? 96 : 70) + '" y2="' + (up ? 10 : 190) + '"><stop offset="0.2" stop-color="' + hs + '"/><stop offset="1" stop-color="' + A.HAIR[c.hairC2] + '"/></linearGradient></defs>');
      hc = 'url(#' + gh + ')';
    }
    var F = FACES[c.face], cy = F.cy || 108, ex = F.rx;
    if (!bare) { p('rect', { x: 0, y: 0, width: 200, height: 200, fill: A.BG[c.bg] }); raw(pattern(c.bgPat, A.BG[c.bg], DARK_BG.indexOf(c.bg) >= 0)); }
    if (!bare && AURA[c.aura]) {
      var ac = AURA[c.aura];
      p('circle', { cx: 100, cy: 100, r: 84, fill: ac[0], opacity: .3 });
      p('path', { d: poly(burst(100, 100, 0, 360, 9, 99, 76)), fill: ac[0], opacity: .65 });
      p('path', { d: poly(burst(100, 104, 4.5, 364.5, 9, 86, 68)), fill: ac[1], opacity: ac[2] });
    }
    // the hair behind the face
    if (c.hair === 'long') p('path', { d: 'M38 104 Q36 36 100 34 Q164 36 162 104 L170 200 L30 200 Z', fill: hc });
    if (c.hair === 'milong' || c.hair === 'meche' || c.hair === 'rideau') p('path', { d: 'M40 104 Q38 36 100 34 Q162 36 160 104 L162 156 Q100 166 38 156 Z', fill: hc });
    if (c.hair === 'afro') { p('circle', { cx: 100, cy: 88, r: 72, fill: hc }); for (var aa = 0; aa < 360; aa += 30) { var ar = aa * Math.PI / 180; p('circle', { cx: 100 + 66 * Math.cos(ar), cy: 88 + 66 * Math.sin(ar) * .95, r: 12, fill: hc }); } }
    if (c.hair === 'tresses') { for (var tx = 0; tx < 2; tx++) { var bx = tx ? 150 : 42; for (var ty = 0; ty < 6; ty++) p('ellipse', { cx: bx + (tx ? 4 : -4), cy: 98 + ty * 15, rx: 9, ry: 9, fill: hc }); } }
    if (BACK[c.hair]) p('path', { d: BACK[c.hair], fill: hc });
    if (c.hair === 'locks') LOCKS.forEach(function (l) { p('rect', { x: l[0] - 6, y: l[1], width: 12, height: l[2] - l[1], rx: 6, fill: hc }); });
    if (!bare && c.topCut === 'capuche') { var hood = 'M46 190 Q44 156 100 154 Q156 156 154 190 Z'; p('path', { d: hood, fill: A.TOP[c.top] }); p('path', { d: hood, fill: '#000', opacity: .24 }); }
    // The neck. On a bare head (the one games put on their own bodies) it is longer and in the skin
    // colour, so it shows between the chin (y = 172) and any collar: games start their outfit at y ≥ 186.
    if (bare) { p('rect', { x: 85, y: 150, width: 30, height: 46, rx: 6, fill: sk }); p('path', { d: 'M85 168 Q100 184 115 168 L115 178 Q100 190 85 178 Z', fill: skd, opacity: .55 }); }
    else p('rect', { x: 84, y: 150, width: 32, height: 26, fill: skd });
    if (!bare) {
      drawTop(p, c);
      if (c.pin) k.push(A.pinSvg(c.pin, 136, 186, 9));
    }
    if (c.hair === 'long' && !bare) p('path', { d: 'M30 200 L36 150 Q50 176 58 200 Z M170 200 L164 150 Q150 176 142 200 Z', fill: hc });
    p('circle', { cx: 100 - ex + 2, cy: 114, r: 11, fill: sk }); p('circle', { cx: 100 + ex - 2, cy: 114, r: 11, fill: sk });
    if (c.hearing) { p('path', { d: 'M' + (100 + ex + 4) + ' 104 q8 6 2 18', stroke: '#C9CED6', strokeWidth: 6, fill: 'none', strokeLinecap: 'round' }); }
    if (c.face === 'carre') p('rect', { x: 100 - F.rx, y: cy - F.ry, width: F.rx * 2, height: F.ry * 2, rx: 30, fill: sk });
    else if (c.face === 'pointu') p('path', { d: 'M42 102 Q42 44 100 44 Q158 44 158 102 Q158 140 128 162 Q110 174 100 174 Q90 174 72 162 Q42 140 42 102 Z', fill: sk });
    else p('ellipse', { cx: 100, cy: cy, rx: F.rx, ry: F.ry, fill: sk });
    // cheeks and marks
    if (c.blush) { p('ellipse', { cx: 72, cy: 134, rx: 9, ry: 5, fill: '#E8857A', opacity: .4 }); p('ellipse', { cx: 128, cy: 134, rx: 9, ry: 5, fill: '#E8857A', opacity: .4 }); }
    if (c.hachures) [65, 121].forEach(function (b) { p('path', sx({ d: 'M' + b + ' 139 L' + (b + 4) + ' 131 M' + (b + 5) + ' 139 L' + (b + 9) + ' 131 M' + (b + 10) + ' 139 L' + (b + 14) + ' 131', stroke: '#E26A5E', strokeWidth: 2, opacity: .9 })); });
    if (c.freckles) [[80, 126], [86, 131], [74, 131], [120, 126], [114, 131], [126, 131]].forEach(function (a) { p('circle', { cx: a[0], cy: a[1], r: 1.7, fill: '#7A4A2C', opacity: .6 }); });
    if (c.scar) { p('path', sx({ d: 'M62 116 L80 132', stroke: '#A8463F', strokeWidth: 2.4, opacity: .75 })); p('path', sx({ d: 'M65 127 L70 119 M72 132 L77 124', stroke: '#A8463F', strokeWidth: 1.6, opacity: .75 })); }
    if (c.mole) p('circle', { cx: 124, cy: 143, r: 1.9, fill: '#4A2E1E' });
    if (c.beard) p('path', { d: 'M44 116 Q44 184 100 186 Q156 184 156 116 Q152 154 124 160 Q100 146 76 160 Q48 154 44 116 Z', fill: hs });
    if (c.bandage) { p('rect', { x: 116, y: 123, width: 22, height: 10, rx: 3.5, fill: '#F0CFA0', stroke: '#C49A6C', strokeWidth: 1.2, transform: 'rotate(-18 127 128)' }); p('rect', { x: 123, y: 125, width: 8, height: 6, rx: 1, fill: '#E3B886', transform: 'rotate(-18 127 128)' }); }
    // the mouth
    var m = c.mouth;
    if (m === 'sourire') p('path', sx({ d: 'M86 147 Q100 159 114 147', stroke: lip, strokeWidth: 3.5 }));
    if (m === 'croc') { p('path', { d: 'M103.5 152.5 L109 150.5 L106.8 157.5 Z', fill: '#fff', stroke: lip, strokeWidth: 1, strokeLinejoin: 'round' }); p('path', sx({ d: 'M86 147 Q100 159 114 147', stroke: lip, strokeWidth: 3.5 })); }
    if (m === 'grand' || m === 'langue') { p('path', { d: 'M84 145 Q100 170 116 145 Z', fill: lip }); p('path', { d: 'M88 146 L112 146 L110 151 L90 151 Z', fill: '#fff' }); if (c.braces) p('path', sx({ d: 'M89 148.5 L111 148.5', stroke: '#9AA3AE', strokeWidth: 1.6 })); }
    if (m === 'langue') p('ellipse', { cx: 104, cy: 159, rx: 7, ry: 6, fill: '#E8857A' });
    if (m === 'neutre') p('path', sx({ d: 'M90 151 L110 151', stroke: lip, strokeWidth: 3.5 }));
    if (m === 'surprise') p('ellipse', { cx: 100, cy: 152, rx: 6, ry: 8, fill: lip });
    if (m === 'chat') p('path', sx({ d: 'M88 147 Q94 155 100 147 Q106 155 112 147', stroke: lip, strokeWidth: 3 }));
    if (m === 'coin') p('path', sx({ d: 'M88 150 Q104 154 114 143', stroke: lip, strokeWidth: 3.5 }));
    if (m === 'moue') p('path', sx({ d: 'M93 154 Q100 148 107 154', stroke: lip, strokeWidth: 3.5 }));
    if (m === 'dents') { p('rect', { x: 86, y: 144, width: 28, height: 12, rx: 4, fill: '#fff', stroke: lip, strokeWidth: 2.5 }); p('path', sx({ d: 'M86 150 L114 150 M93 144 L93 156 M100 144 L100 156 M107 144 L107 156', stroke: lip, strokeWidth: 1.2 })); }
    if (c.braces && m === 'sourire') { p('path', { d: 'M90 150 Q100 156 110 150 L109 152.5 Q100 158 91 152.5 Z', fill: '#fff' }); p('path', sx({ d: 'M91 151.6 Q100 157 109 151.6', stroke: '#9AA3AE', strokeWidth: 1.2 })); }
    // the nose
    if (c.nose === 'petit') p('circle', { cx: 100, cy: 128, r: 3.2, fill: skd });
    if (c.nose === 'arrondi') p('path', sx({ d: 'M94 128 Q100 135 106 128', stroke: skd, strokeWidth: 3 }));
    if (c.nose === 'long') p('path', sx({ d: 'M101 112 L95 130 L103 131', stroke: skd, strokeWidth: 3 }));
    if (c.nose === 'triangle') p('path', { d: 'M100 116 L92 132 L108 132 Z', fill: skd });
    if (c.nose === 'trait') p('path', sx({ d: 'M102 121 L98 130 L102 130', stroke: skd, strokeWidth: 2.4 }));
    if (c.nose === 'retrousse') { p('path', sx({ d: 'M95 127 Q100 133 105 127', stroke: skd, strokeWidth: 2.6 })); p('circle', { cx: 100, cy: 124, r: 2, fill: skd, opacity: .6 }); }
    // the eyes and brows; « Clin d'œil » opens the left eye wide and closes the right one
    [78, 122].forEach(function (x) {
      var o = x < 100 ? -1 : 1, iri = x < 100 ? ir : ir2;
      var e = c.eyes === 'clin' ? (x > 100 ? 'wink' : 'grands') : c.eyes;
      if (e === 'ronds') p('circle', { cx: x, cy: 106, r: 6, fill: ink });
      if (e === 'amande') { p('ellipse', { cx: x, cy: 106, rx: 10, ry: 6, fill: '#fff' }); p('circle', { cx: x, cy: 106, r: 5, fill: iri }); p('circle', { cx: x, cy: 106, r: 2.4, fill: ink }); }
      if (e === 'grands') { p('circle', { cx: x, cy: 106, r: 10, fill: '#fff' }); p('circle', { cx: x, cy: 107, r: 6.5, fill: iri }); p('circle', { cx: x, cy: 107, r: 3, fill: ink }); p('circle', { cx: x + 2.5, cy: 104, r: 2, fill: '#fff' }); }
      if (e === 'souriants') p('path', sx({ d: 'M' + (x - 8) + ' 108 Q' + x + ' 99 ' + (x + 8) + ' 108', stroke: ink, strokeWidth: 3.5 }));
      if (e === 'manga') {
        p('ellipse', { cx: x, cy: 104, rx: 11, ry: 13, fill: '#fff' }); p('ellipse', { cx: x, cy: 106, rx: 8, ry: 10.5, fill: iri }); p('ellipse', { cx: x, cy: 107, rx: 4, ry: 6, fill: ink });
        p('ellipse', { cx: x, cy: 111, rx: 6, ry: 3.5, fill: '#fff', opacity: .2 }); p('circle', { cx: x + 3.5, cy: 100.5, r: 3.2, fill: '#fff' }); p('circle', { cx: x - 3.5, cy: 111, r: 1.6, fill: '#fff' });
        p('path', sx({ d: 'M' + (x - 12) + ' 99 Q' + x + ' 87 ' + (x + 12) + ' 99', stroke: ink, strokeWidth: 4 })); p('path', sx({ d: 'M' + (x + o * 12) + ' 99 l' + (o * 4) + ' -3', stroke: ink, strokeWidth: 3 }));
      }
      if (e === 'percants') {
        var ox = x + o * 13, ix = x - o * 12;
        p('path', { d: 'M' + ox + ' 100 Q' + (x + o * 2) + ' 113 ' + ix + ' 107 Q' + x + ' 98 ' + ox + ' 100 Z', fill: '#fff' });
        p('circle', { cx: x + o, cy: 105, r: 4.4, fill: iri }); p('circle', { cx: x + o, cy: 105, r: 2.1, fill: ink });
        p('path', sx({ d: 'M' + (ox + o * 2) + ' 98 Q' + x + ' 96 ' + ix + ' 107', stroke: ink, strokeWidth: 4 }));
      }
      if (e === 'endormis') {
        p('path', { d: 'M' + (x - 10) + ' 106 A10 7 0 0 0 ' + (x + 10) + ' 106 Z', fill: '#fff' }); p('path', { d: 'M' + (x - 5.5) + ' 106 A5.5 5.5 0 0 0 ' + (x + 5.5) + ' 106 Z', fill: iri });
        p('path', { d: 'M' + (x - 2.6) + ' 106 A2.6 2.6 0 0 0 ' + (x + 2.6) + ' 106 Z', fill: ink });
        p('path', sx({ d: 'M' + (x - 11) + ' 106 Q' + x + ' 102 ' + (x + 11) + ' 106', stroke: ink, strokeWidth: 3.5 })); p('path', sx({ d: 'M' + (x - 9) + ' 100 Q' + x + ' 97 ' + (x + 9) + ' 100', stroke: skd, strokeWidth: 2 }));
      }
      if (e === 'etoiles') { p('circle', { cx: x, cy: 106, r: 10, fill: '#fff' }); p('circle', { cx: x, cy: 107, r: 7, fill: iri }); p('path', { d: star5(x, 107.5, 4.8, 2), fill: '#fff' }); p('circle', { cx: x + 4.5, cy: 102, r: 1.5, fill: '#fff' }); }
      if (e === 'wink') p('path', sx({ d: 'M' + (x + 8) + ' 100 L' + (x - 6) + ' 106 L' + (x + 8) + ' 112', stroke: ink, strokeWidth: 3.5 }));
      if (c.start === 'b' && ['ronds', 'amande', 'grands', 'etoiles'].indexOf(e) >= 0) {
        var ry = e === 'grands' || e === 'etoiles' ? 10 : 6, xx = x + o * (e === 'amande' ? 9 : e === 'ronds' ? 5 : 8);
        p('path', sx({ d: 'M' + xx + ' ' + (106 - ry + 2) + ' l' + (o * 5) + ' -4', stroke: ink, strokeWidth: 2.5 }));
        p('path', sx({ d: 'M' + (xx - o * 3) + ' ' + (106 - ry) + ' l' + (o * 3) + ' -5', stroke: ink, strokeWidth: 2.5 }));
      }
      var L = x < 100, b = c.brows;
      if (b === 'droits') p('path', sx({ d: 'M' + (x - 10) + ' 90 L' + (x + 10) + ' 90', stroke: hs, strokeWidth: 4.5 }));
      if (b === 'arques' || b === 'epais' || b === 'fins') p('path', sx({ d: L ? 'M' + (x - 11) + ' 93 Q' + x + ' 83 ' + (x + 11) + ' 90' : 'M' + (x - 11) + ' 90 Q' + x + ' 83 ' + (x + 11) + ' 93', stroke: hs, strokeWidth: b === 'epais' ? 7 : b === 'fins' ? 2 : 4 }));
      if (b === 'fronces') p('path', sx({ d: L ? 'M' + (x - 11) + ' 85 L' + (x + 10) + ' 93' : 'M' + (x - 10) + ' 93 L' + (x + 11) + ' 85', stroke: hs, strokeWidth: 5 }));
      if (b === 'inquiets') p('path', sx({ d: L ? 'M' + (x - 11) + ' 91 Q' + x + ' 90 ' + (x + 10) + ' 83' : 'M' + (x - 10) + ' 83 Q' + x + ' 90 ' + (x + 11) + ' 91', stroke: hs, strokeWidth: 4.5 }));
      if (b === 'points') p('ellipse', { cx: x - o * 3, cy: 86, rx: 4.5, ry: 3.2, fill: hs });
    });
    if (c.ombre) { p('path', { d: 'M42 82 Q100 74 158 82 L158 118 Q100 112 42 118 Z', fill: '#1B1236', opacity: .74 }); [78, 122].forEach(function (x) { p('circle', { cx: x, cy: 104, r: 2.6, fill: '#FFFFFF' }); }); }
    // the hair in front of the face
    if (CAP.indexOf(c.hair) >= 0) p('path', { d: COURT, fill: hc });
    if (c.hair === 'chignon') p('circle', { cx: 100, cy: 30, r: 18, fill: hc });
    if (c.hair === 'milong' || c.hair === 'long') p('path', { d: 'M40 128 Q34 36 100 34 Q166 36 160 128 Q154 84 132 68 Q108 82 70 70 Q48 86 40 128 Z', fill: hc });
    if (c.hair === 'boucle') { p('path', { d: COURT, fill: hc }); for (var a = 180; a <= 360; a += 22.5) { var r = a * Math.PI / 180; p('circle', { cx: 100 + 56 * Math.cos(r), cy: 98 + 56 * Math.sin(r), r: 15, fill: hc }); } }
    if (c.hair === 'rase' || c.hair === 'crete' || c.hair === 'undercut' || c.hair === 'banane') p('path', { d: RASE, fill: hs, opacity: .45 });
    if (c.hair === 'crete') p('path', { d: 'M90 72 Q86 30 100 16 Q114 30 110 72 Z', fill: hc });
    if (FRONT[c.hair]) p('path', { d: FRONT[c.hair], fill: hc });
    if (c.hair === 'odango') { [58, 142].forEach(function (bx) { p('circle', { cx: bx, cy: 46, r: 19, fill: hc }); p('path', sx({ d: 'M' + (bx - 13) + ' 50 Q' + bx + ' 40 ' + (bx + 13) + ' 50', stroke: '#000', strokeWidth: 2, opacity: .2 })); }); }
    if (c.hair === 'locks') [[64, 60, 86], [80, 56, 84], [120, 56, 84], [136, 60, 86]].forEach(function (l) { p('rect', { x: l[0] - 6, y: l[1], width: 12, height: l[2] - l[1], rx: 6, fill: hc }); });
    if (c.hair === 'couettes') { p('circle', { cx: 52, cy: 62, r: 5.5, fill: '#E8457A' }); p('circle', { cx: 148, cy: 62, r: 5.5, fill: '#E8457A' }); }
    if (c.hair === 'queue') p('circle', { cx: 120, cy: 46, r: 5.5, fill: '#E8457A' });
    if (c.ahoge && c.hair !== 'rase') p('path', sx({ d: 'M101 40 Q94 16 116 10', stroke: hc, strokeWidth: 5 }));
    if (c.reflet && ['rase', 'crete', 'undercut'].indexOf(c.hair) < 0) p('path', sx({ d: 'M60 58 Q100 42 140 58', stroke: '#FFFFFF', strokeWidth: 5, opacity: .42, strokeDasharray: '12 7' }));
    if (c.hairAcc === 'barrette') { p('rect', { x: 128, y: 60, width: 22, height: 6, rx: 3, fill: '#E0A93B', transform: 'rotate(-28 139 63)' }); p('rect', { x: 126, y: 69, width: 22, height: 6, rx: 3, fill: '#25BFC4', transform: 'rotate(-28 137 72)' }); }
    if (c.hairAcc === 'ruban') { p('path', { d: 'M142 50 L125 39 L127 61 Z M142 50 L159 39 L157 61 Z', fill: '#E8457A' }); p('circle', { cx: 142, cy: 50, r: 4.5, fill: '#C22F62' }); }
    // what hangs from the ears; a config of version 2 still says « earrings »
    var ears = c.ears !== 'aucune' ? c.ears : (c.earrings ? 'clous' : 'aucune');
    [100 - ex + 2, 100 + ex - 2].forEach(function (x) {
      if (ears === 'clous') p('circle', { cx: x, cy: 128, r: 3.5, fill: '#E0A93B' });
      if (ears === 'anneaux') p('circle', { cx: x, cy: 132, r: 5.5, fill: 'none', stroke: '#E0A93B', strokeWidth: 2.2 });
      if (ears === 'pendants') { p('path', sx({ d: 'M' + x + ' 126 L' + x + ' 137', stroke: '#E0A93B', strokeWidth: 1.6 })); p('circle', { cx: x, cy: 140, r: 3.6, fill: '#25BFC4' }); }
    });
    // what is worn on the face; « Lunettes qui brillent » needs glasses, and brings round ones
    if (c.patch) { p('ellipse', { cx: 122, cy: 106, rx: 13, ry: 11.5, fill: '#1E1E24' }); p('path', sx({ d: 'M111 99 L60 84 M134 101 L157 96', stroke: '#1E1E24', strokeWidth: 2.5 })); }
    var gl = c.glasses === 'aucune' && c.glare ? 'rondes' : c.glasses;
    if (gl !== 'aucune') {
      var thin = gl === 'fines', g = { stroke: '#2B2B2B', strokeWidth: thin ? 1.6 : 3, fill: 'none' };
      [78, 122].forEach(function (x) {
        if (gl === 'rondes') p('circle', Object.assign({ cx: x, cy: 106, r: 15 }, g));
        else if (thin) p('circle', Object.assign({ cx: x, cy: 106, r: 13.5 }, g));
        else if (gl === 'demi') { p('path', sx({ d: 'M' + (x - 17) + ' 97 L' + (x + 17) + ' 97', stroke: '#2B2B2B', strokeWidth: 4.5 })); p('path', sx({ d: 'M' + (x - 17) + ' 97 Q' + (x - 16) + ' 117 ' + x + ' 117 Q' + (x + 16) + ' 117 ' + (x + 17) + ' 97', stroke: '#2B2B2B', strokeWidth: 1.4 })); }
        else p('rect', Object.assign({ x: x - 17, y: 95, width: 34, height: 23, rx: gl === 'solaires' ? 9 : 4 }, g, { fill: gl === 'solaires' ? '#1E1E24' : 'none' }));
      });
      p('path', Object.assign({ d: 'M93 104 Q100 99 107 104' }, g));
      p('path', Object.assign({ d: 'M' + (100 - ex + 4) + ' 101 L61 101' }, g)); p('path', Object.assign({ d: 'M139 101 L' + (100 + ex - 4) + ' 101' }, g));
    }
    if (c.mask === 'blanc' || c.mask === 'noir') {
      var mf = c.mask === 'noir' ? '#26262C' : '#F4F6F8', ms = c.mask === 'noir' ? '#4A4A55' : '#C9CED6';
      p('path', sx({ d: 'M65 128 L' + (100 - ex + 4) + ' 114 M135 128 L' + (100 + ex - 4) + ' 114', stroke: ms, strokeWidth: 1.8 }));
      p('path', { d: 'M64 126 Q100 116 136 126 L134 152 Q100 170 66 152 Z', fill: mf, stroke: ms, strokeWidth: 1.5 });
      p('path', sx({ d: 'M70 134 Q100 128 130 134 M70 144 Q100 140 130 144', stroke: ms, strokeWidth: 1.4 }));
    }
    // the manga signs
    if (c.goutte) { p('path', { d: 'M152 64 Q143 80 152 87 Q161 80 152 64 Z', fill: '#A9DDF7', stroke: '#3A8FC2', strokeWidth: 1.6 }); p('path', sx({ d: 'M149 77 Q148 81 150 83', stroke: '#FFFFFF', strokeWidth: 1.6 })); }
    if (c.veine) p('path', sx({ d: 'M56 56 Q62 60 60 52 M72 56 Q66 60 68 52 M56 68 Q62 64 60 72 M72 68 Q66 64 68 72', stroke: '#E0322B', strokeWidth: 3 }));
    if (c.etincelles) [[34, 44, 9], [166, 40, 7], [172, 148, 6], [28, 138, 6], [150, 20, 4]].forEach(function (s) { p('path', { d: star4(s[0], s[1], s[2]), fill: '#FFD23F', stroke: '#FFFFFF', strokeWidth: 1 }); });
    if (c.glare) [78, 122].forEach(function (x) { p('ellipse', { cx: x, cy: 106, rx: 12.5, ry: 12.5, fill: '#FFFFFF', opacity: .9 }); p('path', sx({ d: 'M' + (x - 8) + ' 112 L' + (x + 4) + ' 98 M' + (x - 2) + ' 114 L' + (x + 8) + ' 103', stroke: '#BFE1FF', strokeWidth: 2.2 })); });
    if (c.larmes) [72, 128].forEach(function (x) { p('path', sx({ d: 'M' + x + ' 113 Q' + (x + (x < 100 ? -4 : 4)) + ' 132 ' + x + ' 154', stroke: '#7EC8F2', strokeWidth: 7, opacity: .85 })); });
    if (c.bulle) { p('circle', { cx: 112, cy: 133, r: 8.5, fill: '#CFEFFF', opacity: .75, stroke: '#7EC8F2', strokeWidth: 1.4 }); p('circle', { cx: 109, cy: 130, r: 2, fill: '#FFFFFF' }); }
    if (c.deprime) for (var dx = 64; dx <= 136; dx += 9) p('path', sx({ d: 'M' + dx + ' 44 L' + dx + ' 80', stroke: '#3A3A5A', strokeWidth: 2, opacity: .5 }));
    if (c.electricite) { var bolts = 'M26 92 L40 84 L33 78 L48 70 M174 100 L160 92 L168 86 L153 78 M36 152 L50 143 L43 137 L58 128 M164 152 L150 143 L157 137 L142 128'; p('path', sx({ d: bolts, stroke: '#7FE3FF', strokeWidth: 3.6 })); p('path', sx({ d: bolts, stroke: '#FFFFFF', strokeWidth: 1.2 })); }
    if (c.confettis) [[30, 50, 20, '#E8457A'], [168, 46, -30, '#25BFC4'], [178, 122, 45, '#F7C520'], [22, 120, -15, '#7A4CC6'], [52, 22, 60, '#3E9E52'], [146, 20, -50, '#EE7D2B'], [160, 176, 30, '#E8457A'], [40, 176, -40, '#F7C520']].forEach(function (q) { p('rect', { x: q[0] - 4, y: q[1] - 2, width: 8, height: 4, rx: 1, fill: q[3], transform: 'rotate(' + q[2] + ' ' + q[0] + ' ' + q[1] + ')' }); });
    // above the head, top right: one sign at a time
    var ab = c.above, ff = 'Arial Black, Arial, sans-serif';
    if (ab === 'interro' || ab === 'exclam') raw('<text x="158" y="64" text-anchor="middle" font-size="40" font-weight="900" font-family="' + ff + '" fill="#E0322B" stroke="#FFFFFF" stroke-width="3" paint-order="stroke">' + (ab === 'interro' ? '?' : '!') + '</text>');
    if (ab === 'ampoule') { p('path', sx({ d: 'M156 26 L156 31 M140 34 L144 37 M172 34 L168 37 M136 48 L141 48 M176 48 L171 48', stroke: '#E0A93B', strokeWidth: 2.5 })); p('circle', { cx: 156, cy: 48, r: 10, fill: '#FFE15A', stroke: '#C99A00', strokeWidth: 2 }); p('rect', { x: 151, y: 57, width: 10, height: 6, rx: 2, fill: '#9AA3AE' }); }
    if (ab === 'zzz') [[140, 66, 12], [150, 55, 15], [160, 44, 18]].forEach(function (q) { raw('<text x="' + q[0] + '" y="' + q[1] + '" text-anchor="middle" font-size="' + q[2] + '" font-weight="900" font-family="' + ff + '" fill="#4E6BD1" stroke="#FFFFFF" stroke-width="2" paint-order="stroke">Z</text>'); });
    if (ab === 'musique') raw('<text x="146" y="62" font-size="24" fill="#6B4FA0" stroke="#FFFFFF" stroke-width="2" paint-order="stroke">♪</text><text x="156" y="44" font-size="20" fill="#6B4FA0" stroke="#FFFFFF" stroke-width="2" paint-order="stroke">♫</text>');
    if (ab === 'coeurs') { p('path', { d: heart(148, 56, 7), fill: '#E8457A', stroke: '#FFFFFF', strokeWidth: 1.5 }); p('path', { d: heart(162, 38, 5), fill: '#E8457A', stroke: '#FFFFFF', strokeWidth: 1.5 }); }
    if (ab === 'halo') { p('ellipse', { cx: 100, cy: 22, rx: 34, ry: 8, fill: 'none', stroke: '#FFD23F', strokeWidth: 5 }); p('ellipse', { cx: 100, cy: 22, rx: 34, ry: 8, fill: 'none', stroke: '#FFFFFF', strokeWidth: 1.4, opacity: .8 }); }
    if (ab === 'cornes') p('path', { d: 'M60 52 Q54 30 66 16 Q68 34 78 46 Z M140 52 Q146 30 134 16 Q132 34 122 46 Z', fill: '#C22F2F', stroke: '#7A1414', strokeWidth: 1.4 });
    if (ab === 'etourdi') { p('ellipse', { cx: 100, cy: 34, rx: 46, ry: 10, fill: 'none', stroke: '#9AA3AE', strokeWidth: 1.6, strokeDasharray: '4 4' }); [[58, 36], [138, 28], [110, 44]].forEach(function (q) { p('path', { d: star5(q[0], q[1], 6, 2.5), fill: '#FFD23F', stroke: '#C99A00', strokeWidth: .8 }); }); }
    if (ab === 'fumee') { raw(cloud(60, 28, 9, '#FFFFFF', '#B8BCC6')); raw(cloud(140, 28, 9, '#FFFFFF', '#B8BCC6')); }
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
    // Black-out: the control room's headset, its band over the head, the ear cups and the boom mic
    // (design/validated/serious-games-blackout.md §5).
    blackout: { label: 'Casque de la régie', svg: function () {
      return el('path', sx({ d: 'M46 112 Q40 34 100 32 Q160 34 154 112', stroke: '#1B1236', strokeWidth: 7 })) +
        el('path', sx({ d: 'M52 90 Q56 44 100 40 Q144 44 148 90', stroke: '#FF2E88', strokeWidth: 2, opacity: .9 })) +
        el('rect', { x: 34, y: 98, width: 16, height: 30, rx: 7, fill: '#1B1236', stroke: '#FF2E88', strokeWidth: 2 }) +
        el('rect', { x: 150, y: 98, width: 16, height: 30, rx: 7, fill: '#1B1236', stroke: '#FF2E88', strokeWidth: 2 }) +
        el('path', sx({ d: 'M42 126 Q48 150 74 150', stroke: '#1B1236', strokeWidth: 4 })) +
        el('circle', { cx: 76, cy: 150, r: 4.5, fill: '#00E5FF' });
    } },
    // Rétro Rush: the shop's badge, translucent purple plastic clipped on the chest
    // (design/validated/serious-games-retro-rush.md §4) - on the body, nothing on the head.
    'retro-rush': { label: 'Badge « Continue ? »', svg: function () {
      return el('rect', { x: 110, y: 196, width: 34, height: 18, rx: 9, fill: '#6A3EA0', opacity: .78, stroke: '#1E1433', strokeWidth: 1.6 }) +
        el('path', sx({ d: 'M115 201 Q126 198 138 200', stroke: '#FFFFFF', strokeWidth: 1.6, opacity: .6 })) +
        el('circle', { cx: 137, cy: 207, r: 3, fill: '#FF8A3D' }) + el('rect', { x: 116, y: 205, width: 14, height: 3, rx: 1.5, fill: '#EDE7F7' });
    } },
    // Radio-Comète: the studio's ON AIR badge, a red lamp on the chest
    // (design/validated/serious-games-radio-comete.md §4) - on the body, nothing on the head.
    'radio-comete': { label: 'Badge « ON AIR »', svg: function () {
      return el('rect', { x: 108, y: 196, width: 38, height: 18, rx: 4, fill: '#5A1D16', stroke: '#D8503F', strokeWidth: 1.8 }) +
        el('rect', { x: 111, y: 199, width: 32, height: 12, rx: 2, fill: '#D8503F', opacity: .35 }) +
        el('path', sx({ d: 'M115 205 H139', stroke: '#FF8A78', strokeWidth: 2.4 }));
    } },
    // Fusion: a small architect's set square pinned on the chest, cardboard white with an orange edge
    // (design/validated/serious-games-fusion.md §4) - on the body, nothing on the head.
    fusion: { label: 'Équerre de chantier', svg: function () {
      return el('path', { d: 'M112 194 L112 216 L138 216 Z', fill: '#F1F1EE', stroke: '#2E3236', strokeWidth: 1.6 }) +
        el('path', { d: 'M117 204 L117 211 L125 211 Z', fill: '#E07A3F' }) +
        el('path', sx({ d: 'M112 216 H138', stroke: '#E07A3F', strokeWidth: 2.4 }));
    } },
    // SQL pas à pas: a brooch of three stars joined like a constellation, pinned on the chest
    // (design/validated/serious-games-sql-pas-a-pas.md §4) - on the body, nothing on the head.
    'sql-pas-a-pas': { label: 'Broche constellation', svg: function () {
      return el('path', sx({ d: 'M110 206 L122 198 L134 208', stroke: '#9DB4FF', strokeWidth: 1.4 })) +
        el('circle', { cx: 110, cy: 206, r: 3.2, fill: '#FFE27A', stroke: '#0B1030', strokeWidth: 1 }) +
        el('circle', { cx: 122, cy: 198, r: 2.4, fill: '#FFE27A', stroke: '#0B1030', strokeWidth: 1 }) +
        el('circle', { cx: 134, cy: 208, r: 3.6, fill: '#FFB86B', stroke: '#0B1030', strokeWidth: 1 });
    } },
    // Service de nuit: the night porter's badge, a pink neon crescent on a dark plate
    // (design/validated/serious-games-service-de-nuit.md §4) - on the body, nothing on the head.
    'service-de-nuit': { label: 'Badge de veilleur', svg: function () {
      return el('rect', { x: 108, y: 195, width: 38, height: 20, rx: 4, fill: '#14111F', stroke: '#3FE0D0', strokeWidth: 1.6 }) +
        el('path', { d: 'M124 199 A7 7 0 1 0 131 210 A5.5 5.5 0 1 1 124 199 Z', fill: '#FF4F7B' }) +
        el('circle', { cx: 137, cy: 201, r: 1.4, fill: '#FFB547' });
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
    // A square face keeps its background's patterns (speed lines, a burst) inside the square.
    if (!round && !o.bare) clip = '<defs><clipPath id="' + id + '"><rect x="0" y="0" width="200" height="200"/></clipPath></defs><g clip-path="url(#' + id + ')">' + inner + '</g>';
    return '<svg viewBox="' + vb + '" width="' + size + '" height="' + size + '" role="img" aria-label="Avatar" style="display:block;overflow:visible">' + clip + '</svg>';
  };
  A.html = function (c, size, o) {
    o = Object.assign({ size: size }, o || {});
    return '<span class="sg-av" style="display:inline-block;width:' + size + 'px;height:' + size + 'px;border-radius:50%;overflow:hidden;flex:none;vertical-align:middle">' + A.svg(c, o) + '</span>';
  };
  // How often « Aléatoire » draws a box: a quarter by default, the signs and the marks rarely.
  var RARE = { braces: .08, hearing: .08, scar: .06, bandage: .06, mole: .1, ahoge: .12, reflet: .2, hetero: .04, patch: .03,
    hachures: .05, goutte: .04, veine: .04, larmes: .03, ombre: .03, deprime: .03, glare: .04, bulle: .03, etincelles: .05, electricite: .03, confettis: .04 };
  A.random = function (r) {
    r = r || Math.random; var c = {};
    var pick = function (list) { return list[Math.floor(r() * list.length)]; };
    var some = function (key, chance) { var opts = []; A.CATS.forEach(function (cat) { cat.groups.forEach(function (g) { if (g.key === key) opts = g.opts.slice(1); }); }); return r() < chance ? pick(opts)[0] : null; };
    A.CATS.forEach(function (cat) { cat.groups.forEach(function (g) {
      if (g.colors) c[g.key] = Math.floor(r() * g.colors.length);
      else if (g.toggle) g.opts.forEach(function (o) { c[o[0]] = r() < (RARE[o[0]] || .25); });
      else c[g.key] = g.opts[Math.floor(r() * g.opts.length)][0];
    }); });
    // most people wear no glasses, few wear sunglasses indoors
    var gr = r(); c.glasses = gr < .62 ? 'aucune' : gr < .74 ? 'rondes' : gr < .84 ? 'carrees' : gr < .9 ? 'fines' : gr < .96 ? 'demi' : 'solaires';
    // human skins only, light backgrounds, a plain top now and then; the rest is rare
    c.skin = Math.floor(r() * 8);
    c.bg = pick([0, 1, 2, 3, 4, 5, 6, 8, 9, 11, 12]);
    c.hairC2 = r() < .12 ? Math.floor(r() * A.HAIR.length) : -1;
    c.hairAcc = some('hairAcc', .15) || 'aucun';
    c.mask = some('mask', .05) || 'aucun';
    c.ears = some('ears', .2) || 'aucune';
    c.bgPat = some('bgPat', .4) || 'uni';
    c.aura = some('aura', .05) || 'aucune';
    c.above = some('above', .1) || 'aucun';
    c.pin = null;
    return Object.assign({}, A.DEF, c);
  };
  A.VERSION = 3;
  /* A stored config read back: version 3 keys, unknown values replaced by the default, the old
     « sex » of the handoff's first drawings turned into a starting point, and version 2's
     « Boucles d'oreille » box into the studs of the ears' choice. */
  A.normalize = function (c) {
    c = Object.assign({}, c || {});
    if (c.sex && !c.start) { c.start = c.sex === 'f' ? 'b' : 'a'; }
    delete c.sex;
    if (c.earrings === true && !c.ears) { c.ears = 'clous'; }
    delete c.earrings;
    var out = Object.assign({}, A.DEF);
    A.CATS.forEach(function (cat) { cat.groups.forEach(function (g) {
      if (g.colors) { var n = parseInt(c[g.key], 10); if (n >= (g.none ? -1 : 0) && n < g.colors.length) out[g.key] = n; }
      else if (g.toggle) g.opts.forEach(function (o) { if (typeof c[o[0]] === 'boolean') out[o[0]] = c[o[0]]; });
      else if (g.opts.some(function (o) { return o[0] === c[g.key]; })) out[g.key] = c[g.key];
    }); });
    out.pin = PINS.some(function (p) { return p.id === c.pin; }) ? c.pin : null;
    return out;
  };

  root.SGAvatar = A;
})(typeof globalThis !== 'undefined' ? globalThis : window);
