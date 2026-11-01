/* Radio-Comète — Bash, first half: the lexer and the parser.

   A word stays a raw string (quotes included) until it is executed: the shell expands it then,
   with its own quoting rules (shell.js). The parser builds the AST of the subset the game teaches:
   lists, && and ||, pipelines, simple commands with assignments and redirections, if, for (words and
   arithmetic), while, until, case, { }, ( ), functions, [[ ]], (( )) and here-documents. */
(function (root) {
  'use strict';

  function SyntaxErr(msg, line) { var e = new Error(msg); e.syntax = true; e.line = line; return e; }

  var RESERVED = { 'if': 1, 'then': 1, 'else': 1, 'elif': 1, 'fi': 1, 'for': 1, 'while': 1, 'until': 1, 'do': 1, 'done': 1, 'case': 1, 'esac': 1, 'in': 1, '{': 1, '}': 1, '!': 1, 'function': 1, '[[': 1, ']]': 1, 'time': 1 };
  var STOP = { 'then': 1, 'else': 1, 'elif': 1, 'fi': 1, 'do': 1, 'done': 1, 'esac': 1, '}': 1 };
  var CMD_AFTER = { 'then': 1, 'else': 1, 'elif': 1, 'do': 1, '{': 1, '!': 1, 'if': 1, 'while': 1, 'until': 1, 'time': 1 };

  function skipQuoted(src, i, q) { // i is just after the opening quote; returns the index after the closing one
    for (; i < src.length; i++) {
      var c = src[i];
      if (q === '"' && c === '\\') { i++; continue; }
      if (q === '"' && c === '$' && src[i + 1] === '(') { i = skipParen(src, i + 1) - 1; continue; }
      if (q === '"' && c === '$' && src[i + 1] === '{') { i = skipBrace(src, i + 1) - 1; continue; }
      if (q === '"' && c === '`') { i = skipTick(src, i + 1) - 1; continue; }
      if (c === q) return i + 1;
    }
    throw SyntaxErr('unexpected EOF while looking for matching `' + q + "'", 0);
  }
  function skipParen(src, i) { // i at '(' ; returns the index after the matching ')'
    var depth = 0;
    for (; i < src.length; i++) {
      var c = src[i];
      if (c === '\\') { i++; continue; }
      if (c === "'") { i = skipQuoted(src, i + 1, "'") - 1; continue; }
      if (c === '"') { i = skipQuoted(src, i + 1, '"') - 1; continue; }
      if (c === '#' && (i === 0 || /\s/.test(src[i - 1]))) { while (i < src.length && src[i] !== '\n') i++; continue; }
      if (c === '(') depth++;
      else if (c === ')') { depth--; if (depth === 0) return i + 1; }
    }
    throw SyntaxErr('unexpected EOF while looking for matching `)\'', 0);
  }
  function skipBrace(src, i) { // i at '{'
    var depth = 0;
    for (; i < src.length; i++) {
      var c = src[i];
      if (c === '\\') { i++; continue; }
      if (c === "'") { i = skipQuoted(src, i + 1, "'") - 1; continue; }
      if (c === '"') { i = skipQuoted(src, i + 1, '"') - 1; continue; }
      if (c === '{') depth++;
      else if (c === '}') { depth--; if (depth === 0) return i + 1; }
    }
    throw SyntaxErr('unexpected EOF while looking for matching `}\'', 0);
  }
  function skipTick(src, i) { for (; i < src.length; i++) { if (src[i] === '\\') { i++; continue; } if (src[i] === '`') return i + 1; } throw SyntaxErr('unexpected EOF while looking for matching ``\'', 0); }

  var OPS = ['<<<', '<<-', '&>>', '&&', '||', ';;', '|&', '<<', '>>', '<&', '>&', '>|', '&>', '<>', ';', '|', '&', '(', ')', '<', '>'];

  function lex(src) {
    var toks = [], i = 0, line = 1, pending = [], n = src.length, inDb = false;
    function last() { return toks[toks.length - 1]; }
    function atCmd() {
      var t = last();
      if (!t) return true;
      if (t.k === 'nl') return true;
      if (t.k === 'op') return t.v === ';' || t.v === '&' || t.v === '&&' || t.v === '||' || t.v === '|' || t.v === '(' || t.v === ';;';
      return t.k === 'word' && !t.q && !!CMD_AFTER[t.v];
    }
    function readHeredocs() {
      pending.forEach(function (h) {
        var lines = [];
        for (;;) {
          if (i >= n) break;
          var j = src.indexOf('\n', i), ln = j === -1 ? src.slice(i) : src.slice(i, j);
          i = j === -1 ? n : j + 1; line++;
          var cmp = h.strip ? ln.replace(/^\t+/, '') : ln;
          if (cmp === h.delim) break;
          lines.push(h.strip ? ln.replace(/^\t+/, '') : ln);
        }
        h.body = lines.length ? lines.join('\n') + '\n' : '';
      });
      pending = [];
    }
    function word() { // scans one word starting at i; returns { raw, q }
      var s = i, q = false;
      while (i < n) {
        var c = src[i];
        if (c === ' ' || c === '\t' || c === '\n') break;
        if (c === '\\') { if (src[i + 1] === '\n') { i += 2; line++; continue; } q = true; i += 2; continue; }
        if (c === "'") { q = true; var e1 = skipQuoted(src, i + 1, "'"); countNl(i, e1); i = e1; continue; }
        if (c === '"') { q = true; var e2 = skipQuoted(src, i + 1, '"'); countNl(i, e2); i = e2; continue; }
        if (c === '`') { var e3 = skipTick(src, i + 1); countNl(i, e3); i = e3; continue; }
        if (c === '$' && src[i + 1] === "'") { q = true; var e4 = skipQuoted(src, i + 2, "'"); i = e4; continue; }
        if (c === '$' && src[i + 1] === '(') { var e5 = skipParen(src, i + 1); countNl(i, e5); i = e5; continue; }
        if (c === '$' && src[i + 1] === '{') { var e6 = skipBrace(src, i + 1); countNl(i, e6); i = e6; continue; }
        if ((c === '<' || c === '>') && src[i + 1] === '(') { var e7 = skipParen(src, i + 1); i = e7; continue; }
        if (c === '(' && /^[A-Za-z_][A-Za-z0-9_]*(\[[^\]]*\])?\+?=$/.test(src.slice(s, i))) { var e8 = skipParen(src, i); countNl(i, e8); i = e8; continue; }
        if ('|&;()<>'.indexOf(c) !== -1) break;
        i++;
      }
      return { raw: src.slice(s, i), q: q };
    }
    function countNl(a, b) { for (var k = a; k < b; k++) if (src[k] === '\n') line++; }

    while (i < n) {
      var c = src[i];
      if (c === ' ' || c === '\t') { i++; continue; }
      if (c === '\\' && src[i + 1] === '\n') { i += 2; line++; continue; }
      if (c === '#') { while (i < n && src[i] !== '\n') i++; continue; }
      if (c === '\n') { toks.push({ k: 'nl', line: line }); i++; line++; if (pending.length) readHeredocs(); continue; }
      // arithmetic command / C-style for header
      if (c === '(' && src[i + 1] === '(' && atCmd()) {
        var close = findArithEnd(src, i + 2);
        if (close !== -1) { toks.push({ k: 'arith', v: src.slice(i + 2, close), line: line }); countNl(i, close + 2); i = close + 2; continue; }
      }
      var lt = last();
      if (c === '(' && src[i + 1] === '(' && lt && lt.k === 'word' && lt.v === 'for' && !lt.q) {
        var cl = findArithEnd(src, i + 2);
        if (cl !== -1) { toks.pop(); toks.push({ k: 'cfor', v: src.slice(i + 2, cl), line: line }); i = cl + 2; continue; }
      }
      if (inDb && lt && lt.k === 'word' && lt.v === '=~' && !lt.q) { // the regular expression of [[ x =~ re ]] may hold unquoted ( ) |
        var rs = i, depth = 0;
        while (i < n && !(depth === 0 && /[ \t\n]/.test(src[i]))) {
          var rc = src[i];
          if (rc === '\\') { i += 2; continue; }
          if (rc === "'") { i = skipQuoted(src, i + 1, "'"); continue; }
          if (rc === '"') { i = skipQuoted(src, i + 1, '"'); continue; }
          if (rc === '(') depth++; else if (rc === ')') depth--;
          i++;
        }
        toks.push({ k: 'word', v: src.slice(rs, i), q: true, line: line }); continue;
      }
      // operators
      if (('|&;()<>'.indexOf(c) !== -1) && !((c === '<' || c === '>') && src[i + 1] === '(')) {
        var op = null;
        for (var k = 0; k < OPS.length; k++) if (src.substr(i, OPS[k].length) === OPS[k]) { op = OPS[k]; break; }
        i += op.length;
        var tok = { k: 'op', v: op, line: line };
        if (op === '<<' || op === '<<-') {
          while (src[i] === ' ' || src[i] === '\t') i++;
          var w = word();
          var d = w.raw.replace(/\\(.)/g, '$1').replace(/'([^']*)'/g, '$1').replace(/"([^"]*)"/g, '$1');
          tok.hd = { delim: d, quoted: w.q, strip: op === '<<-', body: null };
          pending.push(tok.hd);
        }
        toks.push(tok);
        continue;
      }
      var startLine = line, w2 = word();
      if (/^[0-9]+$/.test(w2.raw) && (src[i] === '<' || src[i] === '>') && src[i + 1] !== '(') { toks.push({ k: 'ionum', v: w2.raw, line: startLine }); continue; }
      toks.push({ k: 'word', v: w2.raw, q: w2.q, line: startLine });
      if (w2.raw === '[[' && !w2.q) inDb = true; else if (w2.raw === ']]' && !w2.q) inDb = false;
    }
    if (pending.length) readHeredocs();
    toks.push({ k: 'eof', line: line });
    return toks;
  }
  function findArithEnd(src, i) { // after "((": finds the matching "))" at depth 0
    var depth = 0;
    for (; i < src.length; i++) {
      var c = src[i];
      if (c === '(') depth++;
      else if (c === ')') { if (depth === 0) return src[i + 1] === ')' ? i : -1; depth--; }
    }
    return -1;
  }

  function parse(src) {
    var toks = lex(src), p = 0;
    function peek() { return toks[p]; }
    function next() { return toks[p++]; }
    function isW(t, v) { return t.k === 'word' && !t.q && t.v === v; }
    function fail(t) {
      var what = t.k === 'eof' ? 'end of file' : (t.k === 'nl' ? 'newline' : "`" + (t.v === undefined ? '?' : t.v) + "'");
      throw SyntaxErr(t.k === 'eof' ? 'syntax error: unexpected end of file' : 'syntax error near unexpected token ' + what, t.line);
    }
    function skipNl() { while (peek().k === 'nl') p++; }
    function skipSep() { while (peek().k === 'nl' || (peek().k === 'op' && peek().v === ';')) p++; }

    function parseSeq(stop) { // stop: function(tok) → bool
      var items = [];
      skipSep();
      while (!stop(peek()) && peek().k !== 'eof') {
        var ao = parseAndOr(), bg = false;
        var t = peek();
        if (t.k === 'op' && t.v === '&') { bg = true; p++; }
        else if (t.k === 'op' && t.v === ';') p++;
        else if (t.k === 'nl') p++;
        else if (!stop(t) && t.k !== 'eof') fail(t);
        items.push({ node: ao, bg: bg });
        skipSep();
      }
      return { t: 'seq', items: items };
    }
    function stopWord(t) { return t.k === 'word' && !t.q && !!STOP[t.v]; }
    function stopParen(t) { return t.k === 'op' && (t.v === ')' || t.v === ';;'); }
    function stopAll(t) { return stopWord(t) || stopParen(t); }

    function parseAndOr() {
      var first = parsePipeline(), rest = [];
      for (;;) {
        var t = peek();
        if (t.k === 'op' && (t.v === '&&' || t.v === '||')) { p++; skipNl(); rest.push({ op: t.v, p: parsePipeline() }); } else break;
      }
      return { t: 'andor', first: first, rest: rest };
    }
    function parsePipeline() {
      var neg = false, timed = false, line = peek().line;
      while (isW(peek(), '!') || isW(peek(), 'time')) { if (peek().v === '!') neg = !neg; else timed = true; p++; }
      var cmds = [parseCommand()], pipeErr = [false];
      for (;;) {
        var t = peek();
        if (t.k === 'op' && (t.v === '|' || t.v === '|&')) { p++; skipNl(); pipeErr.push(t.v === '|&'); cmds.push(parseCommand()); } else break;
      }
      return { t: 'pipe', cmds: cmds, neg: neg, line: line, pipeErr: pipeErr, timed: timed };
    }
    function redirsAfter(node) {
      for (;;) {
        var r = tryRedir();
        if (!r) break;
        (node.redirs = node.redirs || []).push(r);
      }
      return node;
    }
    function tryRedir() {
      var t = peek(), fd = null;
      if (t.k === 'ionum') { fd = t.v; p++; t = peek(); }
      if (t.k !== 'op' || !/^(<<<|<<-|<<|>>|<&|>&|>\||&>>|&>|<>|<|>)$/.test(t.v)) { if (fd !== null) p--; return null; }
      p++;
      var r = { op: t.v, fd: fd, line: t.line };
      if (t.hd) { r.hd = t.hd; return r; }
      var w = next();
      if (w.k !== 'word') fail(w);
      r.target = w.v;
      return r;
    }

    function parseCommand() {
      var t = peek();
      if (t.k === 'arith') { p++; return redirsAfter({ t: 'arith', expr: t.v, line: t.line }); }
      if (t.k === 'cfor') return parseCFor();
      if (t.k === 'op' && t.v === '(') {
        p++;
        var body = parseSeq(function (x) { return x.k === 'op' && x.v === ')'; });
        var c = next(); if (!(c.k === 'op' && c.v === ')')) fail(c);
        return redirsAfter({ t: 'subshell', body: body, line: t.line });
      }
      if (t.k === 'word' && !t.q) {
        switch (t.v) {
          case 'if': return parseIf();
          case 'for': return parseFor();
          case 'while': case 'until': return parseWhile();
          case 'case': return parseCase();
          case '{': {
            p++;
            var b = parseSeq(stopAll);
            var e = next(); if (!isW(e, '}')) fail(e);
            return redirsAfter({ t: 'group', body: b, line: t.line });
          }
          case '[[': return parseDbrack();
          case 'function': {
            p++;
            var nm = next(); if (nm.k !== 'word') fail(nm);
            if (peek().k === 'op' && peek().v === '(') { p++; var rp = next(); if (!(rp.k === 'op' && rp.v === ')')) fail(rp); }
            skipNl();
            return { t: 'func', name: nm.v, body: parseCommand(), line: t.line };
          }
          case 'then': case 'do': case 'done': case 'fi': case 'else': case 'elif': case 'esac': case '}': case 'in': case ']]':
            fail(t);
        }
        // name() { ... }
        var nt = toks[p + 1], nnt = toks[p + 2];
        if (/^[A-Za-z_][A-Za-z0-9_.:-]*$/.test(t.v) && nt.k === 'op' && nt.v === '(' && nnt.k === 'op' && nnt.v === ')') {
          p += 3; skipNl();
          return { t: 'func', name: t.v, body: parseCommand(), line: t.line };
        }
      }
      return parseSimple();
    }

    function parseIf() {
      var line = next().line, conds = [], els = null;
      var cond = parseSeq(stopWord);
      var th = next(); if (!isW(th, 'then')) fail(th);
      conds.push({ cond: cond, body: parseSeq(stopWord) });
      for (;;) {
        var t = next();
        if (isW(t, 'elif')) { var c2 = parseSeq(stopWord); var t2 = next(); if (!isW(t2, 'then')) fail(t2); conds.push({ cond: c2, body: parseSeq(stopWord) }); }
        else if (isW(t, 'else')) { els = parseSeq(stopWord); var f = next(); if (!isW(f, 'fi')) fail(f); break; }
        else if (isW(t, 'fi')) break;
        else fail(t);
      }
      return redirsAfter({ t: 'if', conds: conds, els: els, line: line });
    }
    function parseFor() {
      var line = next().line, v = next();
      if (v.k !== 'word') fail(v);
      var words = null;
      skipNl();
      if (isW(peek(), 'in')) {
        p++; words = [];
        while (peek().k === 'word') words.push(next().v);
        if (!(peek().k === 'nl' || (peek().k === 'op' && peek().v === ';'))) fail(peek());
      } else if (peek().k === 'op' && peek().v === ';') { /* for x; do */ }
      skipSep();
      var d = next(); if (!isW(d, 'do')) fail(d);
      var body = parseSeq(stopWord);
      var e = next(); if (!isW(e, 'done')) fail(e);
      return redirsAfter({ t: 'for', name: v.v, words: words, body: body, line: line });
    }
    function parseCFor() {
      var h = next();
      skipSep();
      var d = next(); if (!isW(d, 'do')) fail(d);
      var body = parseSeq(stopWord);
      var e = next(); if (!isW(e, 'done')) fail(e);
      var parts = h.v.split(';');
      if (parts.length !== 3) throw SyntaxErr('syntax error: arithmetic for needs three expressions', h.line);
      return redirsAfter({ t: 'cfor', init: parts[0], cond: parts[1], step: parts[2], body: body, line: h.line });
    }
    function parseWhile() {
      var kw = next(), cond = parseSeq(stopWord);
      var d = next(); if (!isW(d, 'do')) fail(d);
      var body = parseSeq(stopWord);
      var e = next(); if (!isW(e, 'done')) fail(e);
      return redirsAfter({ t: 'while', until: kw.v === 'until', cond: cond, body: body, line: kw.line });
    }
    function parseCase() {
      var line = next().line, w = next();
      if (w.k !== 'word') fail(w);
      skipNl();
      var inn = next(); if (!isW(inn, 'in')) fail(inn);
      var items = [];
      skipSep();
      while (!isW(peek(), 'esac')) {
        if (peek().k === 'eof') fail(peek());
        if (peek().k === 'op' && peek().v === '(') p++;
        var pats = [];
        for (;;) {
          var pt = next(); if (pt.k !== 'word') fail(pt);
          pats.push(pt.v);
          if (peek().k === 'op' && peek().v === '|') { p++; continue; }
          break;
        }
        var cp = next(); if (!(cp.k === 'op' && cp.v === ')')) fail(cp);
        var body = parseSeq(function (x) { return (x.k === 'op' && x.v === ';;') || isW(x, 'esac'); });
        items.push({ pats: pats, body: body });
        if (peek().k === 'op' && peek().v === ';;') p++;
        skipSep();
      }
      p++;
      return redirsAfter({ t: 'case', word: w.v, items: items, line: line });
    }
    function parseDbrack() {
      var line = next().line, items = [];
      for (;;) {
        var t = next();
        if (t.k === 'eof') fail(t);
        if (isW(t, ']]')) break;
        if (t.k === 'nl') continue;
        items.push(t);
      }
      return redirsAfter({ t: 'dbrack', toks: items, line: line });
    }
    function parseSimple() {
      var line = peek().line, assigns = [], words = [], redirs = [];
      for (;;) {
        var t = peek();
        var r = tryRedir();
        if (r) { redirs.push(r); continue; }
        if (t.k !== 'word') break;
        if (!words.length) {
          var m = /^([A-Za-z_][A-Za-z0-9_]*)(\[[^\]]*\])?(\+?)=([\s\S]*)$/.exec(t.v);
          if (m && !/^["']/.test(t.v)) { assigns.push({ name: m[1], idx: m[2] ? m[2].slice(1, -1) : null, append: m[3] === '+', value: m[4] }); p++; continue; }
        }
        if (!words.length && !t.q && RESERVED[t.v] && t.v !== '!' && t.v !== 'time' && t.v !== '{' && t.v !== '}') { if (words.length === 0 && assigns.length === 0) fail(t); }
        words.push(t.v); p++;
      }
      if (!words.length && !assigns.length && !redirs.length) fail(peek());
      return { t: 'simple', assigns: assigns, words: words, redirs: redirs, line: line };
    }

    var prog = parseSeq(function () { return false; });
    if (peek().k !== 'eof') fail(peek());
    return prog;
  }

  root.RCParse = { parse: parse, lex: lex, SyntaxErr: SyntaxErr, skipParen: skipParen, skipBrace: skipBrace, skipTick: skipTick, skipQuoted: skipQuoted };
})(typeof globalThis !== 'undefined' ? globalThis : window);
