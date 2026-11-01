/* Radio-Comète — shell arithmetic: $(( )), (( )), let, array subscripts.

   ctx: { get(name) → string|undefined, set(name, value:number), index(name, sub) → string|undefined,
          setIndex(name, sub, value), expand(str) → string } — the shell. Integers only, like Bash. */
(function (root) {
  'use strict';

  function ArithError(msg) { var e = new Error(msg); e.arith = true; return e; }

  function tokenize(s) {
    var toks = [], i = 0, m;
    while (i < s.length) {
      var c = s[i];
      if (/\s/.test(c)) { i++; continue; }
      if (/[0-9]/.test(c)) {
        m = /^(0[xX][0-9a-fA-F]+|[0-9]+#[0-9a-zA-Z]+|[0-9]+)/.exec(s.slice(i));
        toks.push({ k: 'n', v: m[1] }); i += m[1].length; continue;
      }
      if (/[A-Za-z_]/.test(c)) {
        m = /^[A-Za-z_][A-Za-z0-9_]*/.exec(s.slice(i));
        var name = m[0]; i += name.length;
        var sub = null;
        if (s[i] === '[') {
          var depth = 0, j = i;
          for (; j < s.length; j++) { if (s[j] === '[') depth++; else if (s[j] === ']') { depth--; if (!depth) break; } }
          sub = s.slice(i + 1, j); i = j + 1;
        }
        toks.push({ k: 'id', v: name, sub: sub }); continue;
      }
      var three = s.substr(i, 3), two = s.substr(i, 2);
      if (three === '<<=' || three === '>>=') { toks.push({ k: 'op', v: three }); i += 3; continue; }
      if (['**', '<<', '>>', '<=', '>=', '==', '!=', '&&', '||', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '++', '--'].indexOf(two) !== -1) { toks.push({ k: 'op', v: two }); i += 2; continue; }
      if ('+-*/%&|^~!<>=?:(),'.indexOf(c) !== -1) { toks.push({ k: 'op', v: c }); i++; continue; }
      throw ArithError('syntax error: operand expected (error token is "' + s.slice(i) + '")');
    }
    return toks;
  }

  function parseNum(v) {
    if (/^0[xX]/.test(v)) return parseInt(v, 16);
    if (/#/.test(v)) { var p = v.split('#'); return parseInt(p[1], +p[0]); }
    if (/^0[0-7]+$/.test(v)) return parseInt(v, 8);
    if (/^0[0-9]+$/.test(v)) throw ArithError(v + ': value too great for base (error token is "' + v + '")');
    return parseInt(v, 10);
  }

  function evaluate(ctx, src) {
    var text = ctx.expand(src);
    if (/^\s*$/.test(text)) return 0;
    var toks = tokenize(text), p = 0;
    function peek() { return toks[p]; }
    function isOp(v) { var t = toks[p]; return t && t.k === 'op' && t.v === v; }
    function val(name, sub) {
      var raw = sub === null ? ctx.get(name) : ctx.index(name, sub === null ? '0' : String(evaluateSub(sub)));
      if (raw === undefined || raw === '') return 0;
      if (/^\s*-?[0-9]+\s*$/.test(raw)) return parseInt(raw, 10);
      return evaluate(ctx, raw);
    }
    function evaluateSub(sub) { return ctx.assoc && ctx.assoc(sub) ? sub : evaluate(ctx, sub); }
    function assign(t, v) { if (t.sub === null) ctx.set(t.v, v); else ctx.setIndex(t.v, ctx.isAssoc && ctx.isAssoc(t.v) ? ctx.expand(t.sub) : String(evaluate(ctx, t.sub)), v); return v; }

    function comma() { var v = assignExpr(); while (isOp(',')) { p++; v = assignExpr(); } return v; }
    function assignExpr() {
      var t = peek();
      if (t && t.k === 'id') {
        var nx = toks[p + 1];
        if (nx && nx.k === 'op' && /^(=|\+=|-=|\*=|\/=|%=|<<=|>>=|&=|\|=|\^=)$/.test(nx.v)) {
          p += 2;
          var rhs = assignExpr(), cur = nx.v === '=' ? 0 : val(t.v, t.sub), op = nx.v.slice(0, -1);
          return assign(t, nx.v === '=' ? rhs : binop(op, cur, rhs));
        }
      }
      return ternary();
    }
    function ternary() {
      var c = lor();
      if (isOp('?')) { p++; var a = assignExpr(); if (!isOp(':')) throw ArithError('syntax error: `:\' expected for conditional expression'); p++; var b = assignExpr(); return c ? a : b; }
      return c;
    }
    function lor() { var v = land(); while (isOp('||')) { p++; var r = land(); v = (v || r) ? 1 : 0; } return v; }
    function land() { var v = bor(); while (isOp('&&')) { p++; var r = bor(); v = (v && r) ? 1 : 0; } return v; }
    function bor() { var v = bxor(); while (isOp('|')) { p++; v = v | bxor(); } return v; }
    function bxor() { var v = band(); while (isOp('^')) { p++; v = v ^ band(); } return v; }
    function band() { var v = eq(); while (isOp('&')) { p++; v = v & eq(); } return v; }
    function eq() { var v = rel(); while (isOp('==') || isOp('!=')) { var o = toks[p++].v, r = rel(); v = (o === '==' ? v === r : v !== r) ? 1 : 0; } return v; }
    function rel() { var v = shift(); while (isOp('<') || isOp('>') || isOp('<=') || isOp('>=')) { var o = toks[p++].v, r = shift(); v = (o === '<' ? v < r : o === '>' ? v > r : o === '<=' ? v <= r : v >= r) ? 1 : 0; } return v; }
    function shift() { var v = add(); while (isOp('<<') || isOp('>>')) { var o = toks[p++].v, r = add(); v = o === '<<' ? v << r : v >> r; } return v; }
    function add() { var v = mul(); while (isOp('+') || isOp('-')) { var o = toks[p++].v, r = mul(); v = o === '+' ? v + r : v - r; } return v; }
    function mul() { var v = pow(); while (isOp('*') || isOp('/') || isOp('%')) { var o = toks[p++].v, r = pow(); v = binop(o, v, r); } return v; }
    function pow() { var v = unary(); if (isOp('**')) { p++; var r = pow(); return Math.pow(v, r); } return v; }
    function binop(o, a, b) {
      switch (o) {
        case '+': return a + b; case '-': return a - b; case '*': return a * b;
        case '/': if (b === 0) throw ArithError('division by 0 (error token is "' + b + '")'); return Math.trunc(a / b);
        case '%': if (b === 0) throw ArithError('division by 0 (error token is "' + b + '")'); return a % b;
        case '<<': return a << b; case '>>': return a >> b; case '&': return a & b; case '|': return a | b; case '^': return a ^ b;
      }
      return 0;
    }
    function unary() {
      var t = peek();
      if (!t) throw ArithError('syntax error: operand expected (error token is "")');
      if (t.k === 'op') {
        if (t.v === '-') { p++; return -unary(); }
        if (t.v === '+') { p++; return unary(); }
        if (t.v === '!') { p++; return unary() ? 0 : 1; }
        if (t.v === '~') { p++; return ~unary(); }
        if (t.v === '++' || t.v === '--') { p++; var id = toks[p++]; if (!id || id.k !== 'id') throw ArithError('syntax error: operand expected'); var nv = val(id.v, id.sub) + (t.v === '++' ? 1 : -1); return assign(id, nv); }
        if (t.v === '(') { p++; var v = comma(); if (!isOp(')')) throw ArithError('syntax error: missing `)\''); p++; return v; }
        throw ArithError('syntax error: operand expected (error token is "' + t.v + '")');
      }
      p++;
      if (t.k === 'n') return parseNum(t.v);
      var cur = val(t.v, t.sub);
      if (isOp('++') || isOp('--')) { var o = toks[p++].v; assign(t, cur + (o === '++' ? 1 : -1)); }
      return cur;
    }
    var result = comma();
    if (p < toks.length) throw ArithError('syntax error in expression (error token is "' + toks[p].v + '")');
    return result;
  }

  root.RCArith = { evaluate: evaluate };
})(typeof globalThis !== 'undefined' ? globalThis : window);
