/* Service de nuit — the PHP interpreter: the students' pages run here, in the browser, with PHP 8.5's
   behaviour (output, warnings, fatal errors, line numbers) as tool/phptest.js checks it against the
   real thing.

   SDNPhp.run({ files, entry, append?, now?, get?, globals?, seed?, maxSteps?, trace? })
     → { output, segments: [{ text, file, line, php }], errors: [{ level, message, file, line, fr }],
         fatal, steps, trace: [{ file, line, vars }] }
   SDNPhp.tokens(src) → [{ type, text, line }]   (syntax highlighting; the texts add up to src)

   Strings are PHP's: bytes. A source file is turned into a "byte string" (one JS char per byte,
   UTF-8 encoded) before lexing, so strlen('é') is 2 and substr() can cut a character in half exactly
   as PHP does; mb_* functions decode. The output is decoded back to text at the end.

   Values: null, true/false, int (a JS number when it is a safe integer, a BigInt beyond 2^53 up to
   PHP_INT_MAX, a float past it), float (Flt, so that 2.0 stays a float), string (byte string),
   array (PArr: an ordered map with copy-on-write — `refs` counts the places holding it, a write
   through a place separates it first when it is shared), Closure. A Ref is a variable slot; an array
   element holds a Ref only once something took a reference to it (foreach by reference, &$a['k']). */
