/* Service de nuit — the characters: Mireille (the manager), Sami (the weekend night porter, who helps),
   Josette (breakfast), Augustin (the night porter who left), drawn flat with a neon rim, four expressions
   each: neutre, sourire, sourcil, surprise. */
(function (root) {
  'use strict';
  var PEOPLE = {
    mireille: { name: 'Mireille', role: 'la gérante', skin: '#E9B99A', hair: '#B9B4C4', top: '#7A2E4A', rim: '#FF4F7B' },
    sami: { name: 'Sami', role: 'veilleur du week-end, BTS SIO 2', skin: '#B9805A', hair: '#2A1E1A', top: '#1F6F6A', rim: '#3FE0D0' },
    josette: { name: 'Josette', role: 'le petit-déjeuner', skin: '#F0C3A2', hair: '#6B4A3A', top: '#F6F1E7', rim: '#FFB547' },
    augustin: { name: 'Augustin', role: 'l\'ancien veilleur', skin: '#E3B08E', hair: '#E8E4DA', top: '#23305A', rim: '#FFB547' },
    narr: { name: '', role: '' }
  };

  function mouth(expr) {
    if (expr === 'sourire') return '<path d="M48 82 Q60 92 72 82" fill="none" stroke="#3A2320" stroke-width="3" stroke-linecap="round"/>';
    if (expr === 'surprise') return '<ellipse cx="60" cy="84" rx="5" ry="6" fill="#3A2320"/>';
    if (expr === 'sourcil') return '<path d="M50 85 L70 83" fill="none" stroke="#3A2320" stroke-width="3" stroke-linecap="round"/>';
    return '<path d="M50 84 L70 84" fill="none" stroke="#3A2320" stroke-width="3" stroke-linecap="round"/>';
  }
  function brows(expr, color) {
    var l = 'M38 54 L52 52', r = 'M68 52 L82 54';
    if (expr === 'surprise') { l = 'M38 48 Q45 44 52 47'; r = 'M68 47 Q75 44 82 48'; }
    if (expr === 'sourcil') { r = 'M68 46 Q75 42 82 46'; }
    if (expr === 'sourire') { l = 'M38 53 Q45 50 52 53'; r = 'M68 53 Q75 50 82 53'; }
    return '<path d="' + l + '" stroke="' + color + '" stroke-width="3.5" fill="none" stroke-linecap="round"/><path d="' + r + '" stroke="' + color + '" stroke-width="3.5" fill="none" stroke-linecap="round"/>';
  }
  function eyes(expr) {
    var ry = expr === 'surprise' ? 4.5 : (expr === 'sourire' ? 2.2 : 3.5);
    return '<ellipse cx="45" cy="64" rx="3.5" ry="' + ry + '" fill="#2A1A18"/><ellipse cx="75" cy="64" rx="3.5" ry="' + ry + '" fill="#2A1A18"/>';
  }

  function svg(who, expr) {
    var p = PEOPLE[who];
    if (!p || who === 'narr') return '';
    expr = expr || 'neutre';
    var s = '<svg viewBox="0 0 120 120" role="img" aria-label="' + p.name + '">';
    s += '<circle cx="60" cy="60" r="58" fill="#1B1729" stroke="' + p.rim + '" stroke-width="2.5"/>';
    s += '<clipPath id="sdn-c-' + who + '"><circle cx="60" cy="60" r="56"/></clipPath><g clip-path="url(#sdn-c-' + who + ')">';
    // shoulders
    s += '<path d="M14 124 Q16 98 42 94 L78 94 Q104 98 106 124 Z" fill="' + p.top + '"/>';
    if (who === 'sami') s += '<path d="M40 94 Q60 112 80 94" fill="none" stroke="#3FE0D0" stroke-width="2" opacity=".7"/>';
    if (who === 'josette') s += '<path d="M44 96 L76 96 L72 124 L48 124 Z" fill="#E5DDD0"/>';
    if (who === 'augustin') s += '<path d="M52 94 L60 108 L68 94" fill="#F6F1E7"/><circle cx="60" cy="114" r="2" fill="#FFB547"/><circle cx="60" cy="121" r="2" fill="#FFB547"/>';
    if (who === 'mireille') s += '<path d="M46 94 L60 110 L74 94" fill="#F6F1E7"/>';
    s += '<rect x="52" y="84" width="16" height="14" fill="' + p.skin + '"/>';
    // head
    if (who === 'mireille') s += '<path d="M30 66 Q28 30 60 28 Q92 30 90 66 L88 84 Q78 74 80 50 L40 50 Q42 74 32 84 Z" fill="' + p.hair + '"/>';
    s += '<ellipse cx="60" cy="64" rx="27" ry="31" fill="' + p.skin + '"/>';
    if (who === 'sami') s += '<path d="M33 58 Q30 30 60 30 Q90 30 87 58 Q84 44 76 42 Q70 48 60 44 Q50 48 44 42 Q36 44 33 58 Z" fill="' + p.hair + '"/><circle cx="40" cy="38" r="8" fill="' + p.hair + '"/><circle cx="55" cy="32" r="9" fill="' + p.hair + '"/><circle cx="72" cy="33" r="9" fill="' + p.hair + '"/><circle cx="84" cy="42" r="7" fill="' + p.hair + '"/>';
    if (who === 'mireille') s += '<path d="M33 52 Q36 34 60 34 Q84 34 87 52 Q74 44 60 46 Q46 44 33 52 Z" fill="' + p.hair + '"/>';
    if (who === 'josette') s += '<path d="M34 56 Q36 40 60 40 Q84 40 86 56 Q74 50 60 50 Q46 50 34 56 Z" fill="' + p.hair + '"/><path d="M36 42 Q30 22 46 20 Q52 6 64 12 Q78 4 82 20 Q94 22 84 42 Z" fill="#FFFFFF" stroke="#E5DDD0" stroke-width="2"/><rect x="36" y="38" width="48" height="8" rx="2" fill="#F1ECE2"/>';
    if (who === 'augustin') s += '<path d="M33 60 Q34 46 40 44 L80 44 Q86 46 87 60 Q84 52 80 52 L40 52 Q36 52 33 60 Z" fill="' + p.hair + '"/><path d="M30 44 Q32 26 60 26 Q88 26 90 44 Z" fill="#23305A"/><rect x="30" y="40" width="60" height="7" fill="#FFB547"/><path d="M26 47 Q60 54 94 47 L94 50 Q60 58 26 50 Z" fill="#141B36"/>';
    s += brows(expr, who === 'augustin' || who === 'mireille' ? '#8D8899' : '#2A1E1A');
    s += eyes(expr);
    if (who === 'mireille') s += '<circle cx="45" cy="64" r="9" fill="none" stroke="#3A2B45" stroke-width="2"/><circle cx="75" cy="64" r="9" fill="none" stroke="#3A2B45" stroke-width="2"/><path d="M54 64 L66 64" stroke="#3A2B45" stroke-width="2"/>';
    s += '<path d="M60 66 Q57 74 61 76" fill="none" stroke="#B57E62" stroke-width="2" stroke-linecap="round"/>';
    if (who === 'augustin') s += '<path d="M44 80 Q52 74 60 79 Q68 74 76 80 Q68 86 60 82 Q52 86 44 80 Z" fill="' + p.hair + '"/>';
    s += mouth(expr);
    s += '</g></svg>';
    return s;
  }

  root.SDNChars = {
    name: function (who) { return (PEOPLE[who] || PEOPLE.sami).name; },
    role: function (who) { return (PEOPLE[who] || PEOPLE.sami).role; },
    svg: svg
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
