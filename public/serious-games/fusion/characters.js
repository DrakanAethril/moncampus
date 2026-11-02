/* Fusion — the characters, drawn in SVG like the figurines of an architect's model: cut cardboard in
   soft flat colours, a thin ink line, a shadow on the table, and a face that changes with the
   expression (the body never does). Hélène helps; Julien and Maëlle are the partners; Sandrine runs
   Delorme's office; Thibault draws at Kerbrat; Bastien has left (a cardboard silhouette, uncoloured). */
(function (root) {
  'use strict';
  var INK = '#2E3236';
  var C = {
    helene: { name: 'Hélène', role: 'Cheffe de projet, Arpège · ta tutrice', skin: '#E9CDB0', hair: '#B9BDB8', shirt: '#7F9157', accent: '#E07A3F' },
    julien: { name: 'Julien Delorme', role: 'Associé · Atelier Delorme, Limoges', skin: '#E2B996', hair: '#5B4636', shirt: '#3C4A5E', accent: '#F1F1EE' },
    maelle: { name: 'Maëlle Kerbrat', role: 'Associée · Kerbrat Architectes, Brive', skin: '#C9946E', hair: '#2B211C', shirt: '#B4432B', accent: '#D8C29D' },
    sandrine: { name: 'Sandrine Aubry', role: 'Assistante de direction · Atelier Delorme', skin: '#EBC9A8', hair: '#C99A4E', shirt: '#5E8C84', accent: '#F1F1EE' },
    thibault: { name: 'Thibault Lacoste', role: 'Dessinateur · Kerbrat Architectes', skin: '#D8AE8A', hair: '#6E5640', shirt: '#D8C29D', accent: '#2E3236' },
    bastien: { name: 'Bastien Roux', role: 'Ancien dessinateur de Kerbrat, parti chez Varenne', skin: '#DEDDD8', hair: '#BDBCB6', shirt: '#CFCEC8', accent: '#BDBCB6' }
  };
  var LINE = 'stroke="' + INK + '" stroke-width="3.5"';
  function brows(expr) {
    var st = 'stroke="' + INK + '" stroke-width="4" stroke-linecap="round" fill="none"';
    if (expr === 'sourcil') return '<path d="M72 82 L90 77" ' + st + '/><path d="M110 79 L130 84" ' + st + '/>';
    if (expr === 'surprise') return '<path d="M72 74 Q81 67 91 72" ' + st + '/><path d="M110 72 Q120 67 130 74" ' + st + '/>';
    if (expr === 'sourire') return '<path d="M72 80 Q81 75 91 79" ' + st + '/><path d="M110 79 Q120 75 130 80" ' + st + '/>';
    return '<path d="M72 80 L91 79" ' + st + '/><path d="M110 79 L130 80" ' + st + '/>';
  }
  function mouth(expr) {
    var st = 'stroke="' + INK + '" stroke-width="4" stroke-linecap="round" fill="none"';
    if (expr === 'sourire') return '<path d="M86 126 Q101 139 116 126" ' + st + '/>';
    if (expr === 'surprise') return '<ellipse cx="101" cy="130" rx="7" ry="9" fill="' + INK + '"/>';
    if (expr === 'sourcil') return '<path d="M89 132 Q101 125 113 132" ' + st + '/>';
    return '<path d="M89 130 L113 130" ' + st + '/>';
  }
  function hair(who, c) {
    var f = 'fill="' + c.hair + '" ' + LINE;
    switch (who) {
      case 'helene': return '<path d="M54 100 C48 50 154 46 148 100 C146 76 128 60 101 60 C76 60 58 72 54 100Z" ' + f + '/>';
      case 'julien': return '<path d="M54 92 C52 44 150 40 148 92 C140 66 120 58 96 62 C78 64 62 74 54 92Z" ' + f + '/><path d="M60 78 C80 52 126 50 146 74" fill="none" ' + LINE + '/>';
      case 'maelle': return '<path d="M52 112 C42 44 160 40 150 112 C148 78 128 62 101 62 C74 62 56 78 52 112Z" ' + f + '/><circle cx="101" cy="42" r="18" ' + f + '/>';
      case 'sandrine': return '<path d="M50 118 C36 40 166 36 152 118 C150 84 132 64 101 64 C70 64 54 84 50 118Z" ' + f + '/><circle cx="58" cy="76" r="12" ' + f + '/><circle cx="144" cy="76" r="12" ' + f + '/><circle cx="74" cy="52" r="13" ' + f + '/><circle cx="128" cy="52" r="13" ' + f + '/><circle cx="101" cy="46" r="14" ' + f + '/>';
      case 'thibault': return '<path d="M52 90 C52 46 150 44 150 90 L150 76 C150 58 128 44 101 44 C74 44 52 58 52 76Z" fill="' + c.accent + '" ' + LINE + '/><rect x="50" y="78" width="102" height="14" rx="4" fill="' + c.accent + '" ' + LINE + '/>';
      default: return '<path d="M54 94 C50 50 152 50 148 94 C144 72 126 64 101 64 C76 64 58 72 54 94Z" ' + f + '/>';
    }
  }
  function extras(who, c) {
    switch (who) {
      case 'helene': return '<g fill="none" ' + LINE + '><rect x="68" y="90" width="27" height="20" rx="4"/><rect x="107" y="90" width="27" height="20" rx="4"/><path d="M95 99 L107 99"/></g><path d="M60 182 L142 182" stroke="' + c.accent + '" stroke-width="6" stroke-linecap="round"/>';
      case 'julien': return '<path d="M84 170 L101 200 L118 170" fill="#F1F1EE" ' + LINE + '/><path d="M101 182 L101 214" stroke="#B4432B" stroke-width="7"/>';
      case 'maelle': return '<path d="M60 176 C76 200 126 200 142 176 L142 192 C126 212 76 212 60 192Z" fill="' + c.accent + '" ' + LINE + '/>';
      case 'sandrine': return '<circle cx="101" cy="186" r="6" fill="#F1F1EE" ' + LINE + '/><path d="M150 214 L176 188" stroke="' + INK + '" stroke-width="5" stroke-linecap="round"/>';
      case 'thibault': return '<path d="M64 128 C66 166 136 166 138 128 C126 146 76 146 64 128Z" fill="' + c.hair + '" ' + LINE + '/><path d="M150 210 L176 168" stroke="#E07A3F" stroke-width="5" stroke-linecap="round"/>';
    }
    return '';
  }
  function svg(who, expr) {
    var c = C[who] || C.helene;
    expr = expr || 'neutre';
    var face = who === 'bastien' ? '' : '<circle cx="81" cy="100" r="5" fill="' + INK + '"/><circle cx="121" cy="100" r="5" fill="' + INK + '"/>' + brows(expr) + mouth(expr);
    var body = '<path d="M16 240 C16 192 58 170 101 170 C144 170 186 192 186 240Z" fill="' + c.shirt + '" ' + LINE + '/>' +
      '<rect x="87" y="140" width="28" height="34" fill="' + c.skin + '" ' + LINE + '/>' +
      '<ellipse cx="101" cy="104" rx="48" ry="57" fill="' + c.skin + '" ' + LINE + '/>' +
      hair(who, c) + face + extras(who, c);
    return '<svg viewBox="0 0 202 248" role="img" aria-label="' + c.name + '"><ellipse cx="112" cy="242" rx="84" ry="7" fill="#2E3236" opacity=".18"/>' + body + '</svg>';
  }
  root.FSChars = { svg: svg, name: function (w) { return (C[w] || { name: w }).name; }, role: function (w) { return (C[w] || { role: '' }).role; }, all: C };
})(typeof globalThis !== 'undefined' ? globalThis : window);
