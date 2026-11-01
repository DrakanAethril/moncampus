/* Radio-Comète — the characters, drawn in SVG (no graphist): a bust in flat colours with a thick
   outline, a drop shadow in a flat, and a face that changes with the expression (the body never
   does). Colette helps, Odile directs, Yanis hosts, Samir runs the web, Marc is gone (a silhouette). */
(function (root) {
  'use strict';
  var INK = '#0E1114';
  var C = {
    colette: { name: 'Colette', role: 'Aidante · ancienne technicienne', skin: '#E7C7A6', hair: '#CDD2D6', shirt: '#5FB37C', accent: '#E8A33D' },
    odile: { name: 'Odile', role: 'Directrice des programmes', skin: '#D8A585', hair: '#2A2220', shirt: '#2E4A7A', accent: '#E9E1CF' },
    yanis: { name: 'Yanis', role: 'Animateur', skin: '#B98062', hair: '#1B1410', shirt: '#E8A33D', accent: '#14181C' },
    samir: { name: 'Samir', role: 'Webmaster bénévole', skin: '#C99875', hair: '#241A14', shirt: '#4A5560', accent: '#5FB37C' },
    marc: { name: 'Marc', role: 'Administrateur (parti)', skin: '#D2B49B', hair: '#6B5B4A', shirt: '#3B444C', accent: '#9AA3AA' }
  };
  function brows(expr, c) {
    var common = 'stroke="' + INK + '" stroke-width="5" stroke-linecap="round" fill="none"';
    if (expr === 'sourcil') return '<path d="M70 82 L90 76" ' + common + '/><path d="M110 78 L132 84" ' + common + '/>';
    if (expr === 'surprise') return '<path d="M70 74 Q80 66 92 72" ' + common + '/><path d="M108 72 Q120 66 132 74" ' + common + '/>';
    if (expr === 'sourire') return '<path d="M70 79 Q80 74 92 78" ' + common + '/><path d="M108 78 Q120 74 132 79" ' + common + '/>';
    return '<path d="M70 79 L92 78" ' + common + '/><path d="M108 78 L132 79" ' + common + '/>';
  }
  function mouth(expr) {
    var st = 'stroke="' + INK + '" stroke-width="5" stroke-linecap="round" fill="none"';
    if (expr === 'sourire') return '<path d="M84 126 Q101 142 118 126" ' + st + '/>';
    if (expr === 'surprise') return '<ellipse cx="101" cy="130" rx="8" ry="10" fill="' + INK + '"/>';
    if (expr === 'sourcil') return '<path d="M88 132 Q101 124 114 132" ' + st + '/>';
    return '<path d="M88 130 L114 130" ' + st + '/>';
  }
  function hair(who, c) {
    switch (who) {
      case 'colette': return '<path d="M52 98 C48 44 150 40 150 98 C150 74 128 56 101 56 C74 56 54 74 52 98Z" fill="' + c.hair + '" stroke="' + INK + '" stroke-width="5"/><circle cx="101" cy="40" r="20" fill="' + c.hair + '" stroke="' + INK + '" stroke-width="5"/>';
      case 'odile': return '<path d="M50 112 C40 40 160 36 152 112 C150 80 130 64 101 64 C72 64 54 80 50 112Z" fill="' + c.hair + '" stroke="' + INK + '" stroke-width="5"/>';
      case 'yanis': return '<path d="M52 92 C44 34 156 30 150 92 C146 62 126 50 101 50 C76 50 56 62 52 92Z" fill="' + c.hair + '" stroke="' + INK + '" stroke-width="5"/><circle cx="62" cy="62" r="14" fill="' + c.hair + '"/><circle cx="140" cy="62" r="14" fill="' + c.hair + '"/><circle cx="101" cy="44" r="16" fill="' + c.hair + '"/>';
      case 'samir': return '<path d="M52 96 C50 48 152 44 150 96 C146 70 126 60 101 60 C76 60 56 70 52 96Z" fill="' + c.hair + '" stroke="' + INK + '" stroke-width="5"/>';
      default: return '<path d="M54 94 C50 50 152 50 148 94 C144 72 126 64 101 64 C76 64 58 72 54 94Z" fill="' + c.hair + '" stroke="' + INK + '" stroke-width="5"/>';
    }
  }
  function extras(who, c, expr) {
    switch (who) {
      case 'colette': return '<g fill="none" stroke="' + INK + '" stroke-width="4"><circle cx="82" cy="100" r="15"/><circle cx="120" cy="100" r="15"/><path d="M97 100 L105 100"/></g><path d="M58 176 C70 214 132 214 144 176" fill="none" stroke="' + c.accent + '" stroke-width="12" stroke-linecap="round"/><path d="M58 176 C70 214 132 214 144 176" fill="none" stroke="' + INK + '" stroke-width="3"/>';
      case 'odile': return '<rect x="132" y="196" width="26" height="18" rx="3" fill="' + c.accent + '" stroke="' + INK + '" stroke-width="3"/><path d="M84 172 L101 204 L118 172" fill="#E9E1CF" stroke="' + INK + '" stroke-width="4"/>';
      case 'yanis': return '<path d="M56 100 C52 30 152 30 146 100" fill="none" stroke="' + INK + '" stroke-width="9"/><rect x="44" y="92" width="18" height="34" rx="9" fill="' + c.accent + '" stroke="' + INK + '" stroke-width="4"/><rect x="140" y="92" width="18" height="34" rx="9" fill="' + c.accent + '" stroke="' + INK + '" stroke-width="4"/><path d="M62 124 C64 156 138 156 140 124 C128 140 74 140 62 124Z" fill="' + c.hair + '" opacity=".85"/>';
      case 'samir': return '<g fill="none" stroke="' + INK + '" stroke-width="4"><rect x="68" y="90" width="28" height="22" rx="5"/><rect x="106" y="90" width="28" height="22" rx="5"/><path d="M96 98 L106 98"/></g><rect x="82" y="192" width="38" height="10" rx="2" fill="' + c.accent + '"/>';
      case 'marc': return '<g opacity=".0"></g>';
    }
    return '';
  }
  function svg(who, expr) {
    var c = C[who] || C.colette;
    expr = expr || 'neutre';
    var body = '<path d="M14 240 C14 190 56 168 101 168 C146 168 188 190 188 240Z" fill="' + c.shirt + '" stroke="' + INK + '" stroke-width="5"/>' +
      '<rect x="86" y="140" width="30" height="34" fill="' + c.skin + '" stroke="' + INK + '" stroke-width="5"/>' +
      '<ellipse cx="101" cy="104" rx="49" ry="58" fill="' + c.skin + '" stroke="' + INK + '" stroke-width="5"/>' +
      hair(who, c) +
      '<circle cx="81" cy="100" r="6" fill="' + INK + '"/><circle cx="121" cy="100" r="6" fill="' + INK + '"/>' +
      brows(expr, c) + mouth(expr) + extras(who, c, expr);
    var ghost = who === 'marc' ? ' style="filter: grayscale(.8) brightness(.55)"' : '';
    return '<svg viewBox="0 0 202 240" role="img" aria-label="' + c.name + '"' + ghost + '><ellipse cx="108" cy="236" rx="86" ry="8" fill="#000" opacity=".35"/>' + body + '</svg>';
  }
  root.RCChars = { svg: svg, name: function (w) { return (C[w] || { name: w }).name; }, role: function (w) { return (C[w] || { role: '' }).role; }, all: C };
})(typeof globalThis !== 'undefined' ? globalThis : window);
