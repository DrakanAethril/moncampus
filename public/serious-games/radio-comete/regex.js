/* Radio-Comète — patterns: shell globs, POSIX basic and extended regular expressions, translated
   to JavaScript regular expressions. Shared by the shell (case, [[ ]], ${x#pat}, pathnames), grep,
   sed, awk, find. */
(function (root) {
  'use strict';

  var CLASS = {
    alpha: 'A-Za-z', digit: '0-9', alnum: 'A-Za-z0-9', upper: 'A-Z', lower: 'a-z', space: ' \\t\\n\\r\\f\\v', blank: ' \\t',
    punct: '!-\\/:-@\\[-`{-~', xdigit: '0-9A-Fa-f', word: 'A-Za-z0-9_', print: ' -~', graph: '!-~', cntrl: '\\x00-\\x1f\\x7f'
  };
  function escLit(c) { return /[\\^$.*+?()[\]{}|\/-]/.test(c) ? '\\' + c : c; }

  /** Parses a bracket expression starting at src[i] === '['; returns { js, end } or null when unterminated. */
  function bracket(src, i, glob) {
    var j = i + 1, neg = false, out = '';
    if (src[j] === '!' || src[j] === '^') { neg = true; j++; }
    var first = true;
    for (; j < src.length; j++) {
      var c = src[j];
      if (c === ']' && !first) {
        return { js: '[' + (neg ? '^' : '') + (out || '') + ']', end: j + 1 };
      }
      first = false;
      if (c === '[' && src[j + 1] === ':') {
        var close = src.indexOf(':]', j + 2);
        if (close !== -1) { var nm = src.slice(j + 2, close); if (CLASS[nm]) { out += CLASS[nm]; j = close + 1; continue; } }
      }
      if (c === '\\' && glob && j + 1 < src.length) { out += escLit(src[j + 1]); j++; continue; }
      if (c === '\\') { out += '\\\\'; continue; }
      if (c === '-' && out !== '' && src[j + 1] !== ']') { out += '-'; continue; }
      out += (c === '[' || c === '^' || c === ']') ? '\\' + c : (c === '-' ? '\\-' : c);
    }
    return null;
  }

  /** Shell glob → JavaScript regular expression source (no anchors). */
  function globSrc(pat) {
    var out = '';
    for (var i = 0; i < pat.length; i++) {
      var c = pat[i];
      if (c === '\\' && i + 1 < pat.length) { out += escLit(pat[++i]); continue; }
      if (c === '*') { out += '[\\s\\S]*'; continue; }
      if (c === '?') { out += '[\\s\\S]'; continue; }
      if (c === '[') { var b = bracket(pat, i, true); if (b) { out += b.js; i = b.end - 1; continue; } }
      out += escLit(c);
    }
    return out;
  }
  function globRe(pat, flags) { return new RegExp('^(?:' + globSrc(pat) + ')$', flags || ''); }
  function hasGlob(pat) { return /[*?[]/.test(pat); }

  /** POSIX regular expression → JavaScript source. mode: 'bre' | 'ere'. */
  function reSrc(pat, mode) {
    var ere = mode !== 'bre', out = '', atStart = true;
    for (var i = 0; i < pat.length; i++) {
      var c = pat[i], nx = pat[i + 1];
      if (c === '\\') {
        if (i + 1 >= pat.length) { out += '\\\\'; break; }
        i++;
        if (!ere) {
          if (nx === '(') { out += '('; atStart = true; continue; }
          if (nx === ')') { out += ')'; atStart = false; continue; }
          if (nx === '|') { out += '|'; atStart = true; continue; }
          if (nx === '{') { out += '{'; atStart = false; continue; }
          if (nx === '}') { out += '}'; continue; }
          if (nx === '+' || nx === '?') { out += nx; continue; }
        }
        if (nx === '<' || nx === '>') { out += '\\b'; continue; }
        if (/[wWsSbB0-9nt]/.test(nx)) { out += '\\' + nx; atStart = false; continue; }
        out += escLit(nx); atStart = false; continue;
      }
      if (c === '[') { var b = bracket(pat, i, false); if (b) { out += b.js; i = b.end - 1; atStart = false; continue; } out += '\\['; atStart = false; continue; }
      if (c === '^') { out += (ere || atStart) ? '^' : '\\^'; continue; }
      if (c === '$') { out += (ere || i === pat.length - 1 || pat.slice(i + 1, i + 3) === '\\)' || pat.slice(i + 1, i + 3) === '\\|') ? '$' : '\\$'; atStart = false; continue; }
      if (c === '*') { out += atStart ? '\\*' : '*'; atStart = false; continue; }
      if (!ere && (c === '+' || c === '?' || c === '|' || c === '(' || c === ')' || c === '{' || c === '}')) { out += '\\' + c; atStart = false; continue; }
      if (ere && c === '{' && !/^\{[0-9]+(,[0-9]*)?\}/.test(pat.slice(i))) { out += '\\{'; atStart = false; continue; }
      if (ere && c === '}' ) { out += /\{[0-9]+(,[0-9]*)?$/.test(out) ? '}' : '\\}'; continue; }
      if (ere && (c === '(' || c === '|')) { out += c; atStart = true; continue; }
      if (c === '/') { out += '\\/'; atStart = false; continue; }
      out += c; atStart = false;
    }
    return out;
  }
  function re(pat, mode, flags) {
    try { return new RegExp(reSrc(pat, mode), flags || ''); } catch (e) { var er = new Error('Invalid regular expression'); er.regex = true; throw er; }
  }

  root.RCRegex = { globSrc: globSrc, globRe: globRe, hasGlob: hasGlob, reSrc: reSrc, re: re, escLit: escLit, CLASS: CLASS };
})(typeof globalThis !== 'undefined' ? globalThis : window);
