/* Radio-Comète — awk: a working subset. BEGIN / END / pattern { action }, ranges, fields and NF,
   variables and associative arrays, the usual statements, user functions, printf, getline from a
   file, and the string functions (length substr index split sub gsub match sprintf tolower toupper). */
(function (root) {
  'use strict';
  var R = root.RCRegex, U = root.RCUtils;

  function AwkError(msg) { var e = new Error(msg); e.awk = true; return e; }

  var KEYWORDS = { BEGIN: 1, END: 1, function: 1, func: 1, 'if': 1, 'else': 1, 'while': 1, 'for': 1, 'do': 1, 'break': 1, 'continue': 1, next: 1, exit: 1, 'return': 1, 'delete': 1, 'in': 1, print: 1, printf: 1, getline: 1 };
  var BUILTINS = { length: 1, substr: 1, index: 1, split: 1, sub: 1, gsub: 1, match: 1, sprintf: 1, sin: 1, cos: 1, atan2: 1, exp: 1, log: 1, sqrt: 1, int: 1, rand: 1, srand: 1, tolower: 1, toupper: 1, system: 1, close: 1, fflush: 1 };

  function lex(src) {
    var toks = [], i = 0, n = src.length;
    function regexAllowed() {
      var t = toks[toks.length - 1];
      if (!t) return true;
      if (t.k === 'num' || t.k === 'str' || t.k === 'id' || t.k === 'builtin' || t.k === 'ere') return false;
      if (t.k === 'op') return !(t.v === ')' || t.v === ']' || t.v === '$' || t.v === '++' || t.v === '--');
      return true;
    }
    while (i < n) {
      var c = src[i];
      if (c === ' ' || c === '\t' || c === '\r') { i++; continue; }
      if (c === '\\' && src[i + 1] === '\n') { i += 2; continue; }
      if (c === '#') { while (i < n && src[i] !== '\n') i++; continue; }
      if (c === '\n') { toks.push({ k: 'nl' }); i++; continue; }
      if (/[0-9.]/.test(c) && (c !== '.' || /[0-9]/.test(src[i + 1] || ''))) { var m = /^(0[xX][0-9a-fA-F]+|[0-9]*\.?[0-9]+(?:[eE][-+]?[0-9]+)?|[0-9]+\.)/.exec(src.slice(i)); toks.push({ k: 'num', v: parseFloat(m[0]) }); i += m[0].length; continue; }
      if (/[A-Za-z_]/.test(c)) {
        var id = /^[A-Za-z_][A-Za-z0-9_]*/.exec(src.slice(i))[0]; i += id.length;
        if (KEYWORDS[id]) toks.push({ k: 'kw', v: id }); else if (BUILTINS[id]) toks.push({ k: 'builtin', v: id }); else toks.push({ k: 'id', v: id, call: src[i] === '(' });
        continue;
      }
      if (c === '"') {
        var s = ''; i++;
        while (i < n && src[i] !== '"') {
          if (src[i] === '\\' && i + 1 < n) { var d = src[++i]; s += { n: '\n', t: '\t', r: '\r', '\\': '\\', '"': '"', '/': '/', a: '\x07', b: '\b', f: '\f', v: '\v' }[d] !== undefined ? { n: '\n', t: '\t', r: '\r', '\\': '\\', '"': '"', '/': '/', a: '\x07', b: '\b', f: '\f', v: '\v' }[d] : '\\' + d; i++; }
          else s += src[i++];
        }
        if (i >= n) throw AwkError('non-terminated string');
        i++; toks.push({ k: 'str', v: s }); continue;
      }
      if (c === '/' && regexAllowed()) {
        var re = ''; i++;
        while (i < n && src[i] !== '/') { if (src[i] === '\\' && src[i + 1] === '/') { re += '/'; i += 2; continue; } if (src[i] === '\\') { re += src[i] + src[i + 1]; i += 2; continue; } if (src[i] === '[') { var j = i; while (j < n && src[j] !== ']') { if (src[j] === '[' && src[j + 1] === ':') { j = src.indexOf(':]', j) + 1; } j++; } re += src.slice(i, j + 1); i = j + 1; continue; } re += src[i++]; }
        i++; toks.push({ k: 'ere', v: re }); continue;
      }
      var three = src.substr(i, 3), two = src.substr(i, 2);
      if (three === '**=') { toks.push({ k: 'op', v: '^=' }); i += 3; continue; }
      if (['&&', '||', '==', '!=', '<=', '>=', '++', '--', '+=', '-=', '*=', '/=', '%=', '^=', '!~', '>>', '**'].indexOf(two) !== -1) { toks.push({ k: 'op', v: two === '**' ? '^' : two }); i += 2; continue; }
      toks.push({ k: 'op', v: c }); i++;
    }
    toks.push({ k: 'eof' });
    return toks;
  }

  function parse(src) {
    var toks = lex(src), p = 0, noIn = 0, noGt = 0;
    function peek() { return toks[p]; }
    function isOp(v) { return toks[p].k === 'op' && toks[p].v === v; }
    function isKw(v) { return toks[p].k === 'kw' && toks[p].v === v; }
    function expectOp(v) { if (!isOp(v)) throw AwkError('syntax error: expected ' + v + ' near ' + (toks[p].v === undefined ? toks[p].k : toks[p].v)); p++; }
    function skipNl() { while (toks[p].k === 'nl') p++; }
    function skipTerm() { while (toks[p].k === 'nl' || isOp(';')) p++; }

    var prog = { begin: [], end: [], rules: [], funcs: {} };
    skipTerm();
    while (toks[p].k !== 'eof') {
      if (isKw('BEGIN')) { p++; skipNl(); prog.begin.push(block()); }
      else if (isKw('END')) { p++; skipNl(); prog.end.push(block()); }
      else if (isKw('function') || isKw('func')) {
        p++; var name = toks[p++].v; expectOp('('); var params = [];
        while (!isOp(')')) { params.push(toks[p++].v); if (isOp(',')) p++; skipNl(); }
        p++; skipNl(); prog.funcs[name] = { params: params, body: block() };
      } else {
        var pat = null, pat2 = null;
        if (!isOp('{')) { pat = expr(); if (isOp(',')) { p++; skipNl(); pat2 = expr(); } }
        var body = null;
        if (isOp('{')) body = block();
        prog.rules.push({ pat: pat, pat2: pat2, body: body, active: false });
      }
      skipTerm();
    }
    return prog;

    function block() {
      expectOp('{'); var stmts = []; skipTerm();
      while (!isOp('}')) { if (toks[p].k === 'eof') throw AwkError('syntax error: missing }'); stmts.push(stmt()); skipTerm(); }
      p++;
      return { t: 'block', stmts: stmts };
    }
    function simpleOrBlock() { skipNl(); if (isOp('{')) return block(); return stmt(); }
    function endStmt() { if (isOp(';') || toks[p].k === 'nl') { p++; return; } if (isOp('}') || toks[p].k === 'eof') return; throw AwkError('syntax error near ' + (toks[p].v === undefined ? toks[p].k : toks[p].v)); }
    function stmt() {
      var t = toks[p];
      if (isOp('{')) return block();
      if (isOp(';')) { p++; return { t: 'block', stmts: [] }; }
      if (t.k === 'kw') {
        switch (t.v) {
          case 'if': { p++; expectOp('('); var c = expr(); expectOp(')'); var th = simpleOrBlock(); var save = p; skipTerm(); var el = null; if (isKw('else')) { p++; el = simpleOrBlock(); } else p = save; return { t: 'if', c: c, th: th, el: el }; }
          case 'while': { p++; expectOp('('); var wc = expr(); expectOp(')'); if (isOp(';')) { p++; return { t: 'while', c: wc, body: { t: 'block', stmts: [] } }; } return { t: 'while', c: wc, body: simpleOrBlock() }; }
          case 'do': { p++; var body = simpleOrBlock(); skipTerm(); if (!isKw('while')) throw AwkError('syntax error: do without while'); p++; expectOp('('); var dc = expr(); expectOp(')'); endStmt(); return { t: 'do', c: dc, body: body }; }
          case 'for': {
            p++; expectOp('(');
            if (toks[p].k === 'id' && toks[p + 1].k === 'kw' && toks[p + 1].v === 'in' && toks[p + 3] && toks[p + 3].k === 'op' && toks[p + 3].v === ')') { var v = toks[p].v, arr = toks[p + 2].v; p += 4; return { t: 'forin', v: v, arr: arr, body: simpleOrBlock() }; }
            var init = isOp(';') ? null : simple(); expectOp(';'); skipNl(); var cond = isOp(';') ? null : expr(); expectOp(';'); skipNl(); var step = isOp(')') ? null : simple(); expectOp(')');
            if (isOp(';')) { p++; return { t: 'for', init: init, cond: cond, step: step, body: { t: 'block', stmts: [] } }; }
            return { t: 'for', init: init, cond: cond, step: step, body: simpleOrBlock() };
          }
          case 'break': p++; endStmt(); return { t: 'break' };
          case 'continue': p++; endStmt(); return { t: 'continue' };
          case 'next': p++; endStmt(); return { t: 'next' };
          case 'exit': { p++; var e = null; if (!(isOp(';') || isOp('}') || toks[p].k === 'nl' || toks[p].k === 'eof')) e = expr(); endStmt(); return { t: 'exit', e: e }; }
          case 'return': { p++; var r = null; if (!(isOp(';') || isOp('}') || toks[p].k === 'nl' || toks[p].k === 'eof')) r = expr(); endStmt(); return { t: 'return', e: r }; }
          case 'delete': { p++; var an = toks[p++].v, idx = null; if (isOp('[')) { p++; idx = exprList(']'); expectOp(']'); } endStmt(); return { t: 'delete', arr: an, idx: idx }; }
        }
      }
      var s = simple(); endStmt(); return s;
    }
    function simple() {
      if (isKw('print') || isKw('printf')) {
        var kind = toks[p++].v, args = [];
        noGt++;
        if (!(isOp(';') || isOp('}') || toks[p].k === 'nl' || isOp('>') || isOp('>>') || isOp('|'))) {
          if (isOp('(')) { var save = p; p++; var l = exprList(')'); if (isOp(')') && (toks[p + 1].k === 'nl' || toks[p + 1].k === 'eof' || (toks[p + 1].k === 'op' && /^[;}>|]|>>$/.test(toks[p + 1].v)))) { p++; args = l; } else { p = save; args = exprList(null); } }
          else args = exprList(null);
        }
        noGt--;
        var redir = null;
        if (isOp('>') || isOp('>>') || isOp('|')) { var rop = toks[p++].v; noGt++; var target = concat(); noGt--; redir = { op: rop, target: target }; }
        return { t: kind, args: args, redir: redir };
      }
      return { t: 'expr', e: expr() };
    }
    function exprList(close) {
      var list = [];
      if (close && isOp(close)) return list;
      list.push(expr());
      while (isOp(',')) { p++; skipNl(); list.push(expr()); }
      return list;
    }
    function expr() { return ternary(); }
    function ternary() {
      var c = orx();
      if (isOp('?')) { p++; skipNl(); var a = ternary(); skipNl(); expectOp(':'); skipNl(); var b = ternary(); return { t: 'cond', c: c, a: a, b: b }; }
      if (isAssign(c) && toks[p].k === 'op' && /^(=|\+=|-=|\*=|\/=|%=|\^=)$/.test(toks[p].v)) { var op = toks[p++].v; skipNl(); return { t: 'assign', op: op, l: c, r: ternary() }; }
      return c;
    }
    function isAssign(n) { return n.t === 'var' || n.t === 'index' || n.t === 'field'; }
    function orx() { var l = andx(); while (isOp('||')) { p++; skipNl(); l = { t: 'or', l: l, r: andx() }; } return l; }
    function andx() { var l = inx(); while (isOp('&&')) { p++; skipNl(); l = { t: 'and', l: l, r: inx() }; } return l; }
    function inx() { var l = match(); while (isKw('in') && !noIn) { p++; var a = toks[p++].v; l = { t: 'in', k: l, arr: a }; } return l; }
    function match() { var l = comparison(); while (isOp('~') || isOp('!~')) { var neg = toks[p++].v === '!~'; var r = comparison(); l = { t: 'match', l: l, r: r, neg: neg }; } return l; }
    function comparison() {
      var l = concat();
      for (;;) {
        var t = toks[p];
        if (t.k === 'op' && /^(<|<=|==|!=|>=)$/.test(t.v) || (t.k === 'op' && t.v === '>' && !noGt)) { p++; l = { t: 'cmp', op: t.v, l: l, r: concat() }; } else break;
      }
      return l;
    }
    function startsOperand() {
      var t = toks[p];
      if (t.k === 'num' || t.k === 'str' || t.k === 'ere' || t.k === 'id' || t.k === 'builtin') return true;
      if (t.k === 'kw') return t.v === 'getline' ? false : false;
      if (t.k === 'op') return t.v === '$' || t.v === '(' || t.v === '!' || t.v === '-' && false || t.v === '++' || t.v === '--';
      return false;
    }
    function concat() {
      var l = additive();
      while (startsOperand() && !(toks[p].k === 'kw')) { if (toks[p].k === 'op' && (toks[p].v === '+' || toks[p].v === '-')) break; l = { t: 'concat', l: l, r: additive() }; }
      return l;
    }
    function additive() { var l = mult(); while (isOp('+') || isOp('-')) { var op = toks[p++].v; l = { t: 'bin', op: op, l: l, r: mult() }; } return l; }
    function mult() { var l = unary(); while (isOp('*') || isOp('/') || isOp('%')) { var op = toks[p++].v; l = { t: 'bin', op: op, l: l, r: unary() }; } return l; }
    function unary() {
      if (isOp('!')) { p++; return { t: 'not', e: unary() }; }
      if (isOp('-')) { p++; return { t: 'neg', e: unary() }; }
      if (isOp('+')) { p++; return { t: 'pos', e: unary() }; }
      return power();
    }
    function power() { var l = postfix(); if (isOp('^')) { p++; var r = unary(); return { t: 'bin', op: '^', l: l, r: r }; } return l; }
    function postfix() {
      var e = primary();
      if ((isOp('++') || isOp('--')) && isAssign(e)) { var op = toks[p++].v; return { t: 'post', op: op, e: e }; }
      return e;
    }
    function primary() {
      var t = toks[p];
      if (t.k === 'num') { p++; return { t: 'num', v: t.v }; }
      if (t.k === 'str') { p++; return { t: 'str', v: t.v }; }
      if (t.k === 'ere') { p++; return { t: 'ere', v: t.v }; }
      if (t.k === 'op') {
        if (t.v === '(') { p++; noGt--; var save = noGt; noGt = 0; var e = expr(); if (isOp(',')) { var list = [e]; while (isOp(',')) { p++; list.push(expr()); } expectOp(')'); noGt = save + 1; if (isKw('in')) { p++; var an = toks[p++].v; return { t: 'in', k: { t: 'multi', list: list }, arr: an }; } throw AwkError('syntax error'); } expectOp(')'); noGt = save + 1; return { t: 'group', e: e }; }
        if (t.v === '$') { p++; return { t: 'field', e: postfixNoIncr() }; }
        if (t.v === '++' || t.v === '--') { p++; var tg = primary(); return { t: 'pre', op: t.v, e: tg }; }
        if (t.v === '-') { p++; return { t: 'neg', e: unary() }; }
      }
      if (t.k === 'builtin') {
        p++; var args = [];
        if (isOp('(')) { p++; var sn = noGt; noGt = 0; args = exprList(')'); noGt = sn; expectOp(')'); }
        return { t: 'call', name: t.v, args: args };
      }
      if (t.k === 'kw' && t.v === 'getline') {
        p++; var gv = null, file = null;
        if (toks[p].k === 'id' && !toks[p].call) { gv = primaryVar(); }
        if (isOp('<')) { p++; file = concatNoCmp(); }
        return { t: 'getline', v: gv, file: file };
      }
      if (t.k === 'id') {
        p++;
        if (t.call && isOp('(')) { p++; var sn2 = noGt; noGt = 0; var fargs = exprList(')'); noGt = sn2; expectOp(')'); return { t: 'ucall', name: t.v, args: fargs }; }
        if (isOp('[')) { p++; var sn3 = noGt; noGt = 0; var idx = exprList(']'); noGt = sn3; expectOp(']'); return { t: 'index', name: t.v, idx: idx }; }
        return { t: 'var', name: t.v };
      }
      throw AwkError('syntax error near ' + (t.v === undefined ? t.k : t.v));
    }
    function primaryVar() { var t = toks[p++]; if (isOp('[')) { p++; var idx = exprList(']'); expectOp(']'); return { t: 'index', name: t.v, idx: idx }; } return { t: 'var', name: t.v }; }
    function postfixNoIncr() { if (isOp('++') || isOp('--')) { var op = toks[p++].v; var tg = primary(); return { t: 'pre', op: op, e: tg }; } return primary(); }
    function concatNoCmp() { return additive(); }
  }

  // ——— Values: numbers, strings, and "strnum" (input text that looks like a number) ———
  function SN(s) { this.s = s; }
  function looksNum(s) { return /^\s*[-+]?(\d+\.?\d*([eE][-+]?\d+)?|\.\d+([eE][-+]?\d+)?|0[xX][0-9a-fA-F]+)\s*$/.test(s) || /^\s*[-+]?(inf|nan)\s*$/i.test(s); }
  function toNum(v) { if (typeof v === 'number') return v; var s = v instanceof SN ? v.s : v; var m = /^\s*[-+]?(\d+\.?\d*([eE][-+]?\d+)?|\.\d+([eE][-+]?\d+)?)/.exec(s); return m ? parseFloat(m[0]) : 0; }
  function numStr(n) { if (Number.isInteger(n) && Math.abs(n) < 1e16) return String(n); var s = n.toPrecision(6); s = String(parseFloat(s)); return s; }
  function toStr(v) { if (typeof v === 'number') return numStr(v); return v instanceof SN ? v.s : v; }
  function toBool(v) { if (typeof v === 'number') return v !== 0; if (v instanceof SN) return looksNum(v.s) ? parseFloat(v.s) !== 0 : v.s !== ''; return v !== ''; }

  function sprintf(fmt, args) {
    var ai = 0;
    return fmt.replace(/%([-+ #0]*)(\*|\d+)?(?:\.(\*|\d+))?([a-zA-Z%])/g, function (m, flags, w, pr, conv) {
      if (conv === '%') return '%';
      if (w === '*') w = String(toNum(args[ai++])); if (pr === '*') pr = String(toNum(args[ai++]));
      var width = w === undefined ? 0 : parseInt(w, 10), prec = pr === undefined ? undefined : parseInt(pr, 10);
      var left = flags.indexOf('-') !== -1, zero = flags.indexOf('0') !== -1 && !left, plus = flags.indexOf('+') !== -1, space = flags.indexOf(' ') !== -1;
      var a = args[ai++], s;
      switch (conv) {
        case 'd': case 'i': { var n = Math.trunc(toNum(a === undefined ? 0 : a)); s = String(Math.abs(n)); if (prec !== undefined) while (s.length < prec) s = '0' + s; s = (n < 0 ? '-' : (plus ? '+' : (space ? ' ' : ''))) + s; break; }
        case 'o': s = (Math.trunc(toNum(a)) >>> 0).toString(8); break;
        case 'x': s = (Math.trunc(toNum(a)) >>> 0).toString(16); break; case 'X': s = (Math.trunc(toNum(a)) >>> 0).toString(16).toUpperCase(); break;
        case 'u': s = String(Math.trunc(toNum(a)) >>> 0); break;
        case 'c': s = typeof a === 'number' ? String.fromCharCode(a) : toStr(a === undefined ? '' : a).charAt(0); break;
        case 's': s = toStr(a === undefined ? '' : a); if (prec !== undefined) s = s.slice(0, prec); break;
        case 'e': case 'E': { var ev = toNum(a); s = Math.abs(ev).toExponential(prec === undefined ? 6 : prec).replace(/e([+-])(\d)$/, 'e$10$2'); s = (ev < 0 ? '-' : (plus ? '+' : '')) + s; if (conv === 'E') s = s.toUpperCase(); break; }
        case 'f': case 'F': { var fv = toNum(a); s = (fv < 0 ? '-' : (plus ? '+' : (space ? ' ' : ''))) + Math.abs(fv).toFixed(prec === undefined ? 6 : prec); break; }
        case 'g': case 'G': { var gv = toNum(a); s = (gv < 0 ? '-' : (plus ? '+' : '')) + String(parseFloat(Math.abs(gv).toPrecision(prec === undefined ? 6 : (prec || 1)))); break; }
        default: return m;
      }
      if (s.length < width) { var fill = Array(width - s.length + 1).join(zero && conv !== 's' && conv !== 'c' ? '0' : ' '); if (left) s += fill; else if (zero && /^[-+ ]/.test(s) && conv !== 's') s = s[0] + fill + s.slice(1); else s = fill + s; }
      return s;
    });
  }

  function run(sh, args, io) {
    var fs = null, assigns = [], i = 0, progText = null, progFiles = [];
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '--') { i++; break; }
      if (a === '-F') { fs = args[++i]; continue; }
      if (/^-F./.test(a)) { fs = a.slice(2); continue; }
      if (a === '-v') { assigns.push(args[++i]); continue; }
      if (/^-v./.test(a)) { assigns.push(a.slice(2)); continue; }
      if (a === '-f') { var pf = U.readFileArg(sh, 'awk', args[++i], io); if (pf === null) return 2; progFiles.push(pf); continue; }
      if (a[0] === '-' && a.length > 1) { io.err.w('awk: unknown option ' + a + ' ignored\n'); continue; }
      break;
    }
    if (progFiles.length) progText = progFiles.join('\n'); else { if (i >= args.length) { io.err.w('usage: awk [-F fs][-v var=value][prog | -f progfile][file ...]\n'); return 2; } progText = args[i++]; }
    var files = args.slice(i), prog;
    try { prog = parse(progText); } catch (e) { if (e.awk) { io.err.w('awk: ' + e.message + '\n'); return 2; } throw e; }

    // ——— The runtime ———
    var vars = Object.create(null), arrays = Object.create(null), fields = [], rec = '', nf = 0, locals = [];
    var out = io.out, redirs = Object.create(null), status = 0, rng = 1;
    function T(s) { return s; }
    vars.FS = fs === null ? ' ' : (fs === 't' ? '\t' : fs.replace(/\\t/g, '\t')); vars.OFS = ' '; vars.ORS = '\n'; vars.RS = '\n'; vars.NR = 0; vars.FNR = 0; vars.SUBSEP = '\x1c'; vars.FILENAME = ''; vars.RSTART = 0; vars.RLENGTH = -1; vars.CONVFMT = '%.6g'; vars.ENVIRON = null;
    assigns.forEach(function (as) { var k = as.indexOf('='); vars[as.slice(0, k)] = new SN(as.slice(k + 1).replace(/\\n/g, '\n').replace(/\\t/g, '\t')); });
    arrays.ENVIRON = Object.create(null); var env = sh.exported(); Object.keys(env).forEach(function (k) { arrays.ENVIRON[k] = new SN(env[k]); });
    var nextSig = { next: true }, exitSig = { exit: true }, retSig = function (v) { this.v = v; }, brk = { b: true }, cont = { c: true };
    var steps = 0;

    function getVar(name) {
      if (locals.length && name in locals[locals.length - 1]) return locals[locals.length - 1][name];
      if (name === 'NF') return nf;
      var v = vars[name]; return v === undefined ? '' : v;
    }
    function setVar(name, v) {
      if (locals.length && name in locals[locals.length - 1]) { locals[locals.length - 1][name] = v; return; }
      if (name === 'NF') { var k = Math.max(0, Math.trunc(toNum(v))); fields.length = k; for (var q = 0; q < k; q++) if (fields[q] === undefined) fields[q] = new SN(''); nf = k; rebuild(); return; }
      vars[name] = v;
    }
    function arr(name) {
      if (locals.length && name in locals[locals.length - 1]) { var l = locals[locals.length - 1][name]; if (typeof l === 'object' && l !== null && !(l instanceof SN)) return l; var na = Object.create(null); locals[locals.length - 1][name] = na; return na; }
      return arrays[name] || (arrays[name] = Object.create(null));
    }
    function setRec(s) {
      rec = s; var sep = toStr(vars.FS), parts;
      if (s === '') parts = [];
      else if (sep === ' ') parts = s.trim() === '' ? [] : s.trim().split(/[ \t\n]+/);
      else if (sep.length === 1 && sep !== '\\') parts = s.split(sep === '|' ? '|' : sep);
      else { try { parts = s.split(R.re(sep, 'ere')); } catch (e) { parts = s.split(sep); } }
      fields = parts.map(function (x) { return new SN(x); }); nf = fields.length;
    }
    function rebuild() { rec = fields.slice(0, nf).map(toStr).join(toStr(vars.OFS)); }
    function getField(k) {
      if (k === 0) return new SN(rec);
      if (k < 0) throw AwkError('trying to access out of range field ' + k);
      return k <= nf ? fields[k - 1] : new SN('');
    }
    function setField(k, v) {
      if (k === 0) { setRec(toStr(v)); return; }
      while (nf < k) { fields.push(new SN('')); nf++; }
      fields[k - 1] = new SN(toStr(v)); rebuild();
    }
    function compare(a, b) {
      var an = typeof a === 'number' || (a instanceof SN && looksNum(a.s)), bn = typeof b === 'number' || (b instanceof SN && looksNum(b.s));
      if (an && bn) { var x = toNum(a), y = toNum(b); return x < y ? -1 : (x > y ? 1 : 0); }
      var s = toStr(a), t = toStr(b); return s < t ? -1 : (s > t ? 1 : 0);
    }
    var reCache = {};
    function regex(v, lit) { var src = lit ? v : toStr(v); var key = src; if (!reCache[key]) { try { reCache[key] = R.re(src, 'ere'); } catch (e) { throw AwkError('bad regular expression: ' + src); } } var r = reCache[key]; r.lastIndex = 0; return r; }
    function key(list) { return list.map(function (e) { return toStr(ev(e)); }).join(toStr(vars.SUBSEP)); }

    function ev(n) {
      if (++steps > 2000000) throw AwkError('awk: too many steps');
      switch (n.t) {
        case 'num': return n.v; case 'str': return n.v;
        case 'ere': return regex(n.v, true).test(rec) ? 1 : 0;
        case 'group': return ev(n.e);
        case 'var': return getVar(n.name);
        case 'index': { var a = arr(n.name), k = key(n.idx); return a[k] === undefined ? (a[k] = '') : a[k]; }
        case 'field': return getField(Math.trunc(toNum(ev(n.e))));
        case 'assign': {
          var val = ev(n.r);
          if (n.op !== '=') { var cur = toNum(ev(n.l)), rv = toNum(val); switch (n.op) { case '+=': val = cur + rv; break; case '-=': val = cur - rv; break; case '*=': val = cur * rv; break; case '/=': if (rv === 0) throw AwkError('division by zero in /='); val = cur / rv; break; case '%=': val = cur % rv; break; case '^=': val = Math.pow(cur, rv); break; } }
          else if (val instanceof SN) val = val;
          store(n.l, val); return val;
        }
        case 'cond': return toBool(ev(n.c)) ? ev(n.a) : ev(n.b);
        case 'or': return toBool(ev(n.l)) || toBool(ev(n.r)) ? 1 : 0;
        case 'and': return toBool(ev(n.l)) && toBool(ev(n.r)) ? 1 : 0;
        case 'not': return toBool(ev(n.e)) ? 0 : 1;
        case 'neg': return -toNum(ev(n.e)); case 'pos': return toNum(ev(n.e));
        case 'in': { var kk = n.k.t === 'multi' ? n.k.list.map(function (e) { return toStr(ev(e)); }).join(toStr(vars.SUBSEP)) : toStr(ev(n.k)); return arr(n.arr)[kk] !== undefined ? 1 : 0; }
        case 'match': { var s = toStr(ev(n.l)), re = n.r.t === 'ere' ? regex(n.r.v, true) : regex(ev(n.r)); var hit = re.test(s); return (n.neg ? !hit : hit) ? 1 : 0; }
        case 'cmp': { var c = compare(ev(n.l), ev(n.r)); switch (n.op) { case '<': return c < 0 ? 1 : 0; case '<=': return c <= 0 ? 1 : 0; case '==': return c === 0 ? 1 : 0; case '!=': return c !== 0 ? 1 : 0; case '>': return c > 0 ? 1 : 0; default: return c >= 0 ? 1 : 0; } }
        case 'concat': return toStr(ev(n.l)) + toStr(ev(n.r));
        case 'bin': { var x = toNum(ev(n.l)), y = toNum(ev(n.r)); switch (n.op) { case '+': return x + y; case '-': return x - y; case '*': return x * y; case '/': if (y === 0) throw AwkError('division by zero'); return x / y; case '%': if (y === 0) throw AwkError('division by zero in %'); return x % y; default: return Math.pow(x, y); } }
        case 'pre': { var pv = toNum(ev(n.e)) + (n.op === '++' ? 1 : -1); store(n.e, pv); return pv; }
        case 'post': { var ov = toNum(ev(n.e)); store(n.e, ov + (n.op === '++' ? 1 : -1)); return ov; }
        case 'call': return builtin(n);
        case 'ucall': return userCall(n);
        case 'getline': return doGetline(n);
      }
      throw AwkError('unknown expression ' + n.t);
    }
    function store(t, v) {
      if (t.t === 'group') return store(t.e, v);
      if (t.t === 'var') setVar(t.name, v);
      else if (t.t === 'index') arr(t.name)[key(t.idx)] = v;
      else if (t.t === 'field') setField(Math.trunc(toNum(ev(t.e))), v);
      else throw AwkError('assignment to a non-variable');
    }
    function userCall(n) {
      var f = prog.funcs[n.name]; if (!f) throw AwkError('calling undefined function ' + n.name);
      if (locals.length > 200) throw AwkError('function call nesting too deep');
      var frame = {};
      f.params.forEach(function (pn, k) { if (k < n.args.length) { var an = n.args[k]; if (an.t === 'var' && (arrays[an.name] || (locals.length && an.name in locals[locals.length - 1] && typeof locals[locals.length - 1][an.name] === 'object' && !(locals[locals.length - 1][an.name] instanceof SN)))) frame[pn] = arr(an.name); else frame[pn] = ev(an); } else frame[pn] = ''; });
      locals.push(frame);
      var ret = '';
      try { exec(f.body); } catch (e) { if (e instanceof retSig) ret = e.v; else throw e; } finally { locals.pop(); }
      return ret;
    }
    function builtin(n) {
      var a = n.args;
      switch (n.name) {
        case 'length': { if (!a.length) return Array.from(rec).length; if (a[0].t === 'var' && (arrays[a[0].name] || (locals.length && typeof locals[locals.length - 1][a[0].name] === 'object' && !(locals[locals.length - 1][a[0].name] instanceof SN) && locals[locals.length - 1][a[0].name] !== undefined && locals[locals.length - 1][a[0].name] !== ''))) return Object.keys(arr(a[0].name)).length; return Array.from(toStr(ev(a[0]))).length; }
        case 'substr': { var s = Array.from(toStr(ev(a[0]))), m = Math.round(toNum(ev(a[1]))), l = a.length > 2 ? Math.round(toNum(ev(a[2]))) : Infinity; var from = Math.max(m, 1), to = Math.min(m + l, s.length + 1); return to > from ? s.slice(from - 1, to - 1).join('') : ''; }
        case 'index': return toStr(ev(a[0])).indexOf(toStr(ev(a[1]))) + 1;
        case 'split': { var str = toStr(ev(a[0])), an = a[1].name, sep = a.length > 2 ? (a[2].t === 'ere' ? a[2].v : toStr(ev(a[2]))) : toStr(vars.FS), parts;
          if (str === '') parts = []; else if (sep === ' ') parts = str.trim() === '' ? [] : str.trim().split(/[ \t\n]+/); else if (sep.length === 1 && a.length > 2 && a[2].t !== 'ere' || (sep.length === 1 && sep !== '\\')) parts = str.split(sep); else parts = str.split(R.re(sep, 'ere'));
          var ar = arr(an); Object.keys(ar).forEach(function (k) { delete ar[k]; }); parts.forEach(function (x, k) { ar[String(k + 1)] = new SN(x); }); return parts.length; }
        case 'sub': case 'gsub': {
          var rx = a[0].t === 'ere' ? regex(a[0].v, true) : regex(ev(a[0])), rep = toStr(ev(a[1])), target = a[2] || { t: 'field', e: { t: 'num', v: 0 } }, src = toStr(ev(target)), count = 0;
          var g = new RegExp(rx.source, 'g' + rx.flags.replace('g', ''));
          var res = src.replace(g, function (mt) { if (n.name === 'sub' && count >= 1) return mt; count++; return rep.replace(/\\\\|\\&|&/g, function (z) { return z === '&' ? mt : (z === '\\&' ? '&' : '\\'); }); });
          if (count) store(target, res); return count; }
        case 'match': { var ms = toStr(ev(a[0])), mr = a[1].t === 'ere' ? regex(a[1].v, true) : regex(ev(a[1])), mm = mr.exec(ms); vars.RSTART = mm ? mm.index + 1 : 0; vars.RLENGTH = mm ? mm[0].length : -1; return vars.RSTART; }
        case 'sprintf': { var args = a.map(ev); return sprintf(toStr(args[0]), args.slice(1)); }
        case 'sin': return Math.sin(toNum(ev(a[0]))); case 'cos': return Math.cos(toNum(ev(a[0]))); case 'atan2': return Math.atan2(toNum(ev(a[0])), toNum(ev(a[1])));
        case 'exp': return Math.exp(toNum(ev(a[0]))); case 'log': return Math.log(toNum(ev(a[0]))); case 'sqrt': return Math.sqrt(toNum(ev(a[0]))); case 'int': return Math.trunc(toNum(ev(a[0])));
        case 'rand': rng = (rng * 16807) % 2147483647; return rng / 2147483647; case 'srand': { var old = rng; rng = a.length ? Math.max(1, Math.trunc(toNum(ev(a[0])))) : 1; return old; }
        case 'tolower': return toStr(ev(a[0])).toLowerCase(); case 'toupper': return toStr(ev(a[0])).toUpperCase();
        case 'system': return 0; case 'close': case 'fflush': return 0;
      }
      return 0;
    }

    var inputs = null, cur = 0, curLines = null, curIdx = 0;
    function nextRecord() {
      if (curLines === null) {
        if (inputs === null) {
          inputs = files.filter(function (f) { return !/^[A-Za-z_][A-Za-z0-9_]*=/.test(f); }).length ? files : [];
          if (!inputs.length) inputs = ['-'];
        }
        for (;;) {
          if (cur >= inputs.length) return null;
          var f = inputs[cur++];
          var am = /^([A-Za-z_][A-Za-z0-9_]*)=([\s\S]*)$/.exec(f);
          if (am) { vars[am[1]] = new SN(am[2]); continue; }
          var d;
          if (f === '-') d = io.in ? io.in.readAll() : ''; else { d = U.readFileArg(sh, 'awk', f, io); if (d === null) { status = 2; continue; } }
          vars.FILENAME = f === '-' ? '' : f; vars.FNR = 0;
          var rs = toStr(vars.RS);
          curLines = rs === '\n' ? (d === '' ? [] : d.split('\n')) : (rs === '' ? d.split(/\n\n+/) : d.split(rs));
          if (rs === '\n' && curLines.length && curLines[curLines.length - 1] === '') curLines.pop();
          curIdx = 0; break;
        }
      }
      while (curLines !== null && curIdx >= curLines.length) { curLines = null; return nextRecord(); }
      var line = curLines[curIdx++];
      vars.NR = toNum(vars.NR) + 1; vars.FNR = toNum(vars.FNR) + 1;
      return line;
    }
    var gfiles = {};
    function doGetline(n) {
      var line;
      if (n.file) {
        var fn = toStr(ev(n.file)), g = gfiles[fn];
        if (!g) { var d = U.readFileArg(sh, 'awk', fn, { err: { w: function () { } } }); if (d === null) return -1; g = gfiles[fn] = { lines: d === '' ? [] : d.replace(/\n$/, '').split('\n'), i: 0 }; }
        if (g.i >= g.lines.length) return 0; line = g.lines[g.i++];
        if (n.v) store(n.v, new SN(line)); else { setRec(line); }
        return 1;
      }
      line = nextRecord(); if (line === null) return 0;
      if (n.v) store(n.v, new SN(line)); else setRec(line);
      return 1;
    }

    function output(text, redir) {
      if (!redir) { out.w(text); return; }
      var target = toStr(ev(redir.target));
      if (redir.op === '|') { (redirs['|' + target] = redirs['|' + target] || { cmd: target, buf: '' }).buf += text; return; }
      var r = redirs[target];
      if (!r) { r = redirs[target] = { file: U.abs(sh, target), first: true }; try { if (redir.op === '>') sh.vfs.write(r.file, ''); } catch (e) { throw AwkError("can't redirect to " + target); } }
      try { sh.vfs.write(r.file, text, true); } catch (e2) { throw AwkError("write error on " + target); }
    }
    function exec(s) {
      if (++steps > 2000000) throw AwkError('too many steps');
      switch (s.t) {
        case 'block': for (var k = 0; k < s.stmts.length; k++) exec(s.stmts[k]); return;
        case 'expr': ev(s.e); return;
        case 'print': { var vals = s.args.length ? s.args.map(function (e) { var v = ev(e); return typeof v === 'number' ? numStr(v) : toStr(v); }) : [rec]; output(vals.join(toStr(vars.OFS)) + toStr(vars.ORS), s.redir); return; }
        case 'printf': { var av = s.args.map(ev); output(sprintf(toStr(av[0]), av.slice(1)), s.redir); return; }
        case 'if': if (toBool(ev(s.c))) exec(s.th); else if (s.el) exec(s.el); return;
        case 'while': while (toBool(ev(s.c))) { try { exec(s.body); } catch (e) { if (e === brk) break; if (e === cont) continue; throw e; } } return;
        case 'do': do { try { exec(s.body); } catch (e) { if (e === brk) break; if (e === cont) continue; throw e; } } while (toBool(ev(s.c))); return;
        case 'for': if (s.init) exec(s.init); while (!s.cond || toBool(ev(s.cond))) { try { exec(s.body); } catch (e) { if (e === brk) break; if (e !== cont) throw e; } if (s.step) exec(s.step); } return;
        case 'forin': { var ar = arr(s.arr), keys = Object.keys(ar); for (var q = 0; q < keys.length; q++) { if (ar[keys[q]] === undefined) continue; setVar(s.v, /^-?\d+$/.test(keys[q]) ? new SN(keys[q]) : keys[q]); try { exec(s.body); } catch (e) { if (e === brk) break; if (e !== cont) throw e; } } return; }
        case 'break': throw brk; case 'continue': throw cont; case 'next': throw nextSig;
        case 'exit': if (s.e) status = Math.trunc(toNum(ev(s.e))); throw exitSig;
        case 'return': throw new retSig(s.e ? ev(s.e) : '');
        case 'delete': { var da = arr(s.arr); if (s.idx) delete da[key(s.idx)]; else Object.keys(da).forEach(function (kk) { delete da[kk]; }); return; }
      }
    }

    var exiting = false;
    try {
      try { prog.begin.forEach(function (b) { exec(b); }); } catch (e) { if (e === exitSig) exiting = true; else throw e; }
      if (!exiting && (prog.rules.length || prog.end.length)) {
        var line;
        recLoop: while ((line = nextRecord()) !== null) {
          setRec(line);
          for (var r = 0; r < prog.rules.length; r++) {
            var rule = prog.rules[r], hit;
            try {
              if (!rule.pat) hit = true;
              else if (rule.pat2) {
                if (rule.active) { hit = true; if (toBool(ev(rule.pat2))) rule.active = false; }
                else if (toBool(ev(rule.pat))) { hit = true; if (!toBool(ev(rule.pat2))) rule.active = true; } else hit = false;
              } else hit = toBool(ev(rule.pat));
              if (hit) { if (rule.body) exec(rule.body); else output(rec + toStr(vars.ORS), null); }
            } catch (e) { if (e === nextSig) continue recLoop; if (e === exitSig) { exiting = true; break recLoop; } throw e; }
          }
        }
      }
      try { prog.end.forEach(function (b) { exec(b); }); } catch (e) { if (e !== exitSig) throw e; }
    } catch (e) {
      if (e && e.awk) { io.err.w('awk: ' + e.message + '\n'); status = 2; }
      else if (e === brk || e === cont || e === nextSig) { }
      else throw e;
    }
    Object.keys(redirs).forEach(function (k) {
      var rd = redirs[k];
      if (rd.cmd) { var sub = sh.fork(); sub.runString(rd.cmd, { in: new root.RCShell.Stream(rd.buf), out: io.out, err: io.err }); }
    });
    return status;
  }

  U.def('awk gawk mawk', run);
})(typeof globalThis !== 'undefined' ? globalThis : window);