(function (root) {
  'use strict';

  /* ---------------------------------------------------------------- byte strings */

  var fcc = String.fromCharCode;
  var NON_ASCII = /[^\x00-\x7f]/;

  /** JS text → byte string (UTF-8). */
  function utf8(s) {
    if (!NON_ASCII.test(s)) return s;
    var out = '';
    for (var i = 0; i < s.length; i++) {
      var c = s.charCodeAt(i);
      if (c < 0x80) { out += s[i]; continue; }
      if (c >= 0xd800 && c < 0xdc00 && i + 1 < s.length) {
        var d = s.charCodeAt(i + 1);
        if (d >= 0xdc00 && d < 0xe000) { c = 0x10000 + ((c - 0xd800) << 10) + (d - 0xdc00); i++; }
      }
      if (c < 0x800) out += fcc(0xc0 | c >> 6, 0x80 | c & 63);
      else if (c < 0x10000) out += fcc(0xe0 | c >> 12, 0x80 | c >> 6 & 63, 0x80 | c & 63);
      else out += fcc(0xf0 | c >> 18, 0x80 | c >> 12 & 63, 0x80 | c >> 6 & 63, 0x80 | c & 63);
    }
    return out;
  }
  /** Byte string → JS text; an invalid sequence becomes U+FFFD (WHATWG "maximal subpart", as a browser shows it). */
  function unutf8(b) {
    if (!NON_ASCII.test(b)) return b;
    var out = '', i = 0, n = b.length;
    while (i < n) {
      var c = b.charCodeAt(i);
      if (c < 0x80) { out += b[i++]; continue; }
      var need, cp, lo = 0x80, hi = 0xbf;
      if (c >= 0xc2 && c <= 0xdf) { need = 1; cp = c & 0x1f; }
      else if (c >= 0xe0 && c <= 0xef) { need = 2; cp = c & 0xf; if (c === 0xe0) lo = 0xa0; if (c === 0xed) hi = 0x9f; }
      else if (c >= 0xf0 && c <= 0xf4) { need = 3; cp = c & 7; if (c === 0xf0) lo = 0x90; if (c === 0xf4) hi = 0x8f; }
      else { out += '�'; i++; continue; }
      var j = i + 1, ok = true;
      while (need > 0) {
        var d = j < n ? b.charCodeAt(j) : -1;
        if (d < lo || d > hi) { ok = false; break; }
        lo = 0x80; hi = 0xbf; cp = cp << 6 | d & 0x3f; j++; need--;
      }
      out += ok ? String.fromCodePoint(cp) : '�';
      i = j;
    }
    return out;
  }
  /** Byte string → array of code points (mb_*); an invalid byte counts as one character of its own. */
  function cps(b) {
    var t = unutf8(b), out = [];
    for (var i = 0; i < t.length; i++) {
      var c = t.codePointAt(i);
      out.push(c);
      if (c > 0xffff) i++;
    }
    return out;
  }
  function fromCps(a) { var s = ''; for (var i = 0; i < a.length; i++) s += String.fromCodePoint(a[i]); return utf8(s); }
  function repeat(s, n) { var out = ''; while (n > 0) { if (n & 1) out += s; s += s; n >>= 1; } return out; }

  /* ---------------------------------------------------------------- values */

  function Flt(v) { this.v = v; }
  function Ref(v) { this.v = v; this.n = 1; }       // n: how many places hold it (variables, array elements)
  function Closure(decl, bound, name) { this.decl = decl; this.bound = bound; this.name = name || '{closure}'; }

  var MAXS = 9007199254740991;
  var BIG = typeof BigInt === 'function';
  var I64MAX = BIG ? BigInt('9223372036854775807') : null, I64MIN = BIG ? BigInt('-9223372036854775808') : null;
  var BMAXS = BIG ? BigInt(MAXS) : null, B0 = BIG ? BigInt(0) : null, B1 = BIG ? BigInt(1) : null;

  /** The PHP array: an ordered map (int keys are JS numbers or BigInts, string keys are strings). */
  function PArr() { this.m = new Map(); this.nx = null; this.refs = 0; }
  PArr.prototype.get = function (k) { var v = this.m.get(k); return v instanceof Ref ? v.v : v; };
  PArr.prototype.has = function (k) { return this.m.has(k); };
  PArr.prototype.set = function (k, v) {
    var o = this.m.get(k);
    if (o instanceof Ref) o.v = v; else this.m.set(k, v);
    if (typeof k !== 'string' && (this.nx === null || k >= this.nx)) this.nx = typeof k === 'number' ? k + 1 : k + B1;
  };
  /** Appends; false when the next key would pass PHP_INT_MAX. */
  PArr.prototype.push = function (v) {
    var k = this.nx === null ? 0 : this.nx;
    if (typeof k === 'bigint') { if (k > I64MAX) return false; if (k <= BMAXS) k = Number(k); }
    else if (k > MAXS) k = BigInt(k);
    this.m.set(k, v);
    this.nx = typeof k === 'number' ? k + 1 : k + B1;
    return true;
  };
  PArr.prototype.del = function (k) { var o = this.m.get(k); if (o instanceof Ref) o.n--; this.m.delete(k); };
  PArr.prototype.ref = function (k) {
    var o = this.m.get(k);
    if (o instanceof Ref) return o;
    var r = new Ref(o === undefined ? null : o);
    this.m.set(k, r);
    if (o === undefined && typeof k !== 'string' && (this.nx === null || k >= this.nx)) this.nx = typeof k === 'number' ? k + 1 : k + B1;
    return r;
  };
  /** A separate copy (the elements that are arrays become shared by both). */
  PArr.prototype.clone = function () {
    var a = new PArr();
    this.m.forEach(function (v, k) {
      if (v instanceof Ref) { if (v.n <= 1) v = v.v; else v.n++; }
      if (v instanceof PArr) v.refs++;
      a.m.set(k, v);
    });
    a.nx = this.nx;
    return a;
  };
  PArr.prototype.vals = function () { var o = []; this.m.forEach(function (v) { o.push(v instanceof Ref ? v.v : v); }); return o; };
  PArr.prototype.keys = function () { return Array.from(this.m.keys()); };
  function list(vals) { var a = new PArr(); for (var i = 0; i < vals.length; i++) { share(vals[i]); a.m.set(i, vals[i]); } a.nx = vals.length; return a; }
  /** Stores a value somewhere new: an array is now held by one more place. */
  function share(v) { if (v instanceof PArr) v.refs++; return v; }

  function isInt(v) { return typeof v === 'number' || typeof v === 'bigint'; }
  function isNum(v) { return typeof v === 'number' || typeof v === 'bigint' || v instanceof Flt; }
  function fv(x) { return x instanceof Flt ? x.v : Number(x); }
  /** Exact BigInt result → PHP value; past the int range it is the float `d` PHP computes instead. */
  function ovf(b, d) { if (b > I64MAX || b < I64MIN) return new Flt(d); return b <= BMAXS && b >= -BMAXS ? Number(b) : b; }
  function bigNorm(b) { return ovf(b, Number(b)); }
  function tname(v) {
    if (v === null || v === undefined) return 'null';
    switch (typeof v) { case 'boolean': return 'bool'; case 'number': case 'bigint': return 'int'; case 'string': return 'string'; }
    if (v instanceof Flt) return 'float';
    if (v instanceof PArr) return 'array';
    return 'Closure';
  }
  function gettype(v) {
    return { 'null': 'NULL', bool: 'boolean', 'int': 'integer', 'float': 'double', string: 'string', array: 'array' }[tname(v)] || 'object';
  }

  /* ---------------------------------------------------------------- numbers */

  var NUM_RE = /^[ \t\n\r\v\f]*([+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)/;
  var WS_RE = /^[ \t\n\r\v\f]*$/;
  /** PHP's numeric string: null when not even a prefix is numeric, else { v, whole } (whole: nothing but spaces after). */
  function numStr(s) {
    var m = NUM_RE.exec(s);
    if (!m) return null;
    var t = m[1], v;
    if (/[.eE]/.test(t)) v = new Flt(parseFloat(t));
    else v = intStr(t);
    return { v: v, whole: WS_RE.test(s.slice(m[0].length)) };
  }
  /** Decimal digits (with sign) → int, or float past the int range. */
  function intStr(t) {
    var neg = t[0] === '-', d = t.replace(/^[+-]/, '').replace(/^0+(?=\d)/, '');
    if (d.length <= 15) { var n = parseInt(d, 10); return neg ? -n || 0 : n; }
    var b = BigInt(d); if (neg) b = -b;
    if (b > I64MAX || b < I64MIN) return new Flt(parseFloat(t));
    return bigNorm(b);
  }
  var INTKEY_RE = /^(?:0|-?[1-9][0-9]*)$/;
  /** "5" → 5 as an array key; "05", "5 ", "-0" stay strings. */
  function strKey(s) {
    if (s.length > 20 || !INTKEY_RE.test(s)) return s;
    if (s.length < 16) return parseInt(s, 10);
    var b = BigInt(s);
    if (b > I64MAX || b < I64MIN) return s;
    return bigNorm(b);
  }

  /* ---------------------------------------------------------------- float formatting */

  function zeros(n) { return n > 0 ? repeat('0', n) : ''; }
  /** Shortest round-trip digits of x > 0: { d: '12345', e: decimal point position }. */
  function shortest(x) {
    var m = /^(\d)(?:\.(\d+))?e([+-]\d+)$/.exec(x.toExponential());
    return { d: (m[1] + (m[2] || '')).replace(/0+$/, '') || '0', e: +m[3] + 1 };
  }
  /** x > 0 rounded to p significant digits, ties to even (zend_dtoa mode 2), trailing zeros dropped. */
  function sigDigits(x, p) {
    var m = /^(\d)(?:\.(\d+))?e([+-]\d+)$/.exec(x.toExponential(p - 1));
    var d = m[1] + (m[2] || ''), e = +m[3] + 1;
    if (p < 100) {
      var t = x.toExponential(p);
      if (t[t.indexOf('e') - 1] === '5') {
        var f = /^(\d)(?:\.(\d+))?e([+-]\d+)$/.exec(x.toExponential(100)), all = f[1] + (f[2] || '');
        if (/^50*$/.test(all.slice(p)) && +all[p - 1] % 2 === 0) { d = all.slice(0, p); e = +f[3] + 1; }
      }
    }
    return { d: d.replace(/0+$/, '') || '0', e: e };
  }
  /** PHP's float → string: precision 14 for echo, 17 (shortest, serialize_precision -1) for var_dump/var_export. */
  function fmtFloat(d, prec) {
    if (d !== d) return 'NAN';
    if (d === Infinity) return 'INF';
    if (d === -Infinity) return '-INF';
    if (d === 0) return 1 / d < 0 ? '-0' : '0';
    var neg = d < 0, x = neg ? -d : d, r = prec === 17 ? shortest(x) : sigDigits(x, prec), s, dg = r.d, e = r.e;
    if (e < 0 ? e < -3 : e > prec) s = dg[0] + '.' + (dg.length > 1 ? dg.slice(1) : '0') + 'E' + (e - 1 < 0 ? '-' : '+') + Math.abs(e - 1);
    else if (e <= 0) s = '0.' + zeros(-e) + dg;
    else if (dg.length <= e) s = dg + zeros(e - dg.length);
    else s = dg.slice(0, e) + '.' + dg.slice(e);
    return (neg ? '-' : '') + s;
  }
  /** x ≥ 0 with exactly n decimals, ties to even (printf's %.nf). */
  function toFixedEven(x, n) {
    if (x >= 1e21) return BigInt(x).toString() + (n ? '.' + zeros(n) : '');
    var s = x.toFixed(n), t = x.toFixed(Math.min(100, n + 30)), dot = t.indexOf('.');
    if (/^50*$/.test(t.slice(dot + 1 + n))) {
      var head = t.slice(0, n ? dot + 1 + n : dot);
      if (+head[head.length - 1] % 2 === 0) s = head;
    }
    return s;
  }
  /** round() of PHP 8.4+ (math.c: pre-rounding through the edge case, half away from zero by default). */
  function phpRound(value, places, mode) {
    if (!isFinite(value) || value === 0) return value;
    var exp = Math.pow(10, Math.abs(places)), tmp, tmp2;
    if (value >= 0) { tmp = Math.floor(places > 0 ? value * exp : value / exp); tmp2 = tmp + 1; }
    else { tmp = Math.ceil(places > 0 ? value * exp : value / exp); tmp2 = tmp - 1; }
    if ((places > 0 ? tmp2 / exp : tmp2 * exp) === value) tmp = tmp2;
    if (Math.abs(tmp) >= 1e16) return value;
    var sgn = value < 0 ? -1 : 1;
    var edge = Math.abs(places > 0 ? (tmp + 0.5 * sgn) / exp : (tmp + 0.5 * sgn) * exp), va = Math.abs(value);
    mode = mode || 1;
    if (mode === 1) { if (va >= edge) tmp += sgn; }
    else if (mode === 2) { if (va > edge) tmp += sgn; }
    else if (va > edge) tmp += sgn;
    else if (va === edge) { var odd = Math.abs(tmp) % 2 === 1; if ((mode === 3 && odd) || (mode === 4 && !odd)) tmp += sgn; }
    var r = places > 0 ? tmp / exp : tmp * exp;
    return r === 0 && value < 0 ? -0 : r;
  }

  /* ---------------------------------------------------------------- lexer */

  var KEYWORDS = {};
  ('abstract and array as break callable case catch class clone const continue declare default die do echo else elseif ' +
    'empty enddeclare endfor endforeach endif endswitch endwhile eval exit extends final finally fn for foreach function ' +
    'global goto if implements include include_once instanceof insteadof interface isset list match namespace new or ' +
    'print private protected public readonly require require_once return static switch throw trait try unset use var ' +
    'while xor yield').split(' ').forEach(function (k) { KEYWORDS[k] = true; });
  var CASTS = { 'int': 'int', integer: 'int', bool: 'bool', 'boolean': 'bool', 'float': 'float', 'double': 'float',
    string: 'string', binary: 'string', array: 'array', object: 'object', unset: 'unset' };
  var OPS3 = ['<<=', '>>=', '**=', '...', '<=>', '===', '!==', '??=', '?->'];
  var OPS2 = ['**', '++', '--', '->', '=>', '::', '==', '!=', '<>', '<=', '>=', '&&', '||', '??', '+=', '-=', '*=', '/=',
    '.=', '%=', '&=', '|=', '^=', '<<', '>>'];
  var NAME_START = /[A-Za-z_\x80-\xff]/, NAME_RE = /[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/y;
  var NUM_TOK = /0[xX][0-9a-fA-F_]+|0[bB][01_]+|0[oO][0-7_]+|(?:[0-9][0-9_]*)?\.[0-9][0-9_]*(?:[eE][+-]?[0-9][0-9_]*)?|[0-9][0-9_]*\.?(?:[eE][+-]?[0-9][0-9_]*)?/y;
  var CAST_RE = /\([ \t]*([A-Za-z]+)[ \t]*\)/y;
  var HEREDOC_RE = /<<<[ \t]*(["']?)([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\1(\r?\n)/y;
  var CLOSERS = { ')': '(', ']': '[', '}': '{' };

  function nl(s) { var n = 0, i = -1; while ((i = s.indexOf('\n', i + 1)) >= 0) n++; return n; }
  var LEXNOTES = [];                  // compile-time notices met while lexing the current file

  /** Lexes from `i` in `src`; tokens: { t, v, line, raw } — t: html var id kw int float str tpl strcontent cast op eof err. */
  function Lexer(src, line) { this.src = src; this.i = 0; this.line = line || 1; this.toks = []; this.stack = []; }
  Lexer.prototype.tok = function (t, v, line, raw) { var o = { t: t, v: v, line: line, raw: raw }; this.toks.push(o); return o; };
  Lexer.prototype.err = function (msg, line) { this.tok('err', msg, line, ''); return 'err'; };
  /** The whole file: inline HTML, then PHP after <?php / <?= until ?>. */
  Lexer.prototype.file = function () {
    var src = this.src, n = src.length;
    for (;;) {
      var k = this.i, open = -1, echo = false;
      while ((k = src.indexOf('<?', k)) >= 0) {
        if (src[k + 2] === '=') { open = k; echo = true; break; }
        if (src.substr(k + 2, 3).toLowerCase() === 'php' && (k + 5 >= n || /[ \t\r\n]/.test(src[k + 5]))) { open = k; break; }
        k += 2;
      }
      var end = open < 0 ? n : open;
      if (end > this.i) { var h = src.slice(this.i, end); this.tok('html', h, this.line, h); this.line += nl(h); }
      if (open < 0) break;
      this.i = open + (echo ? 3 : 5);
      if (echo) this.tok('kw', 'echo', this.line, '<?=');
      else if (this.i < n) {
        if (src[this.i] === '\r' && src[this.i + 1] === '\n') { this.i += 2; this.line++; }
        else { if (src[this.i] === '\n' || src[this.i] === '\r') this.line++; this.i++; }
      }
      var r = this.php(false);
      if (r !== 'close') { if (r === 'err') return this.toks; break; }
    }
    if (this.stack.length) { var top = this.stack[this.stack.length - 1]; this.err("Unclosed '" + top.c + "' on line " + top.line, this.line); }
    else this.tok('eof', null, this.line, '');
    return this.toks;
  };
  /** PHP code until ?> ('close'), the end ('eof'), an error ('err'), or — inside "{$…}" — the brace closing it ('brace'). */
  Lexer.prototype.php = function (inBrace) {
    var src = this.src, n = src.length, depth = 0;
    while (this.i < n) {
      var i = this.i, c = src[i], line = this.line, m;
      if (c === ' ' || c === '\t' || c === '\n' || c === '\r' || c === '\v' || c === '\f') {
        var j = i; while (j < n && /[ \t\n\r\v\f]/.test(src[j])) j++;
        this.line += nl(src.slice(i, j)); this.i = j; continue;
      }
      if (c === '?' && src[i + 1] === '>' && !inBrace) {
        this.tok('op', ';', line, '?>').close = true;
        this.i = i + 2;
        if (src[this.i] === '\n') { this.i++; this.line++; }
        else if (src[this.i] === '\r') { this.i += src[this.i + 1] === '\n' ? 2 : 1; this.line++; }
        return 'close';
      }
      if (c === '#' || (c === '/' && src[i + 1] === '/')) {
        var e = i; while (e < n && src[e] !== '\n' && !(src[e] === '?' && src[e + 1] === '>')) e++;
        this.i = e; continue;
      }
      if (c === '/' && src[i + 1] === '*') {
        var ce = src.indexOf('*/', i + 2);
        if (ce < 0) return this.err('Unterminated comment starting line ' + line, line);
        this.line += nl(src.slice(i, ce)); this.i = ce + 2; continue;
      }
      if (c === '$' && NAME_START.test(src[i + 1] || '')) {
        NAME_RE.lastIndex = i + 1; m = NAME_RE.exec(src);
        this.tok('var', m[0], line, '$' + m[0]); this.i = i + 1 + m[0].length; continue;
      }
      if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(src[i + 1] || ''))) {
        NUM_TOK.lastIndex = i; m = NUM_TOK.exec(src);
        var raw = m[0], clean = raw.replace(/_/g, '');
        if (/^0[xXbBoO]/.test(clean) || (/^0[0-7]+$/.test(clean))) {
          var b = BigInt(/^0[0-7]+$/.test(clean) ? '0o' + clean.slice(1) : clean.toLowerCase());
          this.tok('int', b > I64MAX ? new Flt(Number(b)) : bigNorm(b), line, raw);
        } else if (/[.eE]/.test(clean)) this.tok('float', new Flt(parseFloat(clean)), line, raw);
        else { var iv = intStr(clean); this.tok(iv instanceof Flt ? 'float' : 'int', iv, line, raw); }
        this.i = i + raw.length; continue;
      }
      if (NAME_START.test(c) || (c === '\\' && NAME_START.test(src[i + 1] || ''))) {
        NAME_RE.lastIndex = c === '\\' ? i + 1 : i; m = NAME_RE.exec(src);
        var word = m[0], low = word.toLowerCase(), len = word.length + (c === '\\' ? 1 : 0);
        while (src[i + len] === '\\' && NAME_START.test(src[i + len + 1] || '')) { NAME_RE.lastIndex = i + len + 1; var w2 = NAME_RE.exec(src)[0]; word = w2; len += 1 + w2.length; }
        if (c !== '\\' && KEYWORDS[low] && word === m[0]) this.tok('kw', low === 'die' ? 'exit' : low, line, src.slice(i, i + len));
        else this.tok('id', word, line, src.slice(i, i + len));
        this.i = i + len; continue;
      }
      if (c === "'") {
        var s = '', k = i + 1;
        for (;;) {
          if (k >= n) { this.tok('strcontent', null, line, src.slice(i)); this.i = n; this.line += nl(src.slice(i)); return 'eof'; }
          var ch = src[k];
          if (ch === "'") break;
          if (ch === '\\' && (src[k + 1] === "'" || src[k + 1] === '\\')) { s += src[k + 1]; k += 2; continue; }
          s += ch; k++;
        }
        var sraw = src.slice(i, k + 1);
        this.tok('str', s, line, sraw).q = "'";
        this.line += nl(sraw); this.i = k + 1; continue;
      }
      if (c === '"') {
        this.i = i + 1;
        var parts = this.dq('"');
        if (parts === 'err') return 'err';
        if (parts === null) return this.err('syntax error, unexpected end of file, expecting variable or "${" or "{$"', this.line);
        this.strTok(parts, line, src.slice(i, this.i), '"');
        continue;
      }
      if (c === '<' && src.substr(i, 3) === '<<<') {
        HEREDOC_RE.lastIndex = i; m = HEREDOC_RE.exec(src);
        if (m && this.heredoc(m, line) === 'err') return 'err';
        if (m) continue;
      }
      if (c === '(') {
        CAST_RE.lastIndex = i; m = CAST_RE.exec(src);
        if (m && CASTS[m[1].toLowerCase()]) { this.tok('cast', CASTS[m[1].toLowerCase()], line, m[0]); this.i = i + m[0].length; continue; }
      }
      var op = null, t3 = src.substr(i, 3), t2 = src.substr(i, 2);
      if (OPS3.indexOf(t3) >= 0) op = t3; else if (OPS2.indexOf(t2) >= 0) op = t2; else op = c;
      if (op === '{' && inBrace) depth++;
      if (op === '}' && inBrace) { if (depth === 0) { this.i = i + 1; return 'brace'; } depth--; }
      if (op === '(' || op === '[' || op === '{') this.stack.push({ c: op, line: line });
      else if (CLOSERS[op]) {
        var top = this.stack[this.stack.length - 1];
        if (!top) return this.err("Unmatched '" + op + "'", line);
        if (top.c !== CLOSERS[op]) return this.err("Unclosed '" + top.c + "' on line " + top.line + " does not match '" + op + "'", line);
        this.stack.pop();
      }
      if (!/[-+*\/%=<>!.()\[\]{},;?:&|^~@$"`]/.test(op[0])) return this.err('syntax error, unexpected character 0x' + ('0' + c.charCodeAt(0).toString(16).toUpperCase()).slice(-2), line);
      this.tok('op', op, line, op);
      this.i = i + op.length;
    }
    return 'eof';
  };
  Lexer.prototype.strTok = function (parts, line, raw, q) {
    var lit = true;
    for (var k = 0; k < parts.length; k++) if (typeof parts[k] !== 'string') lit = false;
    if (lit) this.tok('str', parts.join(''), line, raw).q = q;
    else this.tok('tpl', parts, line, raw);
  };
  /** The body of a "…" string (or of a heredoc when quote is null): literal pieces and the token lists of interpolations. */
  Lexer.prototype.dq = function (quote) {
    var src = this.src, n = src.length, parts = [], buf = '', self = this, m;
    function flush() { if (buf) { parts.push(buf); buf = ''; } }
    for (;;) {
      var i = this.i;
      if (i >= n) { if (quote) return null; break; }
      var c = src[i];
      if (c === quote) { this.i = i + 1; break; }
      if (c === '\\' && i + 1 < n) {
        var d = src[i + 1], k = i + 2;
        if (d === 'n') buf += '\n'; else if (d === 't') buf += '\t'; else if (d === 'r') buf += '\r';
        else if (d === 'v') buf += '\v'; else if (d === 'e') buf += '\x1b'; else if (d === 'f') buf += '\f';
        else if (d === '\\') buf += '\\'; else if (d === '$') buf += '$';
        else if (d === '"' && quote) buf += '"';
        else if (d >= '0' && d <= '7') { var o = /[0-7]{1,3}/y; o.lastIndex = i + 1; m = o.exec(src); buf += fcc(parseInt(m[0], 8) & 255); k = i + 1 + m[0].length; }
        else if (d === 'x' && /[0-9A-Fa-f]/.test(src[i + 2] || '')) { var x = /[0-9A-Fa-f]{1,2}/y; x.lastIndex = i + 2; m = x.exec(src); buf += fcc(parseInt(m[0], 16)); k = i + 2 + m[0].length; }
        else if (d === 'u' && src[i + 2] === '{') {
          var u = /\{([0-9A-Fa-f]+)\}/y; u.lastIndex = i + 2; m = u.exec(src);
          if (!m || parseInt(m[1], 16) > 0x10ffff) return this.err('Invalid UTF-8 codepoint escape sequence' + (m ? ': Codepoint too large' : ''), this.line);
          buf += utf8(String.fromCodePoint(parseInt(m[1], 16))); k = i + 2 + m[0].length;
        } else { buf += '\\' + d; if (d === '\n') this.line++; }
        this.i = k; continue;
      }
      if (c === '$' && NAME_START.test(src[i + 1] || '')) {
        flush();
        NAME_RE.lastIndex = i + 1; m = NAME_RE.exec(src);
        var line = this.line, toks = [{ t: 'var', v: m[0], line: line, raw: '$' + m[0] }];
        this.i = i + 1 + m[0].length;
        if (src[this.i] === '[') {
          var j = this.i + 1, key = null;
          var km = /-?[0-9]+/y; km.lastIndex = j; m = km.exec(src);
          if (m) key = { t: 'int', v: /^(0|-?[1-9][0-9]*)$/.test(m[0]) ? strKey(m[0]) : m[0], line: line, raw: m[0] };
          else if (NAME_START.test(src[j] || '')) { NAME_RE.lastIndex = j; m = NAME_RE.exec(src); key = { t: 'str', v: m[0], line: line, raw: m[0], q: "'" }; }
          else if (src[j] === '$' && NAME_START.test(src[j + 1] || '')) { NAME_RE.lastIndex = j + 1; m = NAME_RE.exec(src); key = { t: 'var', v: m[0], line: line, raw: '$' + m[0] }; m[0] = '$' + m[0]; }
          if (key && typeof key.v === 'string' && key.t === 'int') key.t = 'str';
          if (!key) return this.err('syntax error, unexpected string content "", expecting "-" or identifier or variable or number', line);
          if (src[j + m[0].length] !== ']') return this.err('syntax error, unexpected string content "", expecting "]"', line);
          toks.push({ t: 'op', v: '[', line: line, raw: '[' }, key, { t: 'op', v: ']', line: line, raw: ']' });
          this.i = j + m[0].length + 1;
        }
        toks.push({ t: 'eof', line: line, raw: '' });
        parts.push(toks); continue;
      }
      if (c === '{' && src[i + 1] === '$') {
        flush();
        var sub = new Lexer(src, this.line); sub.i = i + 1;
        var r = sub.php(true);
        if (r !== 'brace') {
          var t1 = sub.toks[1];
          if (sub.toks[0] && sub.toks[0].t === 'var' && t1 && t1.t !== 'err' && !(t1.t === 'op' && /^(\[|->|\?->)$/.test(t1.v))) return this.err('syntax error, unexpected ' + tokName(t1) + ', expecting "->" or "?->" or "["', t1.line);
          if (r === 'err') { this.toks.push(sub.toks[sub.toks.length - 1]); return 'err'; }
          return quote ? null : this.err('syntax error, unexpected end of file', sub.line);
        }
        sub.toks.push({ t: 'eof', line: sub.line, raw: '' });
        parts.push(sub.toks); this.i = sub.i; this.line = sub.line; continue;
      }
      if (c === '$' && src[i + 1] === '{') {
        var dm = /\$\{([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\}/y; dm.lastIndex = i; m = dm.exec(src);
        if (m) {
          flush();
          LEXNOTES.push({ level: 'Deprecated', msg: 'Using ${var} in strings is deprecated, use {$var} instead', line: this.line });
          parts.push([{ t: 'var', v: m[1], line: this.line, raw: '$' + m[1] }, { t: 'eof', line: this.line, raw: '' }]);
          this.i = i + m[0].length; continue;
        }
      }
      if (c === '\n') this.line++;
      buf += c; this.i = i + 1;
    }
    flush();
    return parts;
  };
  Lexer.prototype.heredoc = function (m, line) {
    var src = this.src, label = m[2], nowdoc = m[1] === "'", start = this.i + m[0].length, p = start, close = -1, indent = '';
    var lr = new RegExp('[ \\t]*' + label + '(?![A-Za-z0-9_\\x80-\\xff])', 'y');
    while (p <= src.length) {
      lr.lastIndex = p;
      var cm = lr.exec(src);
      if (cm) { close = p; indent = /^[ \t]*/.exec(cm[0])[0]; break; }
      var e = src.indexOf('\n', p);
      if (e < 0) break;
      p = e + 1;
    }
    if (close < 0) return this.err('syntax error, unexpected end of file, expecting variable or heredoc end or "${" or "{$"', this.line + nl(src.slice(this.i)));
    var body = close > start ? src.slice(start, close).replace(/\r?\n$/, '') : '';
    var lines = body.split('\n');
    for (var k = 0; k < lines.length; k++) {
      if (lines[k].slice(0, indent.length) === indent) lines[k] = lines[k].slice(indent.length);
      else if (/^[ \t]*\r?$/.test(lines[k])) lines[k] = lines[k].replace(/^[ \t]*/, '');
      else return this.err('Invalid body indentation level (expecting an indentation level of at least ' + indent.length + ')', line + 1 + k);
    }
    body = lines.join('\n');
    var raw = src.slice(this.i, close + indent.length + label.length);
    this.line = line + 1;
    if (nowdoc) this.tok('str', body, line, raw).q = "'";
    else {
      var sub = new Lexer(body, line + 1), parts = sub.dq(null);
      if (parts === 'err') { this.toks.push(sub.toks[sub.toks.length - 1]); return 'err'; }
      this.strTok(parts, line, raw, '"');
    }
    this.line = line + nl(raw);
    this.i = close + indent.length + label.length;
    return 'ok';
  };

  /** How bison names a token in "syntax error, unexpected …". */
  function tokName(t) {
    function q(raw) {
      var s = raw || '', e = s.indexOf('\n');
      if (e >= 0) s = s.slice(0, e);
      if (s[0] === "'" || s[0] === '"') s = s.slice(1);
      if (s && (s[s.length - 1] === "'" || s[s.length - 1] === '"')) s = s.slice(0, -1);
      return '"' + (s.length > 33 ? s.slice(0, 30) + '...' : s) + '"';
    }
    switch (t.t) {
      case 'eof': return 'end of file';
      case 'var': return 'variable ' + q(t.raw);
      case 'id': return 'identifier ' + q(t.raw);
      case 'kw': return 'token "' + t.v + '"';
      case 'int': return 'integer ' + q(t.raw);
      case 'float': return 'floating-point number ' + q(t.raw);
      case 'str': return (t.raw[0] === "'" ? 'single-quoted string ' : t.raw[0] === '"' ? 'double-quoted string ' : 'heredoc ') + q(t.raw);
      case 'tpl': return t.raw[0] === '"' ? 'token """' : 'heredoc start';
      case 'strcontent': return 'string content ' + q(t.raw);
      case 'html': return 'inline html ' + q(t.raw);
      case 'cast': return 'token "(' + t.v + ')"';
      default: return 'token "' + t.v + '"';
    }
  }

  /* ---------------------------------------------------------------- parser */

  function ParseErr(msg, line, fr) { this.msg = msg; this.line = line; this.fr = fr; }
  function CompileErr(msg, line, fr) { this.msg = msg; this.line = line; this.fr = fr; }

  var BIN = {
    'or': [1, 'L'], 'xor': [2, 'L'], 'and': [3, 'L'], '??': [7, 'R'], '||': [8, 'L'], '&&': [9, 'L'],
    '|': [10, 'L'], '^': [11, 'L'], '&': [12, 'L'],
    '==': [13, 'N'], '!=': [13, 'N'], '<>': [13, 'N'], '===': [13, 'N'], '!==': [13, 'N'], '<=>': [13, 'N'],
    '<': [14, 'N'], '<=': [14, 'N'], '>': [14, 'N'], '>=': [14, 'N'],
    '.': [15, 'L'], '<<': [16, 'L'], '>>': [16, 'L'], '+': [17, 'L'], '-': [17, 'L'],
    '*': [18, 'L'], '/': [18, 'L'], '%': [18, 'L'], 'instanceof': [20, 'N'], '**': [22, 'R']
  };
  var ASSIGN = { '=': 1, '+=': 1, '-=': 1, '*=': 1, '/=': 1, '.=': 1, '%=': 1, '**=': 1, '??=': 1, '&=': 1, '|=': 1, '^=': 1, '<<=': 1, '>>=': 1 };
  var ENDERS = { 'else': 1, 'elseif': 1, 'endif': 1, 'endwhile': 1, 'endfor': 1, 'endforeach': 1, 'endswitch': 1, 'case': 1, 'default': 1, 'enddeclare': 1 };
  var UNSUPPORTED = { 'class': 1, 'new': 1, 'try': 1, 'throw': 1, 'interface': 1, 'trait': 1, 'namespace': 1, 'use': 1,
    'abstract': 1, 'final': 1, 'goto': 1, 'yield': 1, 'clone': 1, 'eval': 1, 'catch': 1, 'finally': 1, 'readonly': 1 };

  function isLval(n) { return n && !n.paren && (n.k === 'var' || n.k === 'dim' || n.k === 'varvar' || n.k === 'list' || n.k === 'prop'); }
  function hasAppend(n) { for (; n && n.k === 'dim'; n = n.base) if (n.key === null) return true; return false; }

  /** Parses a file's tokens: { body, fns (the top-level declarations, bound before the file runs), compileErr }. */
  function parse(toks) {
    var T = toks, p = 0, depth = 0, compileErr = null, brk = [], notes = [], pendingAppend = [];

    function peek() { var t = T[p]; if (t.t === 'err') fail(t.v, t.line, t); return t; }
    function next() { var t = peek(); p++; return t; }
    function isOp(t, v) { return t.t === 'op' && t.v === v; }
    function isKw(t, v) { return t.t === 'kw' && t.v === v; }
    function accept(v) { var t = peek(); if (t.t === 'op' && t.v === v) { p++; return t; } return null; }
    function expect(v, expecting) { var t = peek(); if (t.t === 'op' && t.v === v) { p++; return t; } return unexpected(t, expecting); }
    function fail(msg, line, t) { throw new ParseErr(msg, line, frParse(msg, t || T[p], p > 0 ? T[p - 1] : null)); }
    function unexpected(t, expecting) { fail('syntax error, unexpected ' + tokName(t) + (expecting ? ', expecting ' + expecting : ''), t.line, t); }
    function compile(msg, line) { if (!compileErr) compileErr = new CompileErr(msg, line); }
    function unsupported(t) {
      throw new ParseErr('syntax error, unexpected ' + tokName(t), t.line,
        '« ' + (t.raw || t.v) + ' » n\'est pas pris en charge par le PHP de ce jeu (pas de classes ni d\'exceptions ici) : écris ta page sans.');
    }
    function endStmt(expecting) { if (!accept(';')) unexpected(peek(), expecting); }

    function statements(stop) { var out = []; while (!stop(peek())) out.push(statement()); return out; }
    function block() {
      // a statement, or a { … } block, as the body of if/while/for/foreach
      var s = statement();
      return s.k === 'block' ? s.body : [s];
    }
    function altBody(enders) { return statements(function (t) { return t.t === 'eof' || (t.t === 'kw' && enders.indexOf(t.v) >= 0); }); }
    function endAlt(kw, alts) { var t = peek(); if (!isKw(t, kw)) unexpected(t, t.t === 'eof' ? alts || '"' + kw + '"' : null); p++; endStmt(); }
    function parenExpr() { expect('(', '"("'); var e = expr(0); expect(')'); return e; }

    function statement() {
      var t = peek();
      if (t.t === 'html') { p++; return { k: 'html', text: t.v, line: t.line }; }
      if (t.t === 'op') {
        if (t.v === '{') { p++; depth++; var b = statements(function (x) { return isOp(x, '}') || x.t === 'eof'; }); expect('}'); depth--; return { k: 'block', body: b, line: t.line }; }
        if (t.v === ';') { p++; return { k: 'nop', line: t.line }; }
      }
      if (t.t === 'kw') {
        switch (t.v) {
          case 'echo': {
            p++;
            var args = [expr(0)];
            while (accept(',')) args.push(expr(0));
            endStmt('"," or ";"');
            return { k: 'echo', args: args, line: t.line };
          }
          case 'if': return ifStmt();
          case 'while': {
            p++;
            var wc = parenExpr(); brk.push('loop');
            var wb = accept(':') ? (function () { var b = altBody(['endwhile']); endAlt('endwhile'); return b; })() : block();
            brk.pop();
            return { k: 'while', cond: wc, body: wb, line: t.line };
          }
          case 'do': {
            p++; brk.push('loop');
            var db = block(); brk.pop();
            if (!isKw(peek(), 'while')) unexpected(peek(), '"while"');
            p++;
            var dc = parenExpr();
            endStmt();
            return { k: 'dowhile', cond: dc, body: db, line: t.line };
          }
          case 'for': {
            p++; expect('(', '"("');
            var init = exprList(';'); expect(';', '";"');
            var cond = exprList(';'); expect(';', '";"');
            var step = exprList(')'); expect(')', '")"');
            brk.push('loop');
            var fb = accept(':') ? (function () { var b = altBody(['endfor']); endAlt('endfor'); return b; })() : block();
            brk.pop();
            return { k: 'for', init: init, cond: cond, step: step, body: fb, line: t.line };
          }
          case 'foreach': {
            p++; expect('(', '"("');
            var fe = expr(0);
            if (!isKw(peek(), 'as')) unexpected(peek());
            p++;
            var byRef = !!accept('&'), kt = null, vt = target();
            if (accept('=>')) { kt = vt; if (kt.byRef) unexpected(T[p - 1]); byRef = !!accept('&'); vt = target(); }
            if (!isOp(peek(), ')')) unexpected(peek(), vt.k === 'var' ? '"->" or "?->" or "["' : null);
            p++; brk.push('loop');
            var feb = accept(':') ? (function () { var b = altBody(['endforeach']); endAlt('endforeach'); return b; })() : block();
            brk.pop();
            return { k: 'foreach', e: fe, kt: kt, vt: vt, byRef: byRef, body: feb, line: t.line };
          }
          case 'switch': return switchStmt();
          case 'break': case 'continue': {
            p++;
            var lv = 1, nt = peek();
            if (nt.t === 'int') { p++; lv = nt.v; if (lv < 1) compile("'" + t.v + "' operator accepts only positive integers", t.line); }
            endStmt();
            if (!brk.length) compile("'" + t.v + "' not in the 'loop' or 'switch' context", t.line);
            else if (lv > brk.length) compile("Cannot '" + t.v + "' " + lv + ' levels', t.line);
            else if (t.v === 'continue' && brk[brk.length - lv] === 'switch') {
              var outer = brk.length > lv;
              notes.push({ level: 'Warning', line: t.line, msg: (lv === 1 ? '"continue" targeting switch is equivalent to "break"' : '"continue ' + lv + '" targeting switch is equivalent to "break ' + lv + '"') +
                (outer ? '. Did you mean to use "continue ' + (lv + 1) + '"?' : '') });
            }
            return { k: t.v, n: lv, line: t.line };
          }
          case 'return': {
            p++;
            var re = isOp(peek(), ';') ? null : expr(0);
            endStmt();
            return { k: 'return', e: re, line: t.line };
          }
          case 'function': {
            var la = T[p + 1], la2 = T[p + 2];
            if (la.t === 'kw') { p++; unexpected(la, '"("'); }
            if (la.t === 'id' || (isOp(la, '&') && la2.t === 'id')) {
              p++;
              var d = fnDecl(true);
              return { k: 'fndecl', decl: d, line: t.line };
            }
            break;
          }
          case 'global': {
            p++;
            var names = [];
            do { var gv = peek(); if (gv.t !== 'var') unexpected(gv, 'variable'); p++; names.push(gv.v); } while (accept(','));
            endStmt('"," or ";"');
            return { k: 'global', names: names, line: t.line };
          }
          case 'static': {
            if (T[p + 1].t !== 'var') break;
            p++;
            var vars = [];
            do {
              var sv = next(); if (sv.t !== 'var') unexpected(sv, 'variable');
              vars.push({ name: sv.v, init: accept('=') ? expr(0) : null });
            } while (accept(','));
            endStmt('"," or ";"');
            return { k: 'static', vars: vars, line: t.line, cells: null };
          }
          case 'unset': {
            p++; expect('(', '"("');
            var targets = [];
            if (!isOp(peek(), ')')) { do { if (isOp(peek(), ')')) break; targets.push(expr(0)); } while (accept(',')); }
            expect(')', '")"');
            endStmt();
            return { k: 'unset', targets: targets, line: t.line };
          }
          case 'const': {
            p++;
            var items = [];
            do {
              var cn = next(); if (cn.t !== 'id') unexpected(cn, 'identifier');
              expect('=', '"="');
              items.push({ name: cn.v, e: expr(0) });
            } while (accept(','));
            endStmt('"," or ";"');
            return { k: 'const', items: items, line: t.line };
          }
          case 'declare': {
            p++; expect('(', '"("');
            while (!isOp(peek(), ')') && peek().t !== 'eof') p++;
            expect(')'); endStmt();
            return { k: 'nop', line: t.line };
          }
        }
        if (ENDERS[t.v]) unexpected(t, depth === 0 && !brk.length ? 'end of file' : null);
        if (UNSUPPORTED[t.v]) unsupported(t);
      }
      var e = expr(0);
      endStmt();
      return { k: 'expr', e: e, line: t.line };
    }

    function startsExpr(t) {
      if (t.t === 'var' || t.t === 'int' || t.t === 'float' || t.t === 'str' || t.t === 'tpl' || t.t === 'cast' || t.t === 'id') return true;
      if (t.t === 'kw') return /^(array|list|isset|empty|exit|print|include|include_once|require|require_once|function|fn|static|match|new|clone|throw)$/.test(t.v);
      return t.t === 'op' && /^(\(|\[|!|-|\+|~|@|\+\+|--|\$|&)$/.test(t.v);
    }
    function exprList(close) {
      var out = [];
      if (isOp(peek(), close)) return out;
      out.push(expr(0));
      while (accept(',')) out.push(expr(0));
      return out;
    }
    function target() {
      var t = peek();
      if (isKw(t, 'list') || isOp(t, '[')) { var l = unary(true); return toList(l); }
      if (t.t !== 'var' && !isOp(t, '$')) unexpected(t);
      var v = unary(true);
      if (!isLval(v)) unexpected(peek());
      return v;
    }
    function toList(n) {
      if (n.k === 'list') return n;
      if (n.k !== 'arr') return n;
      var items = n.items.map(function (it) {
        if (!it) return null;
        it.v.okAppend = true;
        var v = it.v.k === 'arr' ? toList(it.v) : it.v;
        return { key: it.key, v: v, byRef: it.byRef };
      });
      return { k: 'list', items: items, line: n.line };
    }

    function ifStmt() {
      var t = next(), conds = [parenExpr()], bodies = [], els = null;
      if (accept(':')) {
        bodies.push(altBody(['elseif', 'else', 'endif']));
        var alts = '"elseif" or "else" or "endif"';
        for (;;) {
          var x = peek();
          if (isKw(x, 'elseif')) { p++; conds.push(parenExpr()); expect(':', '":"'); bodies.push(altBody(['elseif', 'else', 'endif'])); continue; }
          if (isKw(x, 'else')) { p++; expect(':', '":"'); els = altBody(['endif']); alts = null; }
          break;
        }
        endAlt('endif', alts);
        return { k: 'if', conds: conds, bodies: bodies, els: els, line: t.line };
      }
      bodies.push(block());
      for (;;) {
        var y = peek();
        if (isKw(y, 'elseif')) { p++; conds.push(parenExpr()); bodies.push(block()); continue; }
        if (isKw(y, 'else')) {
          p++;
          if (isKw(peek(), 'if')) els = [ifStmt()];
          else els = block();
        }
        break;
      }
      return { k: 'if', conds: conds, bodies: bodies, els: els, line: t.line };
    }

    function switchStmt() {
      var t = next(), e = parenExpr(), alt = false, cases = [];
      if (accept(':')) alt = true; else expect('{', '"{"');
      brk.push('switch'); depth++;
      for (;;) {
        var x = peek();
        if (alt ? isKw(x, 'endswitch') : isOp(x, '}')) break;
        var test;
        if (isKw(x, 'case')) { p++; test = expr(0); }
        else if (isKw(x, 'default')) { p++; test = null; }
        else unexpected(x);
        if (!accept(':') && !accept(';')) unexpected(peek(), test === null ? '":" or ";"' : null);
        var body = statements(function (y) { return isKw(y, 'case') || isKw(y, 'default') || (alt ? isKw(y, 'endswitch') : isOp(y, '}')) || y.t === 'eof'; });
        cases.push({ test: test, body: body, line: x.line });
      }
      brk.pop(); depth--;
      if (alt) endAlt('endswitch'); else expect('}');
      return { k: 'switch', e: e, cases: cases, line: t.line };
    }

    function params() {
      expect('(', '"("');
      var ps = [];
      while (!isOp(peek(), ')')) {
        var type = null, t = peek();
        if (isOp(t, '?') || t.t === 'id' || (t.t === 'kw' && /^(array|callable|static)$/.test(t.v))) type = typeDecl();
        var pr = { name: null, byRef: !!accept('&'), variadic: !!accept('...'), def: null, type: type, line: peek().line };
        var v = peek();
        if (v.t !== 'var') unexpected(v, 'variable');
        p++; pr.name = v.v;
        if (accept('=')) pr.def = expr(0);
        ps.push(pr);
        if (!accept(',')) break;
      }
      expect(')', '")"');
      return ps;
    }
    function typeDecl() {
      var s = '';
      if (accept('?')) s = '?';
      for (;;) {
        var t = next();
        if (t.t !== 'id' && t.t !== 'kw') unexpected(t);
        s += t.raw.replace(/^\\/, '');
        if (accept('|')) { s += '|'; continue; }
        break;
      }
      return s;
    }
    function fnBody(decl) {
      var savedLoops = brk, savedDepth = depth;
      brk = []; depth = 1;
      expect('{', '"{"');
      decl.body = statements(function (x) { return isOp(x, '}') || x.t === 'eof'; });
      expect('}');
      brk = savedLoops; depth = savedDepth;
    }
    function fnDecl(named) {
      var decl = { name: null, params: null, body: null, byRef: !!accept('&'), line: T[p - 1].line, ret: null };
      if (named) { var nt = next(); decl.name = nt.raw.replace(/^\\/, ''); decl.line = nt.line; }
      decl.params = params();
      if (!named && isKw(peek(), 'use')) {
        p++; expect('(', '"("');
        decl.uses = [];
        while (!isOp(peek(), ')')) {
          var r = !!accept('&'), v = next();
          if (v.t !== 'var') unexpected(v, 'variable');
          decl.uses.push({ name: v.v, byRef: r });
          if (!accept(',')) break;
        }
        expect(')');
      }
      if (accept(':')) decl.ret = typeDecl();
      fnBody(decl);
      return decl;
    }

    function args() {
      // after "(": the arguments of a call, up to ")"
      var out = [];
      while (!isOp(peek(), ')')) {
        var t = peek();
        if (accept('...')) { if (isOp(peek(), ')')) { p++; return { fcc: true }; } out.push({ spread: true, e: expr(0) }); }
        else if ((t.t === 'id' || t.t === 'kw') && isOp(T[p + 1], ':') && !isOp(T[p + 1], '::')) { p += 2; out.push({ name: t.raw, e: expr(0) }); }
        else out.push({ e: expr(0) });
        if (!accept(',')) break;
      }
      expect(')', '")"');
      return out;
    }

    function arrayLit(close, line) {
      var items = [];
      while (!isOp(peek(), close)) {
        if (isOp(peek(), ',')) { p++; items.push(null); continue; }
        var it = { key: null, v: null, byRef: false, spread: false };
        if (accept('...')) { it.spread = true; it.v = expr(0); }
        else if (accept('&')) { it.byRef = true; it.v = unary(true); }
        else {
          var e = expr(0);
          if (accept('=>')) { it.key = e; if (accept('&')) { it.byRef = true; it.v = unary(true); } else it.v = expr(0); }
          else it.v = e;
        }
        items.push(it);
        if (!accept(',')) break;
      }
      expect(close, '"' + close + '"');
      return { k: 'arr', items: items, line: line };
    }

    function expr(minbp) {
      var left = unary(false), lastNA = -1;
      for (;;) {
        var t = peek(), op = null;
        if (t.t === 'op') op = t.v;
        else if (t.t === 'kw' && (t.v === 'and' || t.v === 'or' || t.v === 'xor' || t.v === 'instanceof')) op = t.v;
        if (op === '?') {
          if (6 < minbp) break;
          p++;
          var node;
          if (accept(':')) node = { k: 'tern', c: left, a: null, b: expr(7), line: left.line };
          else { var a = expr(0); expect(':', '":"'); node = { k: 'tern', c: left, a: a, b: expr(7), line: left.line }; }
          if (left.k === 'tern' && !left.paren && (left.a || node.a)) {
            var f = left.a ? (node.a ? ['a ? b : c ? d : e', '(a ? b : c) ? d : e', 'a ? b : (c ? d : e)'] : ['a ? b : c ?: d', '(a ? b : c) ?: d', 'a ? b : (c ?: d)'])
              : ['a ?: b ? c : d', '(a ?: b) ? c : d', 'a ?: (b ? c : d)'];
            compile('Unparenthesized `' + f[0] + '` is not supported. Use either `' + f[1] + '` or `' + f[2] + '`', node.line);
          }
          left = node; lastNA = -1;
          continue;
        }
        var info = op && BIN[op];
        if (!info) break;
        if (info[0] < minbp) break;
        if (info[1] === 'N' && info[0] === lastNA) unexpected(t);
        if (op === 'instanceof') unsupported(t);
        p++;
        var right = expr(info[1] === 'R' ? info[0] : info[0] + 1);
        if (op === '&&' || op === '||' || op === 'and' || op === 'or') left = { k: (op === '&&' || op === 'and') ? 'land' : 'lor', l: left, r: right, line: left.line };
        else if (op === 'xor') left = { k: 'lxor', l: left, r: right, line: left.line };
        else if (op === '??') left = { k: 'coalesce', l: left, r: right, line: left.line };
        else left = { k: 'bin', op: op === '<>' ? '!=' : op, l: left, r: right, line: left.line };
        lastNA = info[1] === 'N' ? info[0] : -1;
      }
      return left;
    }

    function unary(noAssign) {
      var t = peek(), e;
      if (t.t === 'op') {
        switch (t.v) {
          case '!': p++; return { k: 'not', e: expr(19), line: t.line };
          case '-': case '+': p++; return { k: t.v === '-' ? 'neg' : 'pos', e: expr(21), line: t.line };
          case '~': p++; return { k: 'bnot', e: expr(21), line: t.line };
          case '@': p++; return { k: 'silence', e: expr(21), line: t.line };
          case '++': case '--': {
            p++;
            var tg = postfix(primary(), true);
            tg.okAppend = true;
            if (!isLval(tg) || tg.k === 'list') unexpected(peek());
            return { k: 'incdec', pre: true, inc: t.v === '++', target: tg, line: t.line };
          }
          case '&': unexpected(t);
        }
      }
      if (t.t === 'cast') { p++; return { k: 'cast', to: t.v, e: expr(21), line: t.line }; }
      if (t.t === 'kw') {
        switch (t.v) {
          case 'print': p++; return { k: 'print', e: expr(5), line: t.line };
          case 'include': case 'include_once': case 'require': case 'require_once':
            p++; return { k: 'include', kind: t.v, e: expr(5), line: t.line };
          case 'fn': case 'function': return postfix(closure(), noAssign);
          case 'static':
            if (isKw(T[p + 1], 'fn') || isKw(T[p + 1], 'function')) { p++; return postfix(closure(), noAssign); }
            break;
        }
      }
      var n = postfix(primary(), noAssign);
      return n;
    }

    function closure() {
      var t = next();
      if (t.v === 'function') { var d = fnDecl(false); return { k: 'closure', decl: d, line: t.line }; }
      var decl = { name: '{closure}', params: params(), body: null, byRef: false, line: t.line, ret: null, arrow: true };
      if (accept(':')) decl.ret = typeDecl();
      expect('=>', '"=>"');
      var body = expr(5);
      decl.body = [{ k: 'return', e: body, line: body.line }];
      return { k: 'closure', decl: decl, line: t.line, arrow: true };
    }

    function primary() {
      var t = next();
      switch (t.t) {
        case 'var': return { k: 'var', name: t.v, line: t.line };
        case 'int': case 'float': return { k: 'lit', v: t.v, line: t.line };
        case 'str': return { k: 'lit', v: t.v, line: t.line };
        case 'tpl': return { k: 'tpl', parts: t.v.map(function (part) { return typeof part === 'string' ? part : sub(part); }), line: t.line };
        case 'cast': p--; return unary(false);
        case 'id': {
          var low = t.raw.replace(/^\\/, '').toLowerCase();
          if (isOp(peek(), '(')) {
            p++;
            var a = args();
            if (a.fcc) return { k: 'fcc', name: t.raw.replace(/^\\/, ''), line: t.line };
            return { k: 'call', name: t.raw.replace(/^\\/, ''), args: a, line: t.line };
          }
          if (low === 'true') return { k: 'lit', v: true, line: t.line };
          if (low === 'false') return { k: 'lit', v: false, line: t.line };
          if (low === 'null') return { k: 'lit', v: null, line: t.line };
          if (t.raw === '__LINE__') return { k: 'lit', v: t.line, line: t.line };
          if (/^__(FILE|DIR|FUNCTION|METHOD|CLASS|NAMESPACE)__$/.test(t.raw)) return { k: 'magic', name: t.raw, line: t.line };
          if (isOp(peek(), '::')) unsupported(peek());
          return { k: 'const', name: t.raw.replace(/^\\/, ''), line: t.line };
        }
        case 'kw':
          switch (t.v) {
            case 'array':
              if (isOp(peek(), '(')) { p++; return arrayLit(')', t.line); }
              break;
            case 'list': {
              expect('(', '"("');
              var l = arrayLit(')', t.line);
              return toList(l);
            }
            case 'isset': {
              expect('(', '"("');
              var vs = [];
              while (!isOp(peek(), ')')) {
                var ie = expr(0);
                if (!isLval(ie) && ie.k !== 'const') compile('Cannot use isset() on the result of an expression (you can use "null !== expression" instead)', ie.line);
                vs.push(ie);
                if (!accept(',')) break;
              }
              expect(')', '")"');
              return { k: 'isset', list: vs, line: t.line };
            }
            case 'empty': { expect('(', '"("'); var ee = expr(0); expect(')', '")"'); return { k: 'empty', e: ee, line: t.line }; }
            case 'exit': {
              var xe = null;
              if (accept('(')) { if (!isOp(peek(), ')')) xe = expr(0); expect(')', '")"'); }
              return { k: 'exit', e: xe, line: t.line };
            }
            case 'match': return matchExpr(t);
            case 'print': case 'include': case 'include_once': case 'require': case 'require_once': case 'fn': case 'function': p--; return unary(false);
          }
          if (UNSUPPORTED[t.v]) unsupported(t);
          return unexpected(t);
        case 'op':
          if (t.v === '(') {
            var pe = expr(0);
            expect(')');
            pe.paren = true;
            return pe;
          }
          if (t.v === '[') return arrayLit(']', t.line);
          if (t.v === '$') {
            if (accept('{')) { var ve = expr(0); expect('}'); return { k: 'varvar', e: ve, line: t.line }; }
            var nx = peek();
            if (nx.t !== 'var' && !isOp(nx, '$')) unexpected(nx, 'variable or "{" or "$"');
            var inner = primary();
            return { k: 'varvar', e: inner, line: t.line };
          }
          if (t.v === '"') return unexpected(t);
          return unexpected(t);
        case 'err': return fail(t.v, t.line, t);
      }
      return unexpected(t);
    }

    function matchExpr(t) {
      var subj = parenExpr();
      expect('{', '"{"');
      var arms = [];
      while (!isOp(peek(), '}')) {
        var conds = null;
        if (isKw(peek(), 'default')) { p++; }
        else { conds = [expr(0)]; while (accept(',')) { if (isOp(peek(), '=>')) break; conds.push(expr(0)); } }
        expect('=>', '"=>"');
        arms.push({ conds: conds, e: expr(0) });
        if (!accept(',')) break;
      }
      expect('}');
      return { k: 'match', subj: subj, arms: arms, line: t.line };
    }

    function postfix(n, noAssign) {
      for (;;) {
        var t = peek();
        if (isOp(t, '[')) {
          p++;
          if (accept(']')) { n = { k: 'dim', base: n, key: null, line: n.line }; continue; }
          if (!startsExpr(peek())) unexpected(peek(), '"]"');
          var key = expr(0);
          expect(']', '"]"');
          n = { k: 'dim', base: n, key: key, line: n.line };
          continue;
        }
        if (isOp(t, '(') && (n.k === 'var' || n.k === 'dim' || n.k === 'varvar' || n.paren || n.k === 'call' || n.k === 'dcall' || n.k === 'closure')) {
          p++;
          var a = args();
          n = { k: 'dcall', fn: n, args: a, line: n.line };
          continue;
        }
        if (isOp(t, '->') || isOp(t, '?->')) {
          p++;
          var pn = next(), pname;
          if (pn.t === 'id' || pn.t === 'kw') pname = { k: 'lit', v: pn.raw, line: pn.line };
          else if (pn.t === 'var') pname = { k: 'var', name: pn.v, line: pn.line };
          else unexpected(pn);
          if (isOp(peek(), '(')) { p++; n = { k: 'mcall', base: n, name: pname, args: args(), nullsafe: t.v === '?->', line: n.line }; continue; }
          n = { k: 'prop', base: n, name: pname, nullsafe: t.v === '?->', line: n.line };
          continue;
        }
        if (isOp(t, '::')) unsupported(t);
        if ((isOp(t, '++') || isOp(t, '--')) && isLval(n) && n.k !== 'list') {
          p++;
          n = { k: 'incdec', pre: false, inc: t.v === '++', target: n, line: n.line };
          continue;
        }
        break;
      }
      if (noAssign) return n;
      var at = peek();
      if (hasAppend(n) && !(at.t === 'op' && ASSIGN[at.v])) pendingAppend.push(n);
      if (at.t === 'op' && ASSIGN[at.v]) {
        var target = n;
        if (n.k === 'arr' && !n.paren && at.v === '=') target = toList(n);
        if (!isLval(target) || (target.k === 'list' && at.v !== '=')) return n;
        p++;
        if (at.v === '=' && accept('&')) {
          var src = unary(true);
          if (!isLval(src) && src.k !== 'call' && src.k !== 'dcall') unexpected(T[p]);
          return { k: 'assignref', target: target, src: src, line: n.line };
        }
        var rhs = expr(5);
        if (at.v === '=') return { k: 'assign', target: target, e: rhs, line: n.line };
        return { k: 'assignop', op: at.v.slice(0, -1), target: target, e: rhs, line: n.line };
      }
      return n;
    }

    function sub(toks) {
      var saveT = T, saveP = p;
      T = toks; p = 0;
      var e = expr(0);
      if (peek().t !== 'eof') unexpected(peek());
      T = saveT; p = saveP;
      return e;
    }

    var body = statements(function (t) { return t.t === 'eof'; });
    for (var i = 0; i < pendingAppend.length; i++) if (!pendingAppend[i].okAppend) compile('Cannot use [] for reading', pendingAppend[i].line);
    return { body: body, compileErr: compileErr, notes: notes };
  }

  /* ---------------------------------------------------------------- run state, errors */

  var R = null;                       // the run in progress
  var BRK = 1, CNT = 2, RET = 3;      // statement completions
  var MAX_DEPTH = 800;

  function PhpThrow(cls, msg, line, file) { this.cls = cls; this.msg = msg; this.line = line; this.file = file; }
  function Stop() {}                  // unwinds after a fatal error has been recorded, or on exit
  function Scope(fn) { this.vars = new Map(); this.fn = fn || null; }

  function record(level, msg, line, file, fr) {
    if (R.silence && level !== 'Fatal error' && level !== 'Parse error') return;
    msg = unutf8(msg);
    R.errors.push({ level: level, message: msg, file: file || R.file, line: line || R.ln, fr: fr || frOf(level, msg) });
  }
  function warn(msg) { record('Warning', msg); }
  function deprecated(msg) { record('Deprecated', msg); }
  function thr(cls, msg) { throw new PhpThrow(cls, msg, R.ln, R.file); }
  function fatal(msg, fr) { record('Fatal error', msg, R.ln, R.file, fr); R.fatal = true; throw new Stop(); }

  /** Output, split by origin: inline HTML (php false) or a PHP statement (its line). */
  function out(text, php, line) {
    if (!text) return;
    var segs = R.segs, last = segs[segs.length - 1];
    if (last && last.php === php && last.line === line && last.file === R.file) last.text += text;
    else segs.push({ text: text, file: R.file, line: line, php: php });
  }

  /* ---------------------------------------------------------------- conversions */

  function toBool(v) {
    if (v === null || v === undefined) return false;
    switch (typeof v) {
      case 'boolean': return v;
      case 'number': return v !== 0;
      case 'bigint': return true;
      case 'string': return v !== '' && v !== '0';
    }
    if (v instanceof Flt) return v.v !== 0;
    if (v instanceof PArr) return v.m.size > 0;
    return true;
  }
  function toStr(v) {
    if (typeof v === 'string') return v;
    if (v === null || v === undefined) return '';
    switch (typeof v) {
      case 'boolean': return v ? '1' : '';
      case 'number': return '' + v;
      case 'bigint': return v.toString();
    }
    if (v instanceof Flt) { if (v.v !== v.v) warn('unexpected NAN value was coerced to string'); return fmtFloat(v.v, 14); }
    if (v instanceof PArr) { warn('Array to string conversion'); return 'Array'; }
    return thr('Error', 'Object of class Closure could not be converted to string');
  }
  /** float → int as a cast does it (PHP 8.5 warns when the float does not fit). */
  function fToInt(d) {
    if (d !== d || d === Infinity || d === -Infinity) { warn('The float ' + fmtFloat(d, 17) + ' is not representable as an int, cast occurred'); return 0; }
    var t = Math.trunc(d);
    if (t <= MAXS && t >= -MAXS) return t || 0;
    if (t < 9223372036854775808 && t >= -9223372036854775808) return bigNorm(BigInt(t));
    warn('The float ' + fmtFloat(d, 17) + ' is not representable as an int, cast occurred');
    return bigNorm(BigInt.asIntN(64, BigInt(t)));
  }
  function capInt(d) {
    if (d !== d) return 0;
    if (d >= 9223372036854775807) return I64MAX;
    if (d <= -9223372036854775808) return I64MIN;
    var t = Math.trunc(d);
    return t <= MAXS && t >= -MAXS ? t || 0 : bigNorm(BigInt(t));
  }
  function toInt(v) {
    switch (typeof v) {
      case 'number': case 'bigint': return v;
      case 'boolean': return v ? 1 : 0;
      case 'string': { var n = numStr(v); if (!n) return 0; return n.v instanceof Flt ? capInt(n.v.v) : n.v; }
    }
    if (v === null || v === undefined) return 0;
    if (v instanceof Flt) return fToInt(v.v);
    if (v instanceof PArr) return v.m.size ? 1 : 0;
    return 1;
  }
  function toFloat(v) {
    switch (typeof v) {
      case 'number': return v;
      case 'bigint': return Number(v);
      case 'boolean': return v ? 1 : 0;
      case 'string': { var n = numStr(v); return n ? fv(n.v) : 0; }
    }
    if (v === null || v === undefined) return 0;
    if (v instanceof Flt) return v.v;
    if (v instanceof PArr) return v.m.size ? 1 : 0;
    return 1;
  }
  /** The number an arithmetic operand stands for, or BAD (TypeError "Unsupported operand types"). */
  var BAD = {};
  function arithNum(v) {
    switch (typeof v) {
      case 'number': case 'bigint': return v;
      case 'boolean': return v ? 1 : 0;
      case 'string': {
        var n = numStr(v);
        if (!n) return BAD;
        if (!n.whole) warn('A non-numeric value encountered');
        return n.v;
      }
    }
    if (v === null || v === undefined) return 0;
    if (v instanceof Flt) return v;
    return BAD;
  }
  /** An operand used as an int (%, <<, intdiv…): a fractional float is deprecated. */
  function intOperand(x) {
    if (x instanceof Flt) {
      var d = x.v;
      if (!isFinite(d) || d !== d) return 0;
      if (Math.trunc(d) !== d) deprecated('Implicit conversion from float ' + fmtFloat(d, 17) + ' to int loses precision');
      return capInt(d);
    }
    return x;
  }

  /* ---------------------------------------------------------------- arithmetic */

  function addN(a, b) {
    if (typeof a === 'number' && typeof b === 'number') { var r = a + b; if (r <= MAXS && r >= -MAXS) return r; }
    if (a instanceof Flt || b instanceof Flt) return new Flt(fv(a) + fv(b));
    return ovf(BigInt(a) + BigInt(b), Number(a) + Number(b));
  }
  function subN(a, b) {
    if (typeof a === 'number' && typeof b === 'number') { var r = a - b; if (r <= MAXS && r >= -MAXS) return r; }
    if (a instanceof Flt || b instanceof Flt) return new Flt(fv(a) - fv(b));
    return ovf(BigInt(a) - BigInt(b), Number(a) - Number(b));
  }
  function mulN(a, b) {
    if (typeof a === 'number' && typeof b === 'number') { var r = a * b; if (r <= MAXS && r >= -MAXS) return r || 0; }
    if (a instanceof Flt || b instanceof Flt) return new Flt(fv(a) * fv(b));
    return ovf(BigInt(a) * BigInt(b), Number(a) * Number(b));
  }
  function divN(a, b) {
    if (fv(b) === 0) thr('DivisionByZeroError', 'Division by zero');
    if (isInt(a) && isInt(b)) {
      if (typeof a === 'number' && typeof b === 'number') return a % b === 0 ? (a / b) || 0 : new Flt(a / b);
      var A = BigInt(a), B = BigInt(b);
      if (A % B === B0 && !(A === I64MIN && B === -B1)) return bigNorm(A / B);
      return new Flt(Number(a) / Number(b));
    }
    return new Flt(fv(a) / fv(b));
  }
  function modN(a, b) {
    a = intOperand(a); b = intOperand(b);
    if (b == 0) thr('DivisionByZeroError', 'Modulo by zero');
    if (typeof a === 'number' && typeof b === 'number') return (a % b) || 0;
    if (b == -1) return 0;
    return bigNorm(BigInt(a) % BigInt(b));
  }
  function powN(a, b) {
    if (isInt(a) && isInt(b) && b >= 0) {
      var r = Math.pow(Number(a), Number(b));
      if (Math.abs(r) <= MAXS) return r || 0;
      if (!isFinite(r) || Math.abs(r) > 1e20) return new Flt(r);
      return ovf(BigInt(a) ** BigInt(b), r);
    }
    return new Flt(Math.pow(fv(a), fv(b)));
  }
  function arith(op, a, b) {
    if (op === '+' && a instanceof PArr && b instanceof PArr) {
      var u = a.clone();
      b.m.forEach(function (v, k) { if (!u.m.has(k)) { v = v instanceof Ref ? v.v : v; u.set(k, share(v)); } });
      return u;
    }
    var x = arithNum(a); if (x === BAD) thr('TypeError', 'Unsupported operand types: ' + tname(a) + ' ' + op + ' ' + tname(b));
    var y = arithNum(b); if (y === BAD) thr('TypeError', 'Unsupported operand types: ' + tname(a) + ' ' + op + ' ' + tname(b));
    switch (op) {
      case '+': return addN(x, y);
      case '-': return subN(x, y);
      case '*': return mulN(x, y);
      case '/': return divN(x, y);
      case '%': return modN(x, y);
      case '**': return powN(x, y);
    }
    var A = BigInt(toIntQuiet(intOperand(x))), B = BigInt(toIntQuiet(intOperand(y)));
    switch (op) {
      case '&': return bigNorm(BigInt.asIntN(64, A & B));
      case '|': return bigNorm(BigInt.asIntN(64, A | B));
      case '^': return bigNorm(BigInt.asIntN(64, A ^ B));
      case '<<': if (B < B0) thr('ArithmeticError', 'Bit shift by negative number'); return B >= BigInt(64) ? 0 : bigNorm(BigInt.asIntN(64, A << B));
      case '>>': if (B < B0) thr('ArithmeticError', 'Bit shift by negative number'); return bigNorm(A >> (B > BigInt(63) ? BigInt(63) : B));
    }
    return null;
  }
  function toIntQuiet(x) { return x instanceof Flt ? capInt(x.v) : x; }

  /* ---------------------------------------------------------------- comparison */

  function kind(v) {
    if (v === null || v === undefined) return 0;
    switch (typeof v) { case 'boolean': return 1; case 'number': case 'bigint': return 2; case 'string': return 4; }
    if (v instanceof Flt) return 3;
    if (v instanceof PArr) return 5;
    return 6;
  }
  function numCmp(a, b) {
    if (a instanceof Flt || b instanceof Flt) { var x = fv(a), y = fv(b); return x === y ? 0 : x < y ? -1 : 1; }
    return a < b ? -1 : a > b ? 1 : 0;
  }
  function bytesCmp(a, b) { return a === b ? 0 : a < b ? -1 : 1; }
  /** zend_binary_strcmp: the difference of the first differing bytes, else of the lengths. */
  function bytesDiff(a, b) {
    var n = Math.min(a.length, b.length);
    for (var i = 0; i < n; i++) { var d = a.charCodeAt(i) - b.charCodeAt(i); if (d) return d; }
    return a.length - b.length;
  }
  function strCmp(a, b) {
    var x = numStr(a), y;
    if (x && x.whole && (y = numStr(b)) && y.whole) return numCmp(x.v, y.v);
    return bytesCmp(a, b);
  }
  function numStrCmp(n, s) {
    var p = numStr(s);
    if (p && p.whole) return numCmp(n, p.v);
    return bytesCmp(n instanceof Flt ? fmtFloat(n.v, 14) : '' + n, s);
  }
  /** zend_compare: -1, 0, 1 (1 too when the two cannot be compared). */
  function cmp(a, b) {
    var ka = kind(a), kb = kind(b);
    if ((ka === 2 || ka === 3) && (kb === 2 || kb === 3)) return numCmp(a, b);
    if (ka === 4 && kb === 4) return strCmp(a, b);
    if (ka === 5 && kb === 5) return arrCmp(a, b);
    if (ka === 0 && kb === 4) return b === '' ? 0 : -1;
    if (ka === 4 && kb === 0) return a === '' ? 0 : 1;
    if (ka <= 1 || kb <= 1) { var x = toBool(a), y = toBool(b); return x === y ? 0 : x ? 1 : -1; }
    if (ka === 4 && (kb === 2 || kb === 3)) return -numStrCmp(b, a) || 0;
    if (kb === 4 && (ka === 2 || ka === 3)) return numStrCmp(a, b);
    if (ka === 5) return 1;
    if (kb === 5) return -1;
    return 1;
  }
  function arrCmp(a, b) {
    if (a.m.size !== b.m.size) return a.m.size < b.m.size ? -1 : 1;
    var r = 0;
    for (var e of a.m) {
      if (!b.m.has(e[0])) return 1;
      var x = e[1] instanceof Ref ? e[1].v : e[1];
      r = cmp(x, b.get(e[0]));
      if (r) return r;
    }
    return 0;
  }
  function looseEq(a, b) {
    var ka = kind(a), kb = kind(b);
    if (ka === 4 && kb === 4) {
      if (a === b) return true;
      var x = numStr(a), y;
      return !!(x && x.whole && (y = numStr(b)) && y.whole && numCmp(x.v, y.v) === 0 && fv(x.v) === fv(y.v));
    }
    if ((ka === 2 || ka === 3) && (kb === 2 || kb === 3)) return (ka === 3 || kb === 3) ? fv(a) === fv(b) : a == b;
    if (ka === 5 && kb === 5) {
      if (a.m.size !== b.m.size) return false;
      for (var e of a.m) { if (!b.m.has(e[0]) || !looseEq(e[1] instanceof Ref ? e[1].v : e[1], b.get(e[0]))) return false; }
      return true;
    }
    if (ka === 3 && kb === 4) { var p = numStr(b); if (p && p.whole) return a.v === fv(p.v); }
    if (ka === 4 && kb === 3) { var q = numStr(a); if (q && q.whole) return b.v === fv(q.v); }
    return cmp(a, b) === 0;
  }
  function strictEq(a, b) {
    if (a === b) return true;
    if (a instanceof Flt && b instanceof Flt) return a.v === b.v;
    if (a instanceof PArr && b instanceof PArr) {
      if (a.m.size !== b.m.size) return false;
      var ia = a.m.entries(), ib = b.m.entries();
      for (;;) {
        var x = ia.next(), y = ib.next();
        if (x.done) return true;
        if (x.value[0] !== y.value[0]) return false;
        var u = x.value[1] instanceof Ref ? x.value[1].v : x.value[1], w = y.value[1] instanceof Ref ? y.value[1].v : y.value[1];
        if (!strictEq(u, w)) return false;
      }
    }
    return false;
  }
  function lt(a, b) {
    if ((a instanceof Flt || b instanceof Flt) && isNum(a) && isNum(b)) return fv(a) < fv(b);
    return cmp(a, b) < 0;
  }
  function le(a, b) {
    if ((a instanceof Flt || b instanceof Flt) && isNum(a) && isNum(b)) return fv(a) <= fv(b);
    return cmp(a, b) <= 0;
  }

  /* ---------------------------------------------------------------- array keys and offsets */

  function normKey(k, isset) {
    switch (typeof k) {
      case 'number': case 'bigint': return k;
      case 'string': return strKey(k);
      case 'boolean': return k ? 1 : 0;
    }
    if (k === null || k === undefined) { deprecated('Using null as an array offset is deprecated, use an empty string instead'); return ''; }
    if (k instanceof Flt) {
      var d = k.v;
      if (!isFinite(d)) return 0;
      if (Math.trunc(d) !== d) deprecated('Implicit conversion from float ' + fmtFloat(d, 17) + ' to int loses precision');
      return capInt(d);
    }
    return thr('TypeError', 'Cannot access offset of type ' + tname(k) + (isset ? ' in isset or empty' : ' on array'));
  }
  function keyRepr(k) { return typeof k === 'string' ? '"' + k + '"' : '' + k; }
  /** $c[$k] for reading; quiet: isset / ?? / empty (no warnings, null when absent). */
  function dimGet(c, k, quiet) {
    if (c instanceof PArr) {
      var key = normKey(k, quiet), v = c.get(key);
      if (v === undefined) { if (!quiet) warn('Undefined array key ' + keyRepr(key)); return null; }
      return v;
    }
    if (typeof c === 'string') return strOffset(c, k, quiet);
    if (c === null || c === undefined) { if (!quiet) warn('Trying to access array offset on null'); return null; }
    if (c instanceof Closure) return thr('Error', 'Cannot use object of type Closure as array');
    if (!quiet) warn('Trying to access array offset on ' + (typeof c === 'boolean' ? '' + c : tname(c)));
    return null;
  }
  function strIndex(k, quiet) {
    switch (typeof k) {
      case 'number': case 'bigint': return Number(k);
      case 'string': {
        var n = numStr(k);
        if (n && isInt(n.v)) {
          if (n.whole) return Number(n.v);
          if (quiet) return null;
          warn('Illegal string offset "' + k + '"');
          return Number(n.v);
        }
        if (quiet) return null;
        return thr('TypeError', 'Cannot access offset of type string on string');
      }
      case 'boolean': if (!quiet) warn('String offset cast occurred'); return k ? 1 : 0;
    }
    if (k === null || k === undefined) { if (!quiet) warn('String offset cast occurred'); return 0; }
    if (k instanceof Flt) {
      if (!quiet) warn('String offset cast occurred');
      else if (Math.trunc(k.v) !== k.v && isFinite(k.v)) deprecated('Implicit conversion from float ' + fmtFloat(k.v, 17) + ' to int loses precision');
      return Math.trunc(k.v) || 0;
    }
    if (quiet) return null;
    return thr('TypeError', 'Cannot access offset of type ' + tname(k) + ' on string');
  }
  function strOffset(s, k, quiet) {
    var i = strIndex(k, quiet);
    if (i === null) return null;
    var j = i < 0 ? i + s.length : i;
    if (j < 0 || j >= s.length) { if (!quiet) warn('Uninitialized string offset ' + i); return quiet ? null : ''; }
    return s[j];
  }

  /* ---------------------------------------------------------------- places (what an assignment writes to) */

  Ref.prototype.get = function () { return this.v; };
  Ref.prototype.set = function (v) { this.v = v; };
  Ref.prototype.ref = function () { if (this.v === undefined) this.v = null; return this; };
  function ElemPlace(a, k) { this.a = a; this.k = k; }
  ElemPlace.prototype.get = function () { return this.k === undefined ? undefined : this.a.get(this.k); };
  ElemPlace.prototype.set = function (v) {
    if (this.k !== undefined) { this.a.set(this.k, v); return; }
    if (!this.a.push(v)) thr('Error', 'Cannot add element to the array as the next element is already occupied');
    this.k = typeof this.a.nx === 'number' ? this.a.nx - 1 : bigNorm(this.a.nx - B1);
  };
  ElemPlace.prototype.ref = function () { if (this.k === undefined) this.set(null); return this.a.ref(this.k); };
  ElemPlace.prototype.undef = function () { warn('Undefined array key ' + keyRepr(this.k)); };
  function StrPlace(base, s, k) { this.base = base; this.s = s; this.k = k; }
  StrPlace.prototype.get = function () { return thr('Error', 'Cannot use assign-op operators with string offsets'); };
  StrPlace.prototype.ref = function () { return thr('Error', 'Cannot create references to/from string offsets'); };
  StrPlace.prototype.set = function (v) {
    if (this.k === undefined) thr('Error', '[] operator not supported for strings');
    var i = strIndex(this.k, false), s = this.s, c = toStr(v);
    if (c === '') thr('Error', 'Cannot assign an empty string to a string offset');
    if (c.length > 1) warn('Only the first byte will be assigned to the string offset');
    if (i < 0) { if (i + s.length < 0) { warn('Illegal string offset ' + i); return; } i += s.length; }
    if (i >= s.length) s += repeat(' ', i - s.length) + c[0];
    else s = s.slice(0, i) + c[0] + s.slice(i + 1);
    this.base.set(s);
  };

  function varRef(sc, name) {
    var sg = R.sg[name];
    if (sg) return sg;
    var r = sc.vars.get(name);
    if (!r) { r = new Ref(undefined); sc.vars.set(name, r); }
    return r;
  }
  /** Phase 1 of a write: evaluates the keys of $a[k1][k2] (PHP compiles them before the value). */
  function prep(n, sc) {
    switch (n.k) {
      case 'var': return n;
      case 'varvar': return { k: 'var', name: toStr(ev(n.e, sc)), line: n.line };
      case 'prop': {
        var b = evq(n.base, sc);
        return thr('Error', 'Attempt to assign property "' + toStr(ev(n.name, sc)) + '" on ' + (typeof b === 'boolean' ? '' + b : tname(b)));
      }
      case 'dim':
        if (n.base.k === 'var' && n.base.name === 'GLOBALS' && n.key) return { k: 'gvar', name: toStr(ev(n.key, sc)) };
        return { k: 'dim', base: prep(n.base, sc), key: n.key ? ev(n.key, sc) : undefined, line: n.line };
    }
    return thr('Error', 'Cannot assign to this expression');
  }
  /** Phase 2: the place itself; containers on the way are created (null, false) or separated (shared arrays). */
  function resolve(d, sc) {
    if (d.k === 'var') return varRef(sc, d.name);
    if (d.k === 'gvar') return varRef(R.gsc, d.name);
    var bp = resolve(d.base, sc);
    if (bp instanceof StrPlace) thr('Error', 'Cannot use string offset as an array');
    var c = bp.get();
    if (c instanceof PArr) {
      if (c.refs > 1) { c.refs--; c = c.clone(); c.refs = 1; bp.set(c); }
    } else if (c === null || c === undefined) { c = new PArr(); c.refs = 1; bp.set(c); }
    else if (c === false) { deprecated('Automatic conversion of false to array is deprecated'); c = new PArr(); c.refs = 1; bp.set(c); }
    else if (typeof c === 'string') {
      if (bp instanceof StrPlace) thr('Error', 'Cannot use string offset as an array');
      return new StrPlace(bp, c, d.key);
    } else if (c instanceof Closure) thr('Error', 'Cannot use object of type Closure as array');
    else thr('Error', 'Cannot use a scalar value as an array');
    return new ElemPlace(c, d.key === undefined ? undefined : normKey(d.key));
  }
  function place(n, sc) { return resolve(prep(n, sc), sc); }
  /** The array a by-reference argument points at, separated so that it can be modified in place. */
  function writable(pl) {
    var c = pl.get();
    if (c instanceof PArr && c.refs > 1) { c.refs--; c = c.clone(); c.refs = 1; pl.set(c); }
    return c;
  }
  function store(pl, v) { pl.set(share(v)); return v; }
  function undefWarn(pl, d) {
    if (pl instanceof ElemPlace) pl.undef();
    else warn('Undefined variable $' + d.name);
  }

  /* ---------------------------------------------------------------- expressions */

  function lookup(sc, name) {
    var sg = R.sg[name];
    if (sg) return sg;
    if (name === 'GLOBALS') {
      var g = new PArr();
      R.gsc.vars.forEach(function (r, k) { if (r.v !== undefined) g.set(strKey(k), share(r.v)); });
      return new Ref(g);
    }
    return sc.vars.get(name);
  }
  function readVar(name, sc) {
    var r = lookup(sc, name);
    if (r === undefined || r.v === undefined) { warn('Undefined variable $' + name); return null; }
    return r.v;
  }
  /** Reads without warnings (isset, ??, empty): null when anything on the way is missing. */
  function evq(n, sc) {
    switch (n.k) {
      case 'var': { R.ln = n.line; var r = lookup(sc, n.name); return r === undefined || r.v === undefined ? null : r.v; }
      case 'varvar': { var r2 = lookup(sc, toStr(ev(n.e, sc))); return r2 === undefined || r2.v === undefined ? null : r2.v; }
      case 'prop': evq(n.base, sc); ev(n.name, sc); return null;
      case 'dim': {
        var base = evq(n.base, sc);
        if (n.key === null) return thr('Error', 'Cannot use [] for reading');
        var k = ev(n.key, sc);
        return dimGet(base, k, true);
      }
    }
    return ev(n, sc);
  }

  function ev(n, sc) {
    R.ln = n.line;
    switch (n.k) {
      case 'lit': return n.v;
      case 'var': return readVar(n.name, sc);
      case 'varvar': return readVar(toStr(ev(n.e, sc)), sc);
      case 'tpl': {
        var s = '';
        for (var i = 0; i < n.parts.length; i++) {
          var part = n.parts[i];
          s += typeof part === 'string' ? part : toStr(ev(part, sc));
        }
        return s;
      }
      case 'arr': return arrayLit(n, sc);
      case 'dim': {
        var base = ev(n.base, sc);
        if (n.key === null) return thr('Error', 'Cannot use [] for reading');
        var key = ev(n.key, sc);
        return dimGet(base, key, false);
      }
      case 'bin': {
        var a = ev(n.l, sc), b = ev(n.r, sc);
        switch (n.op) {
          case '.': return toStr(a) + toStr(b);
          case '==': return looseEq(a, b);
          case '!=': return !looseEq(a, b);
          case '===': return strictEq(a, b);
          case '!==': return !strictEq(a, b);
          case '<': return lt(a, b);
          case '>': return lt(b, a);
          case '<=': return le(a, b);
          case '>=': return le(b, a);
          case '<=>': return cmp(a, b) || 0;
        }
        return arith(n.op, a, b);
      }
      case 'land': return toBool(ev(n.l, sc)) && toBool(ev(n.r, sc));
      case 'lor': return toBool(ev(n.l, sc)) || toBool(ev(n.r, sc));
      case 'lxor': return toBool(ev(n.l, sc)) !== toBool(ev(n.r, sc));
      case 'coalesce': { var c = evq(n.l, sc); return c !== null && c !== undefined ? c : ev(n.r, sc); }
      case 'not': return !toBool(ev(n.e, sc));
      case 'neg': return arith('*', ev(n.e, sc), -1);
      case 'pos': return arith('*', ev(n.e, sc), 1);
      case 'bnot': {
        var bv = ev(n.e, sc);
        if (typeof bv === 'string') { var o = ''; for (var j = 0; j < bv.length; j++) o += fcc(~bv.charCodeAt(j) & 255); return o; }
        if (!isNum(bv)) thr('TypeError', 'Cannot perform bitwise not on ' + tname(bv));
        return bigNorm(BigInt.asIntN(64, ~BigInt(toIntQuiet(bv))));
      }
      case 'silence': R.silence++; try { return ev(n.e, sc); } finally { R.silence--; }
      case 'cast': return cast(n.to, ev(n.e, sc));
      case 'assign': return assign(n, sc);
      case 'assignop': return assignOp(n, sc);
      case 'assignref': return assignRef(n, sc);
      case 'incdec': return incdec(n, sc);
      case 'tern': {
        var cv = ev(n.c, sc);
        if (n.a === null) return toBool(cv) ? cv : ev(n.b, sc);
        return toBool(cv) ? ev(n.a, sc) : ev(n.b, sc);
      }
      case 'isset': {
        for (var t = 0; t < n.list.length; t++) { var iv = evq(n.list[t], sc); if (iv === null || iv === undefined) return false; }
        return true;
      }
      case 'empty': return !toBool(evq(n.e, sc));
      case 'const': return constant(n.name);
      case 'magic':
        switch (n.name) {
          case '__FILE__': return '/var/www/html/' + R.file;
          case '__DIR__': return '/var/www/html';
          case '__FUNCTION__': return sc.fn ? sc.fn.name : '';
        }
        return '';
      case 'call': return callNamed(n, sc);
      case 'dcall': return callDynamic(n, sc);
      case 'fcc': return n.name;
      case 'closure': return makeClosure(n, sc);
      case 'print': { var pv = toStr(ev(n.e, sc)); out(pv, true, R.sl); return 1; }
      case 'exit': {
        var xv = n.e ? ev(n.e, sc) : null;
        if (typeof xv === 'string') out(xv, true, R.sl);
        else if (xv !== null && !isInt(xv) && typeof xv !== 'boolean') thr('TypeError', 'exit(): Argument #1 ($status) must be of type string|int, ' + tname(xv) + ' given');
        R.exited = true;
        throw new Stop();
      }
      case 'include': return include(n, sc);
      case 'match': {
        var mv = ev(n.subj, sc), def = null;
        for (var m = 0; m < n.arms.length; m++) {
          var arm = n.arms[m];
          if (!arm.conds) { def = arm; continue; }
          for (var q = 0; q < arm.conds.length; q++) if (strictEq(mv, ev(arm.conds[q], sc))) return ev(arm.e, sc);
        }
        if (def) return ev(def.e, sc);
        R.ln = n.line;
        return thr('UnhandledMatchError', 'Unhandled match case ' + matchRepr(mv));
      }
      case 'list': return thr('Error', 'Cannot use list() outside of an assignment');
      case 'prop': {
        var pb = ev(n.base, sc), pnm = toStr(ev(n.name, sc));
        if (pb === null && n.nullsafe) return null;
        if (pb instanceof Closure) return thr('Error', 'Undefined property: Closure::$' + pnm);
        warn('Attempt to read property "' + pnm + '" on ' + (typeof pb === 'boolean' ? '' + pb : tname(pb)));
        return null;
      }
      case 'mcall': {
        var mb = ev(n.base, sc), mnm = toStr(ev(n.name, sc));
        if (mb === null && n.nullsafe) return null;
        if (mb instanceof Closure && /^(call|__invoke)$/i.test(mnm)) return callValue(mb, n.args.map(function (a) { return ev(a.e, sc); }));
        return thr('Error', 'Call to a member function ' + mnm + '() on ' + (typeof mb === 'boolean' ? '' + mb : tname(mb)));
      }
    }
    return thr('Error', 'Unsupported expression');
  }

  function matchRepr(v) {
    if (typeof v === 'string') return "'" + (v.length > 15 ? v.slice(0, 15) + '...' : v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    if (v === null) return 'NULL';
    if (typeof v === 'boolean') return v ? 'true' : 'false';
    if (v instanceof Flt) return fmtFloat(v.v, 17);
    if (v instanceof PArr) return 'of type array';
    return '' + v;
  }

  function arrayLit(n, sc) {
    var a = new PArr();
    for (var i = 0; i < n.items.length; i++) {
      var it = n.items[i];
      if (!it) thr('Error', 'Cannot use empty array elements in arrays');
      if (it.spread) {
        var src = ev(it.v, sc);
        if (!(src instanceof PArr)) thr('Error', 'Only arrays and Traversables can be unpacked');
        src.m.forEach(function (v, k) { v = v instanceof Ref ? v.v : v; if (typeof k === 'string') a.set(k, share(v)); else a.push(share(v)); });
        continue;
      }
      if (it.byRef) {
        var r = place(it.v, sc).ref();
        r.n++;
        if (it.key) a.m.set(normKey(ev(it.key, sc)), r); else a.push(r);
        continue;
      }
      if (it.key) {
        var k = ev(it.key, sc), v = ev(it.v, sc);
        a.set(normKey(k), share(v));
      } else if (!a.push(share(ev(it.v, sc)))) thr('Error', 'Cannot add element to the array as the next element is already occupied');
    }
    return a;
  }

  function cast(to, v) {
    switch (to) {
      case 'int': return toInt(v);
      case 'float': return new Flt(toFloat(v));
      case 'string': return toStr(v);
      case 'bool': return toBool(v);
      case 'array':
        if (v instanceof PArr) return v;
        if (v === null || v === undefined) return new PArr();
        return list([v]);
      case 'unset': return thr('Error', 'The (unset) cast is no longer supported');
    }
    return thr('Error', 'Cast to ' + to + ' is not supported here');
  }

  function constant(name) {
    if (Object.prototype.hasOwnProperty.call(R.consts, name)) return R.consts[name];
    if (Object.prototype.hasOwnProperty.call(CONSTS, name)) return CONSTS[name];
    var up = name.toUpperCase();
    if (up === 'TRUE') return true;
    if (up === 'FALSE') return false;
    if (up === 'NULL') return null;
    return thr('Error', 'Undefined constant "' + name + '"');
  }

  function assign(n, sc) {
    if (n.target.k === 'list') { var lv = ev(n.e, sc); destructure(n.target, lv, sc); return lv; }
    if (n.target.k === 'var') {
      var v = ev(n.e, sc);
      return store(varRef(sc, n.target.name), v);
    }
    var d = prep(n.target, sc), val = ev(n.e, sc);
    return store(resolve(d, sc), val);
  }
  function destructure(l, v, sc) {
    var idx = 0;
    for (var i = 0; i < l.items.length; i++) {
      var it = l.items[i];
      if (!it) { idx++; continue; }
      var key = it.key ? ev(it.key, sc) : idx++, x = null;
      if (v instanceof PArr) {
        if (it.byRef) { var w = v.ref(normKey(key)); place(it.v, sc); bindRef(it.v, w, sc); continue; }
        x = dimGet(v, key, false);
      } else if (typeof v === 'string' && v !== '') x = null;
      if (it.v.k === 'list') destructure(it.v, x, sc);
      else store(place(it.v, sc), x);
    }
  }
  function assignOp(n, sc) {
    if (n.op === '??') {
      var cur = evq(n.target, sc);
      if (cur !== null && cur !== undefined) return cur;
      var d0 = prep(n.target, sc), nv = ev(n.e, sc);
      return store(resolve(d0, sc), nv);
    }
    var d = prep(n.target, sc), rhs = ev(n.e, sc), pl = resolve(d, sc), old = pl.get();
    if (old === undefined) { undefWarn(pl, d); old = null; }
    var res = n.op === '.' ? toStr(old) + toStr(rhs) : arith(n.op, old, rhs);
    return store(pl, res);
  }
  /** Makes a variable name hold the slot `ref` (global, static, &, by-reference parameters). */
  function bindVar(sc, name, ref) {
    var old = sc.vars.get(name);
    if (old === ref) return;
    if (old) old.n--;
    ref.n++;
    sc.vars.set(name, ref);
  }
  function bindRef(target, ref, sc) {
    if (target.k === 'var') { if (R.sg[target.name]) thr('Error', 'Cannot re-assign $' + target.name); bindVar(sc, target.name, ref); return; }
    if (target.k === 'varvar') { bindVar(sc, toStr(ev(target.e, sc)), ref); return; }
    var d = prep(target, sc), pl = resolve(d, sc);
    if (!(pl instanceof ElemPlace)) thr('Error', 'Cannot create references to/from string offsets');
    ref.n++;
    if (pl.k === undefined) pl.a.push(ref);
    else { var old = pl.a.m.get(pl.k); if (old instanceof Ref) old.n--; pl.a.m.set(pl.k, ref); }
  }
  function assignRef(n, sc) {
    var ref;
    if (n.src.k === 'call' || n.src.k === 'dcall') { record('Notice', 'Only variables should be assigned by reference'); ref = new Ref(share(ev(n.src, sc))); }
    else ref = place(n.src, sc).ref();
    bindRef(n.target, ref, sc);
    return ref.v;
  }
  function incdec(n, sc) {
    var d = prep(n.target, sc), pl = resolve(d, sc), old = pl.get();
    if (old === undefined) { undefWarn(pl, d); old = null; }
    var nv = step(old, n.inc);
    pl.set(nv);
    return n.pre ? nv : old;
  }
  /** ++ / -- on a value. */
  function step(v, inc) {
    if (isInt(v)) return inc ? addN(v, 1) : subN(v, 1);
    if (v instanceof Flt) return new Flt(v.v + (inc ? 1 : -1));
    if (v === null) { if (inc) return 1; warn('Decrement on type null has no effect, this will change in the next major version of PHP'); return null; }
    if (typeof v === 'boolean') { warn((inc ? 'Increment' : 'Decrement') + ' on type bool has no effect, this will change in the next major version of PHP'); return v; }
    if (typeof v === 'string') {
      var n = numStr(v);
      if (n && n.whole) return inc ? addN(n.v, 1) : subN(n.v, 1);
      if (v === '') { if (inc) { deprecated('Increment on non-numeric string is deprecated, use str_increment() instead'); return '1'; } deprecated('Decrement on empty string is deprecated as non-numeric'); return -1; }
      if (!inc) { deprecated('Decrement on non-numeric string has no effect and is deprecated'); return v; }
      deprecated('Increment on non-numeric string is deprecated, use str_increment() instead');
      return strInc(v);
    }
    if (v instanceof PArr) return thr('TypeError', 'Cannot ' + (inc ? 'increment' : 'decrement') + ' array');
    return thr('TypeError', 'Cannot ' + (inc ? 'increment' : 'decrement') + ' Closure');
  }
  /** Perl's string increment: "a" → "b", "Az" → "Ba", "zz" → "aaa", "a9" → "b0". */
  function strInc(s) {
    var a = s.split(''), i = a.length - 1;
    for (; i >= 0; i--) {
      var c = a[i];
      if (c >= 'a' && c < 'z' || c >= 'A' && c < 'Z' || c >= '0' && c < '9') { a[i] = fcc(c.charCodeAt(0) + 1); return a.join(''); }
      if (c === 'z') a[i] = 'a';
      else if (c === 'Z') a[i] = 'A';
      else if (c === '9') a[i] = '0';
      else return a.join('');
    }
    var f = s[0];
    return (f >= 'a' && f <= 'z' ? 'a' : f >= 'A' && f <= 'Z' ? 'A' : '1') + a.join('');
  }

  /* ---------------------------------------------------------------- functions */

  function declare(decl, file, compileTime) {
    var low = decl.name.toLowerCase();
    var prev = R.fns[low];
    if (prev || BUILTINS[low]) {
      var msg = 'Cannot redeclare function ' + decl.name + '()' + (prev ? ' (previously declared in ' + prev.file + ':' + prev.line + ')' : '');
      if (compileTime) throw new CompileErr(msg, decl.line);
      R.ln = decl.line;
      fatal(msg);
    }
    decl.file = file;
    R.fns[low] = decl;
  }
  function hoist(body, file) {
    for (var i = 0; i < body.length; i++) if (body[i].k === 'fndecl') { declare(body[i].decl, file, true); body[i].hoisted = true; }
  }

  function makeClosure(n, sc) {
    var bound = new Map(), d = n.decl;
    d.file = d.file || R.file;
    if (n.arrow) sc.vars.forEach(function (r, name) { if (r.v !== undefined) bound.set(name, { r: new Ref(r.v), byRef: false }); });
    else if (d.uses) d.uses.forEach(function (u) {
      if (u.byRef) { var br = varRef(sc, u.name).ref(); br.n++; bound.set(u.name, { r: br, byRef: true }); }
      else {
        var r = lookup(sc, u.name);
        if (!r || r.v === undefined) { warn('Undefined variable $' + u.name); bound.set(u.name, { r: new Ref(null), byRef: false }); }
        else bound.set(u.name, { r: new Ref(share(r.v)), byRef: false });
      }
    });
    return new Closure(d, bound);
  }

  /** Evaluates call arguments against a parameter list: values, or places for by-reference parameters. */
  function evalArgs(params, argNodes, sc, fname) {
    var vals = [], named = null;
    for (var i = 0; i < argNodes.length; i++) {
      var a = argNodes[i];
      if (a.spread) {
        var arr = ev(a.e, sc);
        if (!(arr instanceof PArr)) thr('Error', 'Only arrays and Traversables can be unpacked');
        arr.m.forEach(function (v, k) {
          v = v instanceof Ref ? v.v : v;
          if (typeof k === 'string') { named = named || {}; named[k] = v; } else vals.push(v);
        });
        continue;
      }
      if (a.name) {
        named = named || {};
        var pi = -1;
        for (var j = 0; j < params.length; j++) if (params[j].name === a.name) pi = j;
        if (pi >= 0 && params[pi].byRef) { named[a.name] = isLvalNode(a.e) ? place(a.e, sc) : new Ref(ev(a.e, sc)); continue; }
        named[a.name] = ev(a.e, sc);
        continue;
      }
      if (named) thr('Error', 'Cannot use positional argument after named argument');
      var p = params[i] || (params.length && params[params.length - 1].variadic ? params[params.length - 1] : null);
      if (p && p.byRef) {
        if (isLvalNode(a.e)) vals.push(place(a.e, sc));
        else {
          var tmp = ev(a.e, sc);
          if (a.e.k === 'call' || a.e.k === 'dcall') { R.ln = a.e.line; record('Notice', 'Only variables should be passed by reference'); vals.push(new Ref(tmp)); }
          else if (fname === 'extract') vals.push(new Ref(tmp));
          else thr('Error', fname + '(): Argument #' + (i + 1) + ' ($' + p.name + ') could not be passed by reference');
        }
      } else vals.push(ev(a.e, sc));
    }
    if (named) {
      Object.keys(named).forEach(function (name) {
        var idx = -1;
        for (var j = 0; j < params.length; j++) if (params[j].name === name && !params[j].variadic) idx = j;
        if (idx < 0) thr('Error', 'Unknown named parameter $' + name);
        if (idx < vals.length && vals[idx] !== undefined) thr('Error', 'Named parameter $' + name + ' overwrites previous argument');
        while (vals.length < idx) vals.push(undefined);
        vals[idx] = named[name];
      });
    }
    return vals;
  }
  function isLvalNode(e) { return e.k === 'var' || e.k === 'dim' || e.k === 'varvar'; }
  /** Functions PHP compiles to an opcode of their own: their errors carry the line of the last argument, not of the call. */
  var OWN_OPCODE = {};
  ('strlen is_null is_bool is_long is_int is_integer is_float is_double is_string is_array is_object is_scalar boolval intval ' +
    'floatval doubleval strval defined chr ord count sizeof gettype func_num_args func_get_args array_key_exists').split(' ').forEach(function (f) { OWN_OPCODE[f] = 1; });

  function callNamed(n, sc) {
    var low = n.name.toLowerCase(), f = R.fns[low];
    if (f) { var args = evalArgs(f.params, n.args, sc, f.name); R.ln = n.line; return invoke(f, args, n.line, null); }
    var b = BUILTINS[low];
    if (b) return callBuiltin(b, n.args, sc, n.line);
    return thr('Error', 'Call to undefined function ' + n.name + '()');
  }
  function callDynamic(n, sc) {
    var fnv = ev(n.fn, sc);
    if (fnv instanceof Closure) { var args = evalArgs(fnv.decl.params, n.args, sc, '{closure}'); R.ln = n.line; return invoke(fnv.decl, args, n.line, fnv.bound); }
    if (typeof fnv === 'string') {
      var low = fnv.replace(/^\\/, '').toLowerCase(), f = R.fns[low];
      if (f) { var a2 = evalArgs(f.params, n.args, sc, f.name); R.ln = n.line; return invoke(f, a2, n.line, null); }
      if (BUILTINS[low]) return callBuiltin(BUILTINS[low], n.args, sc, n.line);
      return thr('Error', 'Call to undefined function ' + fnv + '()');
    }
    if (fnv instanceof PArr) return thr('Error', 'Array callback must have exactly two elements');
    return thr('Error', 'Value not callable');
  }
  /** Calls a PHP callable value with plain values (array_map, usort…). */
  function callValue(fnv, args) {
    if (fnv instanceof Closure) return invoke(fnv.decl, args, null, fnv.bound);
    var low = toStr(fnv).replace(/^\\/, '').toLowerCase(), f = R.fns[low];
    if (f) return invoke(f, args, null, null);
    var b = BUILTINS[low];
    if (b) return applyBuiltin(b, args.slice());
    return thr('Error', 'Call to undefined function ' + toStr(fnv) + '()');
  }
  function isCallable(v) {
    if (v instanceof Closure) return true;
    if (typeof v !== 'string') return false;
    var low = v.replace(/^\\/, '').toLowerCase();
    return !!(R.fns[low] || BUILTINS[low]);
  }

  /** Runs a user function: binds the parameters (values copied, places by reference), then the body. */
  function invoke(decl, args, callLine, bound) {
    if (R.depth >= MAX_DEPTH) thr('Error', 'Maximum call stack size of 8339456 bytes reached. Infinite recursion?');
    var fsc = new Scope(decl), callerFile = R.file, ps = decl.params, i;
    fsc.args = args.map(function (x) { return x instanceof Ref || x instanceof ElemPlace ? x.get() : x; });
    if (bound) bound.forEach(function (b, name) { if (b.byRef) bindVar(fsc, name, b.r); else fsc.vars.set(name, new Ref(share(b.r.v))); });
    var ln = R.ln, sl = R.sl;
    R.depth++;
    R.file = decl.file;
    try {
      var required = 0, optional = false;
      for (i = 0; i < ps.length; i++) { if (ps[i].variadic) break; if (!ps[i].def) required = i + 1; else optional = true; }
      for (i = 0; i < ps.length; i++) {
        var p = ps[i];
        if (p.variadic) {
          var rest = new PArr();
          for (var j = i; j < args.length; j++) {
            var av = args[j];
            if (p.byRef) { var rr = av instanceof Ref ? av : av.ref(); rr.n++; rest.push(rr); }
            else rest.push(share(coerceUser(decl, j, p, av instanceof Ref || av instanceof ElemPlace ? av.get() : av, callLine, callerFile)));
          }
          fsc.vars.set(p.name, new Ref(rest));
          break;
        }
        if (i < args.length && args[i] !== undefined) {
          var a = args[i];
          if (p.byRef) bindVar(fsc, p.name, a instanceof Ref ? a : a && a.ref ? a.ref() : new Ref(a));
          else {
            if (a instanceof Ref || a instanceof ElemPlace) a = a.get();
            fsc.vars.set(p.name, new Ref(share(coerceUser(decl, i, p, a, callLine, callerFile))));
          }
        } else if (p.def) {
          fsc.vars.set(p.name, new Ref(share(ev(p.def, fsc))));
        } else {
          var given = 0; for (var g = 0; g < args.length; g++) if (args[g] !== undefined) given++;
          R.ln = p.line;
          if (i < args.length || args.length > i) thr('ArgumentCountError', decl.name + '(): Argument #' + (i + 1) + ' ($' + p.name + ') not passed');
          thr('ArgumentCountError', 'Too few arguments to function ' + decl.name + '(), ' + given + ' passed' +
            (callLine ? ' in ' + callerFile + ' on line ' + callLine : '') + ' and ' + (optional ? 'at least' : 'exactly') + ' ' + required + ' expected');
        }
      }
      var sig = execBlock(decl.body, fsc), ret = sig && sig.s === RET ? sig.v : null;
      if (decl.ret) ret = coerceReturn(decl, ret, sig);
      return ret;
    } finally {
      fsc.vars.forEach(function (r) { r.n--; });
      R.depth--;
      R.file = callerFile;
      R.ln = ln; R.sl = sl;
    }
  }

  /** Scalar type declarations in coercive mode (what PHP does without strict_types): int, then float, string, bool. */
  function coerceTo(type, v, onFail) {
    var t = type.toLowerCase(), nullable = t[0] === '?';
    if (nullable) t = t.slice(1);
    var types = t.split('|');
    function has(x) { return types.indexOf(x) >= 0; }
    if (has('mixed')) return v;
    if (v === null && (nullable || has('null'))) return null;
    var k = tname(v);
    if (k === 'int' && has('int')) return v;
    if (k === 'float' && has('float')) return v;
    if (k === 'string' && has('string')) return v;
    if (k === 'bool' && (has('bool') || (v === false && has('false')) || (v === true && has('true')))) return v;
    if (k === 'array' && (has('array') || has('iterable'))) return v;
    if (k === 'Closure' && (has('callable') || has('closure') || has('object'))) return v;
    if (k === 'string' && has('callable') && isCallable(v)) return v;
    if (v === null || k === 'array' || k === 'Closure') return onFail();
    if (has('int')) {
      if (has('float') && k === 'string') { var ns = numStr(v); if (ns && ns.whole) return ns.v; }
      else { var lv = weakLong(v); if (lv !== undefined) return lv; }
    }
    if (has('float')) {
      if (k === 'int') return new Flt(Number(v));
      if (k === 'bool') return new Flt(v ? 1 : 0);
      if (k === 'string') { var n2 = numStr(v); if (n2 && n2.whole) return new Flt(fv(n2.v)); }
    }
    if (has('string')) return toStr(v);
    if (has('bool')) return toBool(v);
    return onFail();
  }
  function weakLong(v) {
    var k = tname(v), d, fromStr = false;
    if (k === 'bool') return v ? 1 : 0;
    if (k === 'int') return v;
    if (k === 'float') d = v.v;
    else if (k === 'string') { var n = numStr(v); if (!n || !n.whole) return undefined; if (isInt(n.v)) return n.v; d = n.v.v; fromStr = true; }
    else return undefined;
    if (d !== d || !isFinite(d) || d >= 9223372036854775808 || d < -9223372036854775808) return undefined;
    if (Math.trunc(d) !== d) deprecated('Implicit conversion from ' + (fromStr ? 'float-string "' + v + '"' : 'float ' + fmtFloat(d, 17)) + ' to int loses precision');
    return capInt(d);
  }
  function coerceUser(decl, i, p, v, callLine, callerFile) {
    if (!p.type) return v;
    return coerceTo(p.type, v, function () {
      R.ln = p.line;
      return thr('TypeError', decl.name + '(): Argument #' + (i + 1) + ' ($' + p.name + ') must be of type ' + p.type + ', ' + tname(v) + ' given' +
        (callLine ? ', called in ' + callerFile + ' on line ' + callLine : ''));
    });
  }
  function coerceReturn(decl, v, sig) {
    var t = decl.ret.toLowerCase();
    if (t === 'void') return null;
    return coerceTo(decl.ret, v, function () {
      if (sig && sig.line) R.ln = sig.line;
      return thr('TypeError', decl.name + '(): Return value must be of type ' + decl.ret + ', ' + (v === null && !sig ? 'none' : tname(v)) + ' returned');
    });
  }

  /* ---------------------------------------------------------------- include */

  function normPath(p) {
    var parts = p.replace(/\\/g, '/').split('/'), outp = [];
    for (var i = 0; i < parts.length; i++) {
      var s = parts[i];
      if (s === '' || s === '.') continue;
      if (s === '..') { outp.pop(); continue; }
      outp.push(s);
    }
    return outp.join('/');
  }
  function include(n, sc) {
    var name = toStr(ev(n.e, sc)), kind = n.kind, once = /_once$/.test(kind), req = kind.indexOf('require') === 0;
    var key = normPath(name.replace(/^\/var\/www\/html\//, ''));
    if (!Object.prototype.hasOwnProperty.call(R.files, key) || name === '') {
      if (name === '') { thr('ValueError', 'Path cannot be empty'); }
      warn(kind + '(' + name + '): Failed to open stream: No such file or directory');
      if (req) fatal('Uncaught Error: Failed opening required \'' + name + "' (include_path='.:/usr/share/php')");
      warn(kind + "(): Failed opening '" + name + "' for inclusion (include_path='.:/usr/share/php')");
      return false;
    }
    if (once && R.included[key]) return true;
    R.included[key] = true;
    var unit = compileUnit(key);
    var sl = R.sl;
    var r = runUnit(unit, sc);
    R.sl = sl;
    return r;
  }
  function compileUnit(name) {
    LEXNOTES = [];
    var toks = new Lexer(R.files[name]).file(), ast, lexNotes = LEXNOTES;
    try {
      ast = parse(toks);
      if (ast.compileErr) throw ast.compileErr;
      hoist(ast.body, name);
    } catch (e) {
      if (e instanceof ParseErr) { record('Parse error', e.msg, e.line, name, e.fr); R.fatal = true; throw new Stop(); }
      if (e instanceof CompileErr) { record('Fatal error', e.msg, e.line, name, e.fr); R.fatal = true; throw new Stop(); }
      throw e;
    }
    lexNotes.concat(ast.notes || []).sort(function (x, y) { return x.line - y.line; }).forEach(function (w) { record(w.level, w.msg, w.line, name); });
    return { name: name, body: ast.body };
  }
  function runUnit(unit, sc) {
    var saved = R.file;
    R.file = unit.name;
    try {
      var sig = execBlock(unit.body, sc);
      return sig && sig.s === RET ? sig.v : 1;
    } finally { R.file = saved; }
  }

  /* ---------------------------------------------------------------- statements */

  function tick() {
    if (++R.steps > R.maxSteps) {
      fatal('Maximum execution time of 30 seconds exceeded',
        'Ta page ne s\'arrête jamais : une boucle tourne sans fin (sa condition reste toujours vraie).');
    }
  }
  function execBlock(body, sc) {
    for (var i = 0; i < body.length; i++) {
      var sig = exec(body[i], sc);
      if (sig) return sig;
    }
    return null;
  }
  /** A loop body's completion: null to go on, 'stop' to leave the loop, or a signal to pass outwards. */
  function loopSig(sig) {
    if (sig.s === BRK) return sig.n > 1 ? { s: BRK, n: sig.n - 1 } : 'stop';
    if (sig.s === CNT) return sig.n > 1 ? { s: CNT, n: sig.n - 1 } : null;
    return sig;
  }

  function exec(s, sc) {
    R.ln = R.sl = s.line;
    tick();
    if (R.trace && s.k !== 'html' && s.k !== 'nop' && s.k !== 'block') traceRec(s, sc);
    var sig, r, i;
    switch (s.k) {
      case 'echo':
        for (i = 0; i < s.args.length; i++) { var v = toStr(ev(s.args[i], sc)); out(v, true, s.line); }
        return null;
      case 'html': out(s.text, false, s.line); return null;
      case 'expr': ev(s.e, sc); return null;
      case 'if':
        for (i = 0; i < s.conds.length; i++) if (toBool(ev(s.conds[i], sc))) return execBlock(s.bodies[i], sc);
        return s.els ? execBlock(s.els, sc) : null;
      case 'while':
        for (;;) {
          R.ln = s.line;
          if (!toBool(ev(s.cond, sc))) return null;
          sig = execBlock(s.body, sc);
          if (sig && (r = loopSig(sig))) return r === 'stop' ? null : r;
          if (!s.body.length) tick();
        }
      case 'dowhile':
        for (;;) {
          sig = execBlock(s.body, sc);
          if (sig && (r = loopSig(sig))) return r === 'stop' ? null : r;
          if (!s.body.length) tick();
          if (!toBool(ev(s.cond, sc))) return null;
        }
      case 'for':
        for (i = 0; i < s.init.length; i++) ev(s.init[i], sc);
        for (;;) {
          var ok = true;
          for (i = 0; i < s.cond.length; i++) ok = toBool(ev(s.cond[i], sc));
          if (!ok) return null;
          sig = execBlock(s.body, sc);
          if (sig && (r = loopSig(sig))) return r === 'stop' ? null : r;
          if (!s.body.length) tick();
          for (i = 0; i < s.step.length; i++) ev(s.step[i], sc);
        }
      case 'foreach': return foreach(s, sc);
      case 'switch': {
        var sv = ev(s.e, sc), start = -1, def = -1;
        for (i = 0; i < s.cases.length; i++) {
          if (s.cases[i].test === null) { if (def < 0) def = i; continue; }
          if (looseEq(sv, ev(s.cases[i].test, sc))) { start = i; break; }
        }
        if (start < 0) start = def;
        if (start < 0) return null;
        for (i = start; i < s.cases.length; i++) {
          sig = execBlock(s.cases[i].body, sc);
          if (sig) {
            if (sig.s === BRK || sig.s === CNT) return sig.n > 1 ? { s: sig.s, n: sig.n - 1 } : null;
            return sig;
          }
        }
        return null;
      }
      case 'break': return { s: BRK, n: s.n };
      case 'continue': return { s: CNT, n: s.n };
      case 'return': return { s: RET, v: s.e ? ev(s.e, sc) : null, line: s.line };
      case 'fndecl': if (!s.hoisted) declare(s.decl, R.file, false); return null;
      case 'global':
        for (i = 0; i < s.names.length; i++) bindVar(sc, s.names[i], varRef(R.gsc, s.names[i]).ref());
        return null;
      case 'static': {
        if (!s.cells) s.cells = {};
        for (i = 0; i < s.vars.length; i++) {
          var sv2 = s.vars[i];
          if (!s.cells[sv2.name]) s.cells[sv2.name] = new Ref(sv2.init ? share(ev(sv2.init, sc)) : null);
          bindVar(sc, sv2.name, s.cells[sv2.name]);
        }
        return null;
      }
      case 'unset':
        for (i = 0; i < s.targets.length; i++) unset(s.targets[i], sc);
        return null;
      case 'const':
        for (i = 0; i < s.items.length; i++) defineConst(s.items[i].name, ev(s.items[i].e, sc));
        return null;
      case 'block': return execBlock(s.body, sc);
      case 'nop': return null;
    }
    return null;
  }

  function defineConst(name, v) {
    if (Object.prototype.hasOwnProperty.call(R.consts, name) || Object.prototype.hasOwnProperty.call(CONSTS, name)) {
      warn('Constant ' + name + ' already defined, this will be an error in PHP 9');
      return false;
    }
    R.consts[name] = v instanceof PArr ? share(v) : v;
    return true;
  }

  function unset(t, sc) {
    if (t.k === 'var' || t.k === 'varvar') {
      var name = t.k === 'var' ? t.name : toStr(ev(t.e, sc)), r = sc.vars.get(name);
      if (R.sg[name]) return;
      if (r) { r.n--; sc.vars.delete(name); }
      return;
    }
    if (t.k !== 'dim') thr('Error', 'Cannot unset this expression');
    if (t.key === null) thr('Error', 'Cannot use [] for unsetting');
    if (t.base.k === 'var' && t.base.name === 'GLOBALS') { R.gsc.vars.delete(toStr(ev(t.key, sc))); return; }
    // the container must exist: unset never creates one
    var c = evq(t.base, sc);
    if (c === null || c === undefined) return;
    if (typeof c === 'string') thr('Error', 'Cannot unset string offsets');
    if (!(c instanceof PArr)) return;
    var d = prep(t.base, sc), key = ev(t.key, sc), bp = resolve(d, sc);
    var arr = writable(bp);
    arr.del(normKey(key));
  }

  function foreach(s, sc) {
    var v;
    if (s.byRef) {
      if (!isLvalNode(s.e)) v = ev(s.e, sc);
      else {
        var pl = place(s.e, sc);
        v = pl.get();
        if (v instanceof PArr) v = writable(pl);
      }
    } else v = ev(s.e, sc);
    if (!(v instanceof PArr)) { R.ln = s.e.line; warn('foreach() argument must be of type array|object, ' + tname(v) + ' given'); return null; }
    var sig, r, k;
    if (s.byRef) {
      var keys = v.keys(), last = null;
      for (var i = 0; i < keys.length; i++) {
        k = keys[i];
        if (!v.m.has(k)) continue;
        if (!s.body.length) tick();
        var ref = v.ref(k);
        if (s.kt) store(place(s.kt, sc), k);
        bindRef(s.vt, ref, sc);
        if (last && last !== ref && last.n <= 1) unref(v, last);
        last = ref;
        sig = execBlock(s.body, sc);
        if (sig && (r = loopSig(sig))) return r === 'stop' ? null : r;
      }
      return null;
    }
    v.refs++;
    try {
      for (var e of v.m) {
        if (!s.body.length) tick();
        k = e[0];
        var val = e[1] instanceof Ref ? e[1].v : e[1];
        if (s.kt) store(place(s.kt, sc), k);
        if (s.vt.k === 'list') destructure(s.vt, val, sc);
        else if (s.vt.k === 'var') store(varRef(sc, s.vt.name), val);
        else store(place(s.vt, sc), val);
        sig = execBlock(s.body, sc);
        if (sig && (r = loopSig(sig))) return r === 'stop' ? null : r;
      }
    } finally { v.refs--; }
    return null;
  }
  /** A reference nothing else holds any more is a plain value again (what PHP does on the next copy). */
  function unref(arr, ref) {
    arr.m.forEach(function (x, k) { if (x === ref) arr.m.set(k, ref.v); });
  }

  /* ---------------------------------------------------------------- builtins: the mechanism */

  var BUILTINS = {};
  var CONSTS = {};
  /** def('str_pad', 'string $string, int $length, string $pad_string=, int $pad_type=', fn): fn(args) with args
      coerced as PHP does for internal functions (a missing optional argument is undefined; a by-reference
      parameter receives a place: get() / set()). */
  function def(name, sig, f) {
    var ps = sig ? sig.split(/,\s*/).map(function (s) {
      var m = /^(\S+)\s+(&)?(\.\.\.)?\$(\w+)(=)?$/.exec(s.trim());
      return { type: m[1], ref: !!m[2], va: !!m[3], name: m[4], opt: !!m[5] || !!m[3] };
    }) : [];
    var req = 0;
    ps.forEach(function (p, i) { if (!p.opt) req = i + 1; });
    BUILTINS[name.toLowerCase()] = {
      name: name, ps: ps, f: f, req: req, max: ps.length && ps[ps.length - 1].va ? Infinity : ps.length,
      params: ps.map(function (p) { return { name: p.name, byRef: p.ref, variadic: p.va }; })
    };
  }
  function alias(a, b) { BUILTINS[a] = Object.assign({}, BUILTINS[b], { name: a }); }

  function callBuiltin(b, argNodes, sc, line) {
    var vals = evalArgs(b.params, argNodes, sc, b.name);
    if (!OWN_OPCODE[b.name]) R.ln = line;
    var saved = R.curSc, ln = R.ln, sl = R.sl;
    R.curSc = sc;
    try { return applyBuiltin(b, vals); } finally { R.curSc = saved; R.ln = ln; R.sl = sl; }
  }
  function applyBuiltin(b, vals) {
    var n = vals.length;
    while (n > 0 && vals[n - 1] === undefined) n--;
    if (n < b.req || n > b.max) {
      var num = n < b.req ? b.req : b.max;
      thr('ArgumentCountError', b.name + '() expects ' + (b.req === b.max ? 'exactly' : n < b.req ? 'at least' : 'at most') + ' ' +
        num + ' argument' + (num === 1 ? '' : 's') + ', ' + n + ' given');
    }
    for (var i = 0; i < n; i++) {
      var p = b.ps[i] || b.ps[b.ps.length - 1];
      if (vals[i] === undefined) { if (!p.opt) thr('ArgumentCountError', b.name + '(): Argument #' + (i + 1) + ' ($' + p.name + ') not passed'); continue; }
      if (p.ref) { if (!(vals[i] && vals[i].get)) vals[i] = new Ref(vals[i]); continue; }
      vals[i] = coerceBI(b.name, i, p, vals[i]);
    }
    vals.length = n;
    return b.f(vals);
  }
  function coerceBI(fname, i, p, v) {
    var t = p.type;
    if (t === 'mixed') return v;
    var low = t.toLowerCase();
    if (low.indexOf('callable') >= 0) {
      if (v === null && low[0] === '?') return null;
      if (isCallable(v)) return v;
      var what = typeof v === 'string' ? 'function "' + v + '" not found or invalid function name' : 'no array or string given';
      return thr('TypeError', fname + '(): Argument #' + (i + 1) + ' ($' + p.name + ') must be a valid callback' + (low[0] === '?' ? ' or null' : '') + ', ' + what);
    }
    if (v === null && low[0] !== '?' && low.indexOf('null') < 0 && /int|float|string|bool/.test(low)) {
      deprecated(fname + '(): Passing null to parameter #' + (i + 1) + ' ($' + p.name + ') of type ' + t + ' is deprecated');
      return /string/.test(low) ? '' : /int/.test(low) ? 0 : /float/.test(low) ? new Flt(0) : false;
    }
    return coerceTo(low.replace('countable|', ''), v, function () {
      return thr('TypeError', fname + '(): Argument #' + (i + 1) + ' ($' + p.name + ') must be of type ' + t + ', ' + tname(v) + ' given');
    });
  }
  function refArray(fname, pl, i, name) {
    var v = pl.get();
    if (!(v instanceof PArr)) thr('TypeError', fname + '(): Argument #' + (i || 1) + ' ($' + (name || 'array') + ') must be of type array, ' + tname(v) + ' given');
    return writable(pl);
  }
  function vErr(fname, i, name, what) { return thr('ValueError', fname + '(): Argument #' + i + ' ($' + name + ') ' + what); }
  function I(v) { return Number(v); }

  /* ---------------------------------------------------------------- builtins: strings */

  function asciiUpper(s) { return s.replace(/[a-z]+/g, function (x) { return x.toUpperCase(); }); }
  function asciiLower(s) { return s.replace(/[A-Z]+/g, function (x) { return x.toLowerCase(); }); }
  function charMask(chars) {
    var set = {};
    for (var i = 0; i < chars.length; i++) {
      if (chars[i + 1] === '.' && chars[i + 2] === '.' && i + 3 < chars.length && chars.charCodeAt(i + 3) >= chars.charCodeAt(i)) {
        for (var c = chars.charCodeAt(i); c <= chars.charCodeAt(i + 3); c++) set[fcc(c)] = true;
        i += 3;
      } else set[chars[i]] = true;
    }
    return set;
  }
  function trimWith(s, chars, mode) {
    var set = charMask(chars === undefined ? ' \n\r\t\v\x00' : chars), a = 0, b = s.length;
    if (mode & 1) while (a < b && set[s[a]]) a++;
    if (mode & 2) while (b > a && set[s[b - 1]]) b--;
    return s.slice(a, b);
  }
  def('strlen', 'string $string', function (a) { return a[0].length; });
  def('mb_strlen', 'string $string, ?string $encoding=', function (a) { return cps(a[0]).length; });
  def('strtoupper', 'string $string', function (a) { return asciiUpper(a[0]); });
  def('strtolower', 'string $string', function (a) { return asciiLower(a[0]); });
  def('mb_strtoupper', 'string $string, ?string $encoding=', function (a) { return utf8(unutf8(a[0]).toUpperCase()); });
  def('mb_strtolower', 'string $string, ?string $encoding=', function (a) { return utf8(unutf8(a[0]).toLowerCase()); });
  def('mb_convert_case', 'string $string, int $mode, ?string $encoding=', function (a) {
    var t = unutf8(a[0]), m = I(a[1]);
    if (m === 0 || m === 4) return utf8(t.toUpperCase());
    if (m === 1 || m === 5) return utf8(t.toLowerCase());
    return utf8(t.toLowerCase().replace(/(^|[^\p{L}\p{N}'])(\p{L})/gu, function (x, p1, p2) { return p1 + p2.toUpperCase(); }));
  });
  def('ucfirst', 'string $string', function (a) { var s = a[0]; return s ? asciiUpper(s[0]) + s.slice(1) : s; });
  def('lcfirst', 'string $string', function (a) { var s = a[0]; return s ? asciiLower(s[0]) + s.slice(1) : s; });
  def('ucwords', 'string $string, string $separators=', function (a) {
    var s = a[0], set = charMask(a[1] === undefined ? ' \t\r\n\f\v' : a[1]), o = '';
    for (var i = 0; i < s.length; i++) o += (i === 0 || set[s[i - 1]]) ? asciiUpper(s[i]) : s[i];
    return o;
  });
  def('trim', 'string $string, string $characters=', function (a) { return trimWith(a[0], a[1], 3); });
  def('ltrim', 'string $string, string $characters=', function (a) { return trimWith(a[0], a[1], 1); });
  def('rtrim', 'string $string, string $characters=', function (a) { return trimWith(a[0], a[1], 2); });
  alias('chop', 'rtrim');
  def('str_repeat', 'string $string, int $times', function (a) {
    if (a[1] < 0) vErr('str_repeat', 2, 'times', 'must be greater than or equal to 0');
    return repeat(a[0], I(a[1]));
  });
  def('str_pad', 'string $string, int $length, string $pad_string=, int $pad_type=', function (a) {
    var s = a[0], pad = a[2] === undefined ? ' ' : a[2], type = a[3] === undefined ? 1 : I(a[3]), n = I(a[1]) - s.length;
    if (pad === '') vErr('str_pad', 3, 'pad_string', 'must not be empty');
    if (type < 0 || type > 2) vErr('str_pad', 4, 'pad_type', 'must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH');
    if (n <= 0) return s;
    var left = type === 0 ? n : type === 2 ? Math.floor(n / 2) : 0, right = n - left;
    function fill(k) { return repeat(pad, Math.ceil(k / pad.length)).slice(0, k); }
    return fill(left) + s + fill(right);
  });
  /** substr()'s offset/length arithmetic (PHP 8): [start, count]. */
  function sliceArgs(len, f, l) {
    f = I(f);
    if (f > len) return null;
    if (f < 0) f = -f > len ? 0 : len + f;
    if (l === null || l === undefined) l = len - f;
    else {
      l = I(l);
      if (l < 0) l = -l > len - f ? 0 : len - f + l;
      else if (l > len - f) l = len - f;
    }
    return [f, l];
  }
  def('substr', 'string $string, int $offset, ?int $length=', function (a) {
    var r = sliceArgs(a[0].length, a[1], a[2]);
    return r ? a[0].substr(r[0], r[1]) : '';
  });
  def('mb_substr', 'string $string, int $start, ?int $length=, ?string $encoding=', function (a) {
    var c = cps(a[0]), r = sliceArgs(c.length, a[1], a[2]);
    return r ? fromCps(c.slice(r[0], r[0] + r[1])) : '';
  });
  function posArgs(fname, h, off) {
    off = off === undefined ? 0 : I(off);
    if (off < 0) off += h.length;
    if (off < 0 || off > h.length) vErr(fname, 3, 'offset', 'must be contained in argument #1 ($haystack)');
    return off;
  }
  def('strpos', 'string $haystack, string $needle, int $offset=', function (a) {
    var i = a[0].indexOf(a[1], posArgs('strpos', a[0], a[2]));
    return i < 0 ? false : i;
  });
  def('stripos', 'string $haystack, string $needle, int $offset=', function (a) {
    var i = asciiLower(a[0]).indexOf(asciiLower(a[1]), posArgs('stripos', a[0], a[2]));
    return i < 0 ? false : i;
  });
  function rpos(fname, h, nd, off) {
    off = off === undefined ? 0 : I(off);
    var i;
    if (off >= 0) {
      if (off > h.length) vErr(fname, 3, 'offset', 'must be contained in argument #1 ($haystack)');
      i = h.lastIndexOf(nd);
      if (i < off) i = -1;
    } else {
      if (-off > h.length) vErr(fname, 3, 'offset', 'must be contained in argument #1 ($haystack)');
      i = h.lastIndexOf(nd, -off < nd.length ? h.length - nd.length : h.length + off);
    }
    return i < 0 ? false : i;
  }
  def('strrpos', 'string $haystack, string $needle, int $offset=', function (a) { return rpos('strrpos', a[0], a[1], a[2]); });
  def('strripos', 'string $haystack, string $needle, int $offset=', function (a) { return rpos('strripos', asciiLower(a[0]), asciiLower(a[1]), a[2]); });
  def('mb_strpos', 'string $haystack, string $needle, int $offset=, ?string $encoding=', function (a) {
    var h = unutf8(a[0]), i = h.indexOf(unutf8(a[1]), a[2] === undefined ? 0 : I(a[2]));
    return i < 0 ? false : Array.from(h.slice(0, i)).length;
  });
  def('str_contains', 'string $haystack, string $needle', function (a) { return a[0].indexOf(a[1]) >= 0; });
  def('str_starts_with', 'string $haystack, string $needle', function (a) { return a[0].slice(0, a[1].length) === a[1]; });
  def('str_ends_with', 'string $haystack, string $needle', function (a) { return a[1] === '' || a[0].slice(-a[1].length) === a[1]; });
  def('strstr', 'string $haystack, string $needle, bool $before_needle=', function (a) {
    var i = a[0].indexOf(a[1]);
    return i < 0 ? false : a[2] ? a[0].slice(0, i) : a[0].slice(i);
  });
  def('stristr', 'string $haystack, string $needle, bool $before_needle=', function (a) {
    var i = asciiLower(a[0]).indexOf(asciiLower(a[1]));
    return i < 0 ? false : a[2] ? a[0].slice(0, i) : a[0].slice(i);
  });
  def('strrchr', 'string $haystack, string $needle', function (a) {
    var i = a[1] === '' ? -1 : a[0].lastIndexOf(a[1][0]);
    return i < 0 ? false : a[0].slice(i);
  });
  def('substr_count', 'string $haystack, string $needle, int $offset=, ?int $length=', function (a) {
    if (a[1] === '') vErr('substr_count', 2, 'needle', 'must not be empty');
    var h = a[0];
    if (a[2] !== undefined) {
      var off = I(a[2]); if (off < 0) off += h.length;
      if (off < 0 || off > h.length) vErr('substr_count', 3, 'offset', 'must be contained in argument #1 ($haystack)');
      var len = a[3] === undefined || a[3] === null ? h.length - off : I(a[3]);
      if (len < 0) len += h.length - off;
      if (len < 0 || off + len > h.length) vErr('substr_count', 4, 'length', 'must be contained in argument #1 ($haystack)');
      h = h.substr(off, len);
    }
    var n = 0, i = 0;
    while ((i = h.indexOf(a[1], i)) >= 0) { n++; i += a[1].length; }
    return n;
  });
  function replaceIn(subject, search, rep, ci, counter) {
    if (search === '') return subject;
    var hay = ci ? asciiLower(subject) : subject, nd = ci ? asciiLower(search) : search, o = '', i = 0, j;
    while ((j = hay.indexOf(nd, i)) >= 0) { o += subject.slice(i, j) + rep; i = j + search.length; counter.n++; }
    return o + subject.slice(i);
  }
  function strReplace(fname, a, ci) {
    var search = a[0], rep = a[1], subject = a[2], counter = { n: 0 };
    if (!(search instanceof PArr) && rep instanceof PArr) thr('TypeError', fname + '(): Argument #2 ($replace) must be of type string when argument #1 ($search) is a string');
    function one(s) {
      s = toStr(s);
      if (!(search instanceof PArr)) return replaceIn(s, search, rep, ci, counter);
      var reps = rep instanceof PArr ? rep.vals() : null, k = 0;
      search.vals().forEach(function (x) {
        var r = reps ? (k < reps.length ? toStr(reps[k]) : '') : rep;
        k++;
        s = replaceIn(s, toStr(x), r, ci, counter);
      });
      return s;
    }
    var res;
    if (subject instanceof PArr) {
      res = new PArr();
      subject.m.forEach(function (v, key) { v = v instanceof Ref ? v.v : v; res.set(key, v instanceof PArr ? share(v) : one(v)); });
    } else res = one(subject);
    if (a[3]) a[3].set(counter.n);
    return res;
  }
  def('str_replace', 'array|string $search, array|string $replace, string|array $subject, mixed &$count=', function (a) { return strReplace('str_replace', a, false); });
  def('str_ireplace', 'array|string $search, array|string $replace, string|array $subject, mixed &$count=', function (a) { return strReplace('str_ireplace', a, true); });
  def('strtr', 'string $string, array|string $from, ?string $to=', function (a) {
    var s = a[0];
    if (a[2] === undefined) {
      if (!(a[1] instanceof PArr)) thr('ArgumentCountError', 'strtr() expects exactly 3 arguments, 2 given');
      var pairs = [];
      a[1].m.forEach(function (v, k) { k = '' + k; if (k !== '') pairs.push([k, toStr(v instanceof Ref ? v.v : v)]); });
      pairs.sort(function (x, y) { return y[0].length - x[0].length; });
      var o = '', i = 0;
      outer: while (i < s.length) {
        for (var p = 0; p < pairs.length; p++) if (s.substr(i, pairs[p][0].length) === pairs[p][0]) { o += pairs[p][1]; i += pairs[p][0].length; continue outer; }
        o += s[i++];
      }
      return o;
    }
    var from = toStr(a[1]), to = a[2] === null ? '' : a[2], n = Math.min(from.length, to.length), map = {};
    for (var q = 0; q < n; q++) map[from[q]] = to[q];
    return s.replace(/[\s\S]/g, function (c) { return Object.prototype.hasOwnProperty.call(map, c) ? map[c] : c; });
  });
  def('strrev', 'string $string', function (a) { return a[0].split('').reverse().join(''); });
  function sign(n) { return n < 0 ? -1 : n > 0 ? 1 : 0; }
  def('strcmp', 'string $string1, string $string2', function (a) { return bytesDiff(a[0], a[1]); });
  def('strcasecmp', 'string $string1, string $string2', function (a) { return bytesDiff(asciiLower(a[0]), asciiLower(a[1])); });
  def('strncmp', 'string $string1, string $string2, int $length', function (a) {
    if (a[2] < 0) vErr('strncmp', 3, 'length', 'must be greater than or equal to 0');
    return bytesDiff(a[0].slice(0, I(a[2])), a[1].slice(0, I(a[2])));
  });
  def('strncasecmp', 'string $string1, string $string2, int $length', function (a) {
    return bytesDiff(asciiLower(a[0]).slice(0, I(a[2])), asciiLower(a[1]).slice(0, I(a[2])));
  });
  /** strnatcmp: digit runs compare as numbers. */
  function natCmp(a, b, ci) {
    if (ci) { a = asciiLower(a); b = asciiLower(b); }
    var re = /(\d+|\D+)/g, x = a.match(re) || [], y = b.match(re) || [];
    for (var i = 0; i < Math.min(x.length, y.length); i++) {
      var p = x[i], q = y[i];
      if (/^\d/.test(p) && /^\d/.test(q)) {
        var pp = p.replace(/^0+(?=\d)/, ''), qq = q.replace(/^0+(?=\d)/, '');
        if (pp.length !== qq.length) return pp.length < qq.length ? -1 : 1;
        if (pp !== qq) return pp < qq ? -1 : 1;
      } else if (p !== q) return bytesCmp(p, q);
    }
    return sign(x.length - y.length);
  }
  def('strnatcmp', 'string $string1, string $string2', function (a) { return natCmp(a[0], a[1], false); });
  def('strnatcasecmp', 'string $string1, string $string2', function (a) { return natCmp(a[0], a[1], true); });
  def('nl2br', 'string $string, bool $use_xhtml=', function (a) {
    var br = a[1] === false ? '<br>' : '<br />';
    return a[0].replace(/\r\n|\n\r|\n|\r/g, function (m) { return br + m; });
  });
  var ENTITIES = { 160: 'nbsp', 161: 'iexcl', 162: 'cent', 163: 'pound', 164: 'curren', 165: 'yen', 166: 'brvbar', 167: 'sect',
    168: 'uml', 169: 'copy', 170: 'ordf', 171: 'laquo', 172: 'not', 173: 'shy', 174: 'reg', 175: 'macr', 176: 'deg', 177: 'plusmn',
    178: 'sup2', 179: 'sup3', 180: 'acute', 181: 'micro', 182: 'para', 183: 'middot', 184: 'cedil', 185: 'sup1', 186: 'ordm',
    187: 'raquo', 188: 'frac14', 189: 'frac12', 190: 'frac34', 191: 'iquest', 215: 'times', 247: 'divide', 338: 'OElig', 339: 'oelig',
    352: 'Scaron', 353: 'scaron', 376: 'Yuml', 402: 'fnof', 710: 'circ', 732: 'tilde', 8211: 'ndash', 8212: 'mdash', 8216: 'lsquo',
    8217: 'rsquo', 8218: 'sbquo', 8220: 'ldquo', 8221: 'rdquo', 8222: 'bdquo', 8224: 'dagger', 8225: 'Dagger', 8226: 'bull',
    8230: 'hellip', 8240: 'permil', 8364: 'euro', 8482: 'trade' };
  'Agrave Aacute Acirc Atilde Auml Aring AElig Ccedil Egrave Eacute Ecirc Euml Igrave Iacute Icirc Iuml ETH Ntilde Ograve Oacute Ocirc Otilde Ouml'
    .split(' ').forEach(function (n, i) { ENTITIES[192 + i] = n; });
  'Oslash Ugrave Uacute Ucirc Uuml Yacute THORN szlig agrave aacute acirc atilde auml aring aelig ccedil egrave eacute ecirc euml igrave iacute icirc iuml eth ntilde ograve oacute ocirc otilde ouml'
    .split(' ').forEach(function (n, i) { ENTITIES[216 + i] = n; });
  'oslash ugrave uacute ucirc uuml yacute thorn yuml'.split(' ').forEach(function (n, i) { ENTITIES[248 + i] = n; });
  function htmlEsc(s, flags, double, all) {
    flags = flags === undefined ? 11 : I(flags);
    var quotes = flags & 3, html5 = (flags & 48) === 48, out = '';
    var t = unutf8(s);
    for (var i = 0; i < t.length; i++) {
      var c = t[i], cp = t.codePointAt(i);
      if (cp > 0xffff) { out += t.slice(i, i + 2); i++; continue; }
      if (c === '&') {
        if (double === false) { var m = /^&(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#[xX][0-9A-Fa-f]+);/.exec(t.slice(i)); if (m) { out += m[0]; i += m[0].length - 1; continue; } }
        out += '&amp;';
      } else if (c === '<') out += '&lt;';
      else if (c === '>') out += '&gt;';
      else if (c === '"' && (quotes & 2)) out += '&quot;';
      else if (c === "'" && quotes === 3) out += html5 ? '&apos;' : '&#039;';
      else if (all && ENTITIES[cp]) out += '&' + ENTITIES[cp] + ';';
      else out += c;
    }
    return utf8(out);
  }
  def('htmlspecialchars', 'string $string, int $flags=, ?string $encoding=, bool $double_encode=', function (a) { return htmlEsc(a[0], a[1], a[3], false); });
  def('htmlentities', 'string $string, int $flags=, ?string $encoding=, bool $double_encode=', function (a) { return htmlEsc(a[0], a[1], a[3], true); });
  function htmlDecode(s, flags, all) {
    flags = flags === undefined ? 11 : I(flags);
    var quotes = flags & 3;
    return utf8(unutf8(s).replace(/&(#[0-9]+|#[xX][0-9A-Fa-f]+|[A-Za-z][A-Za-z0-9]*);/g, function (m, e) {
      if (e[0] === '#') {
        var cp = e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10);
        if ((cp === 39 && quotes !== 3) || (cp === 34 && !(quotes & 2))) return m;
        return cp > 0 && cp <= 0x10ffff ? String.fromCodePoint(cp) : m;
      }
      if (e === 'amp') return '&'; if (e === 'lt') return '<'; if (e === 'gt') return '>';
      if (e === 'quot') return quotes & 2 ? '"' : m;
      if (e === 'apos') return quotes === 3 && (flags & 48) ? "'" : m;
      if (all) for (var k in ENTITIES) if (ENTITIES[k] === e) return String.fromCodePoint(+k);
      return m;
    }));
  }
  def('htmlspecialchars_decode', 'string $string, int $flags=', function (a) { return htmlDecode(a[0], a[1], false); });
  def('html_entity_decode', 'string $string, int $flags=, ?string $encoding=', function (a) { return htmlDecode(a[0], a[1], true); });
  def('strip_tags', 'string $string, array|string|null $allowed_tags=', function (a) {
    var allowed = {};
    if (a[1] instanceof PArr) a[1].vals().forEach(function (t) { allowed[asciiLower(toStr(t))] = true; });
    else if (typeof a[1] === 'string') (a[1].match(/<([A-Za-z0-9]+)>/g) || []).forEach(function (t) { allowed[asciiLower(t.slice(1, -1))] = true; });
    return a[0].replace(/<!--[\s\S]*?(-->|$)|<\?[\s\S]*?(\?>|$)|<\/?([A-Za-z][A-Za-z0-9]*)[^>]*(>|$)|<\/(?=[^A-Za-z])[^>]*(>|$)|<!(?!--)[^>]*(>|$)/g, function (m, c1, c2, name) {
      if (name && allowed[asciiLower(name)] && m[m.length - 1] === '>') return m;
      return '';
    });
  });
  def('addslashes', 'string $string', function (a) { return a[0].replace(/([\\'"])/g, '\\$1').replace(/\x00/g, '\\0'); });
  def('stripslashes', 'string $string', function (a) { return a[0].replace(/\\(.?)/g, function (m, c) { return c === '0' ? '\x00' : c; }); });
  def('implode', 'array|string $separator, ?array $array=', function (a) {
    var sep = a[0], arr = a[1];
    if (arr === undefined || arr === null) {
      if (!(sep instanceof PArr)) thr('TypeError', 'implode(): Argument #1 ($array) must be of type array, ' + tname(sep) + ' given');
      arr = sep; sep = '';
    } else if (sep instanceof PArr) thr('TypeError', 'implode(): Argument #1 ($separator) must be of type string, array given');
    return arr.vals().map(toStr).join(sep);
  });
  alias('join', 'implode');
  def('explode', 'string $separator, string $string, int $limit=', function (a) {
    if (a[0] === '') vErr('explode', 1, 'separator', 'must not be empty');
    var parts = a[1].split(a[0]), lim = a[2] === undefined ? Infinity : I(a[2]);
    if (lim > 0 && parts.length > lim) parts = parts.slice(0, lim - 1).concat(parts.slice(lim - 1).join(a[0]));
    else if (lim < 0) parts = parts.slice(0, lim);
    else if (lim === 0 && parts.length > 1) parts = [parts.join(a[0])];
    return list(parts);
  });
  def('str_split', 'string $string, int $length=', function (a) {
    var n = a[1] === undefined ? 1 : I(a[1]), out = [];
    if (n < 1) vErr('str_split', 2, 'length', 'must be greater than 0');
    for (var i = 0; i < a[0].length; i += n) out.push(a[0].substr(i, n));
    return list(out);
  });
  def('mb_str_split', 'string $string, int $length=, ?string $encoding=', function (a) {
    var n = a[1] === undefined ? 1 : I(a[1]), c = cps(a[0]), out = [];
    if (n < 1) vErr('mb_str_split', 2, 'length', 'must be greater than 0');
    for (var i = 0; i < c.length; i += n) out.push(fromCps(c.slice(i, i + n)));
    return list(out);
  });
  def('wordwrap', 'string $string, int $width=, string $break=, bool $cut_long_words=', function (a) {
    var text = a[0], width = a[1] === undefined ? 75 : I(a[1]), brk = a[2] === undefined ? '\n' : a[2], cut = !!a[3];
    var bl = brk.length, tl = text.length, cur, last = 0, lastSpace = 0, laststart = 0, out = '';
    if (tl === 0) return '';
    if (bl === 0) vErr('wordwrap', 3, 'break', 'must not be empty');
    if (width === 0 && cut) vErr('wordwrap', 4, 'cut_long_words', 'cannot be true when argument #2 ($width) is 0');
    for (cur = 0; cur < tl; cur++) {
      if (text[cur] === brk[0] && cur + bl < tl && text.substr(cur, bl) === brk) {
        out += text.slice(laststart, cur + bl); cur += bl - 1; laststart = lastSpace = cur + 1;
      } else if (text[cur] === ' ') {
        if (cur - laststart >= width) { out += text.slice(laststart, cur) + brk; laststart = cur + 1; }
        lastSpace = cur;
      } else if (cur - laststart >= width && cut && laststart >= lastSpace) {
        out += text.slice(laststart, cur) + brk; laststart = lastSpace = cur;
      } else if (cur - laststart >= width && laststart < lastSpace) {
        out += text.slice(laststart, lastSpace) + brk; laststart = lastSpace = lastSpace + 1;
      }
    }
    if (laststart !== cur) out += text.slice(laststart, cur);
    return out;
  });
  def('ord', 'string $character', function (a) {
    if (!a[0].length) deprecated('ord(): Providing an empty string is deprecated');
    else if (a[0].length > 1) deprecated('ord(): Providing a string that is not one byte long is deprecated. Use ord($str[0]) instead');
    return a[0].length ? a[0].charCodeAt(0) : 0;
  });
  def('chr', 'int $codepoint', function (a) {
    var n = I(a[0]);
    if (n < 0 || n > 255) deprecated('chr(): Providing a value not in-between 0 and 255 is deprecated, this is because a byte value must be in the [0, 255] interval. The value used will be constrained using % 256');
    return fcc(((n % 256) + 256) % 256);
  });
  def('str_word_count', 'string $string, int $format=, ?string $characters=', function (a) {
    // php_str_word_count: letters, ' and -; the string's first character cannot be ' or -, its last cannot be -
    var extra = a[2] ? charMask(a[2]) : {}, s = a[0], words = [], pos = [], i = 0, e = s.length;
    function isW(c) { return /[A-Za-z'-]/.test(c) || extra[c]; }
    if ((s[0] === "'" && !extra["'"]) || (s[0] === '-' && !extra['-'])) i++;
    if (s[e - 1] === '-' && !extra['-']) e--;
    while (i < e) {
      var st = i;
      while (i < e && isW(s[i])) i++;
      if (i > st) { words.push(s.slice(st, i)); pos.push(st); } else i++;
    }
    var f = a[1] === undefined ? 0 : I(a[1]);
    if (f === 0) return words.length;
    if (f === 1) return list(words);
    if (f === 2) { var r = new PArr(); words.forEach(function (w, k) { r.set(pos[k], w); }); return r; }
    return vErr('str_word_count', 2, 'format', 'must be a valid format value');
  });
  def('str_shuffle', 'string $string', function (a) {
    var c = a[0].split('');
    for (var i = c.length - 1; i > 0; i--) { var j = randInt(0, i), t = c[i]; c[i] = c[j]; c[j] = t; }
    return c.join('');
  });
  def('lcg_value', '', function () { return new Flt(rng()); });
  def('bin2hex', 'string $string', function (a) { var o = ''; for (var i = 0; i < a[0].length; i++) o += ('0' + a[0].charCodeAt(i).toString(16)).slice(-2); return o; });
  def('mb_str_pad', 'string $string, int $length, string $pad_string=, int $pad_type=, ?string $encoding=', function (a) {
    var s = cps(a[0]), pad = cps(a[2] === undefined ? ' ' : a[2]), type = a[3] === undefined ? 1 : I(a[3]), n = I(a[1]) - s.length;
    if (!pad.length) vErr('mb_str_pad', 3, 'pad_string', 'must not be empty');
    if (n <= 0) return a[0];
    var left = type === 0 ? n : type === 2 ? Math.floor(n / 2) : 0, right = n - left;
    function fill(k) { var o = []; while (o.length < k) o = o.concat(pad); return o.slice(0, k); }
    return fromCps(fill(left).concat(s, fill(right)));
  });

  /* ---------------------------------------------------------------- builtins: printing values */

  function vdump(v, ind) {
    if (v === null || v === undefined) return ind + 'NULL\n';
    switch (typeof v) {
      case 'boolean': return ind + 'bool(' + v + ')\n';
      case 'number': case 'bigint': return ind + 'int(' + v + ')\n';
      case 'string': return ind + 'string(' + v.length + ') "' + v + '"\n';
    }
    if (v instanceof Flt) return ind + 'float(' + fmtFloat(v.v, 17) + ')\n';
    if (v instanceof PArr) {
      var s = ind + 'array(' + v.m.size + ') {\n';
      v.m.forEach(function (x, k) {
        var amp = x instanceof Ref && x.n > 1;
        s += ind + '  [' + (typeof k === 'string' ? '"' + k + '"' : k) + ']=>\n' + (amp ? vdump(x.v, ind + '  ').replace(/^(\s*)/, '$1&') : vdump(x instanceof Ref ? x.v : x, ind + '  '));
      });
      return s + ind + '}\n';
    }
    return ind + 'object(Closure)#' + (v.id || 1) + ' (0) {\n' + ind + '}\n';
  }
  function printR(v, ind) {
    if (v instanceof PArr) {
      var s = 'Array\n' + ind + '(\n';
      v.m.forEach(function (x, k) {
        x = x instanceof Ref ? x.v : x;
        s += ind + '    [' + k + '] => ' + (x instanceof PArr ? printR(x, ind + '        ') + '\n' : (x instanceof Closure ? 'Closure Object\n' + ind + '        (\n' + ind + '        )\n\n' : toStr(x) + '\n'));
      });
      return s + ind + ')\n';
    }
    if (v instanceof Closure) return 'Closure Object\n' + ind + '(\n' + ind + ')\n';
    return toStr(v);
  }
  function exportStr(s) {
    var o = "'" + s.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    return o.replace(/\x00/g, '\' . "\\0" . \'');
  }
  function varExport(v, ind) {
    if (v === null || v === undefined) return 'NULL';
    switch (typeof v) {
      case 'boolean': return v ? 'true' : 'false';
      case 'number': case 'bigint': return '' + v;
      case 'string': return exportStr(v);
    }
    if (v instanceof Flt) { var f = fmtFloat(v.v, 17); return /^-?\d+$/.test(f) ? f + '.0' : f; }
    if (v instanceof PArr) {
      var s = 'array (\n';
      v.m.forEach(function (x, k) {
        x = x instanceof Ref ? x.v : x;
        s += ind + '  ' + (typeof k === 'string' ? exportStr(k) : k) + ' => ' + (x instanceof PArr ? '\n' + ind + '  ' + varExport(x, ind + '  ') : varExport(x, ind + '  ')) + ',\n';
      });
      return s + ind + ')';
    }
    return '\\Closure::__set_state(array(\n' + ind + '))';
  }
  def('var_dump', 'mixed $value, mixed ...$values', function (a) { a.forEach(function (v) { out(vdump(v, ''), true, R.sl); }); return null; });
  def('print_r', 'mixed $value, bool $return=', function (a) { var s = printR(a[0], ''); if (a[1]) return s; out(s, true, R.sl); return true; });
  def('var_export', 'mixed $value, bool $return=', function (a) { var s = varExport(a[0], ''); if (a[1]) return s; out(s, true, R.sl); return null; });
  function jsonEnc(v, flags, ind) {
    var pretty = flags & 128, nl = pretty ? '\n' : '', sp = pretty ? ' ' : '';
    if (v === null || v === undefined) return 'null';
    switch (typeof v) {
      case 'boolean': return v ? 'true' : 'false';
      case 'number': case 'bigint': return '' + v;
      case 'string': {
        var t = unutf8(v), o = '"';
        for (var i = 0; i < t.length; i++) {
          var c = t[i], code = t.charCodeAt(i);
          if (c === '"') o += '\\"'; else if (c === '\\') o += '\\\\'; else if (c === '/') o += flags & 64 ? '/' : '\\/';
          else if (c === '\n') o += '\\n'; else if (c === '\r') o += '\\r'; else if (c === '\t') o += '\\t';
          else if (c === '\b') o += '\\b'; else if (c === '\f') o += '\\f';
          else if (code < 0x20 || (code > 0x7e && !(flags & 256))) o += '\\u' + ('000' + code.toString(16)).slice(-4);
          else o += c;
        }
        return utf8(o + '"');
      }
    }
    if (v instanceof Flt) { if (!isFinite(v.v)) return '0'; return fmtFloat(v.v, 17); }
    if (v instanceof PArr) {
      var isList = true, idx = 0;
      v.m.forEach(function (x, k) { if (k !== idx++) isList = false; });
      if (!v.m.size) return isList && !(flags & 16) ? '[]' : '{}';
      var parts = [], ind2 = ind + (pretty ? '    ' : '');
      v.m.forEach(function (x, k) {
        x = x instanceof Ref ? x.v : x;
        parts.push(ind2 + (isList ? '' : jsonEnc('' + k, flags, ind2) + ':' + sp) + jsonEnc(x, flags, ind2));
      });
      return (isList ? '[' : '{') + nl + parts.join(',' + nl) + nl + ind + (isList ? ']' : '}');
    }
    return '{}';
  }
  def('json_encode', 'mixed $value, int $flags=, int $depth=', function (a) { return jsonEnc(a[0], a[1] === undefined ? 0 : I(a[1]), ''); });

  /* ---------------------------------------------------------------- builtins: sprintf, number_format */

  function expDigits(x, prec) {
    // x > 0 → mantissa digits (prec + 1 of them, ties to even) and decimal exponent
    var r = sigDigits(x, prec + 1), d = r.d;
    while (d.length < prec + 1) d += '0';
    return { d: d, e: r.e - 1 };
  }
  function formatPrintf(fname, fmt, args) {
    var o = '', i = 0, next = 0, n = fmt.length;
    while (i < n) {
      var c = fmt[i];
      if (c !== '%') { var j = fmt.indexOf('%', i); if (j < 0) j = n; o += fmt.slice(i, j); i = j; continue; }
      i++;
      if (fmt[i] === '%') { o += '%'; i++; continue; }
      var argnum = null, m = /^(\d+)\$/.exec(fmt.slice(i, i + 12));
      if (m) {
        argnum = +m[1] - 1; i += m[0].length;
        if (argnum < 0) thr('ValueError', 'Argument number specifier must be greater than zero and less than 2147483647');
      }
      var left = false, plus = false, pad = ' ';
      for (;;) {
        var f = fmt[i];
        if (f === '-') { left = true; i++; }
        else if (f === '+') { plus = true; i++; }
        else if (f === '0') { pad = '0'; i++; }
        else if (f === ' ') { pad = ' '; i++; }
        else if (f === "'" && i + 1 < n) { pad = fmt[i + 1]; i += 2; }
        else break;
      }
      var width = 0, prec = null;
      if (fmt[i] === '*') { i++; width = I(toInt(args[next++])); }
      else { m = /^\d+/.exec(fmt.slice(i)); if (m) { width = +m[0]; i += m[0].length; } }
      if (fmt[i] === '.') {
        i++;
        if (fmt[i] === '*') { i++; prec = I(toInt(args[next++])); }
        else { m = /^\d+/.exec(fmt.slice(i)); prec = m ? +m[0] : 0; if (m) i += m[0].length; }
      }
      if (fmt[i] === 'l') i++;
      var conv = fmt[i++];
      if (conv === undefined) thr('ValueError', 'Missing format specifier at end of string');
      var ai = argnum !== null ? argnum : next++;
      if (ai >= args.length) {
        if (argnum !== null) thr('ArgumentCountError', 'Argument number must be less than or equal to ' + args.length);
        thr('ArgumentCountError', (ai + 2) + ' arguments are required, ' + (args.length + 1) + ' given');
      }
      var arg = args[ai], s, neg = false;
      switch (conv) {
        case 's': s = toStr(arg); if (prec !== null) s = s.slice(0, prec); break;
        case 'd': case 'i': { var iv = toInt(arg); neg = iv < 0; s = ('' + iv).replace('-', ''); if (plus && !neg) s = '+' + s; break; }
        case 'u': { var uv = toInt(arg); s = uv < 0 ? BigInt.asUintN(64, BigInt(uv)).toString() : '' + uv; break; }
        case 'f': case 'F': {
          var x = toFloat(arg); neg = x < 0;
          if (!isFinite(x)) { s = x !== x ? 'NaN' : 'Inf'; neg = x < 0; if (neg) s = '-' + s; neg = false; pad = ' '; break; }
          s = toFixedEven(Math.abs(x), prec === null ? 6 : Math.min(prec, 53));
          if (plus && !neg) s = '+' + s;
          break;
        }
        case 'e': case 'E': {
          var ex = toFloat(arg), p = prec === null ? 6 : prec;
          neg = ex < 0;
          if (ex === 0) s = '0' + (p ? '.' + zeros(p) : '') + 'e+0';
          else { var ed = expDigits(Math.abs(ex), p); s = ed.d[0] + (p ? '.' + ed.d.slice(1) : '') + 'e' + (ed.e < 0 ? '-' : '+') + Math.abs(ed.e); }
          if (conv === 'E') s = s.toUpperCase();
          if (plus && !neg) s = '+' + s;
          break;
        }
        case 'g': case 'G': {
          var gx = toFloat(arg), gp = prec === null ? 6 : prec === 0 ? 1 : prec;
          neg = gx < 0;
          s = fmtFloat(Math.abs(gx), gp).replace('E', conv === 'g' ? 'e' : 'E');
          if (plus && !neg) s = '+' + s;
          break;
        }
        case 'x': case 'X': case 'o': case 'b': {
          var bv = toInt(arg), radix = conv === 'o' ? 8 : conv === 'b' ? 2 : 16;
          s = (bv < 0 ? BigInt.asUintN(64, BigInt(bv)) : bv).toString(radix);
          if (conv === 'X') s = s.toUpperCase();
          break;
        }
        case 'c': o += fcc(((I(toInt(arg)) % 256) + 256) % 256); continue;
        default: thr('ValueError', 'Unknown format specifier "' + conv + '"');
      }
      if (neg) s = '-' + s;
      if (s.length < width) {
        var fillN = width - s.length;
        if (left) s = s + repeat(pad === '0' && /[dieEfFgG]/.test(conv) ? ' ' : pad, fillN);
        else if (pad === '0' && /[-+]/.test(s[0]) && /[dieEfFgGu]/.test(conv)) s = s[0] + repeat('0', fillN) + s.slice(1);
        else s = repeat(pad, fillN) + s;
      }
      o += s;
    }
    return o;
  }
  def('sprintf', 'string $format, mixed ...$values', function (a) { return formatPrintf('sprintf', a[0], a.slice(1)); });
  def('printf', 'string $format, mixed ...$values', function (a) { var s = formatPrintf('printf', a[0], a.slice(1)); out(s, true, R.sl); return s.length; });
  def('vsprintf', 'string $format, array $values', function (a) { return formatPrintf('vsprintf', a[0], a[1].vals()); });
  def('vprintf', 'string $format, array $values', function (a) { var s = formatPrintf('vprintf', a[0], a[1].vals()); out(s, true, R.sl); return s.length; });
  def('number_format', 'float $num, int $decimals=, ?string $decimal_separator=, ?string $thousands_separator=', function (a) {
    var d = a[0].v, dec = a[1] === undefined ? 0 : I(a[1]), dp = a[2] === undefined || a[2] === null ? '.' : a[2], ts = a[3] === undefined || a[3] === null ? ',' : a[3];
    d = phpRound(d, dec, 1);
    var neg = d < 0;
    if (!isFinite(d)) return (neg ? '-' : '') + (d !== d ? 'nan' : 'inf');
    dec = Math.max(0, dec);
    var s = toFixedEven(Math.abs(d), dec), ip = s.split('.')[0], fp = s.split('.')[1] || '';
    var g = '';
    while (ip.length > 3) { g = ts + ip.slice(-3) + g; ip = ip.slice(0, -3); }
    return (neg ? '-' : '') + ip + g + (dec ? dp + fp : '');
  });

  /* ---------------------------------------------------------------- builtins: math */

  function rng() {
    var t = (R.seed = (R.seed + 0x6D2B79F5) | 0);
    t = Math.imul(t ^ t >>> 15, t | 1);
    t ^= t + Math.imul(t ^ t >>> 7, t | 61);
    return ((t ^ t >>> 14) >>> 0) / 4294967296;
  }
  function randInt(min, max) { return min + Math.floor((rng() + rng() / 4294967296) * (max - min + 1)); }
  def('abs', 'int|float $num', function (a) {
    var v = a[0];
    if (v instanceof Flt) return new Flt(Math.abs(v.v));
    if (typeof v === 'bigint') return v === I64MIN ? new Flt(9223372036854775808) : bigNorm(v < B0 ? -v : v);
    return Math.abs(v);
  });
  def('ceil', 'int|float $num', function (a) { return new Flt(Math.ceil(fv(a[0]))); });
  def('floor', 'int|float $num', function (a) { return new Flt(Math.floor(fv(a[0]))); });
  def('round', 'int|float $num, int $precision=, int $mode=', function (a) {
    var p = a[1] === undefined ? 0 : I(a[1]), v = a[0];
    if (isInt(v) && p >= 0) return new Flt(Number(v));
    return new Flt(phpRound(fv(v), p, a[2] === undefined ? 1 : I(a[2])));
  });
  def('intdiv', 'int $num1, int $num2', function (a) {
    if (a[1] == 0) thr('DivisionByZeroError', 'Division by zero');
    if (typeof a[0] === 'number' && typeof a[1] === 'number') return Math.trunc(a[0] / a[1]) || 0;
    if (BigInt(a[0]) === I64MIN && BigInt(a[1]) === -B1) thr('ArithmeticError', 'Division of PHP_INT_MIN by -1 is not an integer');
    return bigNorm(BigInt(a[0]) / BigInt(a[1]));
  });
  def('fmod', 'float $num1, float $num2', function (a) { return new Flt(a[0].v % a[1].v); });
  def('fdiv', 'float $num1, float $num2', function (a) { return new Flt(a[0].v / a[1].v); });
  def('sqrt', 'float $num', function (a) { return new Flt(Math.sqrt(a[0].v)); });
  def('pow', 'mixed $num, mixed $exponent', function (a) { return arith('**', a[0], a[1]); });
  def('exp', 'float $num', function (a) { return new Flt(Math.exp(a[0].v)); });
  def('log', 'float $num, float $base=', function (a) { return new Flt(a[1] === undefined ? Math.log(a[0].v) : Math.log(a[0].v) / Math.log(a[1].v)); });
  def('log10', 'float $num', function (a) { return new Flt(Math.log10(a[0].v)); });
  def('log2', 'float $num', function (a) { return new Flt(Math.log2(a[0].v)); });
  ['sin', 'cos', 'tan', 'asin', 'acos', 'atan'].forEach(function (f) { def(f, 'float $num', function (a) { return new Flt(Math[f](a[0].v)); }); });
  def('atan2', 'float $y, float $x', function (a) { return new Flt(Math.atan2(a[0].v, a[1].v)); });
  def('hypot', 'float $x, float $y', function (a) { return new Flt(Math.hypot(a[0].v, a[1].v)); });
  def('deg2rad', 'float $num', function (a) { return new Flt(a[0].v / 180 * Math.PI); });
  def('rad2deg', 'float $num', function (a) { return new Flt(a[0].v / Math.PI * 180); });
  def('pi', '', function () { return new Flt(Math.PI); });
  def('is_nan', 'float $num', function (a) { return a[0].v !== a[0].v; });
  def('is_infinite', 'float $num', function (a) { return a[0].v === Infinity || a[0].v === -Infinity; });
  def('is_finite', 'float $num', function (a) { return isFinite(a[0].v); });
  function minmax(fname, a, dir) {
    var vals;
    if (a.length === 1) {
      if (!(a[0] instanceof PArr)) thr('TypeError', fname + '(): Argument #1 ($value) must be of type array, ' + tname(a[0]) + ' given');
      vals = a[0].vals();
      if (!vals.length) vErr(fname, 1, 'value', 'must contain at least one element');
    } else vals = a;
    var best = vals[0];
    var args = a.length > 1;
    for (var i = 1; i < vals.length; i++) if (dir > 0 ? cmp(vals[i], best) > 0 : (args ? cmp(vals[i], best) <= 0 : cmp(vals[i], best) < 0)) best = vals[i];
    return best;
  }
  def('max', 'mixed $value, mixed ...$values', function (a) { return minmax('max', a, 1); });
  def('min', 'mixed $value, mixed ...$values', function (a) { return minmax('min', a, -1); });
  function randRange(fname, a, strictOrder) {
    if (a.length === 0) return randInt(0, 2147483647);
    if (a.length === 1) thr('ArgumentCountError', fname + '() expects exactly 2 arguments, 1 given');
    var lo = I(a[0]), hi = I(a[1]);
    if (hi < lo) {
      if (strictOrder) vErr(fname, 2, 'max', 'must be greater than or equal to argument #1 ($min)');
      return randInt(hi, lo);
    }
    return randInt(lo, hi);
  }
  def('rand', 'int $min=, int $max=', function (a) { return randRange('rand', a, false); });
  def('mt_rand', 'int $min=, int $max=', function (a) { return randRange('mt_rand', a, true); });
  def('random_int', 'int $min, int $max', function (a) {
    if (a[0] > a[1]) vErr('random_int', 1, 'min', 'must be less than or equal to argument #2 ($max)');
    return randInt(I(a[0]), I(a[1]));
  });
  def('mt_srand', 'int $seed=, int $mode=', function (a) { R.seed = a[0] === undefined ? 0 : I(a[0]) | 0; return null; });
  alias('srand', 'mt_srand');
  def('getrandmax', '', function () { return 2147483647; });
  alias('mt_getrandmax', 'getrandmax');
  def('base_convert', 'string $num, int $frombase, int $tobase', function (a) {
    var digits = asciiLower(a[0]).replace(new RegExp('[^0-9a-z]', 'g'), ''), n = B0, fb = BigInt(a[1]);
    for (var i = 0; i < digits.length; i++) { var dv = parseInt(digits[i], 36); if (dv < Number(fb)) n = n * fb + BigInt(dv); }
    return n.toString(I(a[2]));
  });
  def('bindec', 'string $binary_string', function (a) { var d = a[0].replace(/[^01]/g, ''); return d ? bigNorm(BigInt('0b' + d)) : 0; });
  def('hexdec', 'string $hex_string', function (a) { var d = a[0].replace(/[^0-9a-fA-F]/g, ''); return d ? bigNorm(BigInt('0x' + d)) : 0; });
  def('octdec', 'string $octal_string', function (a) { var d = a[0].replace(/[^0-7]/g, ''); return d ? bigNorm(BigInt('0o' + d)) : 0; });
  def('decbin', 'int $num', function (a) { return BigInt.asUintN(64, BigInt(a[0])).toString(2); });
  def('dechex', 'int $num', function (a) { return BigInt.asUintN(64, BigInt(a[0])).toString(16); });
  def('decoct', 'int $num', function (a) { return BigInt.asUintN(64, BigInt(a[0])).toString(8); });

  /* ---------------------------------------------------------------- builtins: types and variables */

  def('gettype', 'mixed $value', function (a) { return gettype(a[0]); });
  def('get_debug_type', 'mixed $value', function (a) { return tname(a[0]); });
  def('intval', 'mixed $value, int $base=', function (a) {
    var v = a[0], base = a[1] === undefined ? 10 : I(a[1]);
    if (typeof v !== 'string' || base === 10) return toInt(v);
    var s = v.trim().toLowerCase(), neg = false;
    if (s[0] === '-' || s[0] === '+') { neg = s[0] === '-'; s = s.slice(1); }
    if (base === 0) { if (/^0x/.test(s)) { base = 16; s = s.slice(2); } else if (/^0b/.test(s)) { base = 2; s = s.slice(2); } else if (/^0o?/.test(s) && s.length > 1) { base = 8; s = s.replace(/^0o?/, ''); } else base = 10; }
    else if (base === 16) s = s.replace(/^0x/, ''); else if (base === 2) s = s.replace(/^0b/, ''); else if (base === 8) s = s.replace(/^0o/, '');
    var n = B0, B = BigInt(base);
    for (var i = 0; i < s.length; i++) { var d = parseInt(s[i], 36); if (isNaN(d) || d >= base) break; n = n * B + BigInt(d); if (n > I64MAX) return neg ? I64MIN : I64MAX; }
    return bigNorm(neg ? -n : n);
  });
  def('floatval', 'mixed $value', function (a) { return new Flt(toFloat(a[0])); });
  alias('doubleval', 'floatval');
  def('strval', 'mixed $value', function (a) { return toStr(a[0]); });
  def('boolval', 'mixed $value', function (a) { return toBool(a[0]); });
  def('settype', 'mixed &$var, string $type', function (a) {
    var t = asciiLower(a[1]), map = { integer: 'int', 'int': 'int', 'float': 'float', 'double': 'float', string: 'string', 'boolean': 'bool', bool: 'bool', array: 'array', 'null': 'null' };
    if (!map[t]) vErr('settype', 2, 'type', 'must be a valid type');
    var v = a[0].get();
    a[0].set(map[t] === 'null' ? null : share(cast(map[t], v === undefined ? null : v)));
    return true;
  });
  def('is_int', 'mixed $value', function (a) { return isInt(a[0]); });
  alias('is_integer', 'is_int'); alias('is_long', 'is_int');
  def('is_float', 'mixed $value', function (a) { return a[0] instanceof Flt; });
  alias('is_double', 'is_float');
  def('is_string', 'mixed $value', function (a) { return typeof a[0] === 'string'; });
  def('is_bool', 'mixed $value', function (a) { return typeof a[0] === 'boolean'; });
  def('is_array', 'mixed $value', function (a) { return a[0] instanceof PArr; });
  def('is_null', 'mixed $value', function (a) { return a[0] === null; });
  def('is_numeric', 'mixed $value', function (a) {
    var v = a[0];
    if (isNum(v)) return true;
    if (typeof v !== 'string') return false;
    var n = numStr(v);
    return !!(n && n.whole);
  });
  def('is_scalar', 'mixed $value', function (a) { var k = kind(a[0]); return k >= 1 && k <= 4; });
  def('is_iterable', 'mixed $value', function (a) { return a[0] instanceof PArr; });
  def('is_countable', 'mixed $value', function (a) { return a[0] instanceof PArr; });
  def('is_object', 'mixed $value', function (a) { return a[0] instanceof Closure; });
  def('is_callable', 'mixed $value, bool $syntax_only=, mixed &$callable_name=', function (a) { return isCallable(a[0]); });
  def('function_exists', 'string $function', function (a) { var l = asciiLower(a[0]).replace(/^\\/, ''); return !!(R.fns[l] || BUILTINS[l]); });
  def('define', 'string $constant_name, mixed $value, bool $case_insensitive=', function (a) {
    if (a[2]) warn('define(): Argument #3 ($case_insensitive) is ignored since declaration of case-insensitive constants is no longer supported');
    return defineConst(a[0], a[1]);
  });
  def('defined', 'string $constant_name', function (a) {
    return Object.prototype.hasOwnProperty.call(R.consts, a[0]) || Object.prototype.hasOwnProperty.call(CONSTS, a[0]);
  });
  def('constant', 'string $name', function (a) { return constant(a[0]); });
  def('compact', 'array|string $var_name, array|string ...$var_names', function (a) {
    var res = new PArr();
    (function add(v) {
      if (v instanceof PArr) { v.vals().forEach(add); return; }
      var name = toStr(v), r = lookup(R.curSc, name);
      if (r && r.v !== undefined) res.set(strKey(name), share(r.v));
      else warn('compact(): Undefined variable $' + name);
    })(list(a));
    return res;
  });
  def('extract', 'array &$array, int $flags=, string $prefix=', function (a) {
    var arr = a[0].get(), n = 0;
    if (!(arr instanceof PArr)) thr('TypeError', 'extract(): Argument #1 ($array) must be of type array, ' + tname(arr) + ' given');
    arr.m.forEach(function (v, k) {
      if (typeof k !== 'string' || !/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/.test(k) || k === 'this' || k === 'GLOBALS') return;
      store(varRef(R.curSc, k), v instanceof Ref ? v.v : v); n++;
    });
    return n;
  });
  def('get_defined_vars', '', function () {
    var res = new PArr();
    R.curSc.vars.forEach(function (r, k) { if (r.v !== undefined) res.set(strKey(k), share(r.v)); });
    return res;
  });
  def('func_get_args', '', function () { return list(R.curSc.args || []); });
  def('func_num_args', '', function () { return (R.curSc.args || []).length; });
  def('error_reporting', '?int $error_level=', function () { return 30719; });
  def('ini_set', 'string $option, string|int|float|bool|null $value', function () { return false; });
  def('ini_get', 'string $option', function (a) { return a[0] === 'display_errors' ? '0' : false; });
  def('set_time_limit', 'int $seconds', function () { return true; });
  def('header', 'string $header, bool $replace=, int $response_code=', function () { return null; });
  def('headers_sent', '', function () { return true; });
  def('http_response_code', 'int $response_code=', function () { return false; });
  def('setlocale', 'int $category, mixed $locales, mixed ...$rest', function () { return false; });
  def('sleep', 'int $seconds', function () { return 0; });
  def('usleep', 'int $microseconds', function () { return null; });
  def('flush', '', function () { return null; });
  def('error_log', 'string $message, int $message_type=, ?string $destination=, ?string $additional_headers=', function () { return true; });
  def('trigger_error', 'string $message, int $error_level=', function (a) {
    var lv = a[1] === undefined ? 1024 : I(a[1]);
    if (lv === 256) fatal(a[0]);
    record(lv === 512 ? 'Warning' : lv === 16384 ? 'Deprecated' : 'Notice', a[0]);
    return true;
  });
  alias('user_error', 'trigger_error');
  def('spl_object_id', 'object $object', function () { return 1; });

  /* ---------------------------------------------------------------- builtins: arrays */

  def('count', 'Countable|array $value, int $mode=', function (a) {
    function rec(arr) { var n = 0; arr.m.forEach(function (v) { v = v instanceof Ref ? v.v : v; n++; if (v instanceof PArr) n += rec(v); }); return n; }
    return a[1] == 1 ? rec(a[0]) : a[0].m.size;
  });
  alias('sizeof', 'count');
  function search(needle, arr, strict) {
    for (var e of arr.m) { var v = e[1] instanceof Ref ? e[1].v : e[1]; if (strict ? strictEq(v, needle) : looseEq(v, needle)) return e[0]; }
    return undefined;
  }
  def('in_array', 'mixed $needle, array $haystack, bool $strict=', function (a) { return search(a[0], a[1], !!a[2]) !== undefined; });
  def('array_search', 'mixed $needle, array $haystack, bool $strict=', function (a) { var k = search(a[0], a[1], !!a[2]); return k === undefined ? false : k; });
  def('array_key_exists', 'mixed $key, array $array', function (a) {
    var k = a[0];
    if (k === null) k = '';
    else if (k instanceof PArr || k instanceof Closure) thr('TypeError', 'array_key_exists(): Argument #1 ($key) must be a valid array offset type');
    return a[1].m.has(normKey(k));
  });
  alias('key_exists', 'array_key_exists');
  def('array_keys', 'array $array, mixed $filter_value=, bool $strict=', function (a) {
    var out = [];
    a[0].m.forEach(function (v, k) {
      v = v instanceof Ref ? v.v : v;
      if (a.length < 2 || (a[2] ? strictEq(v, a[1]) : looseEq(v, a[1]))) out.push(k);
    });
    return list(out);
  });
  def('array_values', 'array $array', function (a) { return list(a[0].vals()); });
  function sumProd(fname, arr, prod) {
    var acc = prod ? 1 : 0;
    arr.vals().forEach(function (v) {
      if (v instanceof PArr || v instanceof Closure) { warn(fname + '(): ' + (prod ? 'Multiplication' : 'Addition') + ' is not supported on type ' + tname(v)); return; }
      var x = arithNum(v);
      if (x === BAD) { warn(fname + '(): ' + (prod ? 'Multiplication' : 'Addition') + ' is not supported on type ' + tname(v)); return; }
      acc = prod ? mulN(acc, x) : addN(acc, x);
    });
    return acc;
  }
  def('array_sum', 'array $array', function (a) { return sumProd('array_sum', a[0], false); });
  def('array_product', 'array $array', function (a) { return sumProd('array_product', a[0], true); });
  def('array_push', 'array &$array, mixed ...$values', function (a) {
    var arr = refArray('array_push', a[0]);
    for (var i = 1; i < a.length; i++) if (!arr.push(share(a[i]))) thr('Error', 'Cannot add element to the array as the next element is already occupied');
    return arr.m.size;
  });
  def('array_pop', 'array &$array', function (a) {
    var arr = refArray('array_pop', a[0]);
    if (!arr.m.size) return null;
    var keys = arr.keys(), k = keys[keys.length - 1], v = arr.get(k);
    arr.del(k);
    if (typeof k !== 'string' && arr.nx !== null && (typeof k === 'number' ? k + 1 : k + B1) == arr.nx) arr.nx = k;
    return v;
  });
  function reindex(arr, entries) {
    arr.m = new Map(); arr.nx = null;
    entries.forEach(function (e) { if (typeof e[0] === 'string') arr.m.set(e[0], e[1]); else arr.push(e[1]); });
  }
  def('array_shift', 'array &$array', function (a) {
    var arr = refArray('array_shift', a[0]);
    if (!arr.m.size) return null;
    var entries = Array.from(arr.m.entries()), first = entries.shift();
    reindex(arr, entries);
    return first[1] instanceof Ref ? first[1].v : first[1];
  });
  def('array_unshift', 'array &$array, mixed ...$values', function (a) {
    var arr = refArray('array_unshift', a[0]), entries = Array.from(arr.m.entries());
    reindex(arr, a.slice(1).map(function (v) { return [0, share(v)]; }).concat(entries));
    return arr.m.size;
  });
  def('array_merge', 'array ...$arrays', function (a) {
    var res = new PArr();
    a.forEach(function (arr, i) {
      if (!(arr instanceof PArr)) thr('TypeError', 'array_merge(): Argument #' + (i + 1) + ' must be of type array, ' + tname(arr) + ' given');
      arr.m.forEach(function (v, k) { v = v instanceof Ref ? v.v : v; if (typeof k === 'string') res.set(k, share(v)); else res.push(share(v)); });
    });
    return res;
  });
  def('array_merge_recursive', 'array ...$arrays', function (a) {
    function merge(dst, src) {
      src.m.forEach(function (v, k) {
        v = v instanceof Ref ? v.v : v;
        if (typeof k !== 'string') { dst.push(share(v)); return; }
        if (!dst.m.has(k)) { dst.set(k, share(v)); return; }
        var cur = dst.get(k), m = cur instanceof PArr ? cur.clone() : list([cur]);
        if (v instanceof PArr) merge(m, v); else m.push(share(v));
        dst.set(k, share(m));
      });
    }
    var res = new PArr();
    a.forEach(function (arr) { merge(res, arr); });
    return res;
  });
  def('array_slice', 'array $array, int $offset, ?int $length=, bool $preserve_keys=', function (a) {
    var entries = Array.from(a[0].m.entries()), r = sliceArgs(entries.length, a[1], a[2]), res = new PArr();
    if (!r) return res;
    entries.slice(r[0], r[0] + r[1]).forEach(function (e) {
      var v = share(e[1] instanceof Ref ? e[1].v : e[1]);
      if (typeof e[0] === 'string' || a[3]) res.set(e[0], v); else res.push(v);
    });
    return res;
  });
  def('array_splice', 'array &$array, int $offset, ?int $length=, mixed $replacement=', function (a) {
    var arr = refArray('array_splice', a[0]), entries = Array.from(arr.m.entries()), n = entries.length;
    var off = I(a[1]); if (off > n) off = n; if (off < 0) off = Math.max(0, n + off);
    var len = a[2] === undefined || a[2] === null ? n - off : I(a[2]);
    if (len < 0) len = Math.max(0, n - off + len); if (off + len > n) len = n - off;
    var removed = entries.slice(off, off + len), rep = a[3] === undefined ? [] : a[3] instanceof PArr ? a[3].vals() : a[3] === null ? [] : [a[3]];
    reindex(arr, entries.slice(0, off).concat(rep.map(function (v) { return [0, share(v)]; }), entries.slice(off + len)));
    var res = new PArr();
    removed.forEach(function (e) { var v = e[1] instanceof Ref ? e[1].v : e[1]; if (typeof e[0] === 'string') res.set(e[0], v); else res.push(v); });
    return res;
  });
  def('array_reverse', 'array $array, bool $preserve_keys=', function (a) {
    var res = new PArr();
    Array.from(a[0].m.entries()).reverse().forEach(function (e) {
      var v = share(e[1] instanceof Ref ? e[1].v : e[1]);
      if (typeof e[0] === 'string' || a[1]) res.set(e[0], v); else res.push(v);
    });
    return res;
  });
  def('array_unique', 'array $array, int $flags=', function (a) {
    var flags = a[1] === undefined ? 2 : I(a[1]), res = new PArr(), seen = new Map(), kept = [];
    a[0].m.forEach(function (v, k) {
      v = v instanceof Ref ? v.v : v;
      if (flags === 2 || flags === 5) { var s = toStr(v); if (seen.has(s)) return; seen.set(s, 1); }
      else {
        for (var i = 0; i < kept.length; i++) if (flags === 1 ? toFloat(kept[i]) === toFloat(v) : looseEq(kept[i], v)) return;
        kept.push(v);
      }
      res.set(k, share(v));
    });
    return res;
  });
  def('array_flip', 'array $array', function (a) {
    var res = new PArr();
    a[0].m.forEach(function (v, k) {
      v = v instanceof Ref ? v.v : v;
      if (typeof v === 'string' || isInt(v)) res.set(normKey(v), k);
      else warn('array_flip(): Can only flip string and integer values, entry skipped');
    });
    return res;
  });
  def('array_fill', 'int $start_index, int $count, mixed $value', function (a) {
    if (a[1] < 0) vErr('array_fill', 2, 'count', 'must be greater than or equal to 0');
    var res = new PArr();
    for (var i = 0; i < a[1]; i++) res.set(i === 0 ? a[0] : addN(a[0], i), share(a[2]));
    return res;
  });
  def('array_fill_keys', 'array $keys, mixed $value', function (a) {
    var res = new PArr();
    a[0].vals().forEach(function (k) { res.set(normKey(isInt(k) ? k : toStr(k)), share(a[1])); });
    return res;
  });
  def('array_pad', 'array $array, int $length, mixed $value', function (a) {
    var vals = Array.from(a[0].m.entries()), n = Math.abs(I(a[1])) - vals.length, res = new PArr(), pads = [];
    for (var i = 0; i < n; i++) pads.push([0, a[2]]);
    reindex(res, a[1] < 0 ? pads.concat(vals) : vals.concat(pads));
    res.m.forEach(function (v) { share(v); });
    return res;
  });
  def('array_combine', 'array $keys, array $values', function (a) {
    var ks = a[0].vals(), vs = a[1].vals();
    if (ks.length !== vs.length) thr('ValueError', 'array_combine(): Argument #1 ($keys) and argument #2 ($values) must have the same number of elements');
    var res = new PArr();
    ks.forEach(function (k, i) { res.set(normKey(isInt(k) ? k : toStr(k)), share(vs[i])); });
    return res;
  });
  def('array_map', '?callable $callback, array $array, array ...$arrays', function (a) {
    var cb = a[0], res = new PArr();
    for (var t = 2; t < a.length; t++) if (!(a[t] instanceof PArr)) thr('TypeError', 'array_map(): Argument #' + (t + 1) + ' must be of type array, ' + tname(a[t]) + ' given');
    if (a.length === 2) {
      if (cb === null) return a[1];
      a[1].m.forEach(function (v, k) { res.set(k, share(callValue(cb, [v instanceof Ref ? v.v : v]))); });
      return res;
    }
    var arrays = a.slice(1).map(function (x) { return x.vals(); }), n = Math.max.apply(null, arrays.map(function (x) { return x.length; }));
    for (var i = 0; i < n; i++) {
      var args = arrays.map(function (x) { return i < x.length ? x[i] : null; });
      res.push(share(cb === null ? list(args) : callValue(cb, args)));
    }
    return res;
  });
  def('array_filter', 'array $array, ?callable $callback=, int $mode=', function (a) {
    var res = new PArr(), cb = a[1], mode = a[2] === undefined ? 0 : I(a[2]);
    a[0].m.forEach(function (v, k) {
      v = v instanceof Ref ? v.v : v;
      var keep = cb === undefined || cb === null ? toBool(v) : toBool(callValue(cb, mode === 2 ? [k] : mode === 1 ? [v, k] : [v]));
      if (keep) res.set(k, share(v));
    });
    return res;
  });
  def('array_reduce', 'array $array, callable $callback, mixed $initial=', function (a) {
    var acc = a[2] === undefined ? null : a[2];
    a[0].vals().forEach(function (v) { acc = callValue(a[1], [acc, v]); });
    return acc;
  });
  def('array_walk', 'array &$array, callable $callback, mixed $arg=', function (a) {
    var arr = refArray('array_walk', a[0]), cb = a[1];
    arr.keys().forEach(function (k) {
      if (!arr.m.has(k)) return;
      var held = arr.m.get(k) instanceof Ref, r = arr.ref(k);
      callValue(cb, a.length > 2 ? [r, k, a[2]] : [r, k]);
      if (!held && arr.m.get(k) === r) arr.m.set(k, r.v);
    });
    return true;
  });
  def('call_user_func', 'callable $callback, mixed ...$args', function (a) { return callValue(a[0], a.slice(1)); });
  def('call_user_func_array', 'callable $callback, array $args', function (a) { return callValue(a[0], a[1].vals()); });
  def('array_key_first', 'array $array', function (a) { var k = a[0].m.keys().next(); return k.done ? null : k.value; });
  def('array_key_last', 'array $array', function (a) { var ks = a[0].keys(); return ks.length ? ks[ks.length - 1] : null; });
  def('array_is_list', 'array $array', function (a) { var i = 0; for (var k of a[0].m.keys()) if (k !== i++) return false; return true; });
  def('array_count_values', 'array $array', function (a) {
    var res = new PArr();
    a[0].vals().forEach(function (v) {
      if (typeof v !== 'string' && !isInt(v)) { warn('array_count_values(): Can only count string and integer values, entry skipped'); return; }
      var k = normKey(v), c = res.get(k);
      res.set(k, c === undefined ? 1 : addN(c, 1));
    });
    return res;
  });
  def('array_column', 'array $array, string|int|null $column_key, string|int|null $index_key=', function (a) {
    var res = new PArr(), ck = a[1] === null ? null : normKey(a[1]), ik = a[2] === undefined || a[2] === null ? null : normKey(a[2]);
    a[0].vals().forEach(function (row) {
      if (!(row instanceof PArr)) return;
      var v;
      if (ck === null) v = row; else { if (!row.m.has(ck)) return; v = row.get(ck); }
      if (ik !== null && row.m.has(ik)) { var kv = row.get(ik); res.set(normKey(isInt(kv) ? kv : toStr(kv)), share(v)); }
      else res.push(share(v));
    });
    return res;
  });
  function setOp(a, keep, byKey, assoc) {
    var res = new PArr(), others = a.slice(1);
    a[0].m.forEach(function (v, k) {
      v = v instanceof Ref ? v.v : v;
      var sv = byKey ? null : toStr(v), found = others.map(function (o) {
        if (byKey) return o.m.has(k);
        if (assoc) return o.m.has(k) && toStr(o.get(k)) === sv;
        for (var w of o.m.values()) if (toStr(w instanceof Ref ? w.v : w) === sv) return true;
        return false;
      });
      if (keep ? found.every(Boolean) : !found.some(Boolean)) res.set(k, share(v));
    });
    return res;
  }
  def('array_diff', 'array $array, array ...$arrays', function (a) { return setOp(a, false, false, false); });
  def('array_diff_key', 'array $array, array ...$arrays', function (a) { return setOp(a, false, true, false); });
  def('array_diff_assoc', 'array $array, array ...$arrays', function (a) { return setOp(a, false, false, true); });
  def('array_intersect', 'array $array, array ...$arrays', function (a) { return setOp(a, true, false, false); });
  def('array_intersect_key', 'array $array, array ...$arrays', function (a) { return setOp(a, true, true, false); });
  def('array_chunk', 'array $array, int $length, bool $preserve_keys=', function (a) {
    if (a[1] < 1) vErr('array_chunk', 2, 'length', 'must be greater than 0');
    var res = new PArr(), cur = null;
    a[0].m.forEach(function (v, k) {
      v = v instanceof Ref ? v.v : v;
      if (!cur || cur.m.size >= a[1]) { cur = new PArr(); res.push(cur); cur.refs = 1; }
      if (a[2]) cur.set(k, share(v)); else cur.push(share(v));
    });
    return res;
  });
  def('range', 'string|int|float $start, string|int|float $end, int|float $step=', function (a) {
    var st = a[0], en = a[1], step = a[2] === undefined ? 1 : a[2], res = new PArr();
    function isNumS(x) { var n = typeof x === 'string' ? numStr(x) : null; return n && n.whole; }
    var fstep = fv(step);
    if (typeof st === 'string' && typeof en === 'string' && st.length >= 1 && en.length >= 1 && !isNumS(st) && !isNumS(en)) {
      var s1 = st.charCodeAt(0), e1 = en.charCodeAt(0), sp = Math.abs(Math.trunc(fstep)) || 1;
      if (Math.abs(fstep) > Math.abs(e1 - s1) && s1 !== e1) vErr('range', 3, 'step', 'must not exceed the specified range');
      if (s1 <= e1) for (var c = s1; c <= e1; c += sp) res.push(fcc(c)); else for (var c2 = s1; c2 >= e1; c2 -= sp) res.push(fcc(c2));
      return res;
    }
    function num(x) { if (typeof x === 'string') { if (x === '') { return 0; } var n = numStr(x); return n ? n.v : 0; } return x; }
    st = num(st); en = num(en);
    if (fstep === 0) vErr('range', 3, 'step', 'cannot be 0');
    var isF = st instanceof Flt || en instanceof Flt || (step instanceof Flt && Math.trunc(fstep) !== fstep);
    var x0 = fv(st), x1 = fv(en), ss = Math.abs(fstep);
    if (x0 !== x1 && ss > Math.abs(x1 - x0)) vErr('range', 3, 'step', 'must not exceed the specified range');
    if (isF) {
      var cnt = Math.floor(Math.abs(x1 - x0) / ss + 1e-9);
      for (var i = 0; i <= cnt; i++) res.push(new Flt(x0 < x1 ? x0 + i * ss : x0 - i * ss));
    } else if (x0 <= x1) { for (var v = x0; v <= x1; v += ss) res.push(v); }
    else for (var w = x0; w >= x1; w -= ss) res.push(w);
    return res;
  });

  /* sorting */
  function sortCmp(flags) {
    flags = flags === undefined ? 0 : I(flags);
    var base = flags & ~8, ci = flags & 8;
    if (base === 1) return function (x, y) { return numCmp(new Flt(toFloat(x)), new Flt(toFloat(y))); };
    if (base === 2 || base === 5) return function (x, y) { var p = toStr(x), q = toStr(y); return ci ? bytesCmp(asciiLower(p), asciiLower(q)) : bytesCmp(p, q); };
    if (base === 6) return function (x, y) { return natCmp(toStr(x), toStr(y), !!ci); };
    return cmp;
  }
  function userCmp(fname, cb) {
    var warned = false;
    return function (x, y) {
      var r = callValue(cb, [x, y]);
      if (typeof r === 'boolean') {
        if (!warned) { deprecated(fname + '(): Returning bool from comparison function is deprecated, return an integer less than, equal to, or greater than zero'); warned = true; }
        if (r) return 1;
        return toBool(callValue(cb, [y, x])) ? -1 : 0;
      }
      return sign(Number(r instanceof Flt ? capInt(r.v) : toInt(r)));
    };
  }
  /* zend_sort: insertion sort up to 16 elements, else a hybrid quicksort — the same comparisons as PHP, so
     that an inconsistent comparison (mixed types) or a callback with side effects behaves as it does there. */
  function zSwap(a, i, j) { var t = a[i]; a[i] = a[j]; a[j] = t; }
  function zSort3(a, i, j, k, c) {
    if (!(c(a[i], a[j]) > 0)) { if (!(c(a[j], a[k]) > 0)) return; zSwap(a, j, k); if (c(a[i], a[j]) > 0) zSwap(a, i, j); return; }
    if (!(c(a[k], a[j]) > 0)) { zSwap(a, i, k); return; }
    zSwap(a, i, j);
    if (c(a[j], a[k]) > 0) zSwap(a, j, k);
  }
  function zSort4(a, i, j, k, l, c) {
    zSort3(a, i, j, k, c);
    if (c(a[k], a[l]) > 0) { zSwap(a, k, l); if (c(a[j], a[k]) > 0) { zSwap(a, j, k); if (c(a[i], a[j]) > 0) zSwap(a, i, j); } }
  }
  function zSort5(a, i, j, k, l, m, c) {
    zSort4(a, i, j, k, l, c);
    if (c(a[l], a[m]) > 0) { zSwap(a, l, m); if (c(a[k], a[l]) > 0) { zSwap(a, k, l); if (c(a[j], a[k]) > 0) { zSwap(a, j, k); if (c(a[i], a[j]) > 0) zSwap(a, i, j); } } }
  }
  function zInsert(a, lo, n, c) {
    switch (n) {
      case 0: case 1: return;
      case 2: if (c(a[lo], a[lo + 1]) > 0) zSwap(a, lo, lo + 1); return;
      case 3: zSort3(a, lo, lo + 1, lo + 2, c); return;
      case 4: zSort4(a, lo, lo + 1, lo + 2, lo + 3, c); return;
      case 5: zSort5(a, lo, lo + 1, lo + 2, lo + 3, lo + 4, c); return;
    }
    var start = lo, end = lo + n, sentry = start + 6, i, j, k;
    for (i = start + 1; i < sentry; i++) {
      j = i - 1;
      if (!(c(a[j], a[i]) > 0)) continue;
      while (j !== start) { j--; if (!(c(a[j], a[i]) > 0)) { j++; break; } }
      for (k = i; k > j; k--) zSwap(a, k, k - 1);
    }
    for (i = sentry; i < end; i++) {
      j = i - 1;
      if (!(c(a[j], a[i]) > 0)) continue;
      for (;;) {
        j -= 2;
        if (!(c(a[j], a[i]) > 0)) { j++; if (!(c(a[j], a[i]) > 0)) j++; break; }
        if (j === start) break;
        if (j === start + 1) { j--; if (c(a[i], a[j]) > 0) j++; break; }
      }
      for (k = i; k > j; k--) zSwap(a, k, k - 1);
    }
  }
  function zSort(a, lo, n, c) {
    for (;;) {
      if (n <= 16) { zInsert(a, lo, n, c); return; }
      var start = lo, end = lo + n, pivot = start + (n >> 1), i, j;
      if (n >= 1024) { var delta = n >> 2; zSort5(a, start, start + delta, pivot, pivot + delta, end - 1, c); }
      else zSort3(a, start, pivot, end - 1, c);
      zSwap(a, start + 1, pivot);
      pivot = start + 1; i = pivot + 1; j = end - 1;
      out: for (;;) {
        while (c(a[pivot], a[i]) > 0) { i++; if (i === j) break out; }
        j--; if (j === i) break;
        while (c(a[j], a[pivot]) > 0) { j--; if (j === i) break out; }
        zSwap(a, i, j);
        i++; if (i === j) break;
      }
      zSwap(a, pivot, i - 1);
      if ((i - 1) - start < end - i) { zSort(a, start, i - start - 1, c); lo = i; n = end - i; }
      else { zSort(a, i, end - i, c); lo = start; n = i - start - 1; }
    }
  }
  function doSort(fname, pl, compare, keep, byKey, rev) {
    var arr = refArray(fname, pl), entries = Array.from(arr.m.entries());
    entries.forEach(function (e, i) { e.push(i); });
    zSort(entries, 0, entries.length, function (x, y) {
      var p = byKey ? x[0] : (x[1] instanceof Ref ? x[1].v : x[1]), q = byKey ? y[0] : (y[1] instanceof Ref ? y[1].v : y[1]);
      return (rev ? compare(q, p) : compare(p, q)) || (x[2] > y[2] ? 1 : x[2] < y[2] ? -1 : 0);
    });
    arr.m = new Map();
    if (keep) { entries.forEach(function (e) { arr.m.set(e[0], e[1]); }); }
    else { entries.forEach(function (e, i) { arr.m.set(i, e[1]); }); arr.nx = entries.length; }
    return true;
  }
  def('sort', 'array &$array, int $flags=', function (a) { return doSort('sort', a[0], sortCmp(a[1]), false, false, false); });
  def('rsort', 'array &$array, int $flags=', function (a) { return doSort('rsort', a[0], sortCmp(a[1]), false, false, true); });
  def('asort', 'array &$array, int $flags=', function (a) { return doSort('asort', a[0], sortCmp(a[1]), true, false, false); });
  def('arsort', 'array &$array, int $flags=', function (a) { return doSort('arsort', a[0], sortCmp(a[1]), true, false, true); });
  def('ksort', 'array &$array, int $flags=', function (a) { return doSort('ksort', a[0], sortCmp(a[1]), true, true, false); });
  def('krsort', 'array &$array, int $flags=', function (a) { return doSort('krsort', a[0], sortCmp(a[1]), true, true, true); });
  def('usort', 'array &$array, callable $callback', function (a) { return doSort('usort', a[0], userCmp('usort', a[1]), false, false, false); });
  def('uasort', 'array &$array, callable $callback', function (a) { return doSort('uasort', a[0], userCmp('uasort', a[1]), true, false, false); });
  def('uksort', 'array &$array, callable $callback', function (a) { return doSort('uksort', a[0], userCmp('uksort', a[1]), true, true, false); });
  def('natsort', 'array &$array', function (a) { return doSort('natsort', a[0], function (x, y) { return natCmp(toStr(x), toStr(y), false); }, true, false, false); });
  def('natcasesort', 'array &$array', function (a) { return doSort('natcasesort', a[0], function (x, y) { return natCmp(toStr(x), toStr(y), true); }, true, false, false); });
  def('shuffle', 'array &$array', function (a) {
    var arr = refArray('shuffle', a[0]), vals = Array.from(arr.m.values());
    for (var i = vals.length - 1; i > 0; i--) { var j = randInt(0, i), t = vals[i]; vals[i] = vals[j]; vals[j] = t; }
    arr.m = new Map(); vals.forEach(function (v, i2) { arr.m.set(i2, v); }); arr.nx = vals.length;
    return true;
  });
  def('array_rand', 'array $array, int $num=', function (a) {
    var keys = a[0].keys(), n = a[1] === undefined ? 1 : I(a[1]);
    if (!keys.length) vErr('array_rand', 1, 'array', 'must not be empty');
    if (n < 1 || n > keys.length) vErr('array_rand', 2, 'num', 'must be between 1 and the number of elements in argument #1 ($array)');
    if (n === 1) return keys[randInt(0, keys.length - 1)];
    var idx = keys.map(function (k, i) { return i; });
    for (var i = idx.length - 1; i > 0; i--) { var j = randInt(0, i), t = idx[i]; idx[i] = idx[j]; idx[j] = t; }
    return list(idx.slice(0, n).sort(function (x, y) { return x - y; }).map(function (i3) { return keys[i3]; }));
  });
  /* the internal pointer (reset, end, current, next, prev, key) */
  function ptrArr(fname, pl) { var v = pl.get ? pl.get() : pl; if (!(v instanceof PArr)) thr('TypeError', fname + '(): Argument #1 ($array) must be of type array, ' + tname(v) + ' given'); return v; }
  function ptrVal(arr) { var ks = arr.keys(), p = arr.pos || 0; return p < ks.length ? arr.get(ks[p]) : false; }
  def('reset', 'array &$array', function (a) { var arr = ptrArr('reset', a[0]); arr.pos = 0; return ptrVal(arr); });
  def('end', 'array &$array', function (a) { var arr = ptrArr('end', a[0]); arr.pos = Math.max(0, arr.m.size - 1); return ptrVal(arr); });
  def('next', 'array &$array', function (a) { var arr = ptrArr('next', a[0]); arr.pos = (arr.pos || 0) + 1; return ptrVal(arr); });
  def('prev', 'array &$array', function (a) { var arr = ptrArr('prev', a[0]); arr.pos = (arr.pos || 0) - 1; if (arr.pos < 0) { arr.pos = arr.m.size; return false; } return ptrVal(arr); });
  def('current', 'array $array', function (a) { return ptrVal(a[0]); });
  alias('pos', 'current');
  def('key', 'array $array', function (a) { var ks = a[0].keys(), p = a[0].pos || 0; return p < ks.length ? ks[p] : null; });

  /* ---------------------------------------------------------------- builtins: date and time (Europe/Paris) */

  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  function utcDate(y, mo, d, h, i, s) { var dt = new Date(0); dt.setUTCFullYear(y, mo, d); dt.setUTCHours(h, i, s, 0); return dt; }
  function lastSunday(y, m) { var d = utcDate(y, m, 0, 1, 0, 0); return d.getTime() / 1000 - d.getUTCDay() * 86400; }
  /** Europe/Paris: CET, CEST from the last Sunday of March to the last Sunday of October (September before 1996). */
  function tzOffset(ts) {
    var y = new Date(ts * 1000).getUTCFullYear();
    if (y < 1976) return 3600;
    return ts >= lastSunday(y, 3) && ts < lastSunday(y, y >= 1996 ? 10 : 9) ? 7200 : 3600;
  }
  function localToTs(y, mo, d, h, i, s) {
    var naive = Math.floor(utcDate(y, mo - 1, d, h, i, s).getTime() / 1000);
    var off = tzOffset(naive - 3600), ts = naive - off;
    if (tzOffset(ts) !== off) ts = naive - tzOffset(ts);
    return ts;
  }
  function isLeap(y) { return y % 4 === 0 && (y % 100 !== 0 || y % 400 === 0); }
  function daysIn(y, m) { return [31, isLeap(y) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][m - 1]; }
  function weeksIn(y) { var j = utcDate(y, 0, 1, 0, 0, 0).getUTCDay(); return j === 4 || (isLeap(y) && j === 3) ? 53 : 52; }
  function p2(n) { return (n < 10 ? '0' : '') + n; }
  function dateFmt(fmt, ts) {
    var off = tzOffset(ts), dt = new Date((ts + off) * 1000);
    var Y = dt.getUTCFullYear(), n = dt.getUTCMonth() + 1, j = dt.getUTCDate(), G = dt.getUTCHours(), mi = dt.getUTCMinutes(), s = dt.getUTCSeconds(), w = dt.getUTCDay();
    var z = j - 1; for (var m = 1; m < n; m++) z += daysIn(Y, m);
    var o = '';
    for (var k = 0; k < fmt.length; k++) {
      var c = fmt[k];
      switch (c) {
        case 'd': o += p2(j); break;
        case 'D': o += DAYS[w].slice(0, 3); break;
        case 'j': o += j; break;
        case 'l': o += DAYS[w]; break;
        case 'N': o += w || 7; break;
        case 'S': o += (j % 10 === 1 && j !== 11) ? 'st' : (j % 10 === 2 && j !== 12) ? 'nd' : (j % 10 === 3 && j !== 13) ? 'rd' : 'th'; break;
        case 'w': o += w; break;
        case 'z': o += z; break;
        case 'W': case 'o': {
          var wk = Math.floor((z + 1 - (w || 7) + 10) / 7), wy = Y;
          if (wk < 1) { wy = Y - 1; wk = weeksIn(wy); } else if (wk > weeksIn(Y)) { wk = 1; wy = Y + 1; }
          o += c === 'W' ? p2(wk) : wy;
          break;
        }
        case 'F': o += MONTHS[n - 1]; break;
        case 'm': o += p2(n); break;
        case 'M': o += MONTHS[n - 1].slice(0, 3); break;
        case 'n': o += n; break;
        case 't': o += daysIn(Y, n); break;
        case 'L': o += isLeap(Y) ? 1 : 0; break;
        case 'Y': o += Y < 0 ? '-' + ('000' + -Y).slice(-4) : ('000' + Y).slice(-4); break;
        case 'y': o += p2(Y % 100); break;
        case 'a': o += G < 12 ? 'am' : 'pm'; break;
        case 'A': o += G < 12 ? 'AM' : 'PM'; break;
        case 'B': o += ('00' + Math.floor((((ts + 3600) % 86400 + 86400) % 86400) / 86.4)).slice(-3); break;
        case 'g': o += G % 12 || 12; break;
        case 'G': o += G; break;
        case 'h': o += p2(G % 12 || 12); break;
        case 'H': o += p2(G); break;
        case 'i': o += p2(mi); break;
        case 's': o += p2(s); break;
        case 'u': o += '000000'; break;
        case 'v': o += '000'; break;
        case 'e': o += 'Europe/Paris'; break;
        case 'I': o += off === 7200 ? 1 : 0; break;
        case 'O': o += '+0' + off / 3600 + '00'; break;
        case 'P': case 'p': o += '+0' + off / 3600 + ':00'; break;
        case 'T': o += off === 7200 ? 'CEST' : 'CET'; break;
        case 'Z': o += off; break;
        case 'c': o += dateFmt('Y-m-d\\TH:i:sP', ts); break;
        case 'r': o += dateFmt('D, d M Y H:i:s O', ts); break;
        case 'U': o += ts; break;
        case '\\': k++; if (k < fmt.length) o += fmt[k]; break;
        default: o += c;
      }
    }
    return o;
  }
  def('date', 'string $format, ?int $timestamp=', function (a) { return dateFmt(a[0], a[1] === undefined || a[1] === null ? R.now : I(a[1])); });
  def('time', '', function () { return R.now; });
  def('mktime', 'int $hour, ?int $minute=, ?int $second=, ?int $month=, ?int $day=, ?int $year=', function (a) {
    var now = new Date((R.now + tzOffset(R.now)) * 1000);
    function g(i, d) { return a[i] === undefined || a[i] === null ? d : I(a[i]); }
    var y = g(5, now.getUTCFullYear());
    if (a[5] !== undefined && a[5] !== null) { if (y >= 0 && y < 70) y += 2000; else if (y >= 70 && y <= 100) y += 1900; }
    return localToTs(y, g(3, now.getUTCMonth() + 1), g(4, now.getUTCDate()), g(0, now.getUTCHours()), g(1, now.getUTCMinutes()), g(2, now.getUTCSeconds()));
  });
  def('checkdate', 'int $month, int $day, int $year', function (a) {
    var m = I(a[0]), d = I(a[1]), y = I(a[2]);
    return m >= 1 && m <= 12 && y >= 1 && y <= 32767 && d >= 1 && d <= daysIn(y, m);
  });
  def('date_default_timezone_get', '', function () { return 'Europe/Paris'; });
  def('date_default_timezone_set', 'string $timezoneId', function () { return true; });
  def('microtime', 'bool $as_float=', function (a) { return a[0] ? new Flt(R.now) : '0.00000000 ' + R.now; });
  def('hrtime', 'bool $as_number=', function () { return R.steps * 1000; });

  /** strtotime(): the formats a beginner writes — ISO / French / US dates, times, now/today/tomorrow/yesterday/midnight/noon,
      "+1 day", "-2 weeks 3 hours", "3 days ago", "next monday", "last friday", "monday", "first/last day of next month", "@ts". */
  var MONTH_RE = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
  var DAY_RE = 'mon(?:day)?|tue(?:s(?:day)?)?|wed(?:nesday)?|thu(?:rs(?:day)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?';
  function monthIndex(s) { return 'janfebmaraprmayjunjulaugsepoctnovdec'.indexOf(s.slice(0, 3)) / 3 + 1; }
  function dayIndex(s) { return ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].indexOf(s.slice(0, 3)); }
  function strtotime(str, base) {
    var s = str.toLowerCase().replace(/^\s+|\s+$/g, ''), off = tzOffset(base), b = new Date((base + off) * 1000);
    var t = { y: b.getUTCFullYear(), m: b.getUTCMonth() + 1, d: b.getUTCDate(), H: b.getUTCHours(), i: b.getUTCMinutes(), s: b.getUTCSeconds() };
    var rel = { y: 0, m: 0, d: 0, H: 0, i: 0, s: 0 }, wd = null, dayOf = null, weekOf = null, timeSet = false, dateSet = false, m, pos = 0;
    var units = { sec: 's', second: 's', min: 'i', minute: 'i', hour: 'H', day: 'd', week: 'w', fortnight: 'f', month: 'm', year: 'y' };
    function addRel(unit, n) {
      unit = unit.replace(/s$/, '');
      if (unit === 'w') unit = 'week';
      var u = units[unit] || units[unit.replace(/s$/, '')];
      if (u === 'w') rel.d += 7 * n; else if (u === 'f') rel.d += 14 * n; else rel[u] += n;
    }
    function eat(re, look) { var r = new RegExp('(?:' + re + ')' + (look || '(?![a-z0-9])'), 'y'); r.lastIndex = pos; var x = r.exec(s); if (x) pos += x[0].length; return x; }
    if ((m = /^@(-?\d+)$/.exec(s))) return +m[1];
    if (s === '') return false;
    while (pos < s.length) {
      if (/[\s,]/.test(s[pos])) { pos++; continue; }
      if (s[pos] === 't' && dateSet && /\d/.test(s[pos + 1] || '')) { pos++; continue; }
      if ((m = eat('(\\d{4})-(\\d{1,2})-(\\d{1,2})', '(?![a-su-z0-9])'))) { t.y = +m[1]; t.m = +m[2]; t.d = +m[3]; dateSet = true; continue; }
      if ((m = eat('(\\d{4})/(\\d{1,2})/(\\d{1,2})'))) { t.y = +m[1]; t.m = +m[2]; t.d = +m[3]; dateSet = true; continue; }
      if ((m = eat('(\\d{1,2})/(\\d{1,2})/(\\d{4}|\\d{2})'))) { t.m = +m[1]; t.d = +m[2]; t.y = +m[3] < 100 ? +m[3] + (+m[3] < 70 ? 2000 : 1900) : +m[3]; dateSet = true; continue; }
      if ((m = eat('(\\d{1,2})[-.](\\d{1,2})[-.](\\d{4})'))) { t.d = +m[1]; t.m = +m[2]; t.y = +m[3]; dateSet = true; continue; }
      if ((m = eat('(\\d{1,2})(?:st|nd|rd|th)?[ -]?(' + MONTH_RE + ')[ -,]*(\\d{4})?'))) { t.d = +m[1]; t.m = monthIndex(m[2]); if (m[3]) t.y = +m[3]; dateSet = true; continue; }
      if ((m = eat('(' + MONTH_RE + ')\\s+(\\d{1,2})(?:st|nd|rd|th)?(?:,?\\s+(\\d{4}))?'))) { t.m = monthIndex(m[1]); t.d = +m[2]; if (m[3]) t.y = +m[3]; dateSet = true; continue; }
      if ((m = eat('(\\d{1,2}):(\\d{2})(?::(\\d{2}))?\\s*(am|pm)?'))) {
        var h = +m[1]; if (m[4]) { h = h % 12 + (m[4] === 'pm' ? 12 : 0); }
        t.H = h; t.i = +m[2]; t.s = m[3] ? +m[3] : 0; timeSet = true; continue;
      }
      if ((m = eat('(\\d{1,2})\\s*(am|pm)'))) { t.H = +m[1] % 12 + (m[2] === 'pm' ? 12 : 0); t.i = t.s = 0; timeSet = true; continue; }
      if (eat('now')) continue;
      if (eat('today|midnight')) { t.H = t.i = t.s = 0; timeSet = true; continue; }
      if (eat('noon')) { t.H = 12; t.i = t.s = 0; timeSet = true; continue; }
      if (eat('tomorrow')) { rel.d += 1; t.H = t.i = t.s = 0; timeSet = true; continue; }
      if (eat('yesterday')) { rel.d -= 1; t.H = t.i = t.s = 0; timeSet = true; continue; }
      if ((m = eat('(first|last) day of'))) { dayOf = m[1]; continue; }
      if ((m = eat('([+-]?)\\s*(\\d+)\\s*(sec|secs|second|seconds|min|mins|minute|minutes|hour|hours|day|days|week|weeks|fortnight|fortnights|month|months|year|years)'))) {
        var n = +m[2] * (m[1] === '-' ? -1 : 1);
        var save = pos;
        if (eat('\\s*ago')) n = -n; else pos = save;
        addRel(m[3], n); continue;
      }
      if ((m = eat('(next|last|previous|this)\\s+(' + DAY_RE + ')'))) { wd = { day: dayIndex(m[2]), dir: m[1] === 'next' ? 1 : m[1] === 'this' ? 0 : -1 }; t.H = t.i = t.s = 0; timeSet = true; continue; }
      if ((m = eat('(next|last|previous|this)\\s+week'))) { weekOf = m[1] === 'next' ? 1 : m[1] === 'this' ? 0 : -1; continue; }
      if ((m = eat('(next|last|previous|this)\\s+(sec|second|min|minute|hour|day|fortnight|month|year)'))) { addRel(m[2], m[1] === 'next' ? 1 : m[1] === 'this' ? 0 : -1); continue; }
      if ((m = eat('(' + DAY_RE + ')'))) { wd = { day: dayIndex(m[1]), dir: 0 }; t.H = t.i = t.s = 0; timeSet = true; continue; }
      if ((m = eat('(' + MONTH_RE + ')'))) { t.m = monthIndex(m[1]); dateSet = true; continue; }
      return false;
    }
    if (dateSet && !timeSet) { t.H = t.i = t.s = 0; }
    if (t.m < 1 || t.m > 12 || t.d < 0 || t.d > 31 || t.H > 24 || t.i > 59 || t.s > 60) return false;
    var y = t.y + rel.y, mo = t.m + rel.m, d = t.d + rel.d;
    if (dayOf) { var first = utcDate(y, mo - 1, 1, 0, 0, 0); y = first.getUTCFullYear(); mo = first.getUTCMonth() + 1; d = dayOf === 'first' ? 1 : daysIn(y, mo); }
    var dt = utcDate(y, mo - 1, d, t.H + rel.H, t.i + rel.i, t.s + rel.s);
    if (weekOf !== null) dt = new Date(dt.getTime() + (weekOf * 7 - (dt.getUTCDay() + 6) % 7) * 86400000);
    if (wd) {
      var cur = dt.getUTCDay(), delta = (wd.day - cur + 7) % 7;
      if (wd.dir > 0 && delta === 0) delta = 7;
      if (wd.dir < 0) delta = delta === 0 ? -7 : delta - 7;
      dt = new Date(dt.getTime() + delta * 86400000);
    }
    return localToTs(dt.getUTCFullYear(), dt.getUTCMonth() + 1, dt.getUTCDate(), dt.getUTCHours(), dt.getUTCMinutes(), dt.getUTCSeconds());
  }
  def('strtotime', 'string $datetime, ?int $baseTimestamp=', function (a) { return strtotime(a[0], a[1] === undefined || a[1] === null ? R.now : I(a[1])); });
  def('cal_days_in_month', 'int $calendar, int $month, int $year', function (a) {
    if (a[1] < 1 || a[1] > 12) vErr('cal_days_in_month', 2, 'month', 'must be between 1 and 12 (inclusive)');
    return daysIn(I(a[2]), I(a[1]));
  });

  /* ---------------------------------------------------------------- builtins: filter_var */

  function filterOpt(opts, name) {
    if (!(opts instanceof PArr)) return undefined;
    var o = opts.get('options');
    return o instanceof PArr ? o.get(name) : undefined;
  }
  def('filter_var', 'mixed $value, int $filter=, array|int $options=', function (a) {
    var v = a[0], f = a[1] === undefined ? 516 : I(a[1]), opts = a[2], flags = opts instanceof PArr ? I(toInt(opts.get('flags') || 0)) : I(opts || 0);
    var fail = flags & 134217728 ? null : false;
    if (v instanceof PArr || v instanceof Closure) return fail;
    var str = typeof v === 'boolean' ? (v ? '1' : '') : toStr(v), t = str.replace(/^[ \t\n\r\v\x00]+|[ \t\n\r\v\x00]+$/g, '');
    switch (f) {
      case 257: {
        if (!/^[+-]?(0|[1-9][0-9]*)$/.test(t)) return fail;
        var iv = intStr(t);
        if (iv instanceof Flt) return fail;
        var lo = filterOpt(opts, 'min_range'), hi = filterOpt(opts, 'max_range');
        if ((lo !== undefined && lt(iv, lo)) || (hi !== undefined && lt(hi, iv))) return fail;
        return iv;
      }
      case 259: {
        if (!/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/.test(t)) return fail;
        return new Flt(parseFloat(t));
      }
      case 258: {
        var low = asciiLower(t);
        if (/^(1|true|on|yes)$/.test(low)) return true;
        if (/^(0|false|off|no|)$/.test(low)) return false;
        return flags & 134217728 ? null : false;
      }
      case 274: {
        var at = str.lastIndexOf('@'), local = str.slice(0, at), dom = str.slice(at + 1);
        if (at < 1 || local.length > 64 || /^\.|\.\.|\.$/.test(local) || !/^[A-Za-z0-9.!#$%&'*+\/=?^_`{|}~-]+$/.test(local)) return false;
        if (!/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/.test(dom)) return false;
        return str;
      }
      case 273: return /^[a-z][a-z0-9+.-]*:\/\/[^\s\/?#@]+(:\d+)?([\/?#][^\s]*)?$/i.test(str) ? str : false;
      case 275: return /^((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/.test(str) ? str : false;
      case 272: { var re = filterOpt(opts, 'regexp'); return re !== undefined && pregMatch(toStr(re), str) ? str : false; }
      case 515: return str.replace(/[&"'<>\x00-\x1f]/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; });
      case 522: return htmlEsc(str, 3, true, false);
      case 517: return str.replace(/[^A-Za-z0-9.!#$%&'*+\-=?^_`{|}~@\[\]]/g, '');
      case 519: return str.replace(/[^0-9+-]/g, '');
      case 520: return str.replace(flags & 4096 ? /[^0-9+.\-]/g : /[^0-9+-]/g, '');
      case 516: case 513: return str;
    }
    return thr('ValueError', 'filter_var(): Argument #2 ($filter) must be a valid filter ID');
  });
  function pregMatch(re, s) {
    var m = /^(.)([\s\S]*)\1([imsxu]*)$/.exec(re);
    if (!m) return false;
    try { return new RegExp(m[2], m[3].replace(/[xu]/g, '')).test(s); } catch (e) { return false; }
  }
  def('preg_match', 'string $pattern, string $subject, mixed &$matches=, int $flags=, int $offset=', function (a) {
    var m = /^(.)([\s\S]*)\1([imsxu]*)$/.exec(a[0]);
    if (!m || /[A-Za-z0-9\\\s]/.test(a[0][0])) { warn('preg_match(): Delimiter must not be alphanumeric, backslash, or NUL'); return false; }
    var re;
    try { re = new RegExp(m[2], m[3].replace(/[xu]/g, '')); } catch (e) { warn('preg_match(): Compilation failed'); return false; }
    var r = re.exec(a[1]);
    if (a[2]) a[2].set(r ? list(r.map(function (x) { return x === undefined ? '' : x; })) : new PArr());
    return r ? 1 : 0;
  });

  /* ---------------------------------------------------------------- builtins: hashes */

  function md5(s) {
    var K = [], S = [7, 12, 17, 22, 5, 9, 14, 20, 4, 11, 16, 23, 6, 10, 15, 21], i;
    for (i = 0; i < 64; i++) K[i] = Math.floor(Math.abs(Math.sin(i + 1)) * 4294967296) | 0;
    var n = s.length, words = [], bitLen = n * 8;
    for (i = 0; i < n; i++) words[i >> 2] |= s.charCodeAt(i) << ((i % 4) * 8);
    words[n >> 2] |= 0x80 << ((n % 4) * 8);
    var total = (((n + 8) >> 6) + 1) * 16;
    for (i = 0; i < total; i++) words[i] = words[i] | 0;
    words[total - 2] = bitLen | 0; words[total - 1] = Math.floor(bitLen / 4294967296);
    var a0 = 0x67452301, b0 = 0xefcdab89 | 0, c0 = 0x98badcfe | 0, d0 = 0x10325476;
    for (var blk = 0; blk < total; blk += 16) {
      var A = a0, B = b0, C = c0, D = d0;
      for (i = 0; i < 64; i++) {
        var F, g, r = Math.floor(i / 16);
        if (r === 0) { F = (B & C) | (~B & D); g = i; }
        else if (r === 1) { F = (D & B) | (~D & C); g = (5 * i + 1) % 16; }
        else if (r === 2) { F = B ^ C ^ D; g = (3 * i + 5) % 16; }
        else { F = C ^ (B | ~D); g = (7 * i) % 16; }
        var tmp = D; D = C; C = B;
        var x = (A + F + K[i] + words[blk + g]) | 0, sh = S[r * 4 + i % 4];
        B = (B + ((x << sh) | (x >>> (32 - sh)))) | 0; A = tmp;
      }
      a0 = (a0 + A) | 0; b0 = (b0 + B) | 0; c0 = (c0 + C) | 0; d0 = (d0 + D) | 0;
    }
    return [a0, b0, c0, d0].map(function (w) { var h = ''; for (var k = 0; k < 4; k++) h += ('0' + ((w >>> (k * 8)) & 255).toString(16)).slice(-2); return h; }).join('');
  }
  function shaWords(s) {
    var n = s.length, words = [], i;
    for (i = 0; i < n; i++) words[i >> 2] |= s.charCodeAt(i) << (24 - (i % 4) * 8);
    words[n >> 2] |= 0x80 << (24 - (n % 4) * 8);
    var total = (((n + 8) >> 6) + 1) * 16;
    for (i = 0; i < total; i++) words[i] = words[i] | 0;
    words[total - 1] = (n * 8) | 0; words[total - 2] = Math.floor(n * 8 / 4294967296);
    return words;
  }
  function hexWords(ws) { return ws.map(function (w) { return ('00000000' + (w >>> 0).toString(16)).slice(-8); }).join(''); }
  function rotl(x, n) { return (x << n) | (x >>> (32 - n)); }
  function sha1(s) {
    var w = shaWords(s), h = [0x67452301, 0xefcdab89 | 0, 0x98badcfe | 0, 0x10325476, 0xc3d2e1f0 | 0], W = [];
    for (var blk = 0; blk < w.length; blk += 16) {
      var a = h[0], b = h[1], c = h[2], d = h[3], e = h[4];
      for (var t = 0; t < 80; t++) {
        W[t] = t < 16 ? w[blk + t] : rotl(W[t - 3] ^ W[t - 8] ^ W[t - 14] ^ W[t - 16], 1);
        var f = t < 20 ? (b & c) | (~b & d) : t < 40 ? b ^ c ^ d : t < 60 ? (b & c) | (b & d) | (c & d) : b ^ c ^ d;
        var k = t < 20 ? 0x5a827999 : t < 40 ? 0x6ed9eba1 : t < 60 ? 0x8f1bbcdc | 0 : 0xca62c1d6 | 0;
        var tmp = (rotl(a, 5) + f + e + k + W[t]) | 0;
        e = d; d = c; c = rotl(b, 30); b = a; a = tmp;
      }
      h[0] = (h[0] + a) | 0; h[1] = (h[1] + b) | 0; h[2] = (h[2] + c) | 0; h[3] = (h[3] + d) | 0; h[4] = (h[4] + e) | 0;
    }
    return hexWords(h);
  }
  var K256 = [0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01,
    0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f,
    0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b, 0xc24b8b70,
    0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070, 0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a,
    0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2];
  function sha256(s) {
    var w = shaWords(s), h = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19], W = [];
    for (var blk = 0; blk < w.length; blk += 16) {
      var a = h[0], b = h[1], c = h[2], d = h[3], e = h[4], f = h[5], g = h[6], hh = h[7];
      for (var t = 0; t < 64; t++) {
        if (t < 16) W[t] = w[blk + t];
        else {
          var x = W[t - 15], y = W[t - 2];
          W[t] = (W[t - 16] + ((x >>> 7 | x << 25) ^ (x >>> 18 | x << 14) ^ (x >>> 3)) + W[t - 7] + ((y >>> 17 | y << 15) ^ (y >>> 19 | y << 13) ^ (y >>> 10))) | 0;
        }
        var t1 = (hh + ((e >>> 6 | e << 26) ^ (e >>> 11 | e << 21) ^ (e >>> 25 | e << 7)) + ((e & f) ^ (~e & g)) + K256[t] + W[t]) | 0;
        var t2 = (((a >>> 2 | a << 30) ^ (a >>> 13 | a << 19) ^ (a >>> 22 | a << 10)) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
        hh = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
      }
      h[0] = (h[0] + a) | 0; h[1] = (h[1] + b) | 0; h[2] = (h[2] + c) | 0; h[3] = (h[3] + d) | 0;
      h[4] = (h[4] + e) | 0; h[5] = (h[5] + f) | 0; h[6] = (h[6] + g) | 0; h[7] = (h[7] + hh) | 0;
    }
    return hexWords(h);
  }
  function crc32(s) {
    var c, crc = -1;
    for (var i = 0; i < s.length; i++) {
      c = (crc ^ s.charCodeAt(i)) & 255;
      for (var k = 0; k < 8; k++) c = c & 1 ? (c >>> 1) ^ 0xedb88320 : c >>> 1;
      crc = (crc >>> 8) ^ c;
    }
    return (crc ^ -1) >>> 0;
  }
  function hex2bin(h) { var o = ''; for (var i = 0; i < h.length; i += 2) o += fcc(parseInt(h.substr(i, 2), 16)); return o; }
  def('md5', 'string $string, bool $binary=', function (a) { var h = md5(a[0]); return a[1] ? hex2bin(h) : h; });
  def('sha1', 'string $string, bool $binary=', function (a) { var h = sha1(a[0]); return a[1] ? hex2bin(h) : h; });
  def('crc32', 'string $string', function (a) { return crc32(a[0]); });
  def('hash', 'string $algo, string $data, bool $binary=, array $options=', function (a) {
    var al = asciiLower(a[0]), h;
    if (al === 'md5') h = md5(a[1]); else if (al === 'sha1') h = sha1(a[1]); else if (al === 'sha256') h = sha256(a[1]);
    else if (al === 'crc32b') h = ('0000000' + crc32(a[1]).toString(16)).slice(-8);
    else return vErr('hash', 1, 'algo', 'must be a valid hashing algorithm');
    return a[2] ? hex2bin(h) : h;
  });
  def('hex2bin', 'string $string', function (a) { return hex2bin(a[0]); });
  (function () {
    var c = { FILTER_VALIDATE_INT: 257, FILTER_VALIDATE_BOOLEAN: 258, FILTER_VALIDATE_BOOL: 258, FILTER_VALIDATE_FLOAT: 259, FILTER_VALIDATE_REGEXP: 272,
      FILTER_VALIDATE_URL: 273, FILTER_VALIDATE_EMAIL: 274, FILTER_VALIDATE_IP: 275, FILTER_DEFAULT: 516, FILTER_UNSAFE_RAW: 516,
      FILTER_SANITIZE_SPECIAL_CHARS: 515, FILTER_SANITIZE_FULL_SPECIAL_CHARS: 522, FILTER_SANITIZE_EMAIL: 517, FILTER_SANITIZE_NUMBER_INT: 519,
      FILTER_SANITIZE_NUMBER_FLOAT: 520, FILTER_NULL_ON_FAILURE: 134217728, FILTER_FLAG_ALLOW_FRACTION: 4096, FILTER_FLAG_ALLOW_THOUSAND: 8192,
      CAL_GREGORIAN: 0 };
    Object.keys(c).forEach(function (k) { CONSTS[k] = c[k]; });
  })();

  /* constants */
  (function () {
    var c = {
      PHP_EOL: '\n', PHP_INT_MAX: I64MAX, PHP_INT_MIN: I64MIN, PHP_INT_SIZE: 8, PHP_FLOAT_EPSILON: new Flt(2.220446049250313e-16),
      PHP_FLOAT_MAX: new Flt(1.7976931348623157e308), PHP_FLOAT_MIN: new Flt(2.2250738585072014e-308), PHP_FLOAT_DIG: 15,
      PHP_VERSION: '8.5.12', PHP_MAJOR_VERSION: 8, PHP_MINOR_VERSION: 5, PHP_RELEASE_VERSION: 12, PHP_VERSION_ID: 80512,
      PHP_OS: 'Linux', PHP_OS_FAMILY: 'Linux', DIRECTORY_SEPARATOR: '/', PATH_SEPARATOR: ':',
      M_PI: new Flt(Math.PI), M_E: new Flt(Math.E), M_SQRT2: new Flt(Math.SQRT2), M_PI_2: new Flt(Math.PI / 2), M_PI_4: new Flt(Math.PI / 4),
      M_1_PI: new Flt(1 / Math.PI), M_LN2: new Flt(Math.LN2), M_LN10: new Flt(Math.LN10),
      NAN: new Flt(NaN), INF: new Flt(Infinity),
      E_ERROR: 1, E_WARNING: 2, E_PARSE: 4, E_NOTICE: 8, E_USER_ERROR: 256, E_USER_WARNING: 512, E_USER_NOTICE: 1024,
      E_DEPRECATED: 8192, E_USER_DEPRECATED: 16384, E_ALL: 30719,
      SORT_REGULAR: 0, SORT_NUMERIC: 1, SORT_STRING: 2, SORT_LOCALE_STRING: 5, SORT_NATURAL: 6, SORT_FLAG_CASE: 8,
      COUNT_NORMAL: 0, COUNT_RECURSIVE: 1,
      ENT_HTML401: 0, ENT_NOQUOTES: 0, ENT_COMPAT: 2, ENT_QUOTES: 3, ENT_IGNORE: 4, ENT_SUBSTITUTE: 8, ENT_XML1: 16, ENT_XHTML: 32, ENT_HTML5: 48,
      STR_PAD_LEFT: 0, STR_PAD_RIGHT: 1, STR_PAD_BOTH: 2,
      PHP_ROUND_HALF_UP: 1, PHP_ROUND_HALF_DOWN: 2, PHP_ROUND_HALF_EVEN: 3, PHP_ROUND_HALF_ODD: 4,
      ARRAY_FILTER_USE_BOTH: 1, ARRAY_FILTER_USE_KEY: 2,
      JSON_HEX_TAG: 1, JSON_HEX_QUOT: 8, JSON_FORCE_OBJECT: 16, JSON_UNESCAPED_SLASHES: 64, JSON_PRETTY_PRINT: 128, JSON_UNESCAPED_UNICODE: 256,
      JSON_THROW_ON_ERROR: 4194304, MB_CASE_UPPER: 0, MB_CASE_LOWER: 1, MB_CASE_TITLE: 2, LC_ALL: 6, LC_TIME: 2,
      DATE_ATOM: 'Y-m-d\\TH:i:sP', DATE_RFC2822: 'D, d M Y H:i:s O', DATE_ISO8601: 'Y-m-d\\TH:i:sO'
    };
    Object.keys(c).forEach(function (k) { CONSTS[k] = c[k]; });
  })();

  /* ---------------------------------------------------------------- explanations in French */

  var TYPE_FR = { 'null': 'null (rien)', bool: 'booléen', 'int': 'entier', 'float': 'nombre à virgule', string: 'texte (string)', array: 'tableau', Closure: 'fonction anonyme' };
  var FR = [
    [/^Undefined variable \$(\S+)/, function (m) { return 'La variable $' + m[1] + ' n\'existe pas encore à cette ligne : vérifie son orthographe (majuscules comprises), ou qu\'elle reçoit bien une valeur plus haut.'; }],
    [/^Undefined array key (.+)/, function (m) { return 'Le tableau n\'a pas de case ' + m[1] + ' : vérifie la clé (orthographe, majuscules), ou teste-la avec isset() avant de la lire.'; }],
    [/^Uninitialized string offset (-?\d+)/, function (m) { return 'Le texte est plus court que ça : il n\'y a pas de caractère à la position ' + m[1] + ' (la première position est 0).'; }],
    [/^Array to string conversion/, 'Tu affiches un tableau comme si c\'était du texte : PHP écrit seulement « Array ». Affiche une case précise ($tab[0]) ou parcours-le avec foreach (print_r pour le déboguer).'],
    [/Uncaught DivisionByZeroError: Division by zero/, 'Division par zéro : à cette ligne, le nombre par lequel tu divises vaut 0. Vérifie-le avant de diviser.'],
    [/Uncaught DivisionByZeroError: Modulo by zero/, 'Modulo par zéro : le nombre à droite de % vaut 0.'],
    [/Call to undefined function (\S+)\(\)/, function (m) { return 'La fonction ' + m[1] + '() n\'existe pas : vérifie son nom, ou qu\'elle est bien déclarée (ou que son fichier est bien inclus).'; }],
    [/Too few arguments to function (\S+)\(\), (\d+) passed.* (\d+) expected/, function (m) { return 'La fonction ' + m[1] + '() attend ' + m[3] + ' argument(s), mais l\'appel ne lui en donne que ' + m[2] + '.'; }],
    [/Undefined constant "(.+)"/, function (m) { return 'PHP ne connaît pas « ' + m[1] + ' » : s\'il s\'agit d\'une variable, il manque le $ devant ; s\'il s\'agit de texte, il manque les guillemets.'; }],
    [/^A non-numeric value encountered/, 'Un calcul porte sur un texte qui ne contient pas qu\'un nombre (par exemple « 5 nuits ») : PHP garde le nombre du début et ignore le reste. Calcule avec un vrai nombre.'],
    [/Unsupported operand types: (\S+) (\S+) (\S+)/, function (m) {
      var bad = m[1] === 'array' || m[3] === 'array' ? 'on ne fait pas de calcul avec un tableau' : 'un texte qui n\'est pas un nombre ne se calcule pas';
      return 'Calcul impossible (' + TYPE_FR[m[1]] + ' ' + m[2] + ' ' + TYPE_FR[m[3]] + ') : ' + bad + '. Vérifie ce que contiennent tes variables.';
    }],
    [/^Uncaught TypeError: (\S+)\(\): Argument #(\d+) \(\$(\w+)\) must be of type (\S+), (\S+) given/, function (m) {
      return 'La fonction ' + m[1] + '() attend en argument n°' + m[2] + ' ($' + m[3] + ') une valeur de type ' + m[4] + ', mais reçoit un(e) ' + (TYPE_FR[m[5]] || m[5]) + '.';
    }],
    [/must be a valid callback/, 'Cette fonction attend le nom d\'une fonction qui existe (entre guillemets), ou une fonction anonyme.'],
    [/(\S+)\(\) expects (exactly|at least|at most) (\d+) arguments?, (\d+) given/, function (m) {
      return 'La fonction ' + m[1] + '() attend ' + { exactly: 'exactement', 'at least': 'au moins', 'at most': 'au plus' }[m[2]] + ' ' + m[3] + ' argument(s), et l\'appel en donne ' + m[4] + '.';
    }],
    [/arguments are required, (\d+) given/, 'Il y a plus de % dans le format que de valeurs après lui : chaque %s, %d… a besoin de sa valeur.'],
    [/^Maximum execution time/, 'Ta page ne s\'arrête jamais : une boucle tourne sans fin (sa condition reste toujours vraie).'],
    [/Failed to open stream: No such file or directory/, 'Le fichier à inclure n\'existe pas : vérifie son nom exact (majuscules, extension, dossier).'],
    [/Failed opening '(.+)' for inclusion/, function (m) { return 'PHP n\'a pas pu inclure ' + m[1] + ' : la page continue sans lui (avec require, elle se serait arrêtée).'; }],
    [/Failed opening required/, 'require arrête la page quand le fichier n\'existe pas : vérifie son nom.'],
    [/Cannot redeclare function (\S+)\(\)/, function (m) { return 'La fonction ' + m[1] + '() est déclarée deux fois (peut-être un fichier inclus deux fois : require_once l\'évite).'; }],
    [/foreach\(\) argument must be of type array\|object, (\S+) given/, function (m) { return 'foreach ne sait parcourir qu\'un tableau, et ici la valeur est de type ' + (TYPE_FR[m[1]] || m[1]) + '.'; }],
    [/Trying to access array offset on (\S+)/, function (m) { return 'Tu lis une case [...] dans une valeur qui n\'est pas un tableau (' + (TYPE_FR[m[1]] || m[1]) + ') : le résultat est null.'; }],
    [/Passing null to parameter #\d+ \(\$(\w+)\)/, function (m) { return 'La fonction reçoit null (une valeur vide) pour $' + m[1] + ' : vérifie que ta variable a bien une valeur.'; }],
    [/Implicit conversion from float/, 'Un nombre à virgule est utilisé là où PHP attend un entier : la partie après la virgule est perdue.'],
    [/Cannot use a scalar value as an array/, 'Cette variable contient déjà un nombre ou un booléen : on ne peut pas y ranger des cases [] comme dans un tableau.'],
    [/\[\] operator not supported for strings/, 'Cette variable contient du texte : on ne peut pas lui ajouter une case avec [] comme à un tableau.'],
    [/Cannot access offset of type (\S+) on string/, 'Dans un texte, on ne lit un caractère qu\'avec une position entière : $texte[0], $texte[1]…'],
    [/Cannot access offset of type (\S+)/, function (m) { return 'Une clé de tableau doit être un entier ou un texte, pas un(e) ' + (TYPE_FR[m[1]] || m[1]) + '.'; }],
    [/Unhandled match case/, 'Aucune branche du match ne correspond à cette valeur : ajoute une branche default.'],
    [/^Unparenthesized/, 'Deux ternaires ? : à la suite sans parenthèses : PHP refuse de deviner l\'ordre. Ajoute des parenthèses.'],
    [/not in the 'loop' or 'switch' context/, 'break et continue ne s\'utilisent que dans une boucle (ou un switch).'],
    [/Cannot '(break|continue)' \d+ levels/, 'Il n\'y a pas autant de boucles imbriquées que le nombre indiqué après break / continue.'],
    [/Constant (\S+) already defined/, function (m) { return 'La constante ' + m[1] + ' existe déjà : une constante ne change jamais de valeur.'; }],
    [/Automatic conversion of false to array/, 'Cette variable valait false et devient un tableau : initialise-la avec [] plutôt.'],
    [/unexpected NAN value/, 'Le calcul donne NAN (« pas un nombre », par exemple la racine d\'un négatif).'],
    [/Maximum call stack size/, 'Une fonction s\'appelle elle-même sans fin (récursion infinie) : il lui manque une condition d\'arrêt.'],
    [/Cannot assign an empty string to a string offset/, 'On ne peut pas remplacer un caractère d\'un texte par un texte vide.'],
    [/Object of class Closure could not be converted to string/, 'Une fonction anonyme ne s\'affiche pas : appelle-la avec des parenthèses.'],
    [/not representable as an int/, 'Ce nombre est trop grand (ou n\'en est pas un) pour devenir un entier.'],
    [/(Increment|Decrement) on/, 'Ce ++ ou -- ne porte pas sur un nombre : vérifie le contenu de la variable.'],
    [/Cannot use \[\] for reading/, '$tab[] sert à ajouter une case, pas à lire : indique la clé à lire.'],
    [/must be greater than or equal to 0|must be greater than 0|cannot be empty|must be contained in/, 'Un argument de la fonction a une valeur impossible (négative, vide ou trop grande).'],
    [/Unknown format specifier/, 'Ce % n\'est pas un format connu : %s (texte), %d (entier), %.2f (nombre à 2 décimales)…'],
    [/Only variables should be passed by reference/, 'Cette fonction modifie la variable qu\'on lui donne : passe-lui une variable, pas un calcul ou un appel de fonction.'],
    [/Return value must be of type/, 'La fonction renvoie une valeur d\'un autre type que celui annoncé après les deux-points.'],
    [/must be of type (\S+), (\S+) given/, function (m) { return 'Mauvais type : une valeur de type ' + m[1] + ' est attendue, mais c\'est un(e) ' + (TYPE_FR[m[2]] || m[2]) + '.'; }]
  ];
  function frOf(level, msg) {
    for (var i = 0; i < FR.length; i++) {
      var m = FR[i][0].exec(msg);
      if (m) return typeof FR[i][1] === 'function' ? FR[i][1](m) : FR[i][1];
    }
    if (level === 'Deprecated') return 'Ce code fonctionne encore, mais une prochaine version de PHP le refusera.';
    if (level === 'Warning' || level === 'Notice') return 'PHP signale un problème à cette ligne, mais la page continue.';
    return 'Erreur fatale : PHP arrête la page à cette ligne.';
  }
  function canEnd(t) {
    return t && (t.t === 'var' || t.t === 'int' || t.t === 'float' || t.t === 'str' || t.t === 'tpl' || t.t === 'id' ||
      (t.t === 'op' && (t.v === ')' || t.v === ']' || t.v === '++' || t.v === '--')));
  }
  function frParse(msg, t, prev) {
    var m;
    if ((m = /^Unclosed '([{(\[])' on line (\d+)$/.exec(msg))) {
      return { '{': 'Une accolade { ouverte ligne ' + m[2] + ' n\'est jamais refermée : il manque une } (à la fin d\'un if, d\'une boucle ou d\'une fonction).',
        '(': 'Une parenthèse ( ouverte ligne ' + m[2] + ' n\'est jamais refermée : il manque une ).', '[': 'Un crochet [ ouvert ligne ' + m[2] + ' n\'est jamais refermé : il manque un ].' }[m[1]];
    }
    if ((m = /^Unclosed '(.)' on line (\d+) does not match '(.)'/.exec(msg))) return 'Ce ' + m[3] + ' ne ferme pas le bon élément : le ' + m[1] + ' ouvert ligne ' + m[2] + ' n\'est pas encore refermé.';
    if ((m = /^Unmatched '(.)'/.exec(msg))) return 'Ce ' + m[1] + ' ne ferme rien : il y en a un de trop, ou bien l\'ouvrant correspondant manque plus haut.';
    if (/^Unterminated comment/.test(msg)) return 'Un commentaire /* n\'est jamais refermé par */ : tout le reste du fichier est ignoré.';
    if (/end of file, expecting variable or "\$\{"/.test(msg)) return 'Une chaîne entre guillemets " n\'est jamais refermée : il manque le guillemet de fin.';
    if (/unexpected string content "", expecting "-" or identifier/.test(msg)) return 'Dans une chaîne entre guillemets, une case de tableau s\'écrit {$tab[\'cle\']} (avec les accolades) ou $tab[cle] (sans apostrophes).';
    if (/unexpected string content/.test(msg)) return 'Une chaîne entre apostrophes \' n\'est jamais refermée : il manque l\'apostrophe de fin (ou une apostrophe du texte doit s\'écrire \\\').';
    if (/unexpected end of file/.test(msg)) return 'Le fichier se termine alors que le code n\'est pas fini : il manque sans doute une } , une ) ou un point-virgule.';
    if (prev && t && t.line > prev.line && canEnd(prev) && !/expecting "[\])]"$/.test(msg)) {
      return 'Il manque sans doute un point-virgule ; à la fin de la ligne ' + prev.line + ' (PHP ne s\'en aperçoit qu\'en lisant la ligne suivante).';
    }
    if (/unexpected token "=",?/.test(msg) && prev && prev.t === 'id') return 'Il manque sans doute le $ devant « ' + prev.raw + ' » : une variable s\'écrit $' + prev.raw + '.';
    if (/unexpected token "}"/.test(msg)) return 'L\'instruction juste avant cette accolade n\'est pas terminée : il manque sans doute un point-virgule.';
    if (/unexpected token "\)", expecting ";"/.test(msg)) return 'Dans un for, les trois parties se séparent par des points-virgules : for ($i = 0; $i < 10; $i++).';
    if (/expecting "\)"$/.test(msg)) return 'Il manque une parenthèse fermante ) avant cet endroit.';
    if (/expecting "\]"$/.test(msg)) return 'Il manque un crochet fermant ] avant cet endroit.';
    if (/expecting variable$/.test(msg)) return 'Ici PHP attend une variable (avec son $).';
    if (/unexpected (single|double)-quoted string|unexpected token """/.test(msg)) return 'Deux valeurs se suivent sans rien entre elles : il manque sans doute un point . pour les coller (ou une virgule dans un echo).';
    if (/unexpected variable/.test(msg)) return 'Une variable arrive là où PHP ne l\'attend pas : il manque sans doute un opérateur (. + …), une virgule ou un point-virgule juste avant.';
    if (/unexpected identifier/.test(msg) && t && t.t === 'id') return 'PHP ne comprend pas le mot « ' + t.raw + ' » ici : s\'il s\'agit d\'une variable, il manque le $ ; s\'il s\'agit de texte, il manque les guillemets.';
    if (/unexpected token "(else|elseif|endif|endwhile|endforeach|endfor)"/.test(msg)) return 'Ce mot ne suit aucun bloc ouvert : vérifie les accolades du if (ou de la boucle) au-dessus.';
    if (/unexpected token ":"/.test(msg)) return 'Ce deux-points est mal placé : après un else, ouvre plutôt une accolade { … }.';
    if (/expecting "\("/.test(msg)) return 'Il faut une parenthèse ( juste après ce mot-clé : if (…), while (…), elseif (…).';
    if (/unexpected token "<"/.test(msg)) return 'On ne peut pas enchaîner deux comparaisons : écris ($a < $b && $b < $c).';
    return 'PHP ne comprend pas cette ligne : relis-la caractère par caractère (point-virgule, parenthèses, guillemets, $ des variables).';
  }

  /* ---------------------------------------------------------------- the trace */

  function short(v, depth) {
    if (v === null || v === undefined) return 'null';
    if (typeof v === 'boolean') return v ? 'true' : 'false';
    if (isInt(v)) return '' + v;
    if (v instanceof Flt) { var f = fmtFloat(v.v, 17); return /^-?\d+$/.test(f) ? f + '.0' : f; }
    if (typeof v === 'string') { var t = unutf8(v); return '"' + (t.length > 40 ? t.slice(0, 40) + '…' : t) + '"'; }
    if (v instanceof PArr) {
      if (depth > 1) return '[…]';
      var isList = true, i = 0, parts = [];
      v.m.forEach(function (x, k) { if (k !== i++) isList = false; });
      i = 0;
      for (var e of v.m) {
        if (i++ >= 8) { parts.push('…'); break; }
        var val = short(e[1] instanceof Ref ? e[1].v : e[1], depth + 1);
        parts.push(isList ? val : (typeof e[0] === 'string' ? "'" + unutf8(e[0]) + "'" : e[0]) + ' => ' + val);
      }
      return '[' + parts.join(', ') + ']';
    }
    return 'Closure';
  }
  function traceRec(s, sc) {
    if (R.trace.length >= 2000) return;
    var vars = {};
    sc.vars.forEach(function (r, name) { if (r.v !== undefined) vars['$' + unutf8(name)] = short(r.v, 0); });
    R.trace.push({ file: R.file, line: s.line, vars: vars });
  }

  /* ---------------------------------------------------------------- run */

  function toPhp(v, asString) {
    if (v === null || v === undefined) return null;
    if (Array.isArray(v)) { var a = new PArr(); v.forEach(function (x) { a.push(share(toPhp(x, asString))); }); return a; }
    switch (typeof v) {
      case 'boolean': return asString ? (v ? '1' : '') : v;
      case 'number': if (asString) return utf8(String(v)); return Number.isInteger(v) && Math.abs(v) <= MAXS ? v : new Flt(v);
      case 'string': return utf8(v);
      case 'object': {
        var o = new PArr();
        Object.keys(v).forEach(function (k) { o.set(strKey(utf8(k)), share(toPhp(v[k], asString))); });
        return o;
      }
    }
    return null;
  }
  function urlencode(s) {
    return unutf8(s).replace(/[^A-Za-z0-9_.\- ]/g, function (c) {
      return utf8(c).split('').map(function (b) { return '%' + ('0' + b.charCodeAt(0).toString(16).toUpperCase()).slice(-2); }).join('');
    }).replace(/ /g, '+');
  }
  function buildQuery(arr, prefix) {
    var parts = [];
    arr.m.forEach(function (v, k) {
      v = v instanceof Ref ? v.v : v;
      var key = prefix ? prefix + '[' + k + ']' : '' + k;
      if (v instanceof PArr) { var q = buildQuery(v, key); if (q) parts.push(q); }
      else if (v !== null) parts.push(urlencode(key) + '=' + urlencode(toStr(v)));
    });
    return parts.join('&');
  }

  function run(opts) {
    opts = opts || {};
    var files = {};
    Object.keys(opts.files || {}).forEach(function (k) { files[normPath(k)] = utf8(String(opts.files[k])); });
    var entry = normPath(opts.entry || Object.keys(files)[0] || 'index.php');
    R = {
      files: files, segs: [], errors: [], fns: {}, consts: {}, included: {}, sg: {}, gsc: new Scope(null), file: entry, ln: 1, sl: 1,
      steps: 0, maxSteps: opts.maxSteps || 200000, trace: opts.trace ? [] : null, depth: 0, silence: 0, fatal: false, exited: false,
      seed: (opts.seed === undefined ? 42 : opts.seed) | 0, curSc: null, now: 0
    };
    var nm = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(opts.now || '');
    R.now = nm ? localToTs(+nm[1], +nm[2], +nm[3], +(nm[4] || 0), +(nm[5] || 0), +(nm[6] || 0)) : Math.floor(Date.now() / 1000);
    var get = toPhp(opts.get || {}, true), qs = buildQuery(get), server = new PArr();
    [['REQUEST_METHOD', 'GET'], ['SCRIPT_NAME', '/' + entry], ['PHP_SELF', '/' + entry], ['REQUEST_URI', '/' + entry + (qs ? '?' + qs : '')],
      ['QUERY_STRING', qs], ['HTTP_HOST', 'localhost'], ['SERVER_NAME', 'localhost']].forEach(function (p) { server.set(p[0], utf8(p[1])); });
    function sg(name, v) { R.sg[name] = new Ref(share(v)); }
    sg('_GET', get); sg('_POST', new PArr()); sg('_REQUEST', get); sg('_SERVER', server);
    sg('_COOKIE', new PArr()); sg('_FILES', new PArr()); sg('_ENV', new PArr());
    Object.keys(opts.globals || {}).forEach(function (k) { R.gsc.vars.set(utf8(k), new Ref(share(toPhp(opts.globals[k], false)))); });
    R.curSc = R.gsc;
    try {
      if (!Object.prototype.hasOwnProperty.call(files, entry)) { R.fatal = true; record('Fatal error', "Failed opening required '" + entry + "'", 0, entry, 'Le fichier de la page n\'existe pas.'); }
      else {
        R.included[entry] = true;
        runUnit(compileUnit(entry), R.gsc);
        if (opts.append) {
          var ap = normPath(opts.append);
          if (Object.prototype.hasOwnProperty.call(files, ap)) { R.included[ap] = true; runUnit(compileUnit(ap), R.gsc); }
        }
      }
    } catch (e) {
      if (e instanceof PhpThrow) { R.fatal = true; R.silence = 0; record('Fatal error', 'Uncaught ' + e.cls + ': ' + e.msg, e.line, e.file); }
      else if (e instanceof RangeError && /call stack/i.test(e.message)) {
        R.fatal = true; R.silence = 0;
        record('Fatal error', 'Uncaught Error: Maximum call stack size of 8339456 bytes reached. Infinite recursion?', R.ln, R.file);
      } else if (!(e instanceof Stop)) throw e;
    }
    var segments = R.segs.map(function (s) { return { text: unutf8(s.text), file: s.file, line: s.line, php: s.php }; });
    var result = {
      output: segments.map(function (s) { return s.text; }).join(''), segments: segments, errors: R.errors,
      fatal: R.fatal, steps: R.steps, trace: R.trace || []
    };
    R = null;
    return result;
  }

  /* ---------------------------------------------------------------- tokens for the editor */

  var HL_OPS = ['<<=', '>>=', '**=', '...', '<=>', '===', '!==', '??=', '?->'].concat(OPS2);
  function tokens(src) {
    var out = [], i = 0, n = src.length, line = 1, m;
    function push(type, text) { if (!text) return; out.push({ type: type, text: text, line: line }); line += nl(text); }
    while (i < n) {
      var k = i, open = -1, len = 0;
      while ((k = src.indexOf('<?', k)) >= 0) {
        if (src[k + 2] === '=') { open = k; len = 3; break; }
        if (src.substr(k + 2, 3).toLowerCase() === 'php' && (k + 5 >= n || /[ \t\r\n]/.test(src[k + 5]))) { open = k; len = 5; break; }
        k += 2;
      }
      push('html', src.slice(i, open < 0 ? n : open));
      if (open < 0) break;
      push('tag', src.substr(open, len));
      i = open + len;
      while (i < n) {
        var c = src[i], rest;
        if (/\s/.test(c)) { m = /\s+/y; m.lastIndex = i; rest = m.exec(src)[0]; push('space', rest); i += rest.length; continue; }
        if (c === '?' && src[i + 1] === '>') { push('tag', '?>'); i += 2; break; }
        if (c === '#' || (c === '/' && src[i + 1] === '/')) {
          var e = i; while (e < n && src[e] !== '\n' && !(src[e] === '?' && src[e + 1] === '>')) e++;
          push('comment', src.slice(i, e)); i = e; continue;
        }
        if (c === '/' && src[i + 1] === '*') { var ce = src.indexOf('*/', i + 2); ce = ce < 0 ? n : ce + 2; push('comment', src.slice(i, ce)); i = ce; continue; }
        if (c === '$' && /[A-Za-z_\u0080-￿]/.test(src[i + 1] || '')) { m = /\$[A-Za-z_\u0080-￿][A-Za-z0-9_\u0080-￿]*/y; m.lastIndex = i; rest = m.exec(src)[0]; push('variable', rest); i += rest.length; continue; }
        if (c === "'" || c === '"' || c === '`') {
          var j = i + 1; while (j < n && src[j] !== c) j += src[j] === '\\' ? 2 : 1;
          push('string', src.slice(i, Math.min(n, j + 1))); i = Math.min(n, j + 1); continue;
        }
        if (src.substr(i, 3) === '<<<') {
          m = /<<<[ \t]*(["']?)([A-Za-z_][A-Za-z0-9_]*)\1/y; m.lastIndex = i;
          var hm = m.exec(src);
          if (hm) {
            var end = new RegExp('\\n[ \\t]*' + hm[2] + '(?![A-Za-z0-9_])', 'g'); end.lastIndex = i;
            var em = end.exec(src), stop = em ? em.index + em[0].length : n;
            push('string', src.slice(i, stop)); i = stop; continue;
          }
        }
        if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(src[i + 1] || ''))) { NUM_TOK.lastIndex = i; rest = NUM_TOK.exec(src)[0]; push('number', rest); i += rest.length; continue; }
        if (/[A-Za-z_\u0080-￿\\]/.test(c)) {
          m = /\\?[A-Za-z_\u0080-￿][A-Za-z0-9_\u0080-￿\\]*/y; m.lastIndex = i;
          var wm = m.exec(src);
          if (wm) {
            var low = wm[0].toLowerCase();
            push(KEYWORDS[low] || low === 'true' || low === 'false' || low === 'null' ? 'keyword' : 'name', wm[0]);
            i += wm[0].length; continue;
          }
        }
        var op = c;
        for (var q = 0; q < HL_OPS.length; q++) if (src.substr(i, HL_OPS[q].length) === HL_OPS[q] && HL_OPS[q].length > op.length) op = HL_OPS[q];
        push('operator', op); i += op.length;
      }
    }
    return out;
  }

  var api = { run: run, tokens: tokens, version: '8.5.12' };
  root.SDNPhp = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
